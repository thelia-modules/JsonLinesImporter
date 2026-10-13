<?php

declare(strict_types=1);

namespace JsonLinesImporter\Controller\Back;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Enum\ImportStatus;
use JsonLinesImporter\Enum\LineAction;
use JsonLinesImporter\Enum\LineResult;
use JsonLinesImporter\Enum\ReferentialType;
use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\JsonLinesImporter;
use JsonLinesImporter\Model\JsonlImport;
use JsonLinesImporter\Model\JsonlImportLineQuery;
use JsonLinesImporter\Model\JsonlImportQuery;
use JsonLinesImporter\Service\AnalysisService;
use JsonLinesImporter\Service\DataCatalog;
use JsonLinesImporter\Service\DataInventory;
use JsonLinesImporter\Service\ImportManager;
use JsonLinesImporter\Service\ImportRunner;
use JsonLinesImporter\Service\MappingInventory;
use JsonLinesImporter\Service\ParametersFile;
use JsonLinesImporter\Service\PreviewBuilder;
use JsonLinesImporter\Service\ShopContext;
use JsonLinesImporter\Service\Writer\HtmlImageWriter;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Translation\Translator;
use Thelia\Model\CategoryQuery;
use Thelia\Model\TemplateQuery;
use Thelia\Tools\TokenProvider;
use Thelia\Tools\URL;

/**
 * The five-step wizard: upload, data selection, parameters (mappings and options), preview, execution.
 *
 * Access is granted on the module itself: VIEW for the screens, UPDATE for the actions. The resource
 * admin.module guards the module management screens and a profile limited to the import does not hold it.
 */
#[Route('/admin/json_lines_importer', name: 'admin_json_lines_importer_')]
class ImportController extends BaseAdminController
{
    public const LIST_PATH = '/admin/json_lines_importer';

    private const PREVIEW_PAGE_SIZE = 50;

