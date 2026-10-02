<?php

declare(strict_types=1);

namespace Dan\Lib\Protocol;

use InvalidArgumentException;
use RuntimeException;

/**
 * What a scenario's search returned, reduced to what distinguishes one
 * result from another: the entity ids in the order the DAL returned them and
 * the total it reported. A candidate that is faster because it returns
 * different rows, a different order, or a wrong total has not improved
 * anything - this is the value that comparison rests on. Shared by the probe,
 * which observes it, and the harness, which stores and compares it.
 *
 * @phpstan-type ResultSetPayload array{ids: list<string>, total: int}
 */
final class ResultSet
{
    /**
     * @param list<string> $ids entity identifiers in result order
     */
    public function __construct(
        public readonly array $ids,
        public readonly int $total,
    ) {
        if ($total < 0) {
            throw new InvalidArgumentException(sprintf('A result total cannot be negative, got %d.', $total));
        }
    }

    public function equals(self $other): bool
    {
        return $this->ids === $other->ids && $this->total === $other->total;
    }

    /**
     * Same identifiers regardless of order - the difference between
     * "different rows" and "the same rows, differently ordered".
     */
    public function sameIds(self $other): bool
    {
        $mine = $this->ids;
        $theirs = $other->ids;
        sort($mine);
        sort($theirs);

        return $mine === $theirs;
    }

    /** @return ResultSetPayload */
    public function toArray(): array
    {
        return [
            'ids' => $this->ids,
            'total' => $this->total,
        ];
    }

    /**
     * @param ResultSetPayload $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(ids: $payload['ids'], total: $payload['total']);
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $ids = $payload['ids'] ?? null;
        $total = $payload['total'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || !is_int($total)) {
            throw new RuntimeException('Malformed result set payload.');
        }
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new RuntimeException('Malformed result set: ids must be strings.');
            }
        }

        return new self(ids: $ids, total: $total);
    }
}
