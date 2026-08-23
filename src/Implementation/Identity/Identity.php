<?php

declare(strict_types=1);

namespace Dan\Harness\Implementation\Identity;

use RuntimeException;

/**
 * For checkouts this is a content hash of src/Core, so identities describe the exact implementation files
 * used by the runtime and do not depend on version-control metadata.
 *
 * @phpstan-type IdentityPayload array{id: string, label: string}
 */
final class Identity
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
    ) {}

    /** @return IdentityPayload */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
        ];
    }

    /**
     * @param IdentityPayload $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(id: $payload['id'], label: $payload['label']);
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $id = $payload['id'] ?? null;
        $label = $payload['label'] ?? null;
        if (!is_string($id) || !is_string($label)) {
            throw new RuntimeException('Malformed implementation identity: expected string fields "id" and "label".');
        }

        return self::fromArray([
            'id' => $id,
            'label' => $label,
        ]);
    }
}
