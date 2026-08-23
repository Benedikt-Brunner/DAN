<?php

declare(strict_types=1);

namespace Dan\Lib\Filesystem;

use InvalidArgumentException;

/**
 * A filesystem path guaranteed to be absolute.
 *
 * Paths that cross process boundaries must be absolute: probe invocations run
 * with the DAL runtime as working directory, so a relative path handed to
 * them would silently resolve against the wrong directory.
 */
final readonly class AbsolutePath
{
    private function __construct(
        private Path $path,
    ) {}

    public static function fromString(string $value): self
    {
        if (!str_starts_with($value, \DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException(sprintf('Expected an absolute path, got "%s".', $value));
        }

        return new self(Path::fromString($value));
    }

    /**
     * Resolves a possibly-relative path against the given absolute working
     * directory; an already-absolute value is taken as-is.
     */
    public static function resolve(string $value, string $workingDirectory): self
    {
        if (str_starts_with($value, \DIRECTORY_SEPARATOR)) {
            return self::fromString($value);
        }
        if (str_starts_with($value, '.' . \DIRECTORY_SEPARATOR)) {
            $value = substr($value, 2);
        }

        return self::fromString($workingDirectory)->join($value);
    }

    public function join(string ...$segments): self
    {
        return new self($this->path->join(...$segments));
    }

    public function basename(): string
    {
        return $this->path->basename();
    }

    public function toPath(): Path
    {
        return $this->path;
    }

    public function toString(): string
    {
        return $this->path->toString();
    }
}
