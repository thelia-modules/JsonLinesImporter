<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

/**
 * Turns the old address of a product page into the paths the rewriting router compares with:
 * no scheme, host, query or fragment, percent-decoded (UTF-8), without the leading slash.
 *
 * The router compares the decoded path with the stored URL by strict equality and does not
 * normalize the trailing slash: a path ending with one is stored twice, with and without it.
 */
final readonly class UrlPathNormalizer
{
    public const MAX_BYTES = 255;

    /** @return list<string> empty when the address cannot become a redirection */
    public function paths(string $address): array
    {
        $address = trim($address);

        if (1 === preg_match('~^[a-z][a-z0-9+.-]*://~i', $address) || str_starts_with($address, '//')) {
            $path = (string) parse_url(str_starts_with($address, '//') ? 'http:'.$address : $address, \PHP_URL_PATH);
        } else {
            $path = (string) preg_replace('~[?#].*$~s', '', $address);
        }

        $path = ltrim(rawurldecode($path), '/');

        if ('' === $path || str_contains($path, '+') || \strlen($path) > self::MAX_BYTES || !mb_check_encoding($path, 'UTF-8')) {
            return [];
        }

        $paths = [$path];

        if (str_ends_with($path, '/') && '' !== rtrim($path, '/')) {
            $paths[] = rtrim($path, '/');
        }

        return $paths;
    }
}
