<?php

declare(strict_types=1);

namespace Macrolab;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Thin PDO wrapper. Every query in the application goes through here, and
 * every one of them is a prepared statement - no SQL is ever built by string
 * concatenation with user input.
 */
final class Db
{
    private static ?self $instance = null;

    private int $transactionDepth = 0;

    private ?PDO $pdo = null;

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(private readonly array $config)
    {
    }

    /**
     * Register the connection details. Connecting is deferred to the first
     * query, so that a page which only needs to *report* a database problem -
     * the installer - can render instead of failing during bootstrap.
     *
     * @param array<string, mixed> $cfg
     */
    public static function init(array $cfg): self
    {
        return self::$instance = new self($cfg);
    }

    private function connect(): PDO
    {
        $cfg = $this->config;
        $charset = (string) ($cfg['charset'] ?? 'utf8mb4');

        if (!empty($cfg['socket'])) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $cfg['socket'], $cfg['name'], $charset);
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'] ?? 'localhost',
                (int) ($cfg['port'] ?? 3306),
                $cfg['name'] ?? '',
                $charset
            );
        }

        $pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements, so placeholders are never interpolated
            // client-side.
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        // The session must agree with the storage convention: everything is UTC.
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

        return $pdo;
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Db::init() must be called before Db::get().');
        }

        return self::$instance;
    }

    /** Inject a live connection directly. Used by tests against a scratch database. */
    public static function swap(PDO $pdo): self
    {
        $db = new self([]);
        $db->pdo = $pdo;

        return self::$instance = $db;
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $data column => value
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );

        $this->query($sql, array_values($data));

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data        column => value
     * @param array<int, mixed>    $whereParams
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $assignments = [];
        foreach (array_keys($data) as $column) {
            $assignments[] = $this->quoteIdentifier($column) . ' = ?';
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $assignments),
            $where
        );

        return $this->query($sql, [...array_values($data), ...$whereParams])->rowCount();
    }

    /**
     * Run $fn inside a transaction, committing on return and rolling back on
     * any throwable. Nested calls join the outer transaction rather than
     * starting a second one.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->transactionDepth === 0) {
            $this->pdo()->beginTransaction();
        }
        $this->transactionDepth++;

        try {
            $result = $fn($this);
        } catch (Throwable $e) {
            $this->transactionDepth--;
            if ($this->transactionDepth === 0 && $this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $e;
        }

        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            $this->pdo()->commit();
        }

        return $result;
    }

    /**
     * Identifiers here come only from application code, never from a request;
     * this guards against a careless future caller, not against user input.
     */
    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new RuntimeException('Refusing to use unsafe SQL identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
    }
}
