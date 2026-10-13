<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

use JsonLinesImporter\Service\MediaProvenance;
use Thelia\Domain\Media\Video\VideoProvider;

/**
 * The source of a video once classified: a video of a platform (provider and identifier), or a file to
 * fetch (no provider).
 */
final readonly class VideoSource
{
    public function __construct(
        public string $source,
        public ?VideoProvider $provider = null,
        public ?string $externalId = null,
    ) {
    }

    public function isFile(): bool
    {
        return null === $this->provider;
    }

    /**
     * What the import remembers of the video: the platform and the identifier, so that two addresses of
     * the same video (youtu.be/x, watch?v=x) are one video; the source itself for a file.
     */
    public function identity(): string
    {
        return null === $this->provider ? MediaProvenance::normalize($this->source) : $this->provider->value.':'.$this->externalId;
    }

    public function label(): string
    {
        return null === $this->provider ? 'file' : $this->provider->label();
    }
}
