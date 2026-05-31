<?php

return [
    'dependencies' => [
        'backend',
        'core',
    ],
    'tags' => [
        'backend.navigation-component',
    ],
    'imports' => [
        '@andreaskiessling/faltools/' => [
            'path' => 'EXT:faltools/Resources/Public/JavaScript/',
        ],
    ],
];
