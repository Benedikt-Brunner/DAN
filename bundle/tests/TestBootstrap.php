<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Shopware\Core\TestBootstrapper;
use Symfony\Component\Filesystem\Filesystem;

// Kernel integration tests need a running MySQL/MariaDB; point DATABASE_URL
// at one (e.g. a dan-started container). Without it the tests skip.
$databaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;
if (!is_string($databaseUrl)) {
    return;
}

// Mirror of TestBootstrapper::dbExists() - the probe it uses to decide
// whether to install Shopware in-process.
$danTestSchemaExists = function (string $databaseUrl): bool {
    $parts = parse_url($databaseUrl);
    if ($parts === false || !isset($parts['host'], $parts['path'])) {
        return false;
    }

    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 3306, ltrim($parts['path'], '/')),
            $parts['user'] ?? 'root',
            isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ],
        );
        $pdo->query('SELECT 1 FROM `plugin`');

        return true;
    } catch (Throwable) {
        return false;
    }
};

// When the schema is missing, TestBootstrapper installs Shopware in-process.
// That installation runs cache:clear, which compiles the new container in a
// temporary build directory and renames it into place afterwards - and a
// kernel booted from a cache built by a PREVIOUS process then requires
// lazy-ghost files from the pre-rename path, crashing the first delete event
// with "Failed opening required .../CacheTracerGhost*.php". Wiping any
// pre-existing cache whenever an install is coming makes installs always
// start cold (which is reliable); warm reruns against an installed database
// keep their cache and stay fast.
if (($_SERVER['FORCE_INSTALL'] ?? false) || !$danTestSchemaExists($databaseUrl)) {
    (new Filesystem())->remove(__DIR__ . '/../var/cache');
}

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_SECRET'] ??= 'dan-not-a-secret';
$_ENV['APP_SECRET'] ??= 'dan-not-a-secret';
$_SERVER['APP_URL'] ??= 'http://localhost:8000';
$_ENV['APP_URL'] ??= 'http://localhost:8000';

(new TestBootstrapper())
    ->setProjectDir(__DIR__ . '/..')
    ->setPlatformEmbedded(false)
    ->setForceInstall(false)
    ->bootstrap();

// From here on, every kernel the tests boot must record on its kernel
// connection - the same wiring bin/dan-console applies in a measured runtime.
// Booting with reuseConnection: false makes KernelLifecycleManager pull the
// connection from Kernel::getConnection(), which serves the armed one.
Dan\Probe\Tests\RecordingKernel::armRecording();
Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager::ensureKernelShutdown();
Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager::bootKernel(reuseConnection: false);
