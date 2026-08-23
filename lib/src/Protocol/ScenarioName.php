<?php

declare(strict_types=1);

namespace Dan\Lib\Protocol;

use InvalidArgumentException;

final readonly class ScenarioName
{
    private function __construct(
        private string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('A scenario name must not be empty.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
