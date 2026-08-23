<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Report;

use Dan\Harness\Comparison\RunComparison;
use Dan\Harness\Implementation\Identity\Identity;
use Dan\Harness\Implementation\Reference\ReferenceType;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Harness\Protocol\Protocol;
use Dan\Harness\Report\MarkdownReportRenderer;
use Dan\Harness\RunStore\Artifact\RunManifest;
use Dan\Lib\Protocol\Tier;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MarkdownReportRendererTest extends TestCase
{
    public function testRendersRunSummaryProtocolWarningAndMissingCells(): void
    {
        $database = new DatabaseTarget(engine: Engine::MySql, version: '8.0');
        $protocol = new Protocol(
            databases: [$database],
            tiers: [Tier::S],
            warmupIterations: 1,
            measuredIterations: 10,
            blocks: 2,
            scenarioFilter: 'product.*',
        );
        $comparison = new RunComparison(
            baselineManifest: $this->manifest(id: 'baseline-id', label: 'Baseline', recordedAt: '2026-08-20T10:00:00+00:00', protocol: $protocol),
            candidateManifest: $this->manifest(id: 'candidate-id', label: 'Candidate', recordedAt: '2026-08-21T11:00:00+00:00', protocol: $protocol),
            protocolsMatch: false,
            cells: [],
            cellsOnlyInBaseline: ['baseline-only.json'],
            cellsOnlyInCandidate: ['candidate-only.json'],
        );

        $report = (new MarkdownReportRenderer())->render(comparison: $comparison);

        self::assertSame(<<<'MARKDOWN'
# DAN profile diff

| | A (baseline) | B (candidate) |
|---|---|---|
| Implementation | Baseline | Candidate |
| Identity | `baseline-id` | `candidate-id` |
| Recorded | 2026-08-20 10:00:00 GMT+0000 | 2026-08-21 11:00:00 GMT+0000 |

> [!WARNING]
> The two runs were recorded under **different protocols**. Latency comparisons below are not meaningful.

Protocol: 1 warmup + 10 measured iterations in 2 blocks, scenario filter `product.*`.

Cells only present in run A: `baseline-only.json`

Cells only present in run B: `candidate-only.json`

MARKDOWN, $report);
    }

    private function manifest(string $id, string $label, string $recordedAt, Protocol $protocol): RunManifest
    {
        return new RunManifest(
            runId: $id,
            createdAt: new DateTimeImmutable($recordedAt),
            implementationReferenceType: ReferenceType::Release,
            implementationReference: 'v6.6.0.0',
            implementationIdentity: new Identity(id: $id, label: $label),
            protocol: $protocol,
        );
    }
}
