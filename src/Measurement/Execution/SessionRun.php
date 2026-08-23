<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Execution;

use Dan\Harness\Implementation\Identity\Identity;
use Dan\Harness\Implementation\Runtime\Runtime;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Harness\RunStore\Filesystem\RunDirectory;

/**
 * One DAL implementation participating in a measurement session: its
 * identity, executable runtime and run directory, under a stable comparison
 * role. Keeping these together guarantees a
 * measurement block always executes against the runtime - and records into the
 * run directory - of the same implementation.
 */
final class SessionRun
{
    public function __construct(
        public readonly RunSlot $slot,
        public readonly Identity $identity,
        public readonly RunDirectory $directory,
        public readonly Runtime $runtime,
    ) {}
}
