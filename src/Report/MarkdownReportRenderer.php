<?php

declare(strict_types=1);

namespace Dan\Harness\Report;

use Dan\Harness\Comparison\AlignedStatement;
use Dan\Harness\Comparison\AlignmentKind;
use Dan\Harness\Comparison\BlockComparison;
use Dan\Harness\Comparison\CellComparison;
use Dan\Harness\Comparison\RunComparison;
use Dan\Harness\Comparison\StatementInstability;
use Dan\Harness\Comparison\StatementPlanComparison;
use Dan\Harness\Environment\DatabaseImage;
use Dan\Harness\Environment\ExecutionEnvironment;
use Dan\Harness\Gate\Violation;
use Dan\Harness\Gate\ViolationKind;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Harness\RunStore\Artifact\RunManifest;
use Dan\Lib\Protocol\StatementDivergence;
use Dan\Lib\Time\Duration;

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
        $this->appendResultDivergence(markdown: $markdown, cells: $comparison->cells);
        $this->appendCellTables(markdown: $markdown, cells: $comparison->cells);
        $this->appendQueryPlans(markdown: $markdown, cells: $comparison->cells);
        $this->appendBlockDiagnostics(markdown: $markdown, cells: $comparison->cells);
        $this->appendMissingCells(markdown: $markdown, side: 'A', cells: $comparison->cellsOnlyInBaseline);
        $this->appendMissingCells(markdown: $markdown, side: 'B', cells: $comparison->cellsOnlyInCandidate);
        $this->appendReproducibility(
            markdown: $markdown,
            baseline: $comparison->baselineManifest->environment,
            candidate: $comparison->candidateManifest->environment,
        );

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

        if (!$comparison->environmentsComparable) {
            $markdown
                ->line('> [!WARNING]')
                ->line('> The two runs were recorded by **different DAN revisions or database images** (see Reproducibility). SQL comparisons may reflect the tooling or the image rather than the implementation.')
                ->blankLine();
        }

        $protocol = $comparison->baselineManifest->protocol;
        $scenarioFilter = $protocol->scenarioFilter === null
            ? ''
            : sprintf(', scenario filter `%s`', $protocol->scenarioFilter);

        $markdown
            ->line(sprintf(
                'Protocol: %d warmup + %d measured iterations in %d blocks, %d warmup at the start of every block%s.',
                $protocol->warmupIterations,
                $protocol->measuredIterations,
                $protocol->blocks,
                $protocol->blockWarmupIterations,
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
     * Correctness comes before performance: a cell whose implementations
     * returned different results is listed here, ahead of every latency
     * table, with what exactly differed.
     *
     * @param list<CellComparison> $cells
     */
    private function appendResultDivergence(MarkdownBuilder $markdown, array $cells): void
    {
        $diverging = array_values(array_filter($cells, fn (CellComparison $cell): bool => !$cell->resultSets->equivalent()));
        if ($diverging === []) {
            return;
        }

        $markdown
            ->heading('Result divergence')
            ->blankLine()
            ->line('> [!CAUTION]')
            ->line('> The two implementations did not return the same result for these cells. Their latency deltas compare different work and must not be read as performance.')
            ->blankLine();
        foreach ($diverging as $cell) {
            $markdown->line(sprintf(
                '- %s / %s / %s: %s',
                $cell->scenario->toString(),
                $cell->tier->value,
                $cell->database->id(),
                $this->describeResultDivergence($cell),
            ));
        }
        $markdown->blankLine();
    }

    private function describeResultDivergence(CellComparison $cell): string
    {
        $resultSets = $cell->resultSets;
        $facts = [];
        if ($resultSets->idsDiffer()) {
            $facts[] = sprintf('different ids (%d in A, %d in B)', count($resultSets->baseline->ids), count($resultSets->candidate->ids));
        }
        if ($resultSets->orderDiffers()) {
            $facts[] = 'same ids in a different order';
        }
        if ($resultSets->totalDiffers()) {
            $facts[] = sprintf('total %d in A, %d in B', $resultSets->baseline->total, $resultSets->candidate->total);
        }
        foreach ($resultSets->inconsistentRuns() as $slot) {
            $facts[] = sprintf('%s returned varying results between its own iterations', $slot === RunSlot::Baseline ? 'A' : 'B');
        }

        return implode('; ', $facts);
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
                    'Result',
                    'Statements',
                    'SQL',
                    'Median A',
                    'Median B',
                    'Delta',
                    'p95 A',
                    'p95 B',
                ])
                ->line('|---|---|---|---|---:|---:|---:|---:|---:|');

            $p95IndicativeOnly = false;
            foreach ($groupCells as $cell) {
                $markdown->tableRow($this->formatCell($cell));
                $p95IndicativeOnly = $p95IndicativeOnly || $cell->p95IsIndicativeOnly();
            }
            $markdown->blankLine();
            if ($p95IndicativeOnly) {
                $markdown
                    ->line(sprintf('Delta: estimated median shift with its %d%% bootstrap interval. \* p95 from fewer than %d samples is close to the largest observed value and only indicative.', $this->confidencePct($groupCells), CellComparison::RELIABLE_P95_SAMPLES))
                    ->blankLine();
            } else {
                $markdown
                    ->line(sprintf('Delta: estimated median shift with its %d%% bootstrap interval.', $this->confidencePct($groupCells)))
                    ->blankLine();
            }
        }
    }

    /**
     * @param list<CellComparison> $cells non-empty
     */
    private function confidencePct(array $cells): int
    {
        return (int) round($cells[0]->wallShift->confidence * 100);
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
        $sqlStatus = $cell->sqlChanged()
            ? sprintf(':warning: changed (%s)', $this->describeChanges($cell))
            : 'unchanged';
        if ($cell->hasUnstableStatements()) {
            $sqlStatus .= ' :grey_question: ' . implode('; ', array_map($this->describeInstability(...), $cell->unstableStatements));
        }
        $delta = $this->formatShift($cell);
        if ($cell->blockEffectsDisagree()) {
            $delta .= ' :grey_question: blocks disagree';
        }

        return [
            $cell->scenario->toString(),
            $cell->resultSets->equivalent() ? 'identical' : ':x: differs',
            sprintf('%d -> %d', $cell->baselineStatementCount, $cell->candidateStatementCount),
            $sqlStatus,
            sprintf('%.2fms', $cell->baselineMedianWall->toMsFloat()),
            sprintf('%.2fms', $cell->candidateMedianWall->toMsFloat()),
            $delta,
            $this->formatP95(duration: $cell->baselineP95Wall, indicativeOnly: $cell->p95IsIndicativeOnly()),
            $this->formatP95(duration: $cell->candidateP95Wall, indicativeOnly: $cell->p95IsIndicativeOnly()),
        ];
    }

    /**
     * The aligned changes with their original statement positions: ~ modified
     * (baseline->candidate position when they differ), - removed from the
     * baseline, + inserted in the candidate, ? ambiguous alignment.
     */
    private function describeChanges(CellComparison $cell): string
    {
        return implode(', ', array_map($this->describeChange(...), $cell->alignment->changes()));
    }

    private function describeChange(AlignedStatement $item): string
    {
        $positions = match (true) {
            $item->baselineIndex !== null && $item->candidateIndex !== null => $item->baselineIndex === $item->candidateIndex
                ? sprintf('~%d', $item->baselineIndex)
                : sprintf('~%d->%d', $item->baselineIndex, $item->candidateIndex),
            $item->baselineIndex !== null => sprintf('-%d', $item->baselineIndex),
            default => sprintf('+%d', $item->candidateIndex),
        };

        return $item->kind === AlignmentKind::Ambiguous ? '?' . $positions : $positions;
    }

    /**
     * Which run, which position, what kind of divergence, and the subset of
     * iterations the position's timings actually describe.
     */
    private function describeInstability(StatementInstability $instability): string
    {
        $kind = match ($instability->divergence) {
            StatementDivergence::Text => 'SQL varies',
            StatementDivergence::Presence => 'intermittent',
            StatementDivergence::TextAndPresence => 'SQL varies, intermittent',
            StatementDivergence::None => 'stable',
        };

        return sprintf(
            '%s #%d %s (%d/%d)',
            $instability->slot === RunSlot::Baseline ? 'A' : 'B',
            $instability->index,
            $kind,
            $instability->observed,
            $instability->iterations,
        );
    }

    /**
     * The estimate followed by its interval, so a reader sees at a glance
     * whether a delta is a finding or noise around zero.
     */
    private function formatShift(CellComparison $cell): string
    {
        $shift = $cell->wallShift;

        return sprintf('%+.1f%% [%+.1f%%, %+.1f%%]', $shift->estimatePct, $shift->lowerPct, $shift->upperPct);
    }

    private function formatP95(Duration $duration, bool $indicativeOnly): string
    {
        return sprintf('%.2fms%s', $duration->toMsFloat(), $indicativeOnly ? '*' : '');
    }

    /**
     * Why changed SQL ran differently: the engine's plan on each side of
     * every aligned change, with the material differences called out. Plans
     * of unchanged statements stay in the cell artifacts.
     *
     * @param list<CellComparison> $cells
     */
    private function appendQueryPlans(MarkdownBuilder $markdown, array $cells): void
    {
        $cellsWithChanges = array_values(array_filter($cells, fn (CellComparison $cell): bool => $cell->planChanges !== []));
        if ($cellsWithChanges === []) {
            return;
        }

        $markdown
            ->heading('Query plans of changed statements')
            ->blankLine()
            ->line('Captured with `EXPLAIN FORMAT=JSON` after timing, bound to the parameter values the DAL used. Row counts are the optimizer\'s estimates.')
            ->blankLine()
            ->tableRow([
                'Cell',
                'Statement',
                'Plan A',
                'Plan B',
                'Material changes',
            ])
            ->line('|---|---|---|---|---|');
        foreach ($cellsWithChanges as $cell) {
            $cellName = sprintf('%s / %s / %s', $cell->scenario->toString(), $cell->tier->value, $cell->database->id());
            foreach ($cell->planChanges as $planChange) {
                $markdown->tableRow($this->formatPlanChange(cellName: $cellName, planChange: $planChange));
            }
        }
        $markdown->blankLine();
    }

    /** @return list<string> */
    private function formatPlanChange(string $cellName, StatementPlanComparison $planChange): array
    {
        $changes = $planChange->materialChanges();

        return [
            $cellName,
            $this->describeChange($planChange->statement),
            $planChange->describeBaseline(),
            $planChange->describeCandidate(),
            $changes === [] ? ($planChange->baselineFacts === null || $planChange->candidateFacts === null ? 'n/a' : 'none') : implode('; ', $changes),
        ];
    }

    /**
     * Per mirrored block pair, so order effects and drift over the session
     * are visible instead of averaged into the headline delta.
     *
     * @param list<CellComparison> $cells
     */
    private function appendBlockDiagnostics(MarkdownBuilder $markdown, array $cells): void
    {
        $cellsWithBlocks = array_values(array_filter($cells, fn (CellComparison $cell): bool => $cell->blocks !== []));
        if ($cellsWithBlocks === []) {
            return;
        }

        $markdown
            ->heading('Block diagnostics')
            ->blankLine()
            ->line('Median wall time per mirrored block pair. "Order" is which implementation ran first within the pair; a delta that flips sign between pairs points at an order effect or host drift rather than at the implementation.')
            ->blankLine()
            ->tableRow([
                'Cell',
                'Block',
                'Order',
                'Median A',
                'Median B',
                'Delta',
            ])
            ->line('|---|---:|---|---:|---:|---:|');
        foreach ($cellsWithBlocks as $cell) {
            $cellName = sprintf('%s / %s / %s', $cell->scenario->toString(), $cell->tier->value, $cell->database->id());
            foreach ($cell->blocks as $block) {
                $markdown->tableRow($this->formatBlock(cellName: $cellName, block: $block));
            }
        }
        $markdown->blankLine();
    }

    /** @return list<string> */
    private function formatBlock(string $cellName, BlockComparison $block): array
    {
        return [
            $cellName,
            (string) $block->blockIndex,
            $block->baselineRanFirst() ? 'A, B' : 'B, A',
            sprintf('%.2fms', $block->baselineMedianWall->toMsFloat()),
            sprintf('%.2fms', $block->candidateMedianWall->toMsFloat()),
            sprintf('%+.1f%%', $block->wallDeltaPct()),
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

    /**
     * The facts an outsider needs to reproduce or audit the measurement. One
     * column per run: within a session both are the same, across stored
     * profiles the differences are exactly what this table is for.
     */
    private function appendReproducibility(MarkdownBuilder $markdown, ExecutionEnvironment $baseline, ExecutionEnvironment $candidate): void
    {
        $markdown
            ->heading('Reproducibility')
            ->blankLine()
            ->line('| | A (baseline) | B (candidate) |')
            ->line('|---|---|---|');
        foreach ($this->environmentFacts($baseline) as $fact => $baselineValue) {
            $markdown->tableRow([
                $fact,
                $baselineValue,
                $this->environmentFacts($candidate)[$fact] ?? 'unknown',
            ]);
        }
        $markdown->blankLine();
    }

    /**
     * @return array<string, string> fact name => formatted value ("unknown" when not discovered)
     */
    private function environmentFacts(ExecutionEnvironment $environment): array
    {
        $engine = $environment->dockerEngine;

        return [
            'DAN revision' => sprintf('`%s`', substr($environment->danRevision, 0, 12)),
            'PHP' => $environment->phpVersion,
            'Composer' => $environment->composerVersion ?? 'unknown',
            'Host' => sprintf('%s, %s', $environment->host->operatingSystem, $environment->host->architecture),
            'Host CPU' => $environment->host->cpuModel ?? 'unknown',
            'Host CPU limit' => $environment->host->cpuLimit === null ? 'unknown' : sprintf('%.2f cores', $environment->host->cpuLimit),
            'Host memory limit' => $environment->host->memoryLimitBytes === null ? 'unknown' : sprintf('%.1f GiB', $environment->host->memoryLimitBytes / 1024 ** 3),
            'Docker engine' => $engine === null ? 'unknown' : sprintf('%s on %s, %s, %d CPUs, %.1f GiB', $engine->version, $engine->operatingSystem, $engine->architecture, $engine->cpus, $engine->memoryBytes / 1024 ** 3),
            'Database network path' => $environment->databaseNetworkPath->value . match ($engine?->userlandProxy) {
                true => ' (userland proxy)',
                false => ' (kernel NAT, userland proxy disabled)',
                null => '',
            },
            'Database images' => implode(', ', array_map(
                fn (DatabaseImage $image): string => sprintf('%s @ %s', $image->target->id(), $image->digest === null ? 'unknown digest' : '`' . substr($image->digest, 0, 19) . '`'),
                $environment->databaseImages,
            )),
        ];
    }

    private function describeViolation(Violation $violation): string
    {
        $cell = $violation->cell;
        $cellName = sprintf('%s / %s / %s', $cell->scenario->toString(), $cell->tier->value, $cell->database->id());

        return match ($violation->kind) {
            ViolationKind::ResultDivergence => sprintf(
                '%s: the implementations returned different results (%s)',
                $cellName,
                $this->describeResultDivergence($cell),
            ),
            ViolationKind::SqlChanged => sprintf(
                '%s: generated SQL changed (%s)',
                $cellName,
                $this->describeChanges($cell),
            ),
            ViolationKind::WallRegression => sprintf(
                '%s: median wall time regressed %.1f%% (%d%% interval [%+.1f%%, %+.1f%%] excludes zero; %.2fms -> %.2fms, limit %.1f%%)',
                $cellName,
                $cell->wallDeltaPct(),
                (int) round($cell->wallShift->confidence * 100),
                $cell->wallShift->lowerPct,
                $cell->wallShift->upperPct,
                $cell->baselineMedianWall->toMsFloat(),
                $cell->candidateMedianWall->toMsFloat(),
                // Non-null by Violation's constructor invariant.
                (float) $violation->limitPct,
            ),
        };
    }
}
