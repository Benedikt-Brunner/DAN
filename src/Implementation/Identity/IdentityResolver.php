<?php

declare(strict_types=1);

namespace Dan\Harness\Implementation\Identity;

use Dan\Harness\Implementation\Reference\Reference;
use Dan\Harness\Implementation\Reference\ReferenceType;
use Dan\Lib\Filesystem\Path;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class IdentityResolver
{
    public function resolve(Reference $reference): Identity
    {
        if ($reference->type === ReferenceType::Release) {
            $constraint = $reference->releaseConstraint();

            return new Identity(id: $constraint, label: sprintf('shopware/core %s', $constraint));
        }

        $checkout = $reference->checkoutPath();
        $fingerprint = $this->fingerprint($checkout->join('src', 'Core'));

        return new Identity(
            id: $fingerprint,
            label: sprintf('%s @ %s', $checkout->basename(), substr($fingerprint, 0, 12)),
        );
    }

    private function fingerprint(Path $directory): string
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
                throw new RuntimeException(sprintf('Could not fingerprint implementation file "%s".', $path));
            }

            hash_update($hash, pack('N', strlen($relativePath)) . $relativePath . $fileHash);
        }

        return hash_final($hash);
    }
}
