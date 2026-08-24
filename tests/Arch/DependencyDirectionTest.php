<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Arch;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Enforces AGENTS.md's inviolable dependency directions by scanning every
 * namespace-qualified name each package's sources reference. Works on parsed
 * tokens, not the autoloader: pest's arch() only sees names the root
 * autoloader can resolve, so a `use Shopware\...` in the harness - whose
 * vendor dir has no Shopware - would be invisible to it. Tokens also cover
 * inline fully-qualified references and attributes, and comments can never
 * false-positive.
 */
final class DependencyDirectionTest extends TestCase
{
    public function testHarnessReferencesNoShopwareAndOnlyTheLibAmongDanNamespaces(): void
    {
        self::assertSame([], $this->violations(directory: 'src', forbiddenPrefixes: ['Shopware\\'], allowedDanNamespaces: [
            'Dan\\Harness\\',
            'Dan\\Lib\\',
        ]));
    }

    public function testProbeReferencesOnlyTheLibAmongDanNamespaces(): void
    {
        // The probe's vendor platform (Shopware, Symfony, Doctrine) is its
        // runtime; among DAN's own namespaces only the lib is legal.
        self::assertSame([], $this->violations(directory: 'bundle/src', forbiddenPrefixes: [], allowedDanNamespaces: [
            'Dan\\Probe\\',
            'Dan\\Lib\\',
        ]));
    }

    public function testLibReferencesOnlyItself(): void
    {
        // Framework-freedom by construction: every namespace-qualified name
        // in lib/src must be lib's own. Global imports (RuntimeException,
        // Traversable, ...) are unqualified and stay legal.
        $violations = [];
        foreach ($this->qualifiedNamesByFile('lib/src') as $file => $names) {
            foreach ($names as $name) {
                if (!$this->matchesNamespace(name: $name, prefix: 'Dan\\Lib\\')) {
                    $violations[] = sprintf('%s references %s', $file, $name);
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * Flags every reference to a forbidden namespace prefix, and every
     * reference to a DAN namespace outside the package's allowed set - a
     * deny-list alone would let a reference to a new (or misspelled)
     * `Dan\...` namespace slip through.
     *
     * @param list<string> $forbiddenPrefixes
     * @param list<string> $allowedDanNamespaces
     *
     * @return list<string> human-readable violation descriptions
     */
    private function violations(string $directory, array $forbiddenPrefixes, array $allowedDanNamespaces): array
    {
        $violations = [];
        foreach ($this->qualifiedNamesByFile($directory) as $file => $names) {
            foreach ($names as $name) {
                foreach ($forbiddenPrefixes as $prefix) {
                    if ($this->matchesNamespace(name: $name, prefix: $prefix)) {
                        $violations[] = sprintf('%s references %s', $file, $name);
                    }
                }
                if ($this->matchesNamespace(name: $name, prefix: 'Dan\\') && !$this->startsWithAny(name: $name, prefixes: $allowedDanNamespaces)) {
                    $violations[] = sprintf('%s references %s', $file, $name);
                }
            }
        }

        return $violations;
    }

    /**
     * @param list<string> $prefixes
     */
    private function startsWithAny(string $name, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($this->matchesNamespace(name: $name, prefix: $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHP resolves namespace and class names case-insensitively, so the scan
     * must match that way too - a mixed-case `use SHOPWARE\...` is a working
     * dependency that a case-sensitive comparison would let through. The bare
     * namespace itself is a reference as well: a file declaring
     * `namespace Dan\Probe;` tokenizes as the prefix minus its trailing
     * separator.
     */
    private function matchesNamespace(string $name, string $prefix): bool
    {
        $normalizedName = strtolower($name);
        $normalizedPrefix = strtolower($prefix);

        return str_starts_with($normalizedName, $normalizedPrefix) || $normalizedName === rtrim($normalizedPrefix, '\\');
    }

    /**
     * @return array<string, list<string>> repo-relative file path => every qualified name it references
     */
    private function qualifiedNamesByFile(string $directory): array
    {
        $root = dirname(__DIR__, 2);
        $names = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $fileNames = [];
            // Token names, not T_* constants: PHPCS (scanned by PHPStan)
            // polyfills the same constants as strings, poisoning their type.
            foreach (PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $token) {
                if ($token->getTokenName() === 'T_NAME_QUALIFIED') {
                    $fileNames[] = $token->text;
                } elseif ($token->getTokenName() === 'T_NAME_FULLY_QUALIFIED') {
                    $name = ltrim($token->text, '\\');
                    // A fully-qualified GLOBAL symbol (\RuntimeException,
                    // \DIRECTORY_SEPARATOR) is not a namespace reference.
                    if (str_contains($name, '\\')) {
                        $fileNames[] = $name;
                    }
                }
            }
            if ($fileNames !== []) {
                $names[substr($file->getPathname(), strlen($root) + 1)] = $fileNames;
            }
        }
        ksort($names);

        // A moved or renamed source tree must fail loudly instead of turning
        // every rule above into a vacuous pass over zero files.
        self::assertNotSame([], $names, sprintf('No PHP files with qualified names found under "%s".', $directory));

        return $names;
    }
}
