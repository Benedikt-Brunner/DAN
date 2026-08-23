<?php

declare(strict_types=1);

namespace Dan\Harness\Dev\PhpCs\Sniffs\Arrays;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\AbstractArraySniff;
use PHP_CodeSniffer\Util\Tokens;
use RuntimeException;

/**
 * Requires arrays with multiple entries to put every entry on its own line.
 */
final class MultiElementArraySniff extends AbstractArraySniff
{
    /**
     * @param File $phpcsFile
     * @param int $stackPtr
     * @param int $arrayStart
     * @param int $arrayEnd
     * @param list<array{index_start?: int, index_end?: int, arrow?: int, value_start: int}> $indices
     */
    protected function processSingleLineArray($phpcsFile, $stackPtr, $arrayStart, $arrayEnd, $indices): void
    {
        $this->processArray(
            phpcsFile: $phpcsFile,
            arrayStart: $arrayStart,
            arrayEnd: $arrayEnd,
            indices: $indices,
        );
    }

    /**
     * @param File $phpcsFile
     * @param int $stackPtr
     * @param int $arrayStart
     * @param int $arrayEnd
     * @param list<array{index_start?: int, index_end?: int, arrow?: int, value_start: int}> $indices
     */
    protected function processMultiLineArray($phpcsFile, $stackPtr, $arrayStart, $arrayEnd, $indices): void
    {
        $this->processArray(
            phpcsFile: $phpcsFile,
            arrayStart: $arrayStart,
            arrayEnd: $arrayEnd,
            indices: $indices,
        );
    }

    /**
     * @param list<array{index_start?: int, index_end?: int, arrow?: int, value_start: int}> $indices
     */
    private function processArray(File $phpcsFile, int $arrayStart, int $arrayEnd, array $indices): void
    {
        if (count($indices) < 2) {
            return;
        }

        $tokens = $phpcsFile->getTokens();
        $firstTokenOnLine = $phpcsFile->findFirstOnLine(\T_WHITESPACE, $arrayStart, true);
        if ($firstTokenOnLine === false) {
            return;
        }
        $baseIndent = str_repeat(' ', $this->tokenMetadata(tokens: $tokens, pointer: $firstTokenOnLine, key: 'column') - 1);
        $entryIndent = $baseIndent . '    ';

        foreach ($indices as $position => $index) {
            $entryStart = $index['index_start'] ?? $index['value_start'];
            $previousContent = $position === 0
                ? $arrayStart
                : $phpcsFile->findPrevious(Tokens::$emptyTokens, $entryStart - 1, $arrayStart, true);
            if (
                $previousContent === false
                || $this->tokenMetadata(tokens: $tokens, pointer: $previousContent, key: 'line')
                    < $this->tokenMetadata(tokens: $tokens, pointer: $entryStart, key: 'line')
            ) {
                continue;
            }

            $fix = $phpcsFile->addFixableError(
                $position === 0
                    ? 'The first entry in a multi-entry array must be on a new line'
                    : 'Every entry in a multi-entry array must be on a new line',
                $entryStart,
                'EntryNotOnOwnLine',
            );
            if ($fix) {
                $this->replaceWhitespaceBefore(
                    phpcsFile: $phpcsFile,
                    token: $entryStart,
                    whitespace: $phpcsFile->eolChar . $entryIndent,
                );
            }
        }

        $lastContent = $phpcsFile->findPrevious(Tokens::$emptyTokens, $arrayEnd - 1, $arrayStart, true);
        if (
            $lastContent !== false
            && $this->tokenMetadata(tokens: $tokens, pointer: $lastContent, key: 'line')
                === $this->tokenMetadata(tokens: $tokens, pointer: $arrayEnd, key: 'line')
        ) {
            $fix = $phpcsFile->addFixableError(
                'The closing bracket of a multi-entry array must be on a new line',
                $arrayEnd,
                'ClosingBracketNotOnOwnLine',
            );
            if ($fix) {
                $this->replaceWhitespaceBefore(
                    phpcsFile: $phpcsFile,
                    token: $arrayEnd,
                    whitespace: $phpcsFile->eolChar . $baseIndent,
                );
            }
        }
    }

    private function replaceWhitespaceBefore(File $phpcsFile, int $token, string $whitespace): void
    {
        $tokens = $phpcsFile->getTokens();
        if ($this->isWhitespace(tokens: $tokens, pointer: $token - 1)) {
            $phpcsFile->fixer->replaceToken($token - 1, $whitespace);

            return;
        }

        $phpcsFile->fixer->addContentBefore($token, $whitespace);
    }

    /**
     * @param array<mixed> $tokens
     */
    private function tokenMetadata(array $tokens, int $pointer, string $key): int
    {
        $token = $tokens[$pointer] ?? null;
        if (!is_array($token) || !isset($token[$key]) || !is_int($token[$key])) {
            throw new RuntimeException(sprintf('Expected integer token metadata "%s" at pointer %d.', $key, $pointer));
        }

        return $token[$key];
    }

    /**
     * @param array<mixed> $tokens
     */
    private function isWhitespace(array $tokens, int $pointer): bool
    {
        $token = $tokens[$pointer] ?? null;

        return is_array($token) && ($token['code'] ?? null) === \T_WHITESPACE;
    }
}
