<?php

declare(strict_types=1);

namespace JsonLinesImporter\Model;

use JsonLinesImporter\Enum\ImportStatus;
use JsonLinesImporter\Enum\RunPhase;
use JsonLinesImporter\JsonLinesImporter;
use JsonLinesImporter\Model\Base\JsonlImport as BaseJsonlImport;

class JsonlImport extends BaseJsonlImport
{
    use JsonColumnTrait;

    public function getStatusEnum(): ImportStatus
    {
        return ImportStatus::tryFrom((string) $this->getStatus()) ?? ImportStatus::ANALYSING;
    }

    public function getPhaseEnum(): ?RunPhase
    {
        return RunPhase::tryFrom((string) $this->getPhase());
    }

    public function getParametersArray(): array
    {
        $parameters = $this->decodeJsonColumn($this->getParameters(), \sprintf('jsonl_import#%d.parameters', (int) $this->getId()));

        return \is_array($parameters) ? $parameters : [];
    }

    public function setParametersArray(array $parameters): static
    {
        return $this->setParameters(json_encode($parameters, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    public function getStoredPath(): string
    {
        return JsonLinesImporter::getStorageDirectory().$this->getStoredName();
    }
}