    #[Route('', name: 'list', methods: 'GET')]
    public function listImports(): Response
    {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::VIEW)) {
            return $response;
        }

        return $this->render('import-list', [
            'imports' => JsonlImportQuery::create()->orderByCreatedAt(Criteria::DESC)->find(),
            'max_size' => ImportManager::humanSize(ImportManager::maxFileBytes()),
        ]);
    }

    #[Route('/upload', name: 'upload', methods: 'POST')]
    public function upload(Request $request, TokenProvider $tokenProvider, ImportManager $importManager): Response
    {
        if (null !== $response = $this->denyUnlessGrantedWithToken(AccessManager::UPDATE, $request, $tokenProvider)) {
            return $response;
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->backWithError(self::LIST_PATH, $this->trans('Choose a .jsonl file of %size% at most.', ['%size%' => ImportManager::humanSize(ImportManager::maxFileBytes())]));
        }

        try {
            $import = $importManager->create($file->getPathname(), $file->getClientOriginalName(), $this->authorName());
        } catch (ImportException $exception) {
            return $this->backWithError(self::LIST_PATH, $this->trans($exception->key(), $exception->parameters()));
        }

        return $this->redirectToImport($import, 'analysis');
    }

    #[Route('/{importId}', name: 'resume', requirements: ['importId' => '\d+'], methods: 'GET')]
    public function resume(int $importId): Response
    {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::VIEW)) {
            return $response;
        }

        $import = $this->findImport($importId);

        if (null === $import) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::LIST_PATH));
        }

        $step = ImportParameters::fromArray($import->getParametersArray())->step;

        return $this->redirectToImport($import, match (true) {
            ImportStatus::DONE === $import->getStatusEnum() => 'report',
            \in_array($import->getStatusEnum(), [ImportStatus::RUNNING, ImportStatus::PAUSED], true) => 'run',
            ImportStatus::ANALYSING === $import->getStatusEnum() || $step <= 1 => 'analysis',
            2 === $step => 'data',
            3 === $step => 'options',
            default => 'preview',
        });
    }

    #[Route('/{importId}/analysis', name: 'analysis', requirements: ['importId' => '\d+'], methods: 'GET')]
    public function analysis(int $importId): Response
    {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::VIEW)) {
            return $response;
        }

        if (null === $import = $this->findImport($importId)) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::LIST_PATH));
        }

        return $this->render('import-analysis', [
            'import' => $import,
            'step' => 1,
            'invalid_lines' => JsonlImportLineQuery::create()->filterByImportId($importId)->filterByValid(0)->orderByLineNumber()->find(),
        ]);
    }

    #[Route('/{importId}/analysis/batch', name: 'analysis_batch', requirements: ['importId' => '\d+'], methods: 'POST')]
    public function analysisBatch(int $importId, Request $request, TokenProvider $tokenProvider, AnalysisService $analysisService): Response
    {
        if (null !== $response = $this->denyUnlessGrantedWithToken(AccessManager::UPDATE, $request, $tokenProvider, true)) {
            return $response;
        }

        if (null === $import = $this->findImport($importId)) {
            return new JsonResponse(['error' => 'Unknown import'], 404);
        }

        $done = $analysisService->analyseBatch($import, ImportRunner::batchDeadline());
        $size = (int) @filesize($import->getStoredPath());

        return new JsonResponse([
            'done' => $done,
            'percent' => $done || 0 === $size ? 100 : (int) floor(100 * (int) $import->getAnalysisOffset() / $size),
            'lines' => (int) $import->getLineCount(),
            'valid' => (int) $import->getValidCount(),
            'invalid' => (int) $import->getInvalidCount(),
        ]);
    }

    #[Route('/{importId}/data', name: 'data', requirements: ['importId' => '\d+'], methods: 'GET')]
    public function data(int $importId, DataInventory $inventory, ShopContext $shopContext): Response
    {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::VIEW)) {
            return $response;
        }

        if (null === $import = $this->findAnalysedImport($importId)) {
            return $this->redirectToImport($this->findImport($importId), null);
        }

        $parameters = ImportParameters::fromArray($import->getParametersArray());
        $found = $inventory->build($import);
        $groups = [];

        foreach (DataCatalog::GROUPS as $group => $keys) {
            foreach ($keys as $key) {
                if (isset($found['keys'][$key])) {
                    $groups[$group][$key] = ['count' => $found['keys'][$key], 'checked' => $parameters->isKeySelected($key)];
                }
            }
        }

        $locales = [];

        foreach ($found['locales'] as $locale => $count) {
            $locales[$locale] = [
                'count' => $count,
                'known' => $shopContext->isKnownLocale($locale),
                'active' => $shopContext->isActiveLocale($locale),
                'checked' => $shopContext->isKnownLocale($locale) && $parameters->isLocaleSelected($locale),
            ];
        }

        return $this->render('import-data', [
            'import' => $import,
            'step' => 2,
            'groups' => $groups,
            'locales' => $locales,
            'parameters' => $parameters,
            'categories' => $this->categoryOptions($shopContext),
            'creates' => JsonlImportLineQuery::create()->filterByImportId($importId)->filterByValid(1)->filterByAction(LineAction::CREATE->value)->count(),
        ]);
    }

    #[Route('/{importId}/data', name: 'data_save', requirements: ['importId' => '\d+'], methods: 'POST')]
    public function saveData(int $importId, Request $request, TokenProvider $tokenProvider, DataInventory $inventory, ShopContext $shopContext, MappingInventory $mappingInventory): Response
    {
        if (null !== $response = $this->denyUnlessGrantedWithToken(AccessManager::UPDATE, $request, $tokenProvider)) {
            return $response;
        }

        if (null === $import = $this->findAnalysedImport($importId)) {
            return $this->redirectToImport($this->findImport($importId), null);
        }

        $found = $inventory->build($import);
        $selectedKeys = array_values(array_intersect(array_keys($found['keys']), (array) $request->request->all('keys')));
        // Only the locales of the i18n blocks have a box: a locale without box (the default one, which
        // the keys at the root target) stays selected.
        $unticked = array_diff(array_keys($found['locales']), (array) $request->request->all('locales'));
        $selectedLocales = array_values(array_diff($shopContext->knownLocales(), $unticked));
        $fallback = $request->request->get('fallback_category_id');

        $this->saveParameters($import, [
            'selected_keys' => $selectedKeys,
            'selected_locales' => $selectedLocales,
            'fallback_category_id' => '' === $fallback ? null : $fallback,
            'step' => 3,
        ]);

        $pages = $mappingInventory->pages($import);

        return [] === $pages
            ? $this->redirectToImport($import, 'options')
            : $this->redirectToImport($import, 'mapping/'.$pages[0]->value);
    }

    #[Route('/{importId}/mapping/{type}', name: 'mapping', requirements: ['importId' => '\d+'], methods: 'GET')]
    public function mapping(int $importId, string $type, MappingInventory $mappingInventory, ShopContext $shopContext): Response
    {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::VIEW)) {
            return $response;
        }

        $referential = ReferentialType::tryFrom($type);

        if (null === $referential || null === $import = $this->findAnalysedImport($importId)) {
            return $this->redirectToImport($this->findImport($importId), null);
        }

        $entries = $mappingInventory->entries($import, $referential);
        $options = [];

        foreach ($entries as $entry) {
            $options[$entry['parent_id']] ??= $mappingInventory->options($referential, $entry['parent_id']);
        }

        $pages = $mappingInventory->pages($import);

        return $this->render('import-mapping', [
            'import' => $import,
            'step' => 3,
            'referential' => $referential,
            'entries' => $entries,
            'options' => $options,
            'pages' => $pages,
            'categories' => ReferentialType::CATEGORY === $referential ? $this->categoryOptions($shopContext) : [],
        ]);
    }

    #[Route('/{importId}/mapping/{type}', name: 'mapping_save', requirements: ['importId' => '\d+'], methods: 'POST')]
    public function saveMapping(int $importId, string $type, Request $request, TokenProvider $tokenProvider, MappingInventory $mappingInventory): Response
    {
        if (null !== $response = $this->denyUnlessGrantedWithToken(AccessManager::UPDATE, $request, $tokenProvider)) {
            return $response;
        }

        $referential = ReferentialType::tryFrom($type);

        if (null === $referential || null === $import = $this->findAnalysedImport($importId)) {
            return $this->redirectToImport($this->findImport($importId), null);
        }

        $choices = (array) $request->request->all('choice');
        $categoryParentId = (int) $request->request->get('category_parent_id', 0);

        foreach ($mappingInventory->entries($import, $referential) as $entry) {
            $mappingInventory->apply($referential, $entry, (string) ($choices[$entry['key']] ?? MappingInventory::CHOICE_IGNORE), $categoryParentId);
        }

        // The values of a feature or an attribute depend on how their parent resolved: the list of
        // pages is computed again once this one is saved.
        $pages = $mappingInventory->pages($import);
        $position = array_search($referential, ReferentialType::pages(), true);

        foreach ($pages as $page) {
            if (array_search($page, ReferentialType::pages(), true) > $position) {
                return $this->redirectToImport($import, 'mapping/'.$page->value);
            }
        }

        return $this->redirectToImport($import, 'options');
    }

    #[Route('/{importId}/options', name: 'options', requirements: ['importId' => '\d+'], methods: 'GET')]
    public function options(int $importId, DataInventory $inventory, MappingInventory $mappingInventory): Response
    {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::VIEW)) {
            return $response;
        }

        if (null === $import = $this->findAnalysedImport($importId)) {
            return $this->redirectToImport($this->findImport($importId), null);
        }

        $parameters = ImportParameters::fromArray($import->getParametersArray());
        $found = $inventory->build($import);

        return $this->render('import-options', [
            'import' => $import,
            'step' => 3,
            'parameters' => $parameters,
            'pages' => $mappingInventory->pages($import),
            'show_images_directory' => $found['local_images'] && $parameters->isKeySelected('images'),
            'show_documents_directory' => $found['local_documents'] && $parameters->isKeySelected('documents'),
            'show_videos_directory' => $found['local_videos'] && $parameters->isKeySelected('videos'),
            'show_html_images' => $found['html_images'],
            'library_available' => HtmlImageWriter::isLibraryAvailable(),
            'templates' => $this->templateOptions(),
        ]);
    }

    #[Route('/{importId}/options', name: 'options_save', requirements: ['importId' => '\d+'], methods: 'POST')]
    public function saveOptions(int $importId, Request $request, TokenProvider $tokenProvider): Response
    {
        if (null !== $response = $this->denyUnlessGrantedWithToken(AccessManager::UPDATE, $request, $tokenProvider)) {
            return $response;
        }

        if (null === $import = $this->findAnalysedImport($importId)) {
            return $this->redirectToImport($this->findImport($importId), null);
        }

        $form = $request->request;
        $errors = [];

        foreach (['images_directory', 'documents_directory', 'videos_directory'] as $directoryKey) {
            $directory = trim((string) $form->get($directoryKey, ''));

            if ('' !== $directory && (!is_dir($directory) || !is_readable($directory))) {
                $errors[] = $this->trans('The directory %directory% does not exist or cannot be read.', ['%directory%' => $directory]);
            }
        }

        $this->saveParameters($import, [
            'create_products' => $form->getBoolean('create_products'),
            'update_products' => $form->getBoolean('update_products'),
            'default_visible' => $form->getBoolean('default_visible'),
            'template_id' => $form->get('template_id'),
            'images_directory' => $form->get('images_directory'),
            'documents_directory' => $form->get('documents_directory'),
            'videos_directory' => $form->get('videos_directory'),
            'copy_html_images' => $form->getBoolean('copy_html_images'),
            'step' => [] === $errors ? 4 : 3,
        ]);

        if ([] !== $errors) {
            return $this->backWithError(self::importPath($import, 'options'), implode(' ', $errors));
        }

        return $this->redirectToImport($import, 'preview');
    }

    #[Route('/{importId}/parameters.json', name: 'export_parameters', requirements: ['importId' => '\d+'], methods: 'GET')]
    public function exportParameters(int $importId, ParametersFile $parametersFile): Response
    {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::VIEW)) {
            return $response;
        }

        if (null === $import = $this->findImport($importId)) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::LIST_PATH));
        }

        $response = new JsonResponse($parametersFile->export($import));
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="json-lines-import-%d-parameters.json"', $importId));

        return $response;
    }

    #[Route('/{importId}/preview', name: 'preview', requirements: ['importId' => '\d+'], methods: 'GET')]
    public function preview(int $importId, Request $request, PreviewBuilder $previewBuilder): Response
    {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::VIEW)) {
            return $response;
        }

        if (null === $import = $this->findAnalysedImport($importId)) {
            return $this->redirectToImport($this->findImport($importId), null);
        }

        $parameters = ImportParameters::fromArray($import->getParametersArray());
        $page = max(1, $request->query->getInt('page', 1));
        $query = JsonlImportLineQuery::create()->filterByImportId($importId)->filterByValid(1)->orderByLineNumber();
        $total = (clone $query)->count();
        $lines = [];

        foreach ($query->offset(($page - 1) * self::PREVIEW_PAGE_SIZE)->limit(self::PREVIEW_PAGE_SIZE)->find() as $row) {
            $action = $row->getActionEnum();
            $preview = $previewBuilder->build($row, $parameters);
            $lines[] = [
                'row' => $row,
                'title' => $this->previewTitle($row->getPayloadArray() ?? []),
                'excluded' => $parameters->isLineExcluded((int) $row->getLineNumber()),
                'forced_exclusion' => (LineAction::CREATE === $action && !$parameters->createProducts) || (LineAction::UPDATE === $action && !$parameters->updateProducts),
                'groups' => $preview['groups'],
                'warnings' => $preview['warnings'],
            ];
        }

        return $this->render('import-preview', [
            'import' => $import,
            'step' => 4,
            'lines' => $lines,
            'page' => $page,
            'page_count' => max(1, (int) ceil($total / self::PREVIEW_PAGE_SIZE)),
            'total' => $total,
            'parameters' => $parameters,
        ]);
    }

    #[Route('/{importId}/preview', name: 'preview_save', requirements: ['importId' => '\d+'], methods: 'POST')]
    public function savePreview(int $importId, Request $request, TokenProvider $tokenProvider, ImportRunner $importRunner): Response
    {
        if (null !== $response = $this->denyUnlessGrantedWithToken(AccessManager::UPDATE, $request, $tokenProvider)) {
            return $response;
        }

        if (null === $import = $this->findAnalysedImport($importId)) {
            return $this->redirectToImport($this->findImport($importId), null);
        }

        // The page shows one slice of the lines: only the lines of that slice change.
        $parameters = ImportParameters::fromArray($import->getParametersArray());
        $shown = array_map('intval', (array) $request->request->all('shown'));
        $kept = array_map('intval', (array) $request->request->all('included'));
        $excluded = array_values(array_unique([
            ...array_diff($parameters->excludedLines, $shown),
            ...array_diff($shown, $kept),
        ]));

        $this->saveParameters($import, ['excluded_lines' => $excluded]);

        $target = (string) $request->request->get('target', 'run');

        if (str_starts_with($target, 'page:')) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::importPath($import, 'preview'), ['page' => (int) substr($target, 5)]));
        }

        try {
            $import->reload();
            $importRunner->start($import);
        } catch (ImportException $exception) {
            return $this->backWithError(self::importPath($import, 'preview'), $this->trans($exception->key(), $exception->parameters()));
        }

        $this->saveParameters($import, ['step' => 5]);

        return $this->redirectToImport($import, 'run');
    }

    #[Route('/{importId}/run', name: 'run', requirements: ['importId' => '\d+'], methods: 'GET')]
    public function run(int $importId): Response
    {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::VIEW)) {
            return $response;
        }

        if (null === $import = $this->findImport($importId)) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::LIST_PATH));
        }

        return $this->render('import-run', [
            'import' => $import,
            'step' => 5,
            'total' => $this->retainedCount($import),
        ]);
    }

    #[Route('/{importId}/run/start', name: 'run_start', requirements: ['importId' => '\d+'], methods: 'POST')]
    public function startRun(int $importId, Request $request, TokenProvider $tokenProvider, ImportRunner $importRunner): Response
    {
        if (null !== $response = $this->denyUnlessGrantedWithToken(AccessManager::UPDATE, $request, $tokenProvider)) {
            return $response;
        }

        if (null === $import = $this->findImport($importId)) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::LIST_PATH));
        }

        try {
            $importRunner->start($import);
        } catch (ImportException $exception) {
            return $this->backWithError(self::importPath($import, 'run'), $this->trans($exception->key(), $exception->parameters()));
        }

        return $this->redirectToImport($import, 'run');
    }

    #[Route('/{importId}/run/batch', name: 'run_batch', requirements: ['importId' => '\d+'], methods: 'POST')]
    public function runBatch(int $importId, Request $request, TokenProvider $tokenProvider, ImportRunner $importRunner): Response
    {
        if (null !== $response = $this->denyUnlessGrantedWithToken(AccessManager::UPDATE, $request, $tokenProvider, true)) {
            return $response;
        }

        if (null === $import = $this->findImport($importId)) {
            return new JsonResponse(['error' => 'Unknown import'], 404);
        }

        $done = $importRunner->runBatch($import, ImportRunner::batchDeadline());
        $import->reload();
        $total = $this->retainedCount($import);
        $processed = (int) $import->getCreatedCount() + (int) $import->getUpdatedCount() + (int) $import->getErrorCount();

        return new JsonResponse([
            'done' => $done,
            'status' => $import->getStatus(),
            'phase' => $import->getPhase(),
            'percent' => $done || 0 === $total ? 100 : min(99, (int) floor(100 * $processed / $total)),
            'processed' => $processed,
            'total' => $total,
            'current_ref' => $import->getCurrentRef(),
            'created' => (int) $import->getCreatedCount(),
            'updated' => (int) $import->getUpdatedCount(),
            'errors' => (int) $import->getErrorCount(),
        ]);
    }

    #[Route('/{importId}/run/pause', name: 'run_pause', requirements: ['importId' => '\d+'], methods: 'POST')]
    public function pauseRun(int $importId, Request $request, TokenProvider $tokenProvider, ImportRunner $importRunner): Response
    {
        if (null !== $response = $this->denyUnlessGrantedWithToken(AccessManager::UPDATE, $request, $tokenProvider)) {
            return $response;
        }

        if (null !== $import = $this->findImport($importId)) {
            $importRunner->pause($import);
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::LIST_PATH));
    }

    #[Route('/{importId}/report', name: 'report', requirements: ['importId' => '\d+'], methods: 'GET')]
    public function report(int $importId): Response
    {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::VIEW)) {
            return $response;
        }

        if (null === $import = $this->findImport($importId)) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::LIST_PATH));
        }

        return $this->render('import-report', [
            'import' => $import,
            'step' => 5,
            'lines' => JsonlImportLineQuery::create()
                ->filterByImportId($importId)
                ->filterByResult(LineResult::INVALID->value, Criteria::NOT_EQUAL)
                ->orderByLineNumber()
                ->find(),
        ]);
    }

    #[Route('/{importId}/delete', name: 'delete', requirements: ['importId' => '\d+'], methods: 'POST')]
    public function deleteImport(int $importId, Request $request, TokenProvider $tokenProvider, ImportManager $importManager, ImportRunner $importRunner): Response
    {
        if (null !== $response = $this->denyUnlessGrantedWithToken(AccessManager::UPDATE, $request, $tokenProvider)) {
            return $response;
        }

        $import = $this->findImport($importId);

        if (null !== $import && null !== $importRunner->runningImport() && $importRunner->runningImport()->getId() === $import->getId()) {
            return $this->backWithError(self::LIST_PATH, $this->trans('A running import cannot be deleted: pause it first.'));
        }

        if (null !== $import) {
            $importManager->delete($import);
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::LIST_PATH));
    }

    private function denyUnlessGranted(string $access): ?Response
    {
        return $this->checkAuth([], JsonLinesImporter::getModuleCode(), $access);
    }

    private function denyUnlessGrantedWithToken(string $access, Request $request, TokenProvider $tokenProvider, bool $json = false): ?Response
    {
        if (null !== $response = $this->denyUnlessGranted($access)) {
            return $json ? new JsonResponse(['error' => 'Forbidden'], 403) : $response;
        }

        try {
            $tokenProvider->checkToken((string) $request->request->get('_token', ''));
        } catch (TokenAuthenticationException) {
            return $json
                ? new JsonResponse(['error' => 'Invalid token'], 403)
                : $this->errorPage($this->trans('The form has expired: reload the page and try again.'), 403);
        }

        return null;
    }

    private function findImport(int $importId): ?JsonlImport
    {
        return JsonlImportQuery::create()->findPk($importId);
    }

    /**
     * Steps 2 to 4 need a complete analysis, and an import not started yet.
     */
    private function findAnalysedImport(int $importId): ?JsonlImport
    {
        $import = $this->findImport($importId);

        return null !== $import && ImportStatus::ANALYSED === $import->getStatusEnum() && $import->getValidCount() > 0 ? $import : null;
    }

    private function saveParameters(JsonlImport $import, array $changes): void
    {
        $current = $import->getParametersArray();
        $parameters = ImportParameters::fromArray($current)->with($changes)->toArray();

        if (isset($changes['step'])) {
            $parameters['step'] = max((int) ($current['step'] ?? 1), (int) $changes['step']);
        }

        // Keys the parameters do not know (flags of the runner) are kept.
        $import->setParametersArray([...$current, ...$parameters])->save();
    }

    private function retainedCount(JsonlImport $import): int
    {
        return JsonlImportLineQuery::create()
            ->filterByImportId($import->getId())
            ->filterByValid(1)
            ->filterByResult(LineResult::EXCLUDED->value, Criteria::NOT_EQUAL)
            ->count();
    }

    private function previewTitle(array $payload): string
    {
        $title = $payload['title'] ?? null;

        foreach ((array) ($payload['i18n'] ?? []) as $block) {
            $title ??= $block['title'] ?? null;
        }

        return (string) $title;
    }

    /** @return array<int, string> */
    private function categoryOptions(ShopContext $shopContext): array
    {
        $options = [];

        foreach (CategoryQuery::create()->orderByParent()->orderByPosition()->find() as $category) {
            $options[(int) $category->getId()] = (string) $category->setLocale($shopContext->defaultLocale())->getTitle();
        }

        return $options;
    }

    /** @return array<int, string> */
    private function templateOptions(): array
    {
        $options = [];

        foreach (TemplateQuery::create()->find() as $template) {
            $options[(int) $template->getId()] = (string) $template->setLocale($this->getCurrentEditionLocale())->getName();
        }

        return $options;
    }

    private function authorName(): ?string
    {
        $admin = $this->getSecurityContext()->getAdminUser();

        return null === $admin ? null : (string) $admin->getUsername();
    }

    private function redirectToImport(?JsonlImport $import, ?string $page): Response
    {
        if (null === $import) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::LIST_PATH));
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::importPath($import, $page)));
    }

    private static function importPath(JsonlImport $import, ?string $page): string
    {
        return self::LIST_PATH.'/'.$import->getId().(null === $page ? '' : '/'.$page);
    }

    private function backWithError(string $path, string $message): Response
    {
        $this->getSession()->getFlashBag()->add('danger', $message);

        return $this->generateRedirect(URL::getInstance()->absoluteUrl($path));
    }

    private function trans(string $id, array $parameters = []): string
    {
        return Translator::getInstance()->trans($id, $parameters, JsonLinesImporter::DOMAIN_NAME);
    }
}
