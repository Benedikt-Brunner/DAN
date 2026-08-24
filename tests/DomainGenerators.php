<?php

declare(strict_types=1);

namespace Dan\Harness\Tests;

use Dan\Harness\Implementation\Identity\Identity;
use Dan\Harness\Implementation\Reference\ReferenceType;
use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Harness\Protocol\Protocol;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Harness\RunStore\Artifact\RunManifest;
use Dan\Harness\RunStore\Artifact\StatementProfile;
use Dan\Harness\RunStore\Artifact\StatementProfileCollection;
use Dan\Lib\Protocol\ScenarioName;
use Dan\Lib\Protocol\Tier;
use DateTimeImmutable;
use Eris\Generator;
use LogicException;

/**
 * Eris generators for DAN's domain objects, shared by the property tests.
 * Each generator produces the full value space the type's own validation
 * admits - not just friendly examples - so round-trip properties exercise
 * the same shapes real runs persist.
 *
 * Eris carries no generic types, so everything it hands back is mixed. The
 * as*() narrowers below are the single boundary where generated values
 * regain their types - builders and test callbacks narrow through them and
 * a generator bug fails loudly instead of corrupting a property.
 */
final class DomainGenerators
{
    private const array SQL_SHAPES = [
        'SELECT `product`.`id` FROM product WHERE id IN (?, ?, ?)',
        'SELECT name FROM category WHERE parent_id = :parent',
        "SELECT p.id\n  FROM product p JOIN category c ON c.id = p.category_id",
        'UPDATE product SET stock = stock - ? WHERE id = ?',
        'SELECT 1',
        'SELECT JSON_EXTRACT(payload, \'$.dimensions.width\') FROM synthetic_blob',
    ];

    /** @return Generator<mixed> */
    public static function tier(): Generator
    {
        return Generator\elements(...Tier::cases());
    }

    /** @return Generator<mixed> */
    public static function databaseTarget(): Generator
    {
        return Generator\map(
            self::buildDatabaseTarget(...),
            Generator\tuple(
                Generator\elements(...Engine::cases()),
                Generator\choose(5, 11),
                Generator\choose(0, 30),
                Generator\choose(0, 40),
                Generator\choose(1, 3),
                Generator\elements('', '-lts', ':jammy', '.22'),
            ),
        );
    }

    /** @return Generator<mixed> */
    public static function scenarioName(): Generator
    {
        return Generator\map(
            self::buildScenarioName(...),
            Generator\tuple(
                Generator\elements('product', 'category', 'order', 'synthetic'),
                Generator\elements('deep-read', 'keyword-listing', 'json-path', 'aggregation'),
                Generator\elements('', '-v2', ' edge case', '.x'),
            ),
        );
    }

    /**
     * Non-empty, bounded lists of nanosecond samples. All list generators
     * here are size-bounded: Eris's seq() grows lists with the iteration
     * count, and nested unbounded lists (cells x statements x samples) made
     * whole-run properties two orders of magnitude slower for no additional
     * coverage.
     *
     * @return Generator<mixed>
     */
    public static function samples(): Generator
    {
        return self::boundedList(elements: Generator\choose(0, 5_000_000_000), maxLength: 20);
    }

    /**
     * @param Generator<mixed> $elements
     *
     * @return Generator<mixed>
     */
    public static function boundedList(Generator $elements, int $maxLength): Generator
    {
        return Generator\bind(
            Generator\choose(1, $maxLength),
            fn (int $length): Generator => Generator\vector($length, $elements),
        );
    }

    /** @return Generator<mixed> */
    public static function statementProfiles(): Generator
    {
        return Generator\map(
            self::buildStatementProfiles(...),
            self::boundedList(elements: Generator\tuple(
                Generator\elements(...self::SQL_SHAPES),
                self::samples(),
                Generator\bool(),
            ), maxLength: 6),
        );
    }

    /** @return Generator<mixed> */
    public static function cellResult(): Generator
    {
        return Generator\map(
            self::buildCellResult(...),
            Generator\tuple(
                self::scenarioName(),
                self::tier(),
                self::databaseTarget(),
                self::samples(),
                self::statementProfiles(),
            ),
        );
    }

