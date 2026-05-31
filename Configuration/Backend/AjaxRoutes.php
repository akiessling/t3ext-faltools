<?php

use AndreasKiessling\Faltools\Controller\MissingFilesTreeController;

return [
    'faltools_missing_files_tree' => [
        'path' => '/faltools/missing-files/tree',
        'methods' => ['GET'],
        'target' => MissingFilesTreeController::class . '::handleRequest',
        'inheritAccessFromModule' => 'file_faltools_missing',
    ],
];
