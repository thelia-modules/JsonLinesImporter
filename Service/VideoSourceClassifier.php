<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Dto\VideoSource;
use JsonLinesImporter\Exception\ImportException;
use Thelia\Domain\Media\Video\UnsupportedVideoUrlException;
use Thelia\Domain\Media\Video\VideoProviderResolver;

/**
 * Tells a video of a platform from a video file.
 *
 * An address is first read by the resolver of the core, which knows the platforms the shop enables. An
 * address it refuses is a file only when its path ends with a video extension: any other page would be
 * downloaded for nothing and refused on its content type. A path on the server is always a file.
 */
final readonly class VideoSourceClassifier
{
    private const FILE_EXTENSIONS = ['mp4', 'm4v', 'webm', 'ogv', 'ogg'];

    public function __construct(
        private VideoProviderResolver $resolver = new VideoProviderResolver(),
    ) {
    }

    /**
     * @throws ImportException with a message for the line report
     */
    public function classify(string $source): VideoSource
    {
        $source = trim($source);

        if (!MediaFetcher::isRemote($source)) {
            return new VideoSource($source);
        }

        try {
            $resolved = $this->resolver->resolve($source);
        } catch (UnsupportedVideoUrlException $exception) {
            $extension = strtolower(pathinfo((string) parse_url($source, \PHP_URL_PATH), \PATHINFO_EXTENSION));

            if (\in_array($extension, self::FILE_EXTENSIONS, true)) {
                return new VideoSource($source);
            }

            throw new ImportException(\sprintf('%s: %s', $source, $exception->getMessage()));
        }

        return new VideoSource($source, $resolved->provider, $resolved->externalId);
    }
}
