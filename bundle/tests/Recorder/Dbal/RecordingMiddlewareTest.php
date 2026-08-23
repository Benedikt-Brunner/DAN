<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Recorder\Dbal;

use Dan\Probe\Recorder\Dbal\RecordingConnectionFactory;
use Dan\Probe\Recorder\Dbal\RecordingMiddleware;
use Dan\Probe\Recorder\QueryRecorder;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class RecordingMiddlewareTest extends TestCase
{
    public function testRecordsOnlyTheActiveScopeWithParametersAndDurations(): void
    {
        $recorder = new QueryRecorder();
        $connection = (new RecordingConnectionFactory())->create(
            connection: DriverManager::getConnection(['url' => 'sqlite:///:memory:']),
            middleware: new RecordingMiddleware(recorder: $recorder),
        );

        $connection->fetchOne('SELECT 0');
        $recorder->start();
        $connection->fetchOne('SELECT ? AS value', [7]);
        $connection->fetchOne('SELECT :value AS value', ['value' => 8]);
        $connection->fetchOne('SELECT 2');
        $recorder->stop();
        $connection->fetchOne('SELECT 3');

        $records = $recorder->drain();
        self::assertSame([
            'SELECT ? AS value',
            // DBAL converts named placeholders before invoking the driver.
            'SELECT ? AS value',
            'SELECT 2',
        ], array_map(fn ($record) => $record->sql, $records));
        self::assertSame([7], $records[0]->params);
        self::assertSame([8], $records[1]->params);
        self::assertNull($records[2]->params);
        self::assertGreaterThan(0, $records[0]->duration->toNsInt());
        self::assertGreaterThan(0, $records[1]->duration->toNsInt());
        self::assertGreaterThan(0, $records[2]->duration->toNsInt());
    }
}
