<?php
declare(strict_types=1);

namespace AndreasKiessling\Faltools\Service;

use AndreasKiessling\Faltools\Dto\MissingFileTreeNode;

final class MissingFileTreeBuilder
{
    /**
     * @param iterable<array{storage: int|string, storage_name: string, identifier: string, reference_count: int|string}> $rows
     * @return list<MissingFileTreeNode>
     */
    public function build(iterable $rows): array
    {
        $roots = [];
        $nodesByKey = [];

        foreach ($rows as $row) {
            $storage = (int)$row['storage'];
            $storageName = (string)$row['storage_name'];
            $identifier = (string)$row['identifier'];
            $referenceCount = (int)$row['reference_count'];

            $rootKey = $storage . ':/';
            if (!isset($nodesByKey[$rootKey])) {
                $nodesByKey[$rootKey] = new MissingFileTreeNode($storage, $storageName, '/', $storageName, 0);
                $roots[] = $nodesByKey[$rootKey];
            }

            $folderIdentifier = $this->getFolderIdentifier($identifier);
            $currentPath = '/';
            $parent = $nodesByKey[$rootKey];
            foreach ($this->splitFolderIdentifier($folderIdentifier) as $folderName) {
                $currentPath .= $folderName . '/';
                $nodeKey = $storage . ':' . $currentPath;
                if (!isset($nodesByKey[$nodeKey])) {
                    $nodesByKey[$nodeKey] = new MissingFileTreeNode(
                        $storage,
                        $storageName,
                        $currentPath,
                        $folderName,
                        substr_count(trim($currentPath, '/'), '/') + 1
                    );
                    $parent->addChild($nodesByKey[$nodeKey]);
                }
                $parent = $nodesByKey[$nodeKey];
            }

            $parent->directMissingFiles++;
            if ($referenceCount > 0) {
                $parent->directReferencedFiles++;
            }

            foreach ($this->getAncestorIdentifiers($folderIdentifier) as $ancestorIdentifier) {
                $nodeKey = $storage . ':' . $ancestorIdentifier;
                if (!isset($nodesByKey[$nodeKey])) {
                    continue;
                }
                $nodesByKey[$nodeKey]->recursiveMissingFiles++;
                if ($referenceCount > 0) {
                    $nodesByKey[$nodeKey]->recursiveReferencedFiles++;
                }
            }
        }

        $this->sortNodes($roots);

        return $roots;
    }

    private function getFolderIdentifier(string $fileIdentifier): string
    {
        $folderIdentifier = dirname($fileIdentifier);
        if ($folderIdentifier === '.' || $folderIdentifier === '/') {
            return '/';
        }
        return rtrim($folderIdentifier, '/') . '/';
    }

    /**
     * @return list<string>
     */
    private function splitFolderIdentifier(string $folderIdentifier): array
    {
        return array_values(array_filter(explode('/', trim($folderIdentifier, '/')), static fn(string $part): bool => $part !== ''));
    }

    /**
     * @return list<string>
     */
    private function getAncestorIdentifiers(string $folderIdentifier): array
    {
        $identifiers = ['/'];
        $currentPath = '/';
        foreach ($this->splitFolderIdentifier($folderIdentifier) as $folderName) {
            $currentPath .= $folderName . '/';
            $identifiers[] = $currentPath;
        }
        return $identifiers;
    }

    /**
     * @param list<MissingFileTreeNode> $nodes
     */
    private function sortNodes(array &$nodes): void
    {
        usort($nodes, static fn(MissingFileTreeNode $a, MissingFileTreeNode $b): int => strcasecmp($a->label, $b->label));
        foreach ($nodes as $node) {
            $this->sortNodes($node->children);
        }
    }
}
