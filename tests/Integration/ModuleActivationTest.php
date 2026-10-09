<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Integration;

use JsonLinesImporter\JsonLinesImporter;
use PHPUnit\Framework\Attributes\Test;
use Propel\Runtime\Propel;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;
use Thelia\Test\IntegrationTestCase;

/**
 * The module activates and deactivates without error, and a second activation leaves its tables alone.
 *
 * Prerequisites: a shop test database where the module is registered (module:refresh).
 */
final class ModuleActivationTest extends IntegrationTestCase
{
    private const TABLES = ['jsonl_import', 'jsonl_import_line', 'jsonl_import_mapping', 'jsonl_import_media'];

    // The activation runs DDL, which commits on its own: no surrounding transaction.
    protected bool $useTransaction = false;

    #[Test]
    public function theModuleActivatesAndDeactivates(): void
    {
        $module = ModuleQuery::create()->findOneByCode(JsonLinesImporter::getModuleCode());

        if (null === $module) {
            self::markTestSkipped('The JsonLinesImporter module is not registered in the test database.');
        }

        $instance = $module->createInstance();
        $instance->setContainer(static::getContainer());

        if (BaseModule::IS_ACTIVATED === (int) $module->getActivate()) {
            $instance->deActivate($module);
        }

        $instance->activate($this->reloadModule());
        self::assertSame(BaseModule::IS_ACTIVATED, (int) $this->reloadModule()->getActivate());
        $this->assertTablesExist();

        $instance->deActivate($this->reloadModule());
        self::assertSame(BaseModule::IS_NOT_ACTIVATED, (int) $this->reloadModule()->getActivate());
        $this->assertTablesExist();

        $instance->activate($this->reloadModule());
        self::assertSame(BaseModule::IS_ACTIVATED, (int) $this->reloadModule()->getActivate());
    }

    private function reloadModule(): \Thelia\Model\Module
    {
        return ModuleQuery::create()->findOneByCode(JsonLinesImporter::getModuleCode());
    }

    private function assertTablesExist(): void
    {
        $connection = Propel::getConnection();

        foreach (self::TABLES as $table) {
            $statement = $connection->prepare('SHOW TABLES LIKE ?');
            $statement->execute([$table]);
            self::assertSame(1, $statement->rowCount(), $table.' exists');
        }
    }
}
