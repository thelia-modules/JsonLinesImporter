<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Integration;

use JsonLinesImporter\Service\ReservedPathGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Thelia\Model\ConfigQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * Against the routes of the test shop and its active front theme.
 */
final class ReservedPathGuardTest extends IntegrationTestCase
{
    public static function reservedPaths(): iterable
    {
        yield 'back-office page' => ['admin/login'];
        yield 'back-office root' => ['admin'];
        yield 'api' => ['api/front/login'];
        yield 'route of the theme' => ['login'];
    }

    public static function freePaths(): iterable
    {
        yield 'old page of a former site' => ['old-page.html'];
        yield 'several segments' => ['products/old/lamp'];
        yield 'administration in a word' => ['administration-guide'];
    }

    #[Test]
    #[DataProvider('reservedPaths')]
    public function anAddressOfTheShopIsReserved(string $path): void
    {
        self::assertTrue(static::getContainer()->get(ReservedPathGuard::class)->isReserved($path));
    }

    #[Test]
    #[DataProvider('freePaths')]
    public function anAddressNoPageAnswersIsFree(string $path): void
    {
        self::assertFalse(static::getContainer()->get(ReservedPathGuard::class)->isReserved($path));
    }

    #[Test]
    public function aPageAnsweringGetOnlyIsReservedFromThePostOfABatch(): void
    {
        $router = static::getContainer()->get('router');

        // A route in GET only at one segment, not served by a template of the theme: the one of SEOne.
        if (null === $router->getRouteCollection()->get('robot_txt')) {
            self::markTestSkipped('No route in GET only to check against (module SEOne).');
        }

        $context = $router->getContext();
        $router->setContext((clone $context)->setMethod('POST'));

        try {
            self::assertTrue(static::getContainer()->get(ReservedPathGuard::class)->isReserved('robots.txt'));
            self::assertSame('POST', $router->getContext()->getMethod(), 'the context of the request is given back');
        } finally {
            $router->setContext($context);
        }
    }

    #[Test]
    public function aTemplateOfTheActiveFrontThemeIsReserved(): void
    {
        $theme = ConfigQuery::read('active-front-template', 'default');
        $view = 'jli-reserved-'.bin2hex(random_bytes(4));
        $file = THELIA_TEMPLATE_DIR.'frontOffice'.DS.$theme.DS.$view.'.html.twig';

        $guard = static::getContainer()->get(ReservedPathGuard::class);
        self::assertFalse($guard->isReserved($view), 'no template, no page');

        file_put_contents($file, '');

        try {
            self::assertTrue($guard->isReserved($view));
        } finally {
            unlink($file);
        }
    }
}
