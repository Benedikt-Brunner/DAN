<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Result;

use Dan\Lib\Protocol\PlanCapture;
use InvalidArgumentException;

/**
 * The engine's own plan for one statement, plus how the capture went: the
 * estimated plan as a decoded JSON object and the engine's EXPLAIN ANALYZE
 * output verbatim (a tree on MySQL, JSON text on MariaDB). Normalizing
 * engine-specific plans into comparable facts is the harness's job; the
 * probe ships both untouched.
 */
final readonly class CapturedPlan
{
    /**
     * @param array<mixed>|null $plan the decoded EXPLAIN FORMAT=JSON object, present only when captured
     * @param string|null $analysis the EXPLAIN ANALYZE output, present only when captured
     */
    public function __construct(
        private PlanCapture $capture,
        private ?array $plan,
        private ?string $analysis,
    ) {
        $captured = $capture === PlanCapture::Captured;
        if ($captured !== ($plan !== null) || $captured !== ($analysis !== null)) {
            throw new InvalidArgumentException('A captured plan carries its JSON object and its analysis; every other outcome carries neither.');
        }
    }

    public static function unsupported(): self
    {
        return new self(capture: PlanCapture::Unsupported, plan: null, analysis: null);
    }

    public static function failed(): self
    {
        return new self(capture: PlanCapture::Failed, plan: null, analysis: null);
    }

    /**
     * @return array{capture: string, raw: array<mixed>|null, analysis: string|null}
     */
    public function toArray(): array
    {
        return [
            'capture' => $this->capture->value,
            'raw' => $this->plan,
            'analysis' => $this->analysis,
        ];
    }
}
