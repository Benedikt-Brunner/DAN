<?php

declare(strict_types=1);

namespace Dan\Probe\Seeding\Progress;

interface SeedProgressReporter
{
    /** A new kind of rows starts being written. */
    public function seeding(SeededRows $rows, int $total): void;

    public function seeded(int $seeded, int $total): void;

    public function finished(): void;
}
