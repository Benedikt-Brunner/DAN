<?php

declare(strict_types=1);

namespace Dan\Probe\Recorder;

use Dan\Lib\Time\Timestamp;

/**
 * Buffers statements while a measurement is active.
 *
 * The DBAL middleware is installed for the lifetime of the connection, while
 * this recorder limits capture to the scenario section being measured.
 */
final class QueryRecorder
{
    /** @var list<RecordedStatement> */
    private array $records = [];

    private bool $active = false;

    public function start(): void
    {
        $this->records = [];
        $this->active = true;
    }

    public function stop(): void
    {
        $this->active = false;
    }

    public function startStatement(): ?Timestamp
    {
        return $this->active ? Timestamp::now() : null;
    }

    /** @param array<mixed>|null $params */
    public function finishStatement(string $sql, ?array $params, ?Timestamp $startedAt): void
    {
        if ($startedAt === null) {
            return;
        }

        $this->records[] = new RecordedStatement(
            sql: $sql,
            params: $params,
            duration: $startedAt->elapsed(),
        );
    }

    /**
     * Returns everything recorded since the last drain and resets the buffer.
     *
     * @return list<RecordedStatement>
     */
    public function drain(): array
    {
        $records = $this->records;
        $this->records = [];

        return $records;
    }
}
