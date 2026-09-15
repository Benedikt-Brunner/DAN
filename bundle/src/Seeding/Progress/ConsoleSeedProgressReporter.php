<?php

declare(strict_types=1);

namespace Dan\Probe\Seeding\Progress;

use Symfony\Component\Console\Output\OutputInterface;

final readonly class ConsoleSeedProgressReporter implements SeedProgressReporter
{
    public function __construct(
        private OutputInterface $output,
    ) {}

    public function seeding(string $what, int $total): void
    {
        $this->output->writeln(sprintf('Seeding %d %s...', $total, $what));
    }

    public function seeded(int $seeded, int $total): void
    {
        $this->output->writeln(sprintf('  %d / %d', $seeded, $total));
    }

    public function finished(): void
    {
        $this->output->writeln('Done.');
    }
}
