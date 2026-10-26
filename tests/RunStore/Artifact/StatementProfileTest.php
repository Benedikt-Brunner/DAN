<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\RunStore\Artifact;

use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Plan\QueryPlan;
use Dan\Harness\RunStore\Artifact\StatementProfile;
use Dan\Lib\Protocol\PlanCapture;
use Dan\Lib\Protocol\StatementDivergence;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StatementProfileTest extends TestCase
{
    public function testMergeSumsObservationsAndKeepsTheFirstSql(): void
    {
        $first = self::profile(sql: 'SELECT 1', samples: [
            10,
            20,
        ], observed: 2);
        $second = self::profile(sql: 'SELECT 1', samples: [30], observed: 1);

        $merged = $first->merge($second);

        self::assertSame('SELECT 1', $merged->sql);
        self::assertSame(3, $merged->observed);
        self::assertSame([
            10,
            20,
            30,
        ], $merged->durationSamples->toNsArray());
        self::assertSame(StatementDivergence::None, $merged->divergence);
    }

    public function testMergeKeepsTheEarlierBlocksPlan(): void
    {
        // Every block captures a plan, and ANALYZE timings differ between
        // them: the pooled profile keeps the first, never the later one.
        $earlier = self::captured(analysis: '-> Table scan on product  (actual time=0.10..0.20 rows=10 loops=1)');
        $later = self::captured(analysis: '-> Table scan on product  (actual time=0.30..0.40 rows=10 loops=1)');

        $merged = self::profile(sql: 'SELECT 1', samples: [10], observed: 1, plan: $earlier)
            ->merge(self::profile(sql: 'SELECT 1', samples: [20], observed: 1, plan: $later));

        self::assertSame($earlier->toArray(), $merged->plan->toArray());
    }

    public function testMergingDifferentSqlIsTextDivergenceNotPresence(): void
    {
        $merged = self::profile(sql: 'SELECT 1', samples: [10], observed: 1)
            ->merge(self::profile(sql: 'SELECT 2', samples: [20], observed: 1));

        self::assertSame(StatementDivergence::Text, $merged->divergence);
        self::assertSame('SELECT 1', $merged->sql);
    }

    public function testMergeCarriesEitherSidesDivergence(): void
    {
        $merged = self::profile(sql: 'SELECT 1', samples: [10], observed: 1, divergence: StatementDivergence::Presence)
            ->merge(self::profile(sql: 'SELECT 1', samples: [20], observed: 1, divergence: StatementDivergence::Text));

        self::assertSame(StatementDivergence::TextAndPresence, $merged->divergence);
    }

    public function testPresenceIsJudgedAgainstTheIterationsItIsPooledOver(): void
    {
        $complete = self::profile(sql: 'SELECT 1', samples: [
            10,
            20,
        ], observed: 2);

        self::assertSame(StatementDivergence::None, $complete->withPresenceAgainst(2)->divergence);
        self::assertSame(StatementDivergence::Presence, $complete->withPresenceAgainst(3)->divergence);
        // Already text-divergent: presence is added, text is kept.
        self::assertSame(
            StatementDivergence::TextAndPresence,
            self::profile(sql: 'SELECT 1', samples: [10], observed: 1, divergence: StatementDivergence::Text)->withPresenceAgainst(2)->divergence,
        );
    }

    public function testRoundTripsThroughItsPayload(): void
    {
        $profile = self::profile(sql: 'SELECT 1', samples: [
            10,
            20,
        ], observed: 2, divergence: StatementDivergence::TextAndPresence);

        $payload = $profile->toArray();

        self::assertSame(2, $payload['observed']);
        self::assertSame('text-and-presence', $payload['divergence']);
        self::assertSame($payload, StatementProfile::fromDecodedArray($payload)->toArray());
    }

    public function testRefusesAnUnknownDivergenceKind(): void
    {
        $payload = self::profile(sql: 'SELECT 1', samples: [10], observed: 1)->toArray();
        $payload['divergence'] = 'flaky';

        $this->expectException(RuntimeException::class);
        StatementProfile::fromDecodedArray($payload);
    }

    /**
     * @param list<int> $samples
     */
    private static function profile(string $sql, array $samples, int $observed, StatementDivergence $divergence = StatementDivergence::None, ?QueryPlan $plan = null): StatementProfile
    {
        return new StatementProfile(
            index: 0,
            sql: $sql,
            durationSamples: SampleCollection::fromArray($samples),
            observed: $observed,
            divergence: $divergence,
            plan: $plan ?? new QueryPlan(capture: PlanCapture::Unsupported, raw: null, analysis: null),
        );
    }

    private static function captured(string $analysis): QueryPlan
    {
        return new QueryPlan(capture: PlanCapture::Captured, raw: ['query_block' => ['select_id' => 1]], analysis: $analysis);
    }
}
