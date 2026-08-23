<?php

declare(strict_types=1);

namespace Dan\Harness\Implementation\Reference;

use Dan\Lib\Filesystem\Path;
use InvalidArgumentException;

/**
 * A reference to the Shopware implementation under test: either a local
 * shopware/shopware checkout or a released shopware/core version constraint.
 */
final class Reference
{
    private function __construct(
        public readonly ReferenceType $type,
        private readonly string|Path $value,
    ) {}

    public static function fromString(string $input): self
    {
        $looksLikePath = str_starts_with($input, '/')
            || str_starts_with($input, './')
            || str_starts_with($input, '../')
            || str_starts_with($input, '~');

        if ($looksLikePath || is_dir($input)) {
            $resolvedPath = realpath($input);
            if ($resolvedPath === false || !is_dir($resolvedPath)) {
                throw new InvalidArgumentException(sprintf('Implementation checkout path "%s" does not exist.', $input));
            }
            $path = Path::fromString($resolvedPath);
            if (!is_file($path->join('src', 'Core', 'composer.json')->toString())) {
                throw new InvalidArgumentException(sprintf('Implementation checkout "%s" does not contain src/Core/composer.json.', $path->toString()));
            }

            return new self(type: ReferenceType::Checkout, value: $path);
        }

        return new self(type: ReferenceType::Release, value: $input);
    }

    public function checkoutPath(): Path
    {
        if (!$this->value instanceof Path) {
            throw new InvalidArgumentException('A release reference does not have a checkout path.');
        }

        return $this->value;
    }

    public function releaseConstraint(): string
    {
        if (!is_string($this->value)) {
            throw new InvalidArgumentException('A checkout reference does not have a release constraint.');
        }

        return $this->value;
    }

    public function toString(): string
    {
        return is_string($this->value) ? $this->value : $this->value->toString();
    }
}
