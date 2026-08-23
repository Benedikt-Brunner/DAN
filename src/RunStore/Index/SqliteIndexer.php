<?php

declare(strict_types=1);

namespace Dan\Harness\RunStore\Index;

use Dan\Harness\Measurement\Result\Statistics;
use Dan\Harness\RunStore\Filesystem\RunDirectory;
use DateTimeInterface;
use PDO;

/**
 * Builds a derived, queryable SQLite index next to the JSON source of truth.
 * The JSON cell files remain authoritative; the index can always be rebuilt.
 * A future local GUI reads this file.
 */
final class SqliteIndexer
{
    public function index(RunDirectory $run): void
    {
        $manifest = $run->manifest();

        @unlink($run->indexPath()->toString());
        $pdo = new PDO('sqlite:' . $run->indexPath()->toString());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec(<<<'SQL'
            CREATE TABLE run (
                run_id TEXT PRIMARY KEY,
                created_at TEXT NOT NULL,
                implementation_identity TEXT NOT NULL,
                implementation_label TEXT NOT NULL,
                protocol_json TEXT NOT NULL
            );
            CREATE TABLE cell (
                scenario TEXT NOT NULL,
                tier TEXT NOT NULL,
                engine TEXT NOT NULL,
                engine_version TEXT NOT NULL,
                statement_count INTEGER NOT NULL,
                iterations INTEGER NOT NULL,
                median_wall_ms REAL NOT NULL,
                p95_wall_ms REAL NOT NULL,
                divergent INTEGER NOT NULL,
                PRIMARY KEY (scenario, tier, engine, engine_version)
            );
            CREATE TABLE statement (
                scenario TEXT NOT NULL,
                tier TEXT NOT NULL,
                engine TEXT NOT NULL,
                engine_version TEXT NOT NULL,
                statement_index INTEGER NOT NULL,
                sql TEXT NOT NULL,
                median_ms REAL NOT NULL,
                p95_ms REAL NOT NULL,
                divergent INTEGER NOT NULL,
                PRIMARY KEY (scenario, tier, engine, engine_version, statement_index)
            );
            SQL);

        $pdo->prepare('INSERT INTO run VALUES (?, ?, ?, ?, ?)')->execute([
            $manifest->runId,
            $manifest->createdAt->format(DateTimeInterface::ATOM),
            $manifest->implementationIdentity->id,
            $manifest->implementationIdentity->label,
            json_encode($manifest->protocol->toArray(), \JSON_THROW_ON_ERROR),
        ]);

        $insertCell = $pdo->prepare('INSERT INTO cell VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insertStatement = $pdo->prepare('INSERT INTO statement VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

        $pdo->beginTransaction();
        foreach ($run->allCells() as $cell) {
            $divergent = false;
            foreach ($cell->statements as $statement) {
                $divergent = $divergent || $statement->divergent;
                $statementStatistics = Statistics::create($statement->durationSamples);
                $insertStatement->execute([
                    $cell->scenario->toString(),
                    $cell->tier->value,
                    $cell->database->engine->value,
                    $cell->database->version,
                    $statement->index,
                    $statement->sql,
                    $statementStatistics->median()->toMsFloat(),
                    $statementStatistics->percentile(Statistics::P95)->toMsFloat(),
                    (int) $statement->divergent,
                ]);
            }
            $wallStatistics = Statistics::create($cell->wallSamples);
            $insertCell->execute([
                $cell->scenario->toString(),
                $cell->tier->value,
                $cell->database->engine->value,
                $cell->database->version,
                count($cell->statements),
                count($cell->wallSamples),
                $wallStatistics->median()->toMsFloat(),
                $wallStatistics->percentile(Statistics::P95)->toMsFloat(),
                (int) $divergent,
            ]);
        }
        $pdo->commit();
    }
}
