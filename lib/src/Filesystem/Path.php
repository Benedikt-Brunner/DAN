<?php

declare(strict_types=1);

namespace Dan\Lib\Filesystem;

use InvalidArgumentException;

/**
 * An immutable filesystem path.
 *
 * The value is deliberately not canonicalized: relative paths stay relative,
 * and paths do not need to exist. Filesystem I/O remains the caller's concern.
 */
final readonly class Path
{
    private function __construct(
        private string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('A filesystem path must not be empty.');
        }
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException('A filesystem path must not contain null bytes.');
        }

        return new self($value);
    }

    public function join(string ...$segments): self
    {
        $path = rtrim($this->value, \DIRECTORY_SEPARATOR);
        foreach ($segments as $segment) {
            if ($segment === '' || str_contains($segment, "\0")) {
                throw new InvalidArgumentException('A filesystem path segment must not be empty or contain null bytes.');
            }
            $path .= \DIRECTORY_SEPARATOR . trim($segment, \DIRECTORY_SEPARATOR);
        }

        return new self($path);
    }

    public function parent(): self
    {
        return new self(dirname($this->value));
    }

    public function basename(): string
    {
        return basename($this->value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
