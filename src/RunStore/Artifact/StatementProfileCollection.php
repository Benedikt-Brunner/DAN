<?php

declare(strict_types=1);

namespace Dan\Harness\RunStore\Artifact;

use Dan\Lib\Collections\Collection;
use RuntimeException;

/**
 * @extends Collection<StatementProfile>
 *
 * @phpstan-import-type StatementProfilePayload from StatementProfile
 */
final readonly class StatementProfileCollection extends Collection
{
    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        if (!array_is_list($payload)) {
            throw new RuntimeException('Malformed statements payload: expected a list.');
        }

        $statements = [];
        foreach ($payload as $statement) {
            if (!is_array($statement)) {
                throw new RuntimeException('Malformed statements payload: every entry must be an object.');
            }
            $statements[] = StatementProfile::fromDecodedArray($statement);
        }

        return self::create($statements);
    }

    public function merge(self $other): self
    {
        $statements = $this->getItems();
        foreach ($other as $index => $statement) {
            $statements[$index] = isset($statements[$index])
                ? $statements[$index]->merge($statement)
                : $statement;
        }

        return self::create(array_values($statements));
    }

    /** @return list<StatementProfilePayload> */
    public function toArray(): array
    {
        return array_map(
            fn (StatementProfile $statement): array => $statement->toArray(),
            $this->getItems(),
        );
    }
}
