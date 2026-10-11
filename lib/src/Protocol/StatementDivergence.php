<?php

declare(strict_types=1);

namespace Dan\Lib\Protocol;

/**
 * How a statement position behaved across the measured iterations of one
 * scenario. Two independent things can go wrong and the vocabulary keeps
 * them apart: the SQL text at the position can differ between iterations
 * (Text), and the position can be absent from some iterations at all
 * (Presence) - a statement seen in 5 of 60 iterations must never read as a
 * stable 60-iteration profile. Shared by the probe, which observes it, and
 * the harness, which stores and reports it.
 */
enum StatementDivergence: string
{
    case None = 'none';
    case Text = 'text';
    case Presence = 'presence';
    case TextAndPresence = 'text-and-presence';

    public static function fromFlags(bool $textDiffers, bool $intermittent): self
    {
        return match (true) {
            $textDiffers && $intermittent => self::TextAndPresence,
            $textDiffers => self::Text,
            $intermittent => self::Presence,
            default => self::None,
        };
    }

    /**
     * Divergence observed in either of two sample sets holds for their union.
     */
    public function merge(self $other): self
    {
        return self::fromFlags(
            textDiffers: $this->includesText() || $other->includesText(),
            intermittent: $this->includesPresence() || $other->includesPresence(),
        );
    }

    public function isDivergent(): bool
    {
        return $this !== self::None;
    }

    public function includesText(): bool
    {
        return $this === self::Text || $this === self::TextAndPresence;
    }

    public function includesPresence(): bool
    {
        return $this === self::Presence || $this === self::TextAndPresence;
    }
}
