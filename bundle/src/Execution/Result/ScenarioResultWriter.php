<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Result;

use Dan\Lib\Filesystem\Path;
use RuntimeException;

/** @api Symfony service instantiated by the dependency-injection container. */
final readonly class ScenarioResultWriter
{
    public function write(Path $outputDirectory, ScenarioResult $result): void
    {
        $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $result->scenario()) . '.json';
        $filePath = $outputDirectory->join($fileName);
        $written = @file_put_contents(
            $filePath->toString(),
            json_encode($result->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n",
        );
        if ($written === false) {
            throw new RuntimeException(sprintf('Could not write the scenario result for "%s" to "%s" - a silently dropped result would falsify the measurement.', $result->scenario(), $filePath->toString()));
        }
    }
}
