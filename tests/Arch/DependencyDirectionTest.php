<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Arch;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame([], $this->libOnlyViolationsAmong($this->qualifiedNamesByFile('lib/src')));
    }

    /**
     * The rules must stay provably non-vacuous: over the real source trees
     * they can only ever assert zero violations, so a matcher that stopped
     * firing would keep them green forever. Every forbidden shape - plain,
     * mixed-case, inline, attribute, and the grouped-use forms the tokenizer
     * splits apart - is injected as an in-memory snippet and must produce
     * exactly its violation through the same tokenizer and rules the real
     * scans use.
     *
     * @param list<string> $expectedViolations
     */
    #[DataProvider('forbiddenReferenceSnippets')]
    public function testTheHarnessRuleFlagsEveryInjectedForbiddenReference(string $source, array $expectedViolations): void
    {
        self::assertSame($expectedViolations, $this->violationsAmong(
            namesByFile: ['snippet.php' => $this->qualifiedNamesIn($source)],
            forbiddenPrefixes: ['Shopware\\'],
            allowedDanNamespaces: [
                'Dan\\Harness\\',
                'Dan\\Lib\\',
            ],
        ));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function forbiddenReferenceSnippets(): array
    {
        return [
            'a plain use statement' => [
                '<?php use Shopware\Core\Framework\Uuid\Uuid;',
                ['snippet.php references Shopware\Core\Framework\Uuid\Uuid'],
            ],
            'a mixed-case use statement' => [
                '<?php use SHOPWARE\Core\Defaults;',
                ['snippet.php references SHOPWARE\Core\Defaults'],
            ],
            'an inline fully qualified reference' => [
                '<?php $language = \Shopware\Core\Defaults::LANGUAGE_SYSTEM;',
                ['snippet.php references Shopware\Core\Defaults'],
            ],
            'an attribute reference' => [
                '<?php #[\Shopware\Core\Framework\Log\Package(\'core\')] final class Widget {}',
                ['snippet.php references Shopware\Core\Framework\Log\Package'],
            ],
            'a grouped use with a single-segment prefix' => [
                '<?php use Shopware\{Component\Foo, Bar};',
                [
                    'snippet.php references Shopware\Component\Foo',
                    'snippet.php references Shopware\Bar',
                ],
            ],
            'a grouped use with a fully qualified prefix' => [
                '<?php use \Shopware\{Component\Baz};',
                ['snippet.php references Shopware\Component\Baz'],
            ],
            'a grouped use with a qualified prefix' => [
                '<?php use Shopware\Core\{Framework\Uuid, Defaults};',
                [
                    'snippet.php references Shopware\Core\Framework\Uuid',
                    'snippet.php references Shopware\Core\Defaults',
                    'snippet.php references Shopware\Core',
                ],
            ],
            'an aliased grouped member' => [
                '<?php use Shopware\{Component\Foo as ComponentFoo};',
                ['snippet.php references Shopware\Component\Foo'],
            ],
            'an unapproved Dan namespace' => [
                '<?php use Dan\Probe\DanProbeBundle;',
                ['snippet.php references Dan\Probe\DanProbeBundle'],
            ],
            'a mixed-case unapproved Dan namespace' => [
                '<?php use DAN\PROBE\DanProbeBundle;',
                ['snippet.php references DAN\PROBE\DanProbeBundle'],
            ],
        ];
    }

    public function testTheHarnessRuleAcceptsLegalReferences(): void
    {
        $source = '<?php namespace Dan\Harness\Comparison; use Dan\Lib\Time\Duration; use RuntimeException; use Symfony\Component\Console\Command\Command; $separator = \DIRECTORY_SEPARATOR;';

        self::assertSame([], $this->violationsAmong(
            namesByFile: ['snippet.php' => $this->qualifiedNamesIn($source)],
            forbiddenPrefixes: ['Shopware\\'],
            allowedDanNamespaces: [
                'Dan\\Harness\\',
                'Dan\\Lib\\',
            ],
        ));
    }

    public function testTheLibRuleFlagsInjectedForeignReferences(): void
    {
        $source = '<?php namespace Dan\Lib\Time; use Symfony\Component\Clock\Clock;';

        self::assertSame(
            ['snippet.php references Symfony\Component\Clock\Clock'],
            $this->libOnlyViolationsAmong(['snippet.php' => $this->qualifiedNamesIn($source)]),
        );
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
        return $this->violationsAmong(
            namesByFile: $this->qualifiedNamesByFile($directory),
            forbiddenPrefixes: $forbiddenPrefixes,
            allowedDanNamespaces: $allowedDanNamespaces,
        );
    }

    /**
     * @param array<string, list<string>> $namesByFile
     * @param list<string> $forbiddenPrefixes
     * @param list<string> $allowedDanNamespaces
     *
     * @return list<string> human-readable violation descriptions
     */
    private function violationsAmong(array $namesByFile, array $forbiddenPrefixes, array $allowedDanNamespaces): array
    {
        $violations = [];
        foreach ($namesByFile as $file => $names) {
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
     * @param array<string, list<string>> $namesByFile
     *
     * @return list<string> human-readable violation descriptions
     */
    private function libOnlyViolationsAmong(array $namesByFile): array
    {
        $violations = [];
        foreach ($namesByFile as $file => $names) {
            foreach ($names as $name) {
                if (!$this->matchesNamespace(name: $name, prefix: 'Dan\\Lib\\')) {
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
            $fileNames = $this->qualifiedNamesIn((string) file_get_contents($file->getPathname()));
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

    /**
     * Every namespace-qualified name the given PHP source references. A
     * grouped `use Prefix\{Member, ...}` needs recombination: the tokenizer
     * emits the prefix and the members as separate tokens - for a
     * single-segment prefix not even as a name token - so neither side alone
     * carries the full dependency.
     *
     * @return list<string>
     */
    private function qualifiedNamesIn(string $source): array
    {
        $tokens = [];
        foreach (PhpToken::tokenize($source) as $token) {
            if (!$token->isIgnorable()) {
                $tokens[] = $token;
            }
        }

        $names = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $name = $this->nameOf($tokens[$index]);
            if ($name === null) {
                continue;
            }
            if ($index + 2 < $count && $tokens[$index + 1]->text === '\\' && $tokens[$index + 2]->text === '{') {
                $expectMember = true;
                $member = $index + 3;
                for (; $member < $count && $tokens[$member]->text !== '}'; ++$member) {
                    if ($tokens[$member]->text === ',') {
                        $expectMember = true;
                        continue;
                    }
                    $memberName = $this->nameOf($tokens[$member]);
                    if ($expectMember && $memberName !== null) {
                        $names[] = $name . '\\' . $memberName;
                        // Only the first name of each group segment is the
                        // reference; an `as` alias that follows is not.
                        $expectMember = false;
                    }
                }
                if (str_contains($name, '\\')) {
                    $names[] = $name;
                }
                // Jump past the group so its members are not re-recorded
                // prefix-less by the outer loop.
                $index = $member;
                continue;
            }
            // A lone identifier (T_STRING) or fully-qualified GLOBAL symbol
            // (\RuntimeException, \DIRECTORY_SEPARATOR) is not a namespace
            // reference.
            if (str_contains($name, '\\')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The token's namespace-name reading, or null for any token that cannot
     * be one. Token names, not T_* constants: PHPCS (scanned by PHPStan)
     * polyfills the same constants as strings, poisoning their type. Plain
     * T_STRING identifiers only count where the caller knows they sit in
     * name position (a grouped-use prefix or member).
     */
    private function nameOf(PhpToken $token): ?string
    {
        return match ($token->getTokenName()) {
            'T_STRING' => $token->text,
            'T_NAME_QUALIFIED' => $token->text,
            'T_NAME_FULLY_QUALIFIED' => ltrim($token->text, '\\'),
            default => null,
        };
    }
}
