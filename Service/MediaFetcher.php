<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Dto\FetchedFile;
use JsonLinesImporter\Enum\MediaKind;
use JsonLinesImporter\Exception\ImportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Brings a media file of a line to a temporary path: downloaded when the source is an http(s) URL,
 * copied when it is a path on the server. The source file is never moved.
 *
 * The file comes from a file the import trusts no more than its author: downloads never reach a private,
 * loopback or link-local address (redirections included), a path is only read inside the directory
 * declared at step 3, and the stored extension comes from the detected type, never from the source name.
 */
final class MediaFetcher
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    private const IDLE_TIMEOUT_SECONDS = 20;

    private const MAX_DURATION_SECONDS = 60;

    private ?HttpClientInterface $httpClient;

    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = null === $httpClient ? null : new NoPrivateNetworkHttpClient($httpClient);
    }

    public static function isRemote(string $source): bool
    {
        return 1 === preg_match('~^https?://~i', trim($source));
    }

    /**
     * @param string|null $directory the only directory a path on the server may be read from
     *
     * @throws ImportException with a message for the line report
     */
    public function fetch(string $source, MediaKind $kind, ?string $directory = null): FetchedFile
    {
        $source = trim($source);

        $file = self::isRemote($source) ? $this->download($source) : $this->copy($source, $directory);

        $mimeType = (string) (new \finfo(\FILEINFO_MIME_TYPE))->file($file->temporaryPath);
        $extension = $kind->extensions()[$mimeType] ?? null;

        if (null === $extension) {
            $file->discard();

            throw new ImportException(\sprintf('%s: unexpected content type %s', $source, $mimeType));
        }

        return new FetchedFile($file->temporaryPath, $file->originalName, $mimeType, $extension);
    }

    private function download(string $url): FetchedFile
    {
        $temporaryPath = $this->temporaryPath();
        $handle = fopen($temporaryPath, 'w');

        try {
            $response = $this->httpClient()->request('GET', $url, [
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
                'max_redirects' => 5,
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new ImportException(\sprintf('%s: HTTP %d', $url, $response->getStatusCode()));
            }

            $size = 0;

            foreach ($this->httpClient()->stream($response) as $chunk) {
                $size += \strlen($chunk->getContent());

                if ($size > self::MAX_BYTES) {
                    $response->cancel();

                    throw new ImportException(\sprintf('%s: larger than %d MB', $url, self::MAX_BYTES / 1024 / 1024));
                }

                fwrite($handle, $chunk->getContent());
            }
        } catch (ExceptionInterface $exception) {
            fclose($handle);
            @unlink($temporaryPath);

            throw new ImportException(\sprintf('%s: download failed (%s)', $url, $exception->getMessage()));
        } catch (ImportException $exception) {
            fclose($handle);
            @unlink($temporaryPath);

            throw $exception;
        }

        fclose($handle);

        $name = rawurldecode(basename((string) parse_url($url, \PHP_URL_PATH)));

        return new FetchedFile($temporaryPath, '' === $name ? 'file' : $name, '');
    }

    private function copy(string $path, ?string $directory): FetchedFile
    {
        $root = null === $directory ? false : realpath($directory);

        if (false === $root || !is_dir($root)) {
            throw new ImportException(\sprintf('%s: a path on the server needs the directory of the media to be set at step 3', $path));
        }

        // The path as given when it lies inside the directory, else its file name in the directory.
        $candidate = realpath($path);

        if (false === $candidate || !str_starts_with($candidate, $root.\DIRECTORY_SEPARATOR)) {
            $candidate = realpath($root.\DIRECTORY_SEPARATOR.basename($path));
        }

        if (false === $candidate || !str_starts_with($candidate, $root.\DIRECTORY_SEPARATOR) || !is_file($candidate) || !is_readable($candidate)) {
            throw new ImportException(\sprintf('%s: file not found in the directory of the media', $path));
        }

        if (filesize($candidate) > self::MAX_BYTES) {
            throw new ImportException(\sprintf('%s: larger than %d MB', $path, self::MAX_BYTES / 1024 / 1024));
        }

        $temporaryPath = $this->temporaryPath();

        if (!copy($candidate, $temporaryPath)) {
            throw new ImportException(\sprintf('%s: the file cannot be copied', $path));
        }

        return new FetchedFile($temporaryPath, basename($candidate), '');
    }

    private function temporaryPath(): string
    {
        return (string) tempnam(sys_get_temp_dir(), 'jsonl-media-');
    }

    private function httpClient(): HttpClientInterface
    {
        return $this->httpClient ??= new NoPrivateNetworkHttpClient(HttpClient::create(['headers' => ['User-Agent' => 'Thelia JsonLinesImporter']]));
    }
}
