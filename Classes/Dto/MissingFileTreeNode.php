<?php
declare(strict_types=1);

namespace AndreasKiessling\Faltools\Dto;

final class MissingFileTreeNode
{
    /**
     * @var list<self>
     */
    public array $children = [];
    public int $directMissingFiles = 0;
    public int $recursiveMissingFiles = 0;
    public int $directReferencedFiles = 0;
    public int $recursiveReferencedFiles = 0;

    public function __construct(
        public readonly int $storage,
        public readonly string $storageName,
        public readonly string $identifier,
        public readonly string $label,
        public readonly int $level,
    ) {}

    public function addChild(self $child): void
    {
        $this->children[] = $child;
    }

    public function hasReferences(): bool
    {
        return $this->recursiveReferencedFiles > 0;
    }
}
