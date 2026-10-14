<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Thelia\Core\Template\TemplateHelperInterface;

/**
 * Tells whether an address belongs to a page of the shop, which a redirection must never take over: the
 * rewriting router runs before the others, so a redirection stored on `login` would answer instead of
 * the login page.
 *
 * Reserved: the back office and the API (`admin`, `api`), an address a route of the shop answers, and
 * an address the catch-all of the front theme (`/{_view}`) answers with a template the theme has.
 */
final readonly class ReservedPathGuard
{
    private const VIEW_ROUTE_PATH = '/{_view}';

    public function __construct(
        private RouterInterface $router,
        private TemplateHelperInterface $templateHelper,
    ) {
    }

    /** @param string $path decoded, without the leading slash */
    public function isReserved(string $path): bool
    {
        if (1 === preg_match('~^(admin|api)(/|$)~', $path)) {
            return true;
        }

        // A redirection answers a visitor's GET, whatever the method of the request writing it: matched
        // in a POST, a page served in GET only would fall to the catch-all of the theme and look free.
        $context = $this->router->getContext();
        $get = clone $context;
        $this->router->setContext($get->setMethod('GET'));

        try {
            $match = $this->router->match('/'.$path);
        } catch (ResourceNotFoundException) {
            return false;
        } catch (MethodNotAllowedException) {
            // Served for another method only: the rewriting router would still answer it first.
            return true;
        } finally {
            $this->router->setContext($context);
        }

        $route = $this->router->getRouteCollection()->get((string) ($match['_route'] ?? ''));

        if (null === $route || self::VIEW_ROUTE_PATH !== $route->getPath()) {
            return true;
        }

        $themeDirectory = rtrim($this->templateHelper->getActiveFrontTemplate()->getAbsolutePath(), \DIRECTORY_SEPARATOR);

        return is_file($themeDirectory.\DIRECTORY_SEPARATOR.$match['_view'].'.html.twig');
    }
}
