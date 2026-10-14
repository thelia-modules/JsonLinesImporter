<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Service\UrlPathNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UrlPathNormalizerTest extends TestCase
{
    #[Test]
    public function anAbsoluteAddressBecomesItsDecodedPathWithAndWithoutTrailingSlash(): void
    {
        self::assertSame(
            ['lighting/desk-lamp-150-m²/', 'lighting/desk-lamp-150-m²'],
            (new UrlPathNormalizer())->paths('https://www.example.com/lighting/desk-lamp-150-m%c2%b2/?utm=1#top'),
        );
    }

    #[Test]
    public function aRelativeSlugIsKeptAsIs(): void
    {
        self::assertSame(['lampe-orion'], (new UrlPathNormalizer())->paths('lampe-orion'));
    }

    #[Test]
    public function anAddressTheRouterCannotMatchIsRefused(): void
    {
        $normalizer = new UrlPathNormalizer();

        self::assertSame([], $normalizer->paths('https://example.com/a+b/'), 'urldecode turns + into a space');
        self::assertSame([], $normalizer->paths('https://example.com/'));
        self::assertSame([], $normalizer->paths('/'.str_repeat('a', 256)));
    }
}
