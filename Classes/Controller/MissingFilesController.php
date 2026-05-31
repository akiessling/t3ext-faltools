<?php
declare(strict_types=1);

namespace AndreasKiessling\Faltools\Controller;

use AndreasKiessling\Faltools\Exception\MissingFilePermissionDeniedException;
use AndreasKiessling\Faltools\Exception\MissingFileReferencedException;
use AndreasKiessling\Faltools\Exception\MissingFileActionException;
use Psr\Http\Message\ResponseFactoryInterface;
use AndreasKiessling\Faltools\Pagination\QueryBuilderPaginator;
use AndreasKiessling\Faltools\Repository\MissingFileRepository;
use AndreasKiessling\Faltools\Service\MissingFileActionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\SlidingWindowPagination;

#[AsController]
final readonly class MissingFilesController
{
    private const ITEMS_PER_PAGE = 50;
    private const LANGUAGE_FILE = 'LLL:EXT:faltools/Resources/Private/Language/locallang_module.xlf:';

    public function __construct(
        private MissingFileRepository $missingFileRepository,
        private MissingFileActionService $missingFileActionService,
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $uriBuilder,
        private IconFactory $iconFactory,
        private PageRenderer $pageRenderer,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $languageService = $this->getLanguageService();
        $selectedStorage = $this->resolveSelectedStorage($request);
        $queryParams = $request->getQueryParams();
        $selectedPath = $this->normalizePath((string)($queryParams['path'] ?? ''));
        $requestedPage = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle(
            $languageService->sL('LLL:EXT:faltools/Resources/Private/Language/locallang_module.xlf:mlang_tabs_tab')
        );
        $this->pageRenderer->addInlineLanguageLabelFile(
            'EXT:faltools/Resources/Private/Language/locallang_module.xlf',
            'faltools.'
        );
        $this->pageRenderer->addCssFile('EXT:faltools/Resources/Public/Css/MissingFilesModule.css');
        $this->pageRenderer->loadJavaScriptModule('@andreaskiessling/faltools/missing-files-actions.js');

        $paginator = new QueryBuilderPaginator(
            $this->missingFileRepository->createMissingFilesQueryBuilder($selectedStorage, $selectedPath),
            max(1, $requestedPage),
            self::ITEMS_PER_PAGE
        );
        $pagination = new SlidingWindowPagination($paginator, 9);
        $currentPage = $paginator->getCurrentPageNumber();
        $totalPages = $paginator->getNumberOfPages();
        $files = $this->missingFileRepository->mapRowsToMissingFiles($paginator->getPaginatedItems());
        $currentPageFileUids = array_map(static fn($file): int => $file->uid, $files);
        $currentPageReferencedFiles = count(array_filter($files, static fn($file): bool => $file->referenceCount > 0));
        $referencedFiles = $this->missingFileRepository->countReferencedMissingFiles($selectedStorage, $selectedPath);
        $folderFileCount = (int)$paginator->getTotalAmountOfItems();

        $view->assignMultiple([
            'files' => $files,
            'selectedStorage' => $selectedStorage,
            'selectedPath' => $selectedPath,
            'deleteActionUrl' => (string)$this->uriBuilder->buildUriFromRoute('file_faltools_missing.delete'),
            'bulkDeleteActionUrl' => (string)$this->uriBuilder->buildUriFromRoute('file_faltools_missing.bulkDelete'),
            'restoreActionUrl' => (string)$this->uriBuilder->buildUriFromRoute('file_faltools_missing.restore'),
            'currentUrl' => $this->buildModuleUrl($selectedStorage, $currentPage, $selectedPath),
            'totalFiles' => $paginator->getTotalAmountOfItems(),
            'referencedFiles' => $referencedFiles,
            'currentPageFileUids' => implode(',', $currentPageFileUids),
            'currentPageFileCount' => count($currentPageFileUids),
            'currentPageReferencedFiles' => $currentPageReferencedFiles,
            'currentFilterReferencedFiles' => $referencedFiles,
            'folderFileCount' => $folderFileCount,
            'folderDeleteScopeLabel' => $selectedPath !== '' ? $selectedPath : '/',
            'totalPages' => $totalPages,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'paginationPages' => $this->buildPaginationPages($pagination, $selectedStorage, $selectedPath),
            'previousPageUrl' => $pagination->getPreviousPageNumber() !== null ? $this->buildModuleUrl($selectedStorage, $pagination->getPreviousPageNumber(), $selectedPath) : '',
            'nextPageUrl' => $pagination->getNextPageNumber() !== null ? $this->buildModuleUrl($selectedStorage, $pagination->getNextPageNumber(), $selectedPath) : '',
        ]);

        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $reloadButton = $buttonBar->makeLinkButton()
            ->setHref($this->buildModuleUrl($selectedStorage, $currentPage, $selectedPath))
            ->setTitle($languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.reload'))
            ->setIcon($this->iconFactory->getIcon('actions-refresh', IconSize::SMALL));
        $buttonBar->addButton($reloadButton);
        $exportButton = $buttonBar->makeLinkButton()
            ->setHref($this->buildExportUrl($selectedStorage, $selectedPath))
            ->setTitle($this->translate('action.exportCsv'))
            ->setIcon($this->iconFactory->getIcon('actions-download', IconSize::SMALL));
        $buttonBar->addButton($exportButton);

        return $view->renderResponse('MissingFiles/Index');
    }

    public function exportAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $selectedStorage = isset($queryParams['storage']) && (int)$queryParams['storage'] > 0
            ? (int)$queryParams['storage']
            : null;
        $selectedPath = $this->normalizePath((string)($queryParams['path'] ?? ''));
        $rows = $this->missingFileRepository->findMissingFileExportRows($selectedStorage, $selectedPath);

        $buffer = fopen('php://temp', 'w+b');
        if ($buffer === false) {
            return $this->errorJsonResponse('bulkDelete.error.title', 'CSV export failed.', 500);
        }
        fputcsv($buffer, ['uid', 'name', 'storage_uid', 'storage_path', 'sha1']);
        foreach ($rows as $row) {
            fputcsv($buffer, [
                $row['uid'],
                $row['name'],
                $row['storage'],
                $row['storage_path'],
                $row['sha1'],
            ]);
        }
        rewind($buffer);
        $csvContent = stream_get_contents($buffer);
        fclose($buffer);
        if (!is_string($csvContent)) {
            $csvContent = '';
        }

        $filename = sprintf(
            'missing-files-%s%s.csv',
            $selectedStorage !== null ? 'storage-' . $selectedStorage . '-' : '',
            (new \DateTimeImmutable())->format('Ymd-His')
        );

        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withBody($this->streamFactory->createStream($csvContent));
    }

    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $this->parseBody($request);
        $uid = (int)($parsedBody['uid'] ?? 0);
        $forceReferences = (bool)($parsedBody['forceReferences'] ?? false);
        $languageService = $this->getLanguageService();

        try {
            $result = $this->missingFileActionService->deleteMissingFile($uid, $forceReferences);
            return new JsonResponse([
                'success' => true,
                'title' => $this->translate('delete.success.title'),
                'message' => sprintf(
                    $this->translate('delete.success.message'),
                    $result->file->name,
                    $result->removedReferences,
                    $result->removedMetadataRecords,
                    $result->removedProcessedFiles
                ),
            ]);
        } catch (MissingFilePermissionDeniedException $exception) {
            return $this->errorJsonResponse('delete.error.title', $exception->getMessage(), 403);
        } catch (MissingFileReferencedException $exception) {
            return $this->errorJsonResponse('delete.error.title', $exception->getMessage(), 409);
        } catch (MissingFileActionException $exception) {
            return $this->errorJsonResponse('delete.error.title', $exception->getMessage(), 400);
        }
    }

    public function restoreAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $this->parseBody($request);
        $uploadedFiles = $request->getUploadedFiles();
        $uid = (int)($parsedBody['uid'] ?? 0);
        $uploadedFile = $uploadedFiles['uploadedFile'] ?? null;

        if (!$uploadedFile instanceof \Psr\Http\Message\UploadedFileInterface) {
            return $this->errorJsonResponse('restore.error.title', $this->translate('restore.error.missingUpload'), 400);
        }

        try {
            $result = $this->missingFileActionService->restoreMissingFile($uid, $uploadedFile);
            $messageLabel = $result->reindexedExistingFile
                ? 'restore.success.reindexedMessage'
                : 'restore.success.message';
            return new JsonResponse([
                'success' => true,
                'title' => $this->translate('restore.success.title'),
                'message' => sprintf(
                    $this->translate($messageLabel),
                    $result->file->name,
                    $result->identifier
                ),
            ]);
        } catch (MissingFilePermissionDeniedException $exception) {
            return $this->errorJsonResponse('restore.error.title', $exception->getMessage(), 403);
        } catch (MissingFileActionException $exception) {
            return $this->errorJsonResponse('restore.error.title', $exception->getMessage(), 400);
        }
    }

    public function bulkDeleteAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $this->parseBody($request);
        $scope = (string)($parsedBody['scope'] ?? 'page');
        $uids = $parsedBody['uids'] ?? [];
        $forceReferences = (bool)($parsedBody['forceReferences'] ?? false);

        if ($scope === 'folder') {
            $storage = isset($parsedBody['storage']) && (int)$parsedBody['storage'] > 0
                ? (int)$parsedBody['storage']
                : null;
            $path = $this->normalizePath((string)($parsedBody['path'] ?? ''));
            $uids = $this->missingFileRepository->findMissingFileUids($storage, $path);
        } elseif (is_string($uids)) {
            $uids = array_filter(array_map('trim', explode(',', $uids)), static fn(string $uid): bool => $uid !== '');
        }
        if (!is_array($uids)) {
            $uids = [];
        }
        $uids = array_values(array_map(static fn($uid): int => (int)$uid, $uids));
        if ($uids === []) {
            return $this->errorJsonResponse('bulkDelete.error.title', $this->translate('bulkDelete.error.noRecords'), 400);
        }

        $result = $this->missingFileActionService->deleteMissingFiles($uids, $forceReferences);
        $hasFailures = $result->failedFiles > 0;
        return new JsonResponse([
            'success' => !$hasFailures,
            'title' => $this->translate(
                $hasFailures
                    ? 'bulkDelete.partial.title'
                    : 'bulkDelete.success.title'
            ),
            'message' => sprintf(
                $this->translate('bulkDelete.success.message'),
                $result->removedFiles,
                $result->skippedFiles,
                $result->failedFiles,
                $result->removedReferences,
                $result->removedMetadataRecords,
                $result->removedProcessedFiles
            ),
        ], $hasFailures ? 207 : 200);
    }

    /**
     * @return list<array{number: int, url: string, current: bool}>
     */
    private function buildPaginationPages(SlidingWindowPagination $pagination, ?int $selectedStorage, string $selectedPath): array
    {
        $pages = [];
        foreach ($pagination->getAllPageNumbers() as $page) {
            $pages[] = [
                'number' => $page,
                'url' => $this->buildModuleUrl($selectedStorage, $page, $selectedPath),
                'current' => $page === $pagination->getPaginator()->getCurrentPageNumber(),
            ];
        }

        return $pages;
    }

    private function resolveSelectedStorage(ServerRequestInterface $request): ?int
    {
        $queryParams = $request->getQueryParams();
        if (isset($queryParams['storage']) && (int)$queryParams['storage'] > 0) {
            return (int)$queryParams['storage'];
        }

        $storageNames = $this->missingFileRepository->getStorageNames();
        $firstStorage = array_key_first($storageNames);
        return $firstStorage !== null ? (int)$firstStorage : null;
    }

    private function buildModuleUrl(?int $selectedStorage, int $page, string $path = ''): string
    {
        $arguments = ['page' => $page];
        if ($selectedStorage !== null) {
            $arguments['storage'] = $selectedStorage;
        }
        if ($path !== '') {
            $arguments['path'] = $path;
        }
        return (string)$this->uriBuilder->buildUriFromRoute('file_faltools_missing', $arguments);
    }

    private function buildExportUrl(?int $selectedStorage, string $path = ''): string
    {
        $arguments = [];
        if ($selectedStorage !== null) {
            $arguments['storage'] = $selectedStorage;
        }
        if ($path !== '') {
            $arguments['path'] = $path;
        }

        return (string)$this->uriBuilder->buildUriFromRoute('file_faltools_missing.export', $arguments);
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $path = '/' . trim($path, '/') . '/';
        return $path === '//' ? '' : $path;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        $parsedBody = $request->getParsedBody();
        return is_array($parsedBody) ? $parsedBody : [];
    }

    private function translate(string $key): string
    {
        return $this->getLanguageService()->sL(self::LANGUAGE_FILE . $key);
    }

    private function errorJsonResponse(string $titleKey, string $message, int $statusCode): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'title' => $this->translate($titleKey),
            'message' => $message,
        ], $statusCode);
    }
}
