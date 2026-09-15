<?php

declare(strict_types=1);

namespace Dan\Harness\Implementation\Identity;

use Dan\Lib\Filesystem\Path;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Content hash of a directory tree: relative paths and file contents, in
 * path order, independent of version-control metadata, timestamps and
 * permissions. Two trees with the same fingerprint contain the same files.
 */
final class ContentFingerprint
{
    public static function ofDirectory(Path $directory): string
    {
        /** @var array<string, string> $files */
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory->toString(), RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $relativePath = substr($path, strlen($directory->toString()) + 1);
            $files[$relativePath] = $path;
        }
        ksort($files, \SORT_STRING);

        $hash = hash_init('sha256');
        foreach ($files as $relativePath => $path) {
            $fileHash = hash_file('sha256', $path, true);
            if ($fileHash === false) {
                throw new RuntimeException(sprintf('Could not fingerprint file "%s".', $path));
            }

            hash_update($hash, pack('N', strlen($relativePath)) . $relativePath . $fileHash);
        }

        return hash_final($hash);
    }

    /**
     * One fingerprint over several trees, each contributing under its name so
     * moving files between trees changes the result.
     *
     * @param array<string, Path> $directories keyed by the name the tree contributes under
     */
    public static function ofDirectories(array $directories): string
    {
        ksort($directories, \SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($directories as $name => $directory) {
            hash_update($hash, pack('N', strlen($name)) . $name . self::ofDirectory($directory));
        }

        return hash_final($hash);
    }
}
