<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service\Writer;

use JsonLinesImporter\Dto\I18nBag;
use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Dto\ProductLine;
use JsonLinesImporter\Dto\VideoLine;
use JsonLinesImporter\Enum\VideoDestination;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\ShopContext;
use JsonLinesImporter\Service\YoutubeId;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\FeatureAvQuery;
use Thelia\Model\FeatureProductQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Product;

/**
 * Videos of a product, to the destination chosen for the import.
 *
 * Blocks: Thelia Blocks allows one block group per object; only its video blocks are rewritten, the
 * other blocks stay in place, before them. Feature: the first video becomes the free value of the chosen
 * feature, as a watch?v= address written in every active locale (an address does not depend on the
 * language, and the front reads the value of the current locale only).
 */
final readonly class VideoWriter
{
    public const BLOCKS_MODULE = 'TheliaBlocks';

    private const BLOCK_TYPE = 'blockVideo';

    private const BLOCK_TITLE = ['default' => 'Video', 'fr' => 'Vidéo', 'en' => 'Video', 'es' => 'Video', 'it' => 'Video', 'de' => 'Video'];

    public function __construct(
        private YoutubeId $youtubeId,
        private FeatureWriter $featureWriter,
        private ShopContext $shopContext,
    ) {
    }

    public static function isBlocksAvailable(): bool
    {
        return class_exists(\TheliaBlocks\Model\BlockGroup::class)
            && null !== ModuleQuery::create()->filterByCode(self::BLOCKS_MODULE)->filterByActivate(1)->findOne();
    }

    public function write(Product $product, ProductLine $line, ImportParameters $parameters, LineReport $report, ConnectionInterface $con): void
    {
        if (!$line->videos->present) {
            return;
        }

        /** @var list<VideoLine> $videos */
        $videos = $line->videos->value ?? [];
        usort($videos, static fn (VideoLine $left, VideoLine $right): int => ($left->position ?? \PHP_INT_MAX) <=> ($right->position ?? \PHP_INT_MAX));

        $valid = [];

        foreach ($videos as $video) {
            $videoId = $this->youtubeId->extract($video->source);

            if (null === $videoId) {
                $report->warn(\sprintf('Video ignored, not a YouTube address: %s', $video->source));

                continue;
            }

            $valid[] = [$video, $videoId];
        }

        if (VideoDestination::FEATURE === $parameters->videoDestination) {
            $this->writeFeature($product, $valid, $parameters->videoFeatureId, $line->videos->isNull(), $report, $con);

            return;
        }

        if (!self::isBlocksAvailable()) {
            $report->warn('Videos ignored: the Thelia Blocks module is not active');

            return;
        }

        $this->writeBlocks($product, $valid, $report, $con);
    }

    /** @param list<array{VideoLine, string}> $videos */
    private function writeFeature(Product $product, array $videos, ?int $featureId, bool $isNull, LineReport $report, ConnectionInterface $con): void
    {
        if (null === $featureId) {
            $report->warn('Videos ignored: no feature chosen to receive them');

            return;
        }

        $link = FeatureProductQuery::create()
            ->filterByProductId($product->getId())
            ->filterByFeatureId($featureId)
            ->findOne($con);

        if ($isNull || [] === $videos) {
            if (null !== $link) {
                $valueId = $link->getIsFreeText() ? $link->getFeatureAvId() : null;
                $link->delete($con);

                if (null !== $valueId) {
                    FeatureAvQuery::create()->filterById($valueId)->delete($con);
                }
            }

            return;
        }

        if (\count($videos) > 1) {
            $report->warn('Only the first video is kept: the feature destination holds one video per product');
        }

        if (null === $link || !$link->getIsFreeText() || null === $link->getFeatureAvId()) {
            $link?->delete($con);
            $link = $this->featureWriter->createFreeLink($product, $featureId, $con);
        }

        $url = $this->youtubeId->watchUrl($videos[0][1]);
        $titles = [];

        foreach ($this->shopContext->activeLocales() as $locale) {
            $titles[$locale] = ['title' => $url];
        }

        $this->featureWriter->writeFreeValue((int) $link->getFeatureAvId(), new I18nBag($titles), $con);
    }

    /** @param list<array{VideoLine, string}> $videos */
    private function writeBlocks(Product $product, array $videos, LineReport $report, ConnectionInterface $con): void
    {
        $defaultLocale = $this->shopContext->defaultLocale();
        $link = \TheliaBlocks\Model\ItemBlockGroupQuery::create()
            ->filterByItemType('product')
            ->filterByItemId($product->getId())
            ->findOne($con);
        $group = null !== $link ? \TheliaBlocks\Model\BlockGroupQuery::create()->findPk($link->getBlockGroupId(), $con) : null;

        if ([] === $videos) {
            if (null !== $group) {
                $this->removeVideoBlocks((int) $group->getId(), $con);
            }

            return;
        }

        $locales = [$defaultLocale];

        foreach ($videos as [$video]) {
            $locales = [...$locales, ...$video->i18n->locales()];
        }

        $groupTitles = $this->groupTitles($videos, array_values(array_unique($locales)), $defaultLocale);

        if (\count(array_unique(array_filter(array_map(static fn (array $item): ?string => $item[0]->i18n->firstValue('group', $defaultLocale), $videos)))) > 1) {
            $report->warn('Several video groups in the line: only the first one is used');
        }

        if (null === $group) {
            $group = (new \TheliaBlocks\Model\BlockGroup())
                ->setVisible(1)
                ->setSlug(substr(strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', 'product_'.$product->getRef())), 0, 45));

            foreach ($groupTitles as $locale => $title) {
                $group->setLocale($locale)->setTitle($title)->setJsonContent('[]');
            }

            $group->save($con);

            (new \TheliaBlocks\Model\ItemBlockGroup())
                ->setItemType('product')
                ->setItemId((int) $product->getId())
                ->setBlockGroupId((int) $group->getId())
                ->save($con);
        }

        foreach (array_keys($groupTitles) as $locale) {
            $group->setLocale($locale);

            if (null === $group->getTitle()) {
                $group->setTitle($groupTitles[$locale]);
            }

            $blocks = self::withoutVideoBlocks((string) $group->getJsonContent());

            foreach ($videos as [$video, $videoId]) {
                $blocks[] = [
                    'id' => self::uuid(),
                    'data' => ['url' => $video->source, 'videoId' => $videoId],
                    'parent' => null,
                    'title' => self::BLOCK_TITLE,
                    'type' => ['id' => self::BLOCK_TYPE],
                ];
            }

            $group->setJsonContent(json_encode($blocks, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        }

        $group->save($con);
    }

    /**
     * @param list<array{VideoLine, string}> $videos
     * @param list<string>                   $locales
     *
     * @return array<string, string> locale => title of the group: its `group`, else the title of the first video
     */
    private function groupTitles(array $videos, array $locales, string $defaultLocale): array
    {
        $titles = [];

        foreach ($locales as $locale) {
            $title = null;

            foreach ($videos as [$video]) {
                $title ??= $video->i18n->get($locale, 'group');
            }

            foreach ($videos as [$video]) {
                $title ??= $video->i18n->get($locale, 'title');
            }

            $titles[$locale] = $title ?? ($videos[0][0]->i18n->firstValue('group', $defaultLocale) ?? $videos[0][0]->i18n->firstValue('title', $defaultLocale) ?? 'Videos');
        }

        return $titles;
    }

    private function removeVideoBlocks(int $groupId, ConnectionInterface $con): void
    {
        foreach (\TheliaBlocks\Model\BlockGroupI18nQuery::create()->filterById($groupId)->find($con) as $translation) {
            $translation
                ->setJsonContent(json_encode(self::withoutVideoBlocks((string) $translation->getJsonContent()), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES))
                ->save($con);
        }
    }

    /** @return list<mixed> */
    private static function withoutVideoBlocks(string $jsonContent): array
    {
        $blocks = json_decode($jsonContent, true);

        return array_values(array_filter(
            \is_array($blocks) ? $blocks : [],
            static fn (mixed $block): bool => self::BLOCK_TYPE !== ($block['type']['id'] ?? null),
        ));
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
