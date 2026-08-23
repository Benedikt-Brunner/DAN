<?php

declare(strict_types=1);

namespace Dan\Harness\Protocol;

use InvalidArgumentException;
use RuntimeException;

/** @phpstan-type DatabaseTargetPayload array{engine: string, version: string} */
final class DatabaseTarget
{
    public function __construct(
        public readonly Engine $engine,
        public readonly string $version,
    ) {}

    public static function fromString(string $spec): self
    {
        $parts = explode(':', $spec, 2);
        if (count($parts) !== 2 || $parts[1] === '') {
            throw new InvalidArgumentException(sprintf('Invalid database target "%s", expected "<engine>:<version>" (e.g. "mysql:8.0").', $spec));
        }
        $engine = Engine::tryFrom($parts[0]);
        if ($engine === null) {
            throw new InvalidArgumentException(sprintf('Unknown database engine "%s", expected one of: %s.', $parts[0], implode(', ', array_map(fn (Engine $e) => $e->value, Engine::cases()))));
        }

        return new self(engine: $engine, version: $parts[1]);
    }

    public function id(): string
    {
        return $this->engine->value . '-' . $this->version;
    }

    /** @return DatabaseTargetPayload */
    public function toArray(): array
    {
        return [
            'engine' => $this->engine->value,
            'version' => $this->version,
        ];
    }

    /**
     * @param DatabaseTargetPayload $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            engine: Engine::tryFrom($payload['engine']) ?? throw new RuntimeException(sprintf('Malformed database target: unknown engine "%s".', $payload['engine'])),
            version: $payload['version'],
        );
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $engine = $payload['engine'] ?? null;
        $version = $payload['version'] ?? null;
        if (!is_string($engine) || !is_string($version)) {
            throw new RuntimeException('Malformed database target: expected string fields "engine" and "version".');
        }

        return self::fromArray([
            'engine' => $engine,
            'version' => $version,
        ]);
    }
}
