<?php

declare(strict_types=1);

namespace Dan\Harness\Plan;

use Dan\Lib\Protocol\PlanCapture;
use RuntimeException;

/**
 * The engine's plan for one recorded statement as the probe captured it:
 * the raw EXPLAIN FORMAT=JSON object and the engine's EXPLAIN ANALYZE output
 * (a tree on MySQL, JSON text on MariaDB), both retained verbatim in the
 * artifact, plus how the capture went. Reading facts out of the raw plan is
 * PlanFacts' job and engine-specific; the analysis is kept for reading.
 *
 * @phpstan-type QueryPlanPayload array{capture: string, raw: array<mixed>|null, analysis: string|null}
 */
final class QueryPlan
{
    /**
     * @param array<mixed>|null $raw present exactly when the plan was captured
     * @param string|null $analysis present exactly when the plan was captured
     */
    public function __construct(
        public readonly PlanCapture $capture,
        public readonly ?array $raw,
        public readonly ?string $analysis,
    ) {
        $captured = $capture === PlanCapture::Captured;
        if ($captured !== ($raw !== null) || $captured !== ($analysis !== null)) {
            throw new RuntimeException('A captured plan carries its raw object and its analysis; every other outcome carries neither.');
        }
    }

    /** @return QueryPlanPayload */
    public function toArray(): array
    {
        return [
            'capture' => $this->capture->value,
            'raw' => $this->raw,
            'analysis' => $this->analysis,
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $capture = $payload['capture'] ?? null;
        $raw = $payload['raw'] ?? null;
        $analysis = $payload['analysis'] ?? null;
        if (!is_string($capture) || ($raw !== null && !is_array($raw)) || ($analysis !== null && !is_string($analysis))) {
            throw new RuntimeException('Malformed query plan payload.');
        }

        return new self(
            capture: PlanCapture::tryFrom($capture) ?? throw new RuntimeException(sprintf('Malformed query plan: unknown capture outcome "%s".', $capture)),
            raw: $raw,
            analysis: $analysis,
        );
    }
}
