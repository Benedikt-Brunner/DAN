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

        return parent::bindValue($param, $value, $type);
    }

    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null)
    {
        $this->params[$param] = &$variable;

        return parent::bindParam($param, $variable, $type, $length);
    }

    /** @param array<mixed>|null $params */
    public function execute($params = null): Result
    {
        $recordedParams = $this->normalizeParams($params ?? $this->params);
        $startedAt = $this->recorder->startStatement();

        try {
            return parent::execute($params);
        } finally {
            $this->recorder->finishStatement(
                sql: $this->sql,
                params: $recordedParams,
                startedAt: $startedAt,
            );
        }
    }

    /**
     * DBAL binds positional parameters to the driver using 1-based indexes.
     * Keep their recorded representation compatible with the caller's list.
     *
     * @param array<mixed> $params
     *
     * @return array<mixed>
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
