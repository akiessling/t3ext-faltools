<?php
declare(strict_types=1);

namespace AndreasKiessling\Faltools\Controller;

use AndreasKiessling\Faltools\Dto\MissingFileTreeNode;
use AndreasKiessling\Faltools\Repository\MissingFileRepository;
use AndreasKiessling\Faltools\Service\MissingFileTreeBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;

#[AsController]
final readonly class MissingFilesTreeController
{
    private const LANGUAGE_FILE = 'LLL:EXT:faltools/Resources/Private/Language/locallang_module.xlf:';
    /**
     * @var array<string, string>
     */
    private const TREE_LABEL_KEYS = [
        'treeStorageLabel' => 'js.treeStorageLabel',
        'treeAllStorages' => 'js.treeAllStorages',
        'treeRefreshLabel' => 'js.treeRefreshLabel',
        'treeLoading' => 'js.treeLoading',
        'treeRequestFailed' => 'js.treeRequestFailed',
        'treeNoMissingFiles' => 'js.treeNoMissingFiles',
        'treeBadgeMissing' => 'js.treeBadgeMissing',
        'treeBadgeReferences' => 'js.treeBadgeReferences',
    ];

    public function __construct(
        private MissingFileRepository $missingFileRepository,
        private MissingFileTreeBuilder $treeBuilder,
        private UriBuilder $uriBuilder,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $selectedStorage = $this->resolveSelectedStorage($request);
        $tree = $this->treeBuilder->build($this->missingFileRepository->findMissingFileTreeRows($selectedStorage));

        return new JsonResponse([
            'nodes' => array_map($this->serializeNode(...), $tree),
            'storageOptions' => $this->missingFileRepository->getStorageNames(),
            'selectedStorage' => $selectedStorage,
            'moduleUrl' => (string)$this->uriBuilder->buildUriFromRoute('file_faltools_missing'),
            'labels' => $this->buildLabels(),
        ]);
    }

    /**
     * @return array{
     *     storage: int,
     *     storageName: string,
     *     identifier: string,
     *     label: string,
     *     level: int,
     *     missingFiles: int,
     *     referencedFiles: int,
     *     hasReferences: bool,
     *     url: string,
     *     children: list<array<string, mixed>>
     * }
     */
    private function serializeNode(MissingFileTreeNode $node): array
    {
        return [
            'storage' => $node->storage,
            'storageName' => $node->storageName,
            'identifier' => $node->identifier,
            'label' => $node->label,
            'level' => $node->level,
            'missingFiles' => $node->recursiveMissingFiles,
            'referencedFiles' => $node->recursiveReferencedFiles,
            'hasReferences' => $node->hasReferences(),
            'url' => (string)$this->uriBuilder->buildUriFromRoute('file_faltools_missing', [
                'storage' => $node->storage,
                'path' => $node->identifier,
                'page' => 1,
            ]),
            'children' => array_map($this->serializeNode(...), $node->children),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildLabels(): array
    {
        $languageService = $this->getLanguageService();
        return array_map(function ($languageKey) use ($languageService) {
            return $languageService->sL(self::LANGUAGE_FILE . $languageKey);
        }, self::TREE_LABEL_KEYS);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    private function resolveSelectedStorage(ServerRequestInterface $request): ?int
    {
        $queryParams = $request->getQueryParams();
        return isset($queryParams['storage']) && (int)$queryParams['storage'] > 0
            ? (int)$queryParams['storage']
            : null;
    }
}
