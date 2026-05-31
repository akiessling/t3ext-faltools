<?php
declare(strict_types=1);

namespace AndreasKiessling\Faltools\Dto;

final readonly class MissingFileRestoreResult
{
    public function __construct(
        public MissingFile $file,
        public string $identifier,
        public bool $reindexedExistingFile = false,
    ) {}
}
