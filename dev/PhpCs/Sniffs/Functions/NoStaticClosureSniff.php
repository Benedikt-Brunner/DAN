<?php

declare(strict_types=1);

namespace Dan\Harness\Dev\PhpCs\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use RuntimeException;

/**
 * Prohibits static arrow functions and static anonymous functions.
 */
final class NoStaticClosureSniff implements Sniff
{
    /**
     * @return list<int>
     */
    public function register(): array
    {
        return [\T_STATIC];
    }

    public function process(File $phpcsFile, $stackPtr): void
    {
        $function = $phpcsFile->findNext(Tokens::$emptyTokens, $stackPtr + 1, null, true);
        if ($function === false) {
            return;
        }

        $tokens = $phpcsFile->getTokens();
        $functionCode = $this->tokenCode(tokens: $tokens, pointer: $function);
        if ($functionCode === \T_FN || $functionCode === \T_CLOSURE) {
            $this->addError(phpcsFile: $phpcsFile, stackPtr: $stackPtr);
        }
    }

    private function addError(File $phpcsFile, int $stackPtr): void
    {
        $fix = $phpcsFile->addFixableError(
            'Inline functions must not be declared static',
            $stackPtr,
            'StaticClosure',
        );
        if (!$fix) {
            return;
        }

        $tokens = $phpcsFile->getTokens();
        $phpcsFile->fixer->beginChangeset();
        $phpcsFile->fixer->replaceToken($stackPtr, '');
        $followingToken = $tokens[$stackPtr + 1] ?? null;
        if (is_array($followingToken) && ($followingToken['code'] ?? null) === \T_WHITESPACE) {
            $phpcsFile->fixer->replaceToken($stackPtr + 1, '');
        }
        $phpcsFile->fixer->endChangeset();
    }

    /**
     * @param array<mixed> $tokens
     */
    private function tokenCode(array $tokens, int $pointer): int|string
    {
        $token = $tokens[$pointer] ?? null;
        $code = is_array($token) ? ($token['code'] ?? null) : null;
        if (!is_int($code) && !is_string($code)) {
            throw new RuntimeException(sprintf('Expected token code at pointer %d.', $pointer));
        }

        return $code;
    }
}
