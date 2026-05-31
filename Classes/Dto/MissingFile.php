<?php
declare(strict_types=1);

namespace AndreasKiessling\Faltools\Dto;

final readonly class MissingFile
{
    public function __construct(
        public int $uid,
        public int $storage,
        public string $storageName,
        public string $identifier,
        public string $name,
        public string $extension,
        public string $mimeType,
        public int $size,
        public int $creationDate,
        public int $modificationDate,
        public int $lastIndexed,
        public int $referenceCount,
        public ?string $publicUrl = null,
    ) {}

    public function getFolderIdentifier(): string
    {
        $folderIdentifier = dirname($this->identifier);
        if ($folderIdentifier === '.' || $folderIdentifier === '/') {
            return '/';
        }
        return rtrim($folderIdentifier, '/') . '/';
    }

    public function hasReferences(): bool
    {
        return $this->referenceCount > 0;
    }
}
