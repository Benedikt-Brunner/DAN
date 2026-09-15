<?php

declare(strict_types=1);

namespace Dan\Harness\Plan;

use Dan\Lib\Protocol\PlanCapture;
use RuntimeException;

/**
 * The engine's plan for one recorded statement as the probe captured it:
 * the raw EXPLAIN FORMAT=JSON object, retained verbatim in the artifact,
 * plus how the capture went. Reading facts out of it is PlanFacts' job and
 * engine-specific.
 *
 * @phpstan-type QueryPlanPayload array{capture: string, raw: array<mixed>|null}
 */
final class QueryPlan
{
    /**
     * @param array<mixed>|null $raw present exactly when the plan was captured
     */
    public function __construct(
        public readonly PlanCapture $capture,
        public readonly ?array $raw,
    ) {
        if (($capture === PlanCapture::Captured) !== ($raw !== null)) {
            throw new RuntimeException('A captured plan carries its raw object; every other outcome carries none.');
        }
    }

    /** @return QueryPlanPayload */
    public function toArray(): array
    {
        return [
            'capture' => $this->capture->value,
            'raw' => $this->raw,
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $capture = $payload['capture'] ?? null;
        $raw = $payload['raw'] ?? null;
        if (!is_string($capture) || ($raw !== null && !is_array($raw))) {
            throw new RuntimeException('Malformed query plan payload.');
        }

        return new self(
            capture: PlanCapture::tryFrom($capture) ?? throw new RuntimeException(sprintf('Malformed query plan: unknown capture outcome "%s".', $capture)),
            raw: $raw,
        );
    }
}
