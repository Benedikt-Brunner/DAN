<?php

declare(strict_types=1);

namespace Dan\Probe\Seeding\Progress;

use Symfony\Component\Console\Output\OutputInterface;

final readonly class ConsoleSeedProgressReporter implements SeedProgressReporter
{
    public function __construct(
        private OutputInterface $output,
    ) {}

    public function seedingCategories(int $total): void
    {
        $this->output->writeln(sprintf('Seeding %d categories...', $total));
    }

    public function seedingProducts(int $total): void
    {
        $this->output->writeln(sprintf('Seeding %d products...', $total));
    }

    public function productsSeeded(int $seeded, int $total): void
    {
        $this->output->writeln(sprintf('  %d / %d', $seeded, $total));
    }

    public function seedingSyntheticBlobs(int $total): void
    {
        $this->output->writeln(sprintf('Seeding %d synthetic blobs...', $total));
    }

    public function syntheticBlobsSeeded(int $seeded, int $total): void
    {
        $this->output->writeln(sprintf('  %d / %d', $seeded, $total));
    }

    public function finished(): void
    {
        $this->output->writeln('Done.');
    }
}
