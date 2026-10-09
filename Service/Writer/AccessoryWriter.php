<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service\Writer;

use JsonLinesImporter\Dto\AccessoryLine;
use JsonLinesImporter\Dto\ProductLine;
use JsonLinesImporter\Service\LineReport;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\Accessory;
use Thelia\Model\AccessoryQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductQuery;

/**
 * Accessories, written in a second pass once every line is imported: an accessory may be a product
 * created by the same file.
 */
final readonly class AccessoryWriter
{
    public function write(Product $product, ProductLine $line, LineReport $report, ConnectionInterface $con): void
    {
        if (!$line->accessories->present) {
            return;
        }

        $targets = [];

        /** @var AccessoryLine $accessory */
        foreach ($line->accessories->value ?? [] as $index => $accessory) {
            if ($accessory->ref === $product->getRef()) {
                $report->warn(\sprintf('Accessory %s ignored: a product cannot be its own accessory', $accessory->ref));

                continue;
            }

            $accessoryId = ProductQuery::create()->filterByRef($accessory->ref)->select(['Id'])->findOne($con);

            if (null === $accessoryId) {
                $report->warn(\sprintf('Accessory %s ignored: unknown product', $accessory->ref));

                continue;
            }

            $targets[(int) $accessoryId] = $accessory->position ?? $index + 1;
        }

        foreach (AccessoryQuery::create()->filterByProductId($product->getId())->find($con) as $link) {
            if (!isset($targets[(int) $link->getAccessory()])) {
                $link->delete($con);

                continue;
            }

            $link->setPosition($targets[(int) $link->getAccessory()])->save($con);
            unset($targets[(int) $link->getAccessory()]);
        }

        foreach ($targets as $accessoryId => $position) {
            $link = (new Accessory())
                ->setProductId((int) $product->getId())
                ->setAccessory($accessoryId);
            $link->save($con);

            // Thelia overwrites the position on insert: it is applied once the row exists.
            $link->setPosition($position)->save($con);
        }
    }
}
