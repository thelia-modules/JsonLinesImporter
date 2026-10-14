<?php

declare(strict_types=1);

namespace JsonLinesImporter\Command;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Enum\LineResult;
use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\Model\JsonlImport;
use JsonLinesImporter\Model\JsonlImportLineQuery;
use JsonLinesImporter\Service\AnalysisService;
use JsonLinesImporter\Service\ImportManager;
use JsonLinesImporter\Service\ImportRunner;
use JsonLinesImporter\Service\ParametersFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thelia\Command\ContainerAwareCommand;

/**
 * Replays an import: the same services as the back-office wizard, with the parameters it exported.
 */
#[AsCommand(
    name: 'jsonlinesimporter:import',
    description: 'Import products from a JSON Lines file, with the parameters exported by the back-office wizard.',
)]
final class ImportCommand extends ContainerAwareCommand
{
    public function __construct(
        private readonly ImportManager $importManager,
        private readonly AnalysisService $analysisService,
        private readonly ImportRunner $importRunner,
        private readonly ParametersFile $parametersFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'The .jsonl file to import')
            ->addOption('params', 'p', InputOption::VALUE_REQUIRED, 'Parameters file exported from step 3 of the wizard')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analyse and preview the file without writing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // The URL singleton is only set by the HTTP kernel: without it, creating a product or a
        // translation fails while generating its rewritten URL.
        $this->initRequest();

        $file = (string) $input->getArgument('file');

        try {
            $parameters = $this->parameters($input->getOption('params'));
            $import = $this->importManager->create($file, basename($file), 'console', $parameters);
        } catch (ImportException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->section(\sprintf('Analysis of %s (import #%d)', basename($file), $import->getId()));

        while (!$this->analysisService->analyseBatch($import, ImportRunner::batchDeadline())) {
            $io->write('.');
        }

        $io->writeln(\sprintf('%d line(s), %d valid, %d invalid', $import->getLineCount(), $import->getValidCount(), $import->getInvalidCount()));
        $this->printInvalidLines($io, $import);

        if ($input->getOption('dry-run')) {
            $this->printPlan($io, $import, $parameters);
            $this->importManager->delete($import);
            $io->note('Dry run: nothing was written.');

            return 0 === $import->getInvalidCount() ? Command::SUCCESS : Command::FAILURE;
        }

        if (0 === $import->getValidCount()) {
            $io->error('No valid line to import.');

            return Command::FAILURE;
        }

        try {
            $this->importRunner->start($import);
        } catch (ImportException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->section('Import');
        $progress = $io->createProgressBar((int) $import->getValidCount());

        while (!$this->importRunner->runBatch($import, ImportRunner::batchDeadline())) {
            $progress->setProgress($this->processedCount($import));
        }

        $progress->finish();
        $io->newLine(2);

        return $this->printReport($io, $import);
    }

    private function parameters(mixed $path): ImportParameters
    {
        if (null === $path || '' === $path) {
            return new ImportParameters();
        }

        $content = @file_get_contents((string) $path);

        if (false === $content) {
            throw new ImportException(\sprintf('The parameters file %s cannot be read.', $path));
        }

        return $this->parametersFile->load($content);
    }

    private function printInvalidLines(SymfonyStyle $io, JsonlImport $import): void
    {
        $lines = JsonlImportLineQuery::create()->filterByImportId($import->getId())->filterByValid(0)->orderByLineNumber()->find();

        foreach ($lines as $line) {
            $io->writeln(\sprintf('<error>line %d</error> %s: %s', $line->getLineNumber(), $line->getRef() ?? '?', implode(' | ', $line->getErrorList())));
        }
    }

    private function printPlan(SymfonyStyle $io, JsonlImport $import, ImportParameters $parameters): void
    {
        $rows = [];

        foreach (JsonlImportLineQuery::create()->filterByImportId($import->getId())->filterByValid(1)->orderByLineNumber()->find() as $line) {
            $rows[] = [
                $line->getLineNumber(),
                $line->getRef(),
                $parameters->isLineExcluded((int) $line->getLineNumber()) ? 'excluded' : $line->getAction(),
                implode(' | ', $line->getWarningList()),
            ];
        }

        $io->table(['Line', 'Ref', 'Action', 'Warnings'], $rows);
    }

    private function processedCount(JsonlImport $import): int
    {
        return (int) $import->getCreatedCount() + (int) $import->getUpdatedCount() + (int) $import->getErrorCount();
    }

    private function printReport(SymfonyStyle $io, JsonlImport $import): int
    {
        $import->reload();

        $io->definitionList(
            ['Created' => (string) $import->getCreatedCount()],
            ['Updated' => (string) $import->getUpdatedCount()],
            ['Excluded' => (string) $import->getExcludedCount()],
            ['Errors' => (string) $import->getErrorCount()],
        );

        $lines = JsonlImportLineQuery::create()->filterByImportId($import->getId())->filterByValid(1)->orderByLineNumber()->find();

        foreach ($lines as $line) {
            $result = $line->getResultEnum();

            if (LineResult::ERROR === $result) {
                $io->writeln(\sprintf('<error>line %d</error> %s: %s', $line->getLineNumber(), $line->getRef(), implode(' | ', $line->getErrorList())));
            }

            foreach ($line->getWarningList() as $warning) {
                $io->writeln(\sprintf('<comment>line %d</comment> %s: %s', $line->getLineNumber(), $line->getRef(), $warning));
            }
        }

        return 0 === (int) $import->getErrorCount() ? Command::SUCCESS : Command::FAILURE;
    }
}
