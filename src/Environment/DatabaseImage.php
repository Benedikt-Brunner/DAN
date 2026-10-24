<?php

declare(strict_types=1);

namespace Dan\Harness\Environment;

use Dan\Harness\Protocol\DatabaseTarget;
use RuntimeException;

/**
 * The database image a target resolved to: its tag plus the immutable
 * content digest, so "mysql:8.0" in two sessions can be told apart when the
 * tag moved. A null digest is an explicit unknown, never a guess.
 *
 * @phpstan-import-type DatabaseTargetPayload from DatabaseTarget
 *
 * @phpstan-type DatabaseImagePayload array{target: DatabaseTargetPayload, digest: string|null}
 */
final class DatabaseImage
{
    public function __construct(
        public readonly DatabaseTarget $target,
        public readonly ?string $digest,
    ) {}

    /** @return DatabaseImagePayload */
    public function toArray(): array
    {
        return [
            'target' => $this->target->toArray(),
            'digest' => $this->digest,
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $target = $payload['target'] ?? null;
        $digest = $payload['digest'] ?? null;
        if (!is_array($target) || ($digest !== null && !is_string($digest))) {
            throw new RuntimeException('Malformed database image payload.');
        }

        return new self(target: DatabaseTarget::fromDecodedArray($target), digest: $digest);
    }
}
