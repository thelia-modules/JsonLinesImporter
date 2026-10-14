<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Integration;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Enum\ImportStatus;
use JsonLinesImporter\Enum\LineResult;
use JsonLinesImporter\Model\JsonlImport;
use JsonLinesImporter\Model\JsonlImportLineQuery;
use JsonLinesImporter\Model\JsonlImportQuery;
use JsonLinesImporter\Service\AnalysisService;
use JsonLinesImporter\Service\ImportManager;
use JsonLinesImporter\Service\ImportRunner;
use PHPUnit\Framework\Attributes\Test;
use Thelia\Model\ModuleQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * A line that is not UTF-8 (a Windows-1252 export) is reported as invalid; its bytes never reach a text
 * column, where a strict MySQL would refuse them and stop the analysis on that line at every batch.
 */
final class AnalysisEncodingTest extends IntegrationTestCase
{
    private const NAME = 'encoding.jsonl';

    // The analysis commits each line in its own transaction.
    protected bool $useTransaction = false;

    private ?string $path = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (null === ModuleQuery::create()->filterByCode('JsonLinesImporter')->filterByActivate(1)->findOne()) {
            self::markTestSkipped('The JsonLinesImporter module is not active in the test database.');
        }

        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        if (null !== $this->path) {
            @unlink($this->path);
        }

        parent::tearDown();
    }

    #[Test]
    public function aLineThatIsNotUtf8IsInvalidAndTheAnalysisGoesOn(): void
    {
        $import = $this->analyse([
            '{"ref":"JLI-ENC-1","title":"Lamp"}',
            "{\"ref\":\"JLI-ENC-2\",\"title\":\"Lampe \xe9clair\"}",
            '{"ref":"JLI-ENC-3","title":"Desk"}',
        ]);

        self::assertSame(ImportStatus::ANALYSED, $import->getStatusEnum());
        self::assertSame(2, (int) $import->getValidCount());
        self::assertSame(1, (int) $import->getInvalidCount());

        $line = JsonlImportLineQuery::create()->filterByImportId($import->getId())->filterByLineNumber(2)->findOne();
        self::assertNotNull($line);
        self::assertSame(LineResult::INVALID->value, $line->getResult());
        self::assertSame(['(root): the line is not valid UTF-8'], $line->getErrorList());
    }

    /** @param list<string> $lines */
    private function analyse(array $lines): JsonlImport
    {
        $container = static::getContainer();
        $this->path = (string) tempnam(sys_get_temp_dir(), 'jli-test-');
        file_put_contents($this->path, implode("\n", $lines)."\n");

        $import = $container->get(ImportManager::class)->create($this->path, self::NAME, 'test', new ImportParameters());

        // Bounded: a line the analysis cannot store would be read again at every batch.
        for ($batch = 0; $batch < 5; ++$batch) {
            if ($container->get(AnalysisService::class)->analyseBatch($import, ImportRunner::batchDeadline())) {
                break;
            }
        }

        $import->reload();

        return $import;
    }

    private function cleanUp(): void
    {
        foreach (JsonlImportQuery::create()->filterByOriginalName(self::NAME)->find() as $import) {
            $import->delete();
        }
    }
}
