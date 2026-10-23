<?php

namespace JoliCode\MediaBundle\Tests\Application\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Test-only DBAL middleware: records the SQL statements sent to the database.
 */
#[AsMiddleware]
class QueryCounterMiddleware implements Middleware
{
    /**
     * Static, because the DBAL driver is wrapped by an instance that is not the one found in the test container.
     *
     * @var list<string>
     */
    private static array $queries = [];

    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        $record = static function (string $sql): void {
            self::$queries[] = $sql;
        };

        return new class($driver, $record) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private \Closure $record)
            {
                parent::__construct($driver);
            }

            #[\Override]
            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): Connection {
                $record = $this->record;

                return new class(parent::connect($params), $record) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private \Closure $record)
                    {
                        parent::__construct($connection);
                    }

                    #[\Override]
                    public function prepare(string $sql): Statement
                    {
                        $record = $this->record;

                        return new class(parent::prepare($sql), $record, $sql) extends AbstractStatementMiddleware {
                            public function __construct(Statement $statement, private \Closure $record, private string $sql)
                            {
                                parent::__construct($statement);
                            }

                            #[\Override]
                            public function execute(): Result
                            {
                                ($this->record)($this->sql);

                                return parent::execute();
                            }
                        };
                    }

                    #[\Override]
                    public function query(string $sql): Result
                    {
                        ($this->record)($sql);

                        return parent::query($sql);
                    }

                    #[\Override]
                    public function exec(string $sql): int|string
                    {
                        ($this->record)($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    public static function reset(): void
    {
        self::$queries = [];
    }

    /**
     * @return list<string>
     */
    public static function getQueries(): array
    {
        return self::$queries;
    }
}
