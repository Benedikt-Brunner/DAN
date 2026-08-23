<?php

declare(strict_types=1);

namespace Dan\Probe\Seeding\Progress;

interface SeedProgressReporter
{
    public function seedingCategories(int $total): void;

    public function seedingProducts(int $total): void;

    public function productsSeeded(int $seeded, int $total): void;

    public function seedingSyntheticBlobs(int $total): void;

    public function syntheticBlobsSeeded(int $seeded, int $total): void;

    public function finished(): void;
}
