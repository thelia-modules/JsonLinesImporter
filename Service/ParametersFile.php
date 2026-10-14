<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Enum\ReferentialType;
use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\Model\JsonlImport;
use JsonLinesImporter\Model\JsonlImportMappingQuery;

/**
 * The choices of an import (selected data, options, excluded lines, mappings), as the JSON file the
 * back-office exports and the console command replays.
 */
final readonly class ParametersFile
{
    public const FORMAT = 'json-lines-importer-parameters/1';

    public function __construct(
        private ReferentialResolver $resolver,
    ) {
    }

    public function export(JsonlImport $import): array
    {
        $parameters = ImportParameters::fromArray($import->getParametersArray())->toArray();
        unset($parameters['step']);

        $mappings = [];

        foreach (JsonlImportMappingQuery::create()->orderByType()->orderByParentId()->orderBySourceTitle()->find() as $mapping) {
            $mappings[] = [
                'type' => $mapping->getType(),
                'parent_id' => (int) $mapping->getParentId(),
                'source_title' => $mapping->getSourceTitle(),
                'target_id' => null === $mapping->getTargetId() ? null : (int) $mapping->getTargetId(),
                'ignored' => (bool) $mapping->getIgnored(),
            ];
        }

        return [
            'format' => self::FORMAT,
            'parameters' => $parameters,
            'mappings' => $mappings,
        ];
    }

    /**
     * Memorizes the mappings of the file, then returns its parameters.
     *
     * @throws ImportException when the content is not a parameters file
     */
    public function load(string $content): ImportParameters
    {
        $data = json_decode($content, true);

        if (!\is_array($data) || self::FORMAT !== ($data['format'] ?? null) || !\is_array($data['parameters'] ?? null)) {
            throw new ImportException('Not a parameters file of the JSON Lines importer.');
        }

        foreach ((array) ($data['mappings'] ?? []) as $mapping) {
            $type = ReferentialType::tryFrom((string) ($mapping['type'] ?? ''));
            $title = trim((string) ($mapping['source_title'] ?? ''));

            if (null === $type || '' === $title) {
                continue;
            }

            $this->resolver->memorize(
                $type,
                (int) ($mapping['parent_id'] ?? 0),
                $title,
                isset($mapping['target_id']) ? (int) $mapping['target_id'] : null,
                (bool) ($mapping['ignored'] ?? false),
            );
        }

        return ImportParameters::fromArray($data['parameters']);
    }
}
