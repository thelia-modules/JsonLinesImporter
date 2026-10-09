<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service\Writer;

use JsonLinesImporter\Dto\ProductLine;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\UrlPathNormalizer;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\Product;
use Thelia\Model\RewritingUrl;
use Thelia\Model\RewritingUrlQuery;
use Thelia\Tools\URL;

/**
 * `url` is the old address of the product page: it becomes a 301 redirection to the current URL of the
 * product in the same locale. The current URL is never changed, and an old redirection is kept when the
 * address changes from one import to the next.
 *
 * The rows are written in SQL, not through the RewritingUrl model: its preInsert() sanitizes the URL
 * (accents converted, non ASCII characters dropped: "m²" becomes "m") so the router, which compares the
 * decoded request path, would never find it; and its postInsert() points every other redirection of the
 * product at the new row, the new row included.
 */
final readonly class RedirectWriter
{
    public function __construct(
        private UrlPathNormalizer $normalizer,
    ) {
    }

    public function write(Product $product, ProductLine $line, LineReport $report, ConnectionInterface $con): void
    {
        $written = false;

        foreach ($line->urls->values as $locale => $fields) {
            $address = $fields['url'] ?? null;

            if (null === $address) {
                continue;
            }

            $paths = $this->normalizer->paths($address);

            if ([] === $paths) {
                $report->warn(\sprintf('url (%s) ignored: %s cannot be a redirection (empty, "+", or longer than %d bytes)', $locale, $address, UrlPathNormalizer::MAX_BYTES));

                continue;
            }

            $current = RewritingUrlQuery::create()
                ->filterByView('product')
                ->filterByViewId((string) $product->getId())
                ->filterByViewLocale($locale)
                ->filterByRedirected(null, Criteria::ISNULL)
                ->findOne($con);

            if (null === $current) {
                $report->warn(\sprintf('url (%s) ignored: the product has no URL in this locale', $locale));

                continue;
            }

            foreach ($paths as $path) {
                $written = $this->redirect($path, $current, $report, $con) || $written;
            }
        }

        if ($written) {
            try {
                URL::getInstance()->clearRewritingUrlCache();
            } catch (\Throwable) {
                // No URL instance yet: nothing cached either.
            }
        }
    }

    private function redirect(string $path, RewritingUrl $current, LineReport $report, ConnectionInterface $con): bool
    {
        if ($path === $current->getUrl()) {
            return false;
        }

        $existing = RewritingUrlQuery::create()->filterByUrl($path)->findOne($con);

        if (null === $existing) {
            $con->prepare('INSERT INTO `rewriting_url` (`url`, `view`, `view_id`, `view_locale`, `redirected`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, NOW(), NOW())')
                ->execute([$path, 'product', (string) $current->getViewId(), $current->getViewLocale(), (int) $current->getId()]);

            return true;
        }

        $sameProduct = 'product' === $existing->getView()
            && (string) $existing->getViewId() === (string) $current->getViewId()
            && $existing->getViewLocale() === $current->getViewLocale();

        if (!$sameProduct) {
            $report->warn(\sprintf('Redirection /%s not created: the address is already used by another page', $path));

            return false;
        }

        if ((int) $existing->getRedirected() === (int) $current->getId()) {
            return false;
        }

        $con->prepare('UPDATE `rewriting_url` SET `redirected` = ?, `updated_at` = NOW() WHERE `id` = ?')
            ->execute([(int) $current->getId(), (int) $existing->getId()]);

        return true;
    }
}
