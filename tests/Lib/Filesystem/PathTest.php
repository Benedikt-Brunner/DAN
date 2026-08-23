<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Lib\Filesystem;

use Dan\Lib\Filesystem\Path;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathTest extends TestCase
{
    public function testPreservesTheProvidedPath(): void
    {
        self::assertSame('./runs/../runs', Path::fromString('./runs/../runs')->toString());
    }

    public function testBuildsChildPaths(): void
    {
        $path = Path::fromString('/tmp/')->join('/runs/', 'baseline');

        self::assertSame('/tmp/runs/baseline', $path->toString());
        self::assertSame('baseline', $path->basename());
        self::assertSame('/tmp/runs', $path->parent()->toString());
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsInvalidPaths(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        Path::fromString($path);
    }

    public function testRejectsAnEmptyChildSegment(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Path::fromString('/tmp')->join('');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'null byte' => ["foo\0bar"];
    }
}
