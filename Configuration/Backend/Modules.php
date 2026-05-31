<?php

use AndreasKiessling\Faltools\Controller\MissingFilesController;

return [
    'file_faltools_missing' => [
        'parent' => 'file',
        'access' => 'user',
        'path' => '/module/file/faltools/missing',
        'iconIdentifier' => 'faltools-module-missing-files',
        'labels' => 'LLL:EXT:faltools/Resources/Private/Language/locallang_module.xlf',
        'inheritNavigationComponentFromMainModule' => false,
        'navigationComponent' => '@andreaskiessling/faltools/missing-files-tree',
        'routes' => [
            '_default' => [
                'target' => MissingFilesController::class . '::handleRequest',
            ],
            'delete' => [
                'path' => '/delete',
                'methods' => ['POST'],
                'target' => MissingFilesController::class . '::deleteAction',
            ],
            'restore' => [
                'path' => '/restore',
                'methods' => ['POST'],
                'target' => MissingFilesController::class . '::restoreAction',
            ],
            'bulkDelete' => [
                'path' => '/bulk-delete',
                'methods' => ['POST'],
                'target' => MissingFilesController::class . '::bulkDeleteAction',
            ],
            'export' => [
                'path' => '/export',
                'methods' => ['GET'],
                'target' => MissingFilesController::class . '::exportAction',
            ],
        ],
    ],
];
