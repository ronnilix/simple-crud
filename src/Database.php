<?php
declare(strict_types = 1);

namespace SimpleCrud;

use Atlas\Pdo\Connection;
use Atlas\Query\Bind;
use Atlas\Query\Delete;
use Atlas\Query\Insert;
use Atlas\Query\Select;
use Atlas\Query\Update;
use Exception;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use SimpleCrud\Scheme\Scheme;
use SimpleCrud\Scheme\SchemeInterface;

final class Database
{
    const CONFIG_LOCALE = 'locale';

    private $connection;
    private $scheme;
    private $tables = [];
    private $tablesClasses = [];
    private $inTransaction = false;
    private $onExecute;
    private $config = [];

    private $fieldFactory;

    public function __construct(PDO $pdo, SchemeInterface $scheme = null)
    {
        $this->connection = new Connection($pdo);
        $this->scheme = $scheme ?: new Scheme($pdo);
    }

    /**
     * Configure custom classes for some tables
     * [table => classname]
     */
    public function setTablesClasses(array $classes): self
    {
        $this->tablesClasses = $classes;

        return $this;
    }

    /**
     * Returns a config value
     */
    public function getConfig(string $name)
    {
        return $this->config[$name] ?? null;
    }

    /**
     * Set a config value
     * @param mixed $value
     */
    public function setConfig(string $name, $value): self
    {
        $this->config[$name] = $value;

        return $this;
    }

    /**
     * Return the scheme class
     */
    public function getScheme(): SchemeInterface
    {
        return $this->scheme;
    }

    /**
     * Returns the connection instance.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function select(): Select
    {
        return new Select($this->connection, new Bind());
    }

    public function update(): Update
    {
        return new Update($this->connection, new Bind());
    }

    public function delete(): Delete
    {
        return new Delete($this->connection, new Bind());
    }

    public function insert(): Insert
    {
        return new Insert($this->connection, new Bind());
    }

    /**
     * Set the FieldFactory instance used by the tables.
     */
    public function setFieldFactory(FieldFactory $fieldFactory): self
    {
        $this->fieldFactory = $fieldFactory;

        return $this;
    }

    /**
     * Returns the FieldFactory instance used by the tables.
     */
    public function getFieldFactory(): FieldFactory
    {
        if ($this->fieldFactory === null) {
            return $this->fieldFactory = new FieldFactory();
        }

        return $this->fieldFactory;
    }

    /**
     * Magic method to initialize the tables in lazy mode.
     *
     * @throws SimpleCrudException If the table cannot be instantiated
     */
    public function __get(string $name): Table
    {
        if (isset($this->tables[$name])) {
            return $this->tables[$name];
        }

        if (!$this->__isset($name)) {
            throw new InvalidArgumentException(
                sprintf('The table "%s" does not exist', $name)
            );
        }

        $class = $this->tablesClasses[$name] ?? Table::class;

        return $this->tables[$name] = new $class($this, $name);
    }

    /**
     * Magic method to check if a table exists or not.
     */
    public function __isset(string $name): bool
    {
        return in_array($name, $this->getScheme()->getTables());
    }

    /**
     * Execute a query and returns the statement object with the result.
     *
     * @throws Exception
     */
    public function execute(string $query, array $marks = null): PDOStatement
    {
        $statement = $this->connection->prepare($query);
        $statement->execute($marks);

        return $statement;
    }

    /**
     * Execute a callable inside a transaction.
     *
     * @return mixed The callable returned value
     */
    public function executeTransaction(callable $callable)
    {
        try {
            $transaction = $this->beginTransaction();

            $return = $callable($this);

            if ($transaction) {
                $this->commit();
            }
        } catch (Exception $exception) {
            if ($transaction) {
                $this->rollBack();
            }

            throw $exception;
        }

        return $return;
    }

    /**
     * Returns the last insert id.
     */
    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }

    /**
     * Starts a transaction if it's not started yet.
     */
    public function beginTransaction(): bool
    {
        if (!$this->inTransaction()) {
            $this->connection->beginTransaction();

            return $this->inTransaction = true;
        }

        return false;
    }

    /**
     * Commits the changes of the transaction to the database.
     */
    public function commit()
    {
        if ($this->inTransaction()) {
            $this->connection->commit();
            $this->inTransaction = false;
        }
    }

    /**
     * RollBack a transaction.
     */
    public function rollBack()
    {
        if ($this->inTransaction()) {
            $this->connection->rollBack();
            $this->inTransaction = false;
        }
    }

    /**
     * Check if there is a transaction opened currently in this adapter.
     */
    public function inTransaction()
    {
        return ($this->inTransaction === true) && ($this->connection->inTransaction() === true);
    }
}
