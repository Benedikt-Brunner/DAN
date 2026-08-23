<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\PhpCs;

use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\LocalFile;
use PHP_CodeSniffer\Ruleset;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MultiElementArraySniffTest extends TestCase
{
    public function testAcceptsSingleEntryAndMultilineArrays(): void
    {
        $file = $this->lint(<<<'PHP'
            <?php

            declare(strict_types=1);

            $single = ['one'];
            $multiple = [
                'one',
                'two',
            ];
            PHP);

        self::assertSame(0, $file->getErrorCount());
    }

    public function testRejectsAndFixesMultipleEntriesOnOneLine(): void
    {
        $file = $this->lint(<<<'PHP'
            <?php

            declare(strict_types=1);

            $value = count(['one', 'two']);
            PHP);

        self::assertSame(3, $file->getErrorCount());
        self::assertSame(3, $file->getFixableCount());

        $file->fixer->fixFile();

        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            $value = count([
                'one',
                'two'
            ]);
            PHP . "\n", $file->fixer->getContents());
    }

    private function lint(string $code): LocalFile
    {
        require_once dirname(__DIR__, 2) . '/vendor/squizlabs/php_codesniffer/autoload.php';
        if (!defined('PHP_CODESNIFFER_VERBOSITY')) {
            define('PHP_CODESNIFFER_VERBOSITY', 0);
        }
        if (!defined('PHP_CODESNIFFER_CBF')) {
            define('PHP_CODESNIFFER_CBF', false);
        }

        $path = tempnam(sys_get_temp_dir(), 'dan-phpcs-');
        if ($path === false || file_put_contents($path, $code . "\n") === false) {
            throw new RuntimeException('Unable to create the temporary PHPCS fixture.');
        }

        try {
            $config = new Config(cliArgs: ['--standard=' . dirname(__DIR__, 2) . '/phpcs.xml.dist']);
            $config->cache = false;
            $ruleset = new Ruleset($config);
            $file = new LocalFile($path, $ruleset, $config);
            $file->process();

            return $file;
        } finally {
            unlink($path);
        }
    }
}