    /** @return Generator<mixed> */
    public static function protocol(): Generator
    {
        return Generator\map(
            self::buildProtocol(...),
            Generator\tuple(
                self::boundedList(elements: self::databaseTarget(), maxLength: 3),
                Generator\elements(
                    [Tier::S],
                    [
                        Tier::S,
                        Tier::M,
                    ],
                    [
                        Tier::M,
                        Tier::L,
                    ],
                    Tier::cases(),
                ),
                Generator\choose(0, 50),
                Generator\choose(1, 1000),
                Generator\choose(1, 1000),
                Generator\elements(null, 'product.*', 'deep-read', ''),
            ),
        );
    }

    /** @return Generator<mixed> */
    public static function identity(): Generator
    {
        return Generator\map(
            self::buildIdentity(...),
            Generator\tuple(
                Generator\choose(0, 2_147_483_647),
                Generator\choose(0, 2_147_483_647),
                Generator\elements('v6.6.10.22', 'local checkout', 'candidate #42', ''),
            ),
        );
    }

    /** @return Generator<mixed> */
    public static function runManifest(): Generator
    {
        return Generator\map(
            self::buildRunManifest(...),
            Generator\tuple(
                Generator\choose(1, 999_999),
                Generator\choose(0, 2_147_483_647),
                Generator\elements(...ReferenceType::cases()),
                Generator\elements('v6.6.10.22', '/tmp/shopware', 'v6.7.0.0'),
                self::identity(),
                self::protocol(),
            ),
        );
    }

    /**
     * Returns the payload with the value at the given key path replaced by
     * junk - the seed for "refuses type-corrupted payloads" properties.
     *
     * @param array<mixed> $payload
     * @param list<int|string> $path
     *
     * @return array<mixed>
     */
    public static function corruptedAt(array $payload, array $path, mixed $junk): array
    {
        $key = $path[0] ?? throw new LogicException('An empty corruption path cannot address a field.');
        if (count($path) === 1) {
            $payload[$key] = $junk;

            return $payload;
        }
        $nested = $payload[$key] ?? null;
        if (!is_array($nested)) {
            throw new LogicException(sprintf('Corruption path segment "%s" does not address a nested payload.', $key));
        }
        $payload[$key] = self::corruptedAt(payload: $nested, path: array_values(array_slice($path, 1)), junk: $junk);

        return $payload;
    }

    /** @return list<int|string> */
    public static function asPath(mixed $value): array
    {
        $path = [];
        foreach (self::asList($value) as $segment) {
            if (!is_int($segment) && !is_string($segment)) {
                throw new LogicException('A corruption path segment must be an int or string key.');
            }
            $path[] = $segment;
        }

        return $path;
    }

    public static function asInt(mixed $value): int
    {
        if (!is_int($value)) {
            throw new LogicException('Generated value is not an int.');
        }

        return $value;
    }

    public static function asString(mixed $value): string
    {
        if (!is_string($value)) {
            throw new LogicException('Generated value is not a string.');
        }

        return $value;
    }

    public static function asStringOrNull(mixed $value): ?string
    {
        return $value === null ? null : self::asString($value);
    }

    public static function asBool(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new LogicException('Generated value is not a bool.');
        }

