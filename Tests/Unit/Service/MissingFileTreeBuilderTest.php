<?php
declare(strict_types=1);

namespace AndreasKiessling\Faltools\Tests\Unit\Service;

use AndreasKiessling\Faltools\Service\MissingFileTreeBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MissingFileTreeBuilderTest extends TestCase
{
    #[Test]
    public function itBuildsTreeWithRecursiveAndDirectCounters(): void
    {
        $builder = new MissingFileTreeBuilder();

        $tree = $builder->build([
            ['storage' => 1, 'storage_name' => 'Storage A', 'identifier' => '/docs/a.pdf', 'reference_count' => 0],
            ['storage' => 1, 'storage_name' => 'Storage A', 'identifier' => '/docs/sub/b.pdf', 'reference_count' => 2],
            ['storage' => 1, 'storage_name' => 'Storage A', 'identifier' => '/c.pdf', 'reference_count' => 1],
        ]);

        self::assertCount(1, $tree);
        $root = $tree[0];
        self::assertSame('Storage A', $root->label);
        self::assertSame(3, $root->recursiveMissingFiles);
        self::assertSame(2, $root->recursiveReferencedFiles);
        self::assertSame(1, $root->directMissingFiles);
        self::assertSame(1, $root->directReferencedFiles);

        $docs = $root->children[0];
        self::assertSame('/docs/', $docs->identifier);
        self::assertSame(2, $docs->recursiveMissingFiles);
        self::assertSame(1, $docs->recursiveReferencedFiles);
        self::assertSame(1, $docs->directMissingFiles);
        self::assertSame(0, $docs->directReferencedFiles);

        $sub = $docs->children[0];
        self::assertSame('/docs/sub/', $sub->identifier);
        self::assertSame(1, $sub->recursiveMissingFiles);
        self::assertSame(1, $sub->recursiveReferencedFiles);
        self::assertSame(1, $sub->directMissingFiles);
        self::assertSame(1, $sub->directReferencedFiles);
    }

    #[Test]
    public function itSortsRootsAndChildrenAlphabeticallyByLabel(): void
    {
        $builder = new MissingFileTreeBuilder();

        $tree = $builder->build([
            ['storage' => 2, 'storage_name' => 'Zeta Storage', 'identifier' => '/z-folder/a.txt', 'reference_count' => 0],
            ['storage' => 1, 'storage_name' => 'Alpha Storage', 'identifier' => '/b-folder/a.txt', 'reference_count' => 0],
            ['storage' => 1, 'storage_name' => 'Alpha Storage', 'identifier' => '/a-folder/a.txt', 'reference_count' => 0],
        ]);

        self::assertCount(2, $tree);
        self::assertSame('Alpha Storage', $tree[0]->label);
        self::assertSame('Zeta Storage', $tree[1]->label);

        self::assertSame('a-folder', $tree[0]->children[0]->label);
        self::assertSame('b-folder', $tree[0]->children[1]->label);
    }
}
