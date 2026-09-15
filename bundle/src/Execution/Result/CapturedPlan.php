<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Result;

use Dan\Lib\Protocol\PlanCapture;
use InvalidArgumentException;

/**
 * The engine's own plan for one statement as a decoded JSON object, plus
 * how the capture went. Normalizing engine-specific plans into comparable
 * facts is the harness's job; the probe ships the raw plan untouched.
 */
final readonly class CapturedPlan
{
    /**
     * @param array<mixed>|null $plan the decoded EXPLAIN FORMAT=JSON object, present only when captured
     */
    public function __construct(
        private PlanCapture $capture,
        private ?array $plan,
    ) {
        if (($capture === PlanCapture::Captured) !== ($plan !== null)) {
            throw new InvalidArgumentException('A captured plan carries its JSON object; every other outcome carries none.');
        }
    }

    public static function unsupported(): self
    {
        return new self(capture: PlanCapture::Unsupported, plan: null);
    }

    public static function failed(): self
    {
        return new self(capture: PlanCapture::Failed, plan: null);
    }

    /**
     * @return array{capture: string, raw: array<mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'capture' => $this->capture->value,
            'raw' => $this->plan,
        ];
    }
}
