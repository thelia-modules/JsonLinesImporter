<?php

declare(strict_types=1);

namespace JsonLinesImporter;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Finder\Finder;
use Thelia\Core\Install\Database;
use Thelia\Module\BaseModule;

class JsonLinesImporter extends BaseModule
{
    /** @var string */
    public const DOMAIN_NAME = 'jsonlinesimporter';

    public const BO_DOMAIN_NAME = 'jsonlinesimporter.bo.default-twig';

    public const SCHEMA_ID = 'urn:thelia:json-lines-importer:product-import:1.0';

    public function postActivation(?ConnectionInterface $con = null): void
    {
        if (!self::getConfigValue('is_initialized', false)) {
            $database = new Database($con);

            $database->insertSql(null, [__DIR__.'/Config/TheliaMain.sql']);

            self::setConfigValue('is_initialized', true);
        }
    }

    public function destroy(?ConnectionInterface $con = null, $deleteModuleData = false): void
    {
        if (!$deleteModuleData) {
            return;
        }

        $con ??= Propel::getConnection();

        // Children first: the foreign keys would refuse the parent while they are there.
        $con->exec('DROP TABLE IF EXISTS `jsonl_import_line`');
        $con->exec('DROP TABLE IF EXISTS `jsonl_import`');
        $con->exec('DROP TABLE IF EXISTS `jsonl_import_mapping`');
        $con->exec('DROP TABLE IF EXISTS `jsonl_import_media`');

        self::setConfigValue('is_initialized', false);
    }

    public function update($currentVersion, $newVersion, ?ConnectionInterface $con = null): void
    {
        $updateDir = __DIR__.DS.'Config'.DS.'update';

        if (!is_dir($updateDir)) {
            return;
        }

        $finder = Finder::create()
            ->name('*.sql')
            ->depth(0)
            ->sortByName()
            ->in($updateDir);

        $database = new Database($con);

        /** @var \SplFileInfo $file */
        foreach ($finder as $file) {
            if (version_compare($currentVersion, $file->getBasename('.sql'), '<')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/I18n/*',
                __DIR__.'/Config/**/*',
                // Propel models and queries: instantiated by the ORM, never services.
                __DIR__.'/Model/*',
                // Value objects built per line, never services.
                __DIR__.'/Dto/*',
                __DIR__.'/Enum/*',
                __DIR__.'/Exception/*',
                __DIR__.'/Validation/ValidationResult.php',
                __DIR__.'/templates/**/*',
                __DIR__.'/Resources/**/*',
                __DIR__.'/tests/*',
                // Dev dependencies of a checkout installed at the module root: not module classes.
                __DIR__.'/vendor/',
                __DIR__.'/JsonLinesImporter.php',
            ])
            ->autowire(true)
            ->autoconfigure(true);
    }

    /**
     * Where uploaded files are kept: under the shop local directory, out of the web root.
     */
    public static function getStorageDirectory(): string
    {
        return THELIA_LOCAL_DIR.'jsonl-imports'.DS;
    }
}
