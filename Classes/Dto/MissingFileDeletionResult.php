<?php
declare(strict_types=1);

namespace AndreasKiessling\Faltools\Dto;

final readonly class MissingFileDeletionResult
{
    public function __construct(
        public MissingFile $file,
        public int $removedReferences,
        public int $removedMetadataRecords,
        public int $removedProcessedFiles,
    ) {}
}
