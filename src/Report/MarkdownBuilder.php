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

    /**
     * A fenced block of verbatim text. The fence outgrows any backtick run
     * inside the content, so engine output (MySQL quotes identifiers in
     * backticks) can never close it early.
     */
    public function codeBlock(string $content): self
    {
        preg_match_all('/`+/', $content, $runs);
        $longestRun = 2;
        foreach ($runs[0] as $run) {
            $longestRun = max($longestRun, strlen($run));
        }
        $fence = str_repeat('`', $longestRun + 1);

        return $this->line($fence)->line(rtrim($content, "\n"))->line($fence);
    }

    public function build(): string
    {
        return implode("\n", $this->lines);
    }
}