        return $value;
    }

    /** @return list<mixed> */
    public static function asList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new LogicException('Generated value is not a list.');
        }

        return $value;
    }

    /** @return list<int> */
    public static function asIntList(mixed $value): array
    {
        return array_map(self::asInt(...), self::asList($value));
    }

    /** @return list<string> */
    public static function asStringList(mixed $value): array
    {
        return array_map(self::asString(...), self::asList($value));
    }

    /** @return list<CellResult> */
    public static function asCellResults(mixed $value): array
    {
        return array_map(self::asCellResult(...), self::asList($value));
    }

    public static function asCellResult(mixed $value): CellResult
    {
        if (!$value instanceof CellResult) {
            throw new LogicException('Generated value is not a CellResult.');
        }

        return $value;
    }

    public static function asEngine(mixed $value): Engine
    {
        if (!$value instanceof Engine) {
            throw new LogicException('Generated value is not an Engine.');
        }

        return $value;
    }

    public static function asTier(mixed $value): Tier
    {
        if (!$value instanceof Tier) {
            throw new LogicException('Generated value is not a Tier.');
        }

        return $value;
    }

    /** @return list<Tier> */
    public static function asTiers(mixed $value): array
    {
        return array_map(self::asTier(...), self::asList($value));
    }

    public static function asScenarioName(mixed $value): ScenarioName
    {
        if (!$value instanceof ScenarioName) {
            throw new LogicException('Generated value is not a ScenarioName.');
        }

        return $value;
    }

    public static function asDatabaseTarget(mixed $value): DatabaseTarget
    {
        if (!$value instanceof DatabaseTarget) {
            throw new LogicException('Generated value is not a DatabaseTarget.');
        }

        return $value;
    }

    /** @return list<DatabaseTarget> */
    public static function asDatabaseTargets(mixed $value): array
    {
        return array_map(self::asDatabaseTarget(...), self::asList($value));
    }

    public static function asStatementProfiles(mixed $value): StatementProfileCollection
    {
        if (!$value instanceof StatementProfileCollection) {
            throw new LogicException('Generated value is not a StatementProfileCollection.');
        }

        return $value;
    }

    public static function asReferenceType(mixed $value): ReferenceType
    {
        if (!$value instanceof ReferenceType) {
            throw new LogicException('Generated value is not a ReferenceType.');
        }

        return $value;
    }

    public static function asIdentity(mixed $value): Identity
    {
        if (!$value instanceof Identity) {
            throw new LogicException('Generated value is not an Identity.');
        }

        return $value;
    }

    public static function asProtocol(mixed $value): Protocol
    {
        if (!$value instanceof Protocol) {
            throw new LogicException('Generated value is not a Protocol.');
        }

        return $value;
    }

    /**
     * @param array<mixed> $parts
     */
    private static function buildDatabaseTarget(array $parts): DatabaseTarget
    {
        $segments = [
            self::asInt($parts[1]),
            self::asInt($parts[2]),
            self::asInt($parts[3]),
        ];

        return new DatabaseTarget(
            engine: self::asEngine($parts[0]),
            version: implode('.', array_slice($segments, 0, self::asInt($parts[4]))) . self::asString($parts[5]),
        );
    }

    /**
     * @param array<mixed> $parts
     */
    private static function buildScenarioName(array $parts): ScenarioName
    {
        return ScenarioName::fromString(self::asString($parts[0]) . '.' . self::asString($parts[1]) . self::asString($parts[2]));
    }

    /**
     * @param array<mixed> $statements
     */
    private static function buildStatementProfiles(array $statements): StatementProfileCollection
    {
        $profiles = [];
        foreach (array_values($statements) as $index => $statement) {
            $parts = self::asList($statement);
            $profiles[] = new StatementProfile(
                index: $index,
                sql: self::asString($parts[0]),
                durationSamples: SampleCollection::fromArray(self::asIntList($parts[1])),
                divergent: self::asBool($parts[2]),
            );
        }

        return StatementProfileCollection::create($profiles);
    }

    /**
     * @param array<mixed> $parts
     */
    private static function buildCellResult(array $parts): CellResult
    {
        return new CellResult(
            scenario: self::asScenarioName($parts[0]),
            tier: self::asTier($parts[1]),
            database: self::asDatabaseTarget($parts[2]),
            wallSamples: SampleCollection::fromArray(self::asIntList($parts[3])),
            statements: self::asStatementProfiles($parts[4]),
        );
    }

    /**
     * @param array<mixed> $parts
     */
    private static function buildProtocol(array $parts): Protocol
    {
        $measuredIterations = self::asInt($parts[3]);

        return new Protocol(
            databases: self::asDatabaseTargets($parts[0]),
            tiers: self::asTiers($parts[1]),
            warmupIterations: self::asInt($parts[2]),
            measuredIterations: $measuredIterations,
            blocks: max(1, min(self::asInt($parts[4]), $measuredIterations)),
            scenarioFilter: self::asStringOrNull($parts[5]),
        );
    }

    /**
     * @param array<mixed> $parts
     */
    private static function buildIdentity(array $parts): Identity
    {
        return new Identity(
            id: sprintf('%08x%08x', self::asInt($parts[0]), self::asInt($parts[1])),
            label: self::asString($parts[2]),
        );
    }

    /**
     * @param array<mixed> $parts
     */
    private static function buildRunManifest(array $parts): RunManifest
    {
        return new RunManifest(
            runId: sprintf('run-%d', self::asInt($parts[0])),
            // Second precision on purpose: the persisted format (ATOM)
            // carries no fractional seconds.
            createdAt: new DateTimeImmutable('@' . self::asInt($parts[1])),
            implementationReferenceType: self::asReferenceType($parts[2]),
            implementationReference: self::asString($parts[3]),
            implementationIdentity: self::asIdentity($parts[4]),
            protocol: self::asProtocol($parts[5]),
        );
    }
}
