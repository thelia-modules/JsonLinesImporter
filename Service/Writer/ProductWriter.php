<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service\Writer;

use JsonLinesImporter\Dto\I18nBag;
use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Dto\ProductLine;
use JsonLinesImporter\Enum\LineAction;
use JsonLinesImporter\Enum\ReferentialType;
use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\ReferentialResolver;
use JsonLinesImporter\Service\ShopContext;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Thelia\Model\Product;
use Thelia\Model\ProductI18nQuery;
use Thelia\Model\ProductQuery;

/**
 * Writes one line in its own transaction, never nested in another one: Propel has no savepoints, a
 * nested rollback cancels nothing and makes the outer commit fail. Files are fetched before the
 * transaction; files written for a line that fails are removed, files to delete are only removed once
 * the line is committed.
 */
final readonly class ProductWriter
{
    public function __construct(
        private ShopContext $shopContext,
        private ReferentialResolver $resolver,
        private MediaWriter $mediaWriter,
        private SaleElementWriter $saleElementWriter,
        private CategoryWriter $categoryWriter,
        private FeatureWriter $featureWriter,
        private VideoWriter $videoWriter,
        private RedirectWriter $redirectWriter,
        private HtmlImageWriter $htmlImageWriter,
        private AccessoryWriter $accessoryWriter,
    ) {
    }

    public function plannedAction(string $ref): LineAction
    {
        return ProductQuery::create()->filterByRef($ref)->exists() ? LineAction::UPDATE : LineAction::CREATE;
    }

    /**
     * @throws ImportException|\Throwable when the line cannot be written; nothing of it remains in the database
     */
    public function write(ProductLine $line, ImportParameters $parameters, LineReport $report): LineAction
    {
        $product = ProductQuery::create()->findOneByRef($line->ref);
        $isCreation = null === $product;

        if ($isCreation && !$parameters->createProducts) {
            throw new ImportException('The product does not exist and the creation of products is disabled');
        }

        if (!$isCreation && !$parameters->updateProducts) {
            throw new ImportException('The product exists and the update of products is disabled');
        }

        if ($parameters->copyHtmlImages) {
            $line = $this->withI18n($line, $this->htmlImageWriter->rewrite($line->i18n, $report));
        }

        $fetched = $this->mediaWriter->prepare($line, $product?->getId(), $parameters, $report);
        $files = new FileOperations();
        $con = Propel::getWriteConnection('TheliaMain');
        $con->beginTransaction();

        try {
            $product = $isCreation ? $this->create($line, $parameters, $report, $con) : $product;

            $this->writeProduct($product, $line, $isCreation, $report, $con);

            $imageKeys = $this->mediaWriter->write($product, $line, $fetched, $files, $con);
            $this->saleElementWriter->write($product, $line, $isCreation, $imageKeys, $report, $con);

            if (!$isCreation) {
                $this->categoryWriter->write($product, $line, $report, $con);
            } elseif ($line->categories->hasValue()) {
                // Product::create() set the default one; the others are added now.
                $this->categoryWriter->write($product, $line, new LineReport(), $con);
            }

            $this->featureWriter->write($product, $line, $parameters->videoFeatureId, $report, $con);
            $this->videoWriter->write($product, $line, $parameters, $report, $con);
            $this->redirectWriter->write($product, $line, $report, $con);

            $con->commit();

            if ($files->haveProductImagesBeenDeleted()) {
                $report->markProductImagesDeleted();
            }

            $files->commit();
        } catch (\Throwable $exception) {
            $con->rollBack();
            $files->rollback();

            throw $exception;
        } finally {
            foreach ($fetched as $file) {
                $file->discard();
            }
        }

        return $isCreation ? LineAction::CREATE : LineAction::UPDATE;
    }

    /**
     * Second pass, once every line is imported.
     */
    public function writeAccessories(ProductLine $line, LineReport $report): void
    {
        if (!$line->accessories->present) {
            return;
        }

        $product = ProductQuery::create()->findOneByRef($line->ref);

        if (null === $product) {
            return;
        }

        $con = Propel::getWriteConnection('TheliaMain');
        $con->beginTransaction();

        try {
            $this->accessoryWriter->write($product, $line, $report, $con);
            $con->commit();
        } catch (\Throwable $exception) {
            $con->rollBack();
            $report->warn('Accessories not written: '.$exception->getMessage());
        }
    }

    private function create(ProductLine $line, ImportParameters $parameters, LineReport $report, ConnectionInterface $con): Product
    {
        $categoryIds = $this->categoryWriter->resolve($line, $report);
        $categoryId = $categoryIds[0] ?? $parameters->fallbackCategoryId;

        if (null === $categoryId) {
            throw new ImportException('No category for the new product: set a fallback category');
        }

        $taxRuleId = $this->taxRuleId($line, $report) ?? $this->shopContext->defaultTaxRuleId();

        if (null === $taxRuleId) {
            throw new ImportException('No tax rule for the new product and no default tax rule in the shop');
        }

        $defaultLocale = $this->shopContext->defaultLocale();

        // Thelia requires a title to create a product (its URL is generated from it): the title of the
        // line whatever the selection of step 2, else the reference.
        $product = (new Product())
            ->setRef($line->ref)
            ->setVisible($line->visible->hasValue() ? (int) $line->visible->value : (int) $parameters->defaultVisible)
            ->setVirtual((int) $line->virtual->or(false))
            ->setPosition((int) $line->position->or(0))
            ->setTemplateId($parameters->templateId);

        $product->setLocale($defaultLocale)->setTitle($line->i18n->get($defaultLocale, 'title') ?? $line->ref);

        $sale = $line->sale;
        $product->create(
            (int) $categoryId,
            (float) $sale->price->or(0),
            (int) $this->shopContext->defaultCurrency()->getId(),
            $taxRuleId,
            (float) $sale->weight->or(0),
            (int) $sale->quantity->or(0),
        );

        return $product;
    }

    private function writeProduct(Product $product, ProductLine $line, bool $isCreation, LineReport $report, ConnectionInterface $con): void
    {
        if (!$isCreation) {
            if ($line->visible->present) {
                $product->setVisible((int) $line->visible->or(false));
            }

            if ($line->virtual->present) {
                $product->setVirtual((int) $line->virtual->or(false));
            }

            if ($line->position->hasValue()) {
                $product->setPosition((int) $line->position->value);
            }
        }

        if ($line->taxRule->present) {
            $taxRuleId = $line->taxRule->isNull() ? $this->shopContext->defaultTaxRuleId() : $this->taxRuleId($line, $report);

            if (null !== $taxRuleId) {
                $product->setTaxRuleId($taxRuleId);
            }
        }

        if ($line->brand->present) {
            $brandId = $line->brand->isNull() ? null : $this->resolver->resolve(ReferentialType::BRAND, $line->brand->value);

            if ($line->brand->hasValue() && null === $brandId) {
                $report->warn(\sprintf('Brand "%s" ignored', $line->brand->value->matchingTitle));
            } else {
                $product->setBrandId($brandId);
            }
        }

        $this->writeTranslations($product, $line, $isCreation, $report, $con);

        $product->save($con);
    }

    /**
     * Title rule: always written on creation (the reference when the line has none); on an existing
     * product, only when its box is ticked at step 2. A null title is treated as an absent one.
     */
    private function writeTranslations(Product $product, ProductLine $line, bool $isCreation, LineReport $report, ConnectionInterface $con): void
    {
        $defaultLocale = $this->shopContext->defaultLocale();
        $writeTitle = $isCreation || $line->titleSelected;

        foreach ($line->i18n->values as $locale => $fields) {
            if (\array_key_exists('title', $fields) && null === $fields['title']) {
                $report->warn(\sprintf('title (%s): null ignored, a product needs a title', $locale));
                unset($fields['title']);
            }

            if (!$writeTitle) {
                unset($fields['title']);
            }

            if ([] === $fields) {
                continue;
            }

            $translation = $product->getTranslation($locale, $con);

            // A new translation generates its URL from the title on insert, and fails without one.
            if ($translation->isNew() && !isset($fields['title'])) {
                $fields['title'] = $this->existingTitle($product, $defaultLocale) ?? $line->ref;
            }

            foreach ($fields as $name => $value) {
                match ($name) {
                    'title' => $translation->setTitle($value),
                    'chapo' => $translation->setChapo($value),
                    'description' => $translation->setDescription($value),
                    'postscriptum' => $translation->setPostscriptum($value),
                    'meta_title' => $translation->setMetaTitle($value),
                    'meta_description' => $translation->setMetaDescription($value),
                    'meta_keywords' => $translation->setMetaKeywords($value),
                    default => null,
                };
            }
        }
    }

    private function existingTitle(Product $product, string $defaultLocale): ?string
    {
        $title = ProductI18nQuery::create()
            ->filterById($product->getId())
            ->filterByLocale($defaultLocale)
            ->select(['Title'])
            ->findOne();

        return null === $title || '' === trim((string) $title) ? null : (string) $title;
    }

    private function taxRuleId(ProductLine $line, LineReport $report): ?int
    {
        if (!$line->taxRule->hasValue()) {
            return null;
        }

        $taxRuleId = $this->resolver->resolve(ReferentialType::TAX_RULE, $line->taxRule->value);

        if (null === $taxRuleId) {
            $report->warn(\sprintf('Tax rule "%s" ignored', $line->taxRule->value->matchingTitle));
        }

        return $taxRuleId;
    }

    private function withI18n(ProductLine $line, I18nBag $i18n): ProductLine
    {
        return new ProductLine(
            ref: $line->ref,
            visible: $line->visible,
            virtual: $line->virtual,
            position: $line->position,
            i18n: $i18n,
            titleSelected: $line->titleSelected,
            urls: $line->urls,
            sale: $line->sale,
            taxRule: $line->taxRule,
            categories: $line->categories,
            brand: $line->brand,
            features: $line->features,
            variants: $line->variants,
            images: $line->images,
            documents: $line->documents,
            videos: $line->videos,
            accessories: $line->accessories,
        );
    }
}
