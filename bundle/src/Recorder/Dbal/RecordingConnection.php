<?php

declare(strict_types=1);

namespace Dan\Probe\Recorder\Dbal;

use Dan\Probe\Recorder\QueryRecorder;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class RecordingConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly QueryRecorder $recorder)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new RecordingStatement(
            statement: parent::prepare($sql),
            recorder: $this->recorder,
            sql: $sql,
        );
    }

    public function query(string $sql): Result
    {
        $startedAt = $this->recorder->startStatement();

        try {
            return parent::query($sql);
        } finally {
            $this->recorder->finishStatement(sql: $sql, params: null, startedAt: $startedAt);
        }
    }

    public function exec(string $sql): int
    {
        $startedAt = $this->recorder->startStatement();

        try {
            return parent::exec($sql);
        } finally {
            $this->recorder->finishStatement(sql: $sql, params: null, startedAt: $startedAt);
        }
    }
}
