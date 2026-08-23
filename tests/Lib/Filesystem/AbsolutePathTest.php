<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Lib\Filesystem;

use Dan\Lib\Filesystem\AbsolutePath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AbsolutePathTest extends TestCase
{
    public function testRejectsARelativePath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AbsolutePath::fromString('runs/session');
    }

    public function testKeepsAnAbsolutePathUntouched(): void
    {
        self::assertSame('/data/runs', AbsolutePath::fromString('/data/runs')->toString());
    }

    public function testResolvesARelativePathAgainstTheWorkingDirectory(): void
    {
        $path = AbsolutePath::resolve(value: 'runs', workingDirectory: '/work');

        self::assertSame('/work/runs', $path->toString());
    }

    public function testResolveStripsALeadingCurrentDirectorySegment(): void
    {
        $path = AbsolutePath::resolve(value: './runs', workingDirectory: '/work');

        self::assertSame('/work/runs', $path->toString());
    }

    public function testResolveKeepsAnAbsoluteValueUntouched(): void
    {
        $path = AbsolutePath::resolve(value: '/data/runs', workingDirectory: '/work');

        self::assertSame('/data/runs', $path->toString());
    }

    public function testResolveRejectsARelativeWorkingDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AbsolutePath::resolve(value: 'runs', workingDirectory: 'work');
    }

    public function testJoinStaysAbsolute(): void
    {
        $path = AbsolutePath::fromString('/data')->join('runs', 'session');

        self::assertSame('/data/runs/session', $path->toString());
        self::assertSame('session', $path->basename());
        self::assertSame('/data/runs/session', $path->toPath()->toString());
    }
}
