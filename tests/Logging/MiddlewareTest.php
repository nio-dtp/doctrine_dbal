<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Logging;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MiddlewareTest extends TestCase
{
    private Driver $driver;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    public function setUp(): void
    {
        $connection = $this->createMock(Connection::class);

        $driver = $this->createMock(Driver::class);
        $driver->method('connect')
            ->willReturn($connection);

        $this->logger = $this->createMock(LoggerInterface::class);

        $middleware   = new Middleware($this->logger);
        $this->driver = $middleware->wrap($driver);
    }

    public function testConnectAndDisconnect(): void
    {
        $this->logger->expects(self::exactly(2))
            ->method('info')
            ->withConsecutive(
                [
                    'Connecting with parameters {params}',
                    [
                        'params' => [
                            'username' => 'admin',
                            'password' => '<redacted>',
                            'url' => '<redacted>',
                        ],
                    ],
                ],
                ['Disconnecting', []],
            );

        $this->driver->connect([
            'username' => 'admin',
            'password' => 'Passw0rd!',
            'url' => 'mysql://user:secret@localhost/mydb',
        ]);
    }

    public function testQuery(): void
    {
        $this->logger->expects(self::once())
            ->method('debug')
            ->with('Executing query: {sql}', ['sql' => 'SELECT 1']);

        $connection = $this->driver->connect([]);
        $connection->query('SELECT 1');
    }

    public function testExec(): void
    {
        $this->logger->expects(self::once())
            ->method('debug')
            ->with('Executing statement: {sql}', ['sql' => 'DROP DATABASE doctrine']);

        $connection = $this->driver->connect([]);
        $connection->exec('DROP DATABASE doctrine');
    }

    public function testBeginCommitRollback(): void
    {
        $this->logger->expects(self::exactly(3))
            ->method('debug')
            ->withConsecutive(
                ['Beginning transaction'],
                ['Committing transaction'],
                ['Rolling back transaction'],
            );

        $connection = $this->driver->connect([]);
        $connection->beginTransaction();
        $connection->commit();
        $connection->rollBack();
    }

    public function testExecuteStatementWithParameters(): void
    {
        $this->logger->expects(self::once())
            ->method('debug')
            ->with('Executing statement: {sql} (parameters: {params}, types: {types})', [
                'sql' => 'SELECT ?, ?',
                'params' => [1 => 42],
                'types' => [1 => ParameterType::INTEGER],
            ]);

        $connection = $this->driver->connect([]);
        $statement  = $connection->prepare('SELECT ?, ?');
        $statement->bindValue(1, 42, ParameterType::INTEGER);

        $statement->execute();
    }

    public function testExecuteStatementWithNamedParameters(): void
    {
        $this->logger->expects(self::once())
            ->method('debug')
            ->with('Executing statement: {sql} (parameters: {params}, types: {types})', [
                'sql' => 'SELECT :value',
                'params' => ['value' => 'Test'],
                'types' => ['value' => ParameterType::STRING],
            ]);

        $connection = $this->driver->connect([]);
        $statement  = $connection->prepare('SELECT :value');
        $statement->bindValue('value', 'Test', ParameterType::STRING);

        $statement->execute();
    }
}