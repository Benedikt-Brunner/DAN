<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Result;

use Dan\Lib\Filesystem\Path;

/** @api Symfony service instantiated by the dependency-injection container. */
final readonly class ScenarioResultWriter
{
    public function write(Path $outputDirectory, ScenarioResult $result): void
    {
        $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $result->scenario()) . '.json';
        file_put_contents(
            $outputDirectory->join($fileName)->toString(),
            json_encode($result->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n",
        );
    }
}
