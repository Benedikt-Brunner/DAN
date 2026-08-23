<?php

declare(strict_types=1);

namespace Dan\Harness\Report;

use Dan\Harness\Comparison\CellComparison;
use Dan\Harness\Comparison\RunComparison;
use Dan\Harness\Gate\Violation;
use Dan\Harness\Gate\ViolationKind;
use Dan\Harness\RunStore\Artifact\RunManifest;

/**
 * PR-comment-ready markdown diff report.
 * TODO: HTML report renderer for local runs / future GUI (reads index.sqlite).
 */
final class MarkdownReportRenderer
{
    /**
     * @param list<Violation> $violations
     */
    public function render(RunComparison $comparison, array $violations = []): string
    {
        $markdown = new MarkdownBuilder();

        $this->appendRunSummary(
            markdown: $markdown,
            baseline: $comparison->baselineManifest,
            candidate: $comparison->candidateManifest,
        );
        $this->appendProtocol(markdown: $markdown, comparison: $comparison);
        $this->appendViolations(markdown: $markdown, violations: $violations);
        $this->appendCellTables(markdown: $markdown, cells: $comparison->cells);
        $this->appendMissingCells(markdown: $markdown, side: 'A', cells: $comparison->cellsOnlyInBaseline);
        $this->appendMissingCells(markdown: $markdown, side: 'B', cells: $comparison->cellsOnlyInCandidate);

        return $markdown->build();
    }

    private function appendRunSummary(MarkdownBuilder $markdown, RunManifest $baseline, RunManifest $candidate): void
    {
        $markdown
            ->heading(title: 'DAN profile diff', level: 1)
            ->blankLine()
            ->line('| | A (baseline) | B (candidate) |')
            ->line('|---|---|---|')
            ->tableRow([
                'Implementation',
                $baseline->implementationIdentity->label,
                $candidate->implementationIdentity->label,
            ])
            ->tableRow([
                'Identity',
                sprintf('`%s`', $baseline->implementationIdentity->id),
                sprintf('`%s`', $candidate->implementationIdentity->id),
            ])
            ->tableRow([
                'Recorded',
                $baseline->createdAt->format('Y-m-d H:i:s T'),
                $candidate->createdAt->format('Y-m-d H:i:s T'),
            ])
            ->blankLine();
    }

    private function appendProtocol(MarkdownBuilder $markdown, RunComparison $comparison): void
    {
        if (!$comparison->protocolsMatch) {
            $markdown
                ->line('> [!WARNING]')
                ->line('> The two runs were recorded under **different protocols**. Latency comparisons below are not meaningful.')
                ->blankLine();
        }

        $protocol = $comparison->baselineManifest->protocol;
        $scenarioFilter = $protocol->scenarioFilter === null
            ? ''
            : sprintf(', scenario filter `%s`', $protocol->scenarioFilter);

        $markdown
            ->line(sprintf(
                'Protocol: %d warmup + %d measured iterations in %d blocks%s.',
                $protocol->warmupIterations,
                $protocol->measuredIterations,
                $protocol->blocks,
                $scenarioFilter,
            ))
            ->blankLine();
    }

    /**
     * @param list<Violation> $violations
     */
    private function appendViolations(MarkdownBuilder $markdown, array $violations): void
    {
        if ($violations === []) {
            return;
        }

        $markdown->heading('Gate violations')->blankLine();
        foreach ($violations as $violation) {
            $markdown->line('- :x: ' . $this->describeViolation($violation));
        }
        $markdown->blankLine();
    }

    /**
     * @param list<CellComparison> $cells
     */
    private function appendCellTables(MarkdownBuilder $markdown, array $cells): void
    {
        foreach ($this->groupCells($cells) as $group => $groupCells) {
            $markdown
                ->heading($group)
                ->blankLine()
                ->tableRow([
                    'Scenario',
                    'Statements',
                    'SQL',
                    'Median A',
                    'Median B',
                    'Delta',
                    'p95 A',
                    'p95 B',
                ])
                ->line('|---|---|---|---:|---:|---:|---:|---:|');

            foreach ($groupCells as $cell) {
                $markdown->tableRow($this->formatCell($cell));
            }
            $markdown->blankLine();
        }
    }

    /**
     * @param list<CellComparison> $cells
     *
     * @return array<string, list<CellComparison>>
     */
    private function groupCells(array $cells): array
    {
        $grouped = [];
        foreach ($cells as $cell) {
            $grouped[$cell->tier->value . ' / ' . $cell->database->id()][] = $cell;
        }
        ksort($grouped);

        return $grouped;
    }

    /** @return list<string> */
    private function formatCell(CellComparison $cell): array
    {
        $sqlStatus = $cell->sqlChanged
            ? sprintf(':warning: changed (%s)', implode(', ', $cell->changedStatementIndices))
            : 'unchanged';
        if ($cell->divergent) {
            $sqlStatus .= ' :grey_question: divergent';
        }

        return [
            $cell->scenario->toString(),
            sprintf('%d -> %d', $cell->baselineStatementCount, $cell->candidateStatementCount),
            $sqlStatus,
            sprintf('%.2fms', $cell->baselineMedianWall->toMsFloat()),
            sprintf('%.2fms', $cell->candidateMedianWall->toMsFloat()),
            sprintf('%+.1f%%', $cell->wallDeltaPct()),
            sprintf('%.2fms', $cell->baselineP95Wall->toMsFloat()),
            sprintf('%.2fms', $cell->candidateP95Wall->toMsFloat()),
        ];
    }

    /**
     * @param list<string> $cells
     */
    private function appendMissingCells(MarkdownBuilder $markdown, string $side, array $cells): void
    {
        if ($cells === []) {
            return;
        }

        $formattedCells = array_map(fn (string $cell): string => sprintf('`%s`', $cell), $cells);
        $markdown
            ->line(sprintf('Cells only present in run %s: %s', $side, implode(', ', $formattedCells)))
            ->blankLine();
    }

    private function describeViolation(Violation $violation): string
    {
        $cell = $violation->cell;
        $cellName = sprintf('%s / %s / %s', $cell->scenario->toString(), $cell->tier->value, $cell->database->id());

        return match ($violation->kind) {
            ViolationKind::SqlChanged => sprintf(
                '%s: generated SQL changed (statements %s)',
                $cellName,
                implode(', ', $cell->changedStatementIndices),
            ),
            ViolationKind::WallRegression => sprintf(
                '%s: median wall time regressed %.1f%% (%.2fms -> %.2fms, limit %.1f%%)',
                $cellName,
                $cell->wallDeltaPct(),
                $cell->baselineMedianWall->toMsFloat(),
                $cell->candidateMedianWall->toMsFloat(),
                // Non-null by Violation's constructor invariant.
                (float) $violation->limitPct,
            ),
        };
    }
}
