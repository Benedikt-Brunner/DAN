<?php

declare(strict_types=1);

namespace Dan\Probe\Recorder\Dbal;

use Dan\Probe\Recorder\QueryRecorder;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

final class RecordingStatement extends AbstractStatementMiddleware
{
    /** @var array<int|string, mixed> */
    private array $params = [];

    /** @var array<int|string, int> */
    private array $types = [];

    public function __construct(
        Statement $statement,
        private readonly QueryRecorder $recorder,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    public function bindValue($param, $value, $type = ParameterType::STRING)
    {
        $this->params[$param] = $value;
        $this->types[$param] = $type;

        return parent::bindValue($param, $value, $type);
    }

    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null)
    {
        $this->params[$param] = &$variable;
        $this->types[$param] = $type;

        return parent::bindParam($param, $variable, $type, $length);
    }

    /** @param array<mixed>|null $params */
    public function execute($params = null): Result
    {
        $recordedParams = $this->normalizeParams($params ?? $this->params);
        // Values passed to execute() directly carry no types: the driver
        // binds them all as strings, and so would a replay without types.
        $recordedTypes = $params === null ? $this->normalizeParams($this->types) : null;
        $startedAt = $this->recorder->startStatement();

        try {
            return parent::execute($params);
        } finally {
            $this->recorder->finishStatement(
                sql: $this->sql,
                params: $recordedParams,
                types: $recordedTypes,
                startedAt: $startedAt,
            );
        }
    }

    /**
     * DBAL binds positional parameters to the driver using 1-based indexes.
     * Keep their recorded representation compatible with the caller's list;
     * values and types are bound together, so both stay aligned.
     *
     * @template T
     *
     * @param array<T> $params
     *
     * @return array<T>
     */
    private function normalizeParams(array $params): array
    {
        foreach (array_keys($params) as $key) {
            if (!is_int($key)) {
                return $params;
            }
        }

        return array_values($params);
    }
}
