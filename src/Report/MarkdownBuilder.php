<?php

declare(strict_types=1);

namespace Dan\Harness\Report;

final class MarkdownBuilder
{
    /** @var list<string> */
    private array $lines = [];

    public function line(string $line): self
    {
        $this->lines[] = $line;

        return $this;
    }

    public function blankLine(): self
    {
        return $this->line('');
    }

    public function heading(string $title, int $level = 2): self
    {
        return $this->line(str_repeat('#', $level) . ' ' . $title);
    }

    /**
     * @param list<string> $cells
     */
    public function tableRow(array $cells): self
    {
        return $this->line('| ' . implode(' | ', $cells) . ' |');
    }

    public function build(): string
    {
        return implode("\n", $this->lines);
    }
}
