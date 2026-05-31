<?php
declare(strict_types=1);

namespace AndreasKiessling\Faltools\Dto;

final readonly class MissingFileBulkDeletionResult
{
    public function __construct(
        public int $removedFiles,
        public int $skippedFiles,
        public int $failedFiles,
        public int $removedReferences,
        public int $removedMetadataRecords,
        public int $removedProcessedFiles,
    ) {}
}
