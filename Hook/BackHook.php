<?php

declare(strict_types=1);

namespace JsonLinesImporter\Hook;

use JsonLinesImporter\Controller\Back\ImportController;
use JsonLinesImporter\JsonLinesImporter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Tools\URL;

/**
 * Back-office integration: the entry of the Catalog menu, and the assets of the wizard on the module
 * pages only.
 */
class BackHook extends BaseHook
{
    //Constructor injection: a #[Required] property would stay null on a module hook
    public function __construct(
        private readonly SecurityContext $securityContext,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'main.top-menu-catalog' => [
                ['type' => 'back', 'method' => 'onMainTopMenuCatalog'],
            ],
            'main.head-css' => [
                ['type' => 'back', 'method' => 'onMainHeadCss'],
            ],
            'main.footer-js' => [
                ['type' => 'back', 'method' => 'onMainFooterJs'],
            ],
        ];
    }

    public function onMainTopMenuCatalog(HookRenderBlockEvent $event): void
    {
        // The right checked is the one granted on the module itself, not the admin.module resource,
        // which guards the module management screens and is not held by a profile limited to the import.
        if (!$this->securityContext->isGranted(['ADMIN'], [], [JsonLinesImporter::getModuleCode()], [AccessManager::VIEW])) {
            return;
        }

        $event->add([
            'id' => 'catalog_menu_json_lines_importer',
            'class' => '',
            'url' => URL::getInstance()->absoluteUrl(ImportController::LIST_PATH),
            'title' => $this->trans('JSONL import', [], JsonLinesImporter::DOMAIN_NAME),
        ]);
    }

    public function onMainHeadCss(HookRenderEvent $event): void
    {
        if ($this->isModulePage()) {
            $event->add($this->addCSS('assets/dist/json-lines-importer-admin.css'));
        }
    }

    public function onMainFooterJs(HookRenderEvent $event): void
    {
        if ($this->isModulePage()) {
            $event->add($this->addJS('assets/dist/json-lines-importer-admin.js', ['defer' => 'defer']));
        }
    }

    private function isModulePage(): bool
    {
        return str_starts_with($this->getRequest()?->getPathInfo() ?? '', ImportController::LIST_PATH);
    }
}
