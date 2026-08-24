<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Report;

use Dan\Harness\Comparison\CellComparison;
use Dan\Harness\Comparison\RunComparison;
use Dan\Harness\Gate\Policy;
use Dan\Harness\Gate\Violation;
use Dan\Harness\Implementation\Identity\Identity;
use Dan\Harness\Implementation\Reference\ReferenceType;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Harness\Protocol\Protocol;
use Dan\Harness\Report\MarkdownReportRenderer;
use Dan\Harness\RunStore\Artifact\RunManifest;
use Dan\Lib\Protocol\ScenarioName;
use Dan\Lib\Protocol\Tier;
use Dan\Lib\Time\Duration;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file snapshots of the markdown diff report over the comparison
 * shapes DAN produces: a clean A/A, an SQL change, a gate-violating
 * regression, and a protocol mismatch. Formatting changes surface as
 * reviewable snapshot diffs; run.
 *
 *   DAN_UPDATE_SNAPSHOTS=1 vendor/bin/pest tests/Report
 *
 * to accept an intended change, then review the .md diff like any other.
 */
final class MarkdownReportSnapshotTest extends TestCase
{
    #[DataProvider('reportCases')]
    public function testRenderedReportMatchesSnapshot(string $snapshot): void
    {
        $case = self::comparisonCases()[$snapshot];
        $rendered = (new MarkdownReportRenderer())->render(comparison: $case['comparison'], violations: $case['violations']);

        $path = __DIR__ . '/snapshots/' . $snapshot . '.md';
        if (getenv('DAN_UPDATE_SNAPSHOTS') === '1') {
            file_put_contents($path, $rendered);
        }

        self::assertFileExists($path, 'Missing snapshot - run DAN_UPDATE_SNAPSHOTS=1 to create it.');
        self::assertStringEqualsFile($path, $rendered, sprintf(
            'The rendered report diverged from tests/Report/snapshots/%s.md. If the change is intended, regenerate with DAN_UPDATE_SNAPSHOTS=1 and review the snapshot diff.',
            $snapshot,
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reportCases(): iterable
    {
        foreach (array_keys(self::comparisonCases()) as $snapshot) {
            yield $snapshot => [$snapshot];
        }
    }

    /**
     * @return array<string, array{comparison: RunComparison, violations: list<Violation>}>
     */
    private static function comparisonCases(): array
    {
        $mysql = new DatabaseTarget(engine: Engine::MySql, version: '8.0');
        $mariadb = new DatabaseTarget(engine: Engine::MariaDb, version: '11.4');
        $protocol = self::protocol();

        $cleanCells = [
            self::cell(scenario: 'product.deep-read', tier: Tier::S, database: $mysql, medianMs: [
                12.5,
                12.5,
            ], p95Ms: [
                14.1,
                14.1,
            ]),
            self::cell(scenario: 'product.keyword-listing', tier: Tier::S, database: $mysql, medianMs: [
                8.2,
                8.2,
            ], p95Ms: [
                9.9,
                9.9,
            ]),
            self::cell(scenario: 'product.deep-read', tier: Tier::M, database: $mariadb, medianMs: [
                47.3,
                47.3,
            ], p95Ms: [
                55.0,
                55.0,
            ]),
        ];

        $sqlChangeCells = [
            self::cell(scenario: 'product.deep-read', tier: Tier::S, database: $mysql, medianMs: [
                12.5,
                12.6,
            ], p95Ms: [
                14.1,
                14.3,
            ], changedIndices: [
                1,
                3,
            ], statementCounts: [
                4,
                5,
            ]),
            self::cell(scenario: 'synthetic.json-path', tier: Tier::S, database: $mysql, medianMs: [
                3.0,
                3.1,
            ], p95Ms: [
                3.4,
                3.5,
            ], divergent: true),
        ];

        $regressionCells = [
            self::cell(scenario: 'product.deep-read', tier: Tier::S, database: $mysql, medianMs: [
                12.5,
                19.4,
            ], p95Ms: [
                14.1,
                26.0,
            ]),
            self::cell(scenario: 'product.keyword-listing', tier: Tier::S, database: $mysql, medianMs: [
                8.2,
                8.3,
            ], p95Ms: [
                9.9,
                10.0,
            ], changedIndices: [0]),
        ];
        // The violations come from the real gate, so the report renders what
        // CI would actually enforce.
        $violations = (new Policy(maxWallRegressionPct: 10.0, failOnSqlChange: true))->evaluate($regressionCells);

        return [
            'clean-aa' => [
                'comparison' => new RunComparison(
                    baselineManifest: self::manifest(id: 'baseline-aaaaaaaa', label: 'v6.6.10.22', recordedAt: '2026-08-20 10:00:00', protocol: $protocol),
                    candidateManifest: self::manifest(id: 'candidate-aaaaaaa', label: 'v6.6.10.22', recordedAt: '2026-08-20 10:20:00', protocol: $protocol),
                    protocolsMatch: true,
                    cells: $cleanCells,
                    cellsOnlyInBaseline: [],
                    cellsOnlyInCandidate: [],
                ),
                'violations' => [],
            ],
            'sql-change' => [
                'comparison' => new RunComparison(
                    baselineManifest: self::manifest(id: 'baseline-bbbbbbbb', label: 'v6.6.10.22', recordedAt: '2026-08-20 10:00:00', protocol: $protocol),
                    candidateManifest: self::manifest(id: 'candidate-bbbbbbb', label: 'local checkout', recordedAt: '2026-08-20 10:20:00', protocol: $protocol),
                    protocolsMatch: true,
                    cells: $sqlChangeCells,
                    cellsOnlyInBaseline: [],
                    cellsOnlyInCandidate: [],
                ),
                'violations' => [],
            ],
            'regression-with-violations' => [
                'comparison' => new RunComparison(
                    baselineManifest: self::manifest(id: 'baseline-cccccccc', label: 'v6.6.10.22', recordedAt: '2026-08-20 10:00:00', protocol: $protocol),
                    candidateManifest: self::manifest(id: 'candidate-ccccccc', label: 'local checkout', recordedAt: '2026-08-20 10:20:00', protocol: $protocol),
                    protocolsMatch: true,
                    cells: $regressionCells,
                    cellsOnlyInBaseline: [],
                    cellsOnlyInCandidate: [],
                ),
                'violations' => $violations,
            ],
            'protocol-mismatch' => [
                'comparison' => new RunComparison(
                    baselineManifest: self::manifest(id: 'baseline-dddddddd', label: 'v6.6.10.22', recordedAt: '2026-08-20 10:00:00', protocol: $protocol),
                    candidateManifest: self::manifest(id: 'candidate-ddddddd', label: 'v6.7.0.0', recordedAt: '2026-08-21 09:00:00', protocol: self::protocol(measuredIterations: 60)),
                    protocolsMatch: false,
                    cells: [],
                    cellsOnlyInBaseline: ['product.deep-read--S--mysql-8.0.json'],
                    cellsOnlyInCandidate: ['order.aggregation--S--mysql-8.0.json'],
                ),
                'violations' => [],
            ],
        ];
    }

    private static function protocol(int $measuredIterations = 30): Protocol
    {
        return new Protocol(
            databases: [new DatabaseTarget(engine: Engine::MySql, version: '8.0')],
            tiers: [Tier::S],
            warmupIterations: 5,
            measuredIterations: $measuredIterations,
            blocks: 4,
            scenarioFilter: null,
        );
    }

    private static function manifest(string $id, string $label, string $recordedAt, Protocol $protocol): RunManifest
    {
        return new RunManifest(
            runId: $id,
            createdAt: new DateTimeImmutable($recordedAt . '+00:00'),
            implementationReferenceType: ReferenceType::Release,
            implementationReference: $label,
            implementationIdentity: new Identity(id: $id, label: $label),
            protocol: $protocol,
        );
    }

    /**
     * @param array{float, float} $medianMs baseline and candidate median wall time
     * @param array{float, float} $p95Ms baseline and candidate p95 wall time
     * @param list<int> $changedIndices
     * @param array{int, int} $statementCounts
     */
    private static function cell(
        string $scenario,
        Tier $tier,
        DatabaseTarget $database,
        array $medianMs,
        array $p95Ms,
        array $changedIndices = [],
        array $statementCounts = [
            4,
            4,
        ],
        bool $divergent = false,
    ): CellComparison {
        return new CellComparison(
            scenario: ScenarioName::fromString($scenario),
            tier: $tier,
            database: $database,
            baselineStatementCount: $statementCounts[0],
            candidateStatementCount: $statementCounts[1],
            sqlChanged: $changedIndices !== [],
            changedStatementIndices: $changedIndices,
            baselineMedianWall: Duration::fromNs($medianMs[0] * 1_000_000),
            candidateMedianWall: Duration::fromNs($medianMs[1] * 1_000_000),
            baselineP95Wall: Duration::fromNs($p95Ms[0] * 1_000_000),
            candidateP95Wall: Duration::fromNs($p95Ms[1] * 1_000_000),
            divergent: $divergent,
        );
    }
}
