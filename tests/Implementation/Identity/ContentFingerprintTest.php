<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Implementation\Identity;

use Dan\Harness\Implementation\Identity\ContentFingerprint;
use Dan\Lib\Filesystem\Path;
use PHPUnit\Framework\TestCase;

final class ContentFingerprintTest extends TestCase
{
    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/dan-fingerprint-' . bin2hex(random_bytes(4));
        mkdir($this->workDirectory . '/a/nested', 0o777, true);
        mkdir($this->workDirectory . '/b', 0o777, true);
        file_put_contents($this->workDirectory . '/a/one.php', 'one');
        file_put_contents($this->workDirectory . '/a/nested/two.php', 'two');
        file_put_contents($this->workDirectory . '/b/one.php', 'one');
    }

    protected function tearDown(): void
    {
        unlink($this->workDirectory . '/a/one.php');
        unlink($this->workDirectory . '/a/nested/two.php');
        unlink($this->workDirectory . '/b/one.php');
        rmdir($this->workDirectory . '/a/nested');
        rmdir($this->workDirectory . '/a');
        rmdir($this->workDirectory . '/b');
        rmdir($this->workDirectory);
    }

    public function testTheSameContentUnderTheSameRelativePathsFingerprintsTheSame(): void
    {
        $a = ContentFingerprint::ofDirectory(Path::fromString($this->workDirectory . '/a'));

        self::assertSame($a, ContentFingerprint::ofDirectory(Path::fromString($this->workDirectory . '/a')));
        self::assertNotSame($a, ContentFingerprint::ofDirectory(Path::fromString($this->workDirectory . '/b')), 'A missing nested file changes the fingerprint.');
    }

    public function testMovingAFileBetweenTreesChangesTheCombinedFingerprint(): void
    {
        $combined = ContentFingerprint::ofDirectories([
            'a' => Path::fromString($this->workDirectory . '/a'),
            'b' => Path::fromString($this->workDirectory . '/b'),
        ]);
        rename($this->workDirectory . '/a/nested/two.php', $this->workDirectory . '/b/two.php');

        try {
            self::assertNotSame($combined, ContentFingerprint::ofDirectories([
                'a' => Path::fromString($this->workDirectory . '/a'),
                'b' => Path::fromString($this->workDirectory . '/b'),
            ]));
        } finally {
            rename($this->workDirectory . '/b/two.php', $this->workDirectory . '/a/nested/two.php');
        }
    }

    public function testTheCombinedFingerprintDoesNotDependOnKeyOrder(): void
    {
        $directories = [
            'a' => Path::fromString($this->workDirectory . '/a'),
            'b' => Path::fromString($this->workDirectory . '/b'),
        ];

        self::assertSame(ContentFingerprint::ofDirectories($directories), ContentFingerprint::ofDirectories(array_reverse($directories, true)));
    }
}
