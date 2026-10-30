<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Every value goes through a native prepared statement;
 * table/column names passed to the helpers are validated identifiers.
 */
final class Database
{
    private ?PDO $pdo = null;
    private int $transactionLevel = 0;

    public function __construct(private array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = $this->config;
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['database'], $c['charset']);
            $this->pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
            $this->pdo->exec("SET NAMES {$c['charset']} COLLATE {$c['collation']}, time_zone = '+00:00', "
                . "sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");
        }
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach (array_values(array_is_list($params) ? $params : []) as $i => $value) {
            $stmt->bindValue($i + 1, $value, $this->type($value));
        }
        if (!array_is_list($params)) {
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . ltrim((string) $key, ':'), $value, $this->type($value));
            }
        }
        $stmt->execute();
        return $stmt;
    }

    public function select(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_map([$this, 'ident'], array_keys($data));
        $sql = 'INSERT INTO ' . $this->ident($table) . ' (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($data), '?')) . ')';
        $this->query($sql, array_values($data));
        return (int) $this->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $where column => value (AND-ed equality) */
    public function update(string $table, array $data, array $where): int
    {
        if ($where === []) {
            throw new \InvalidArgumentException('Refusing to UPDATE without a WHERE clause.');
        }
        $set = implode(', ', array_map(fn ($c) => $this->ident($c) . ' = ?', array_keys($data)));
        [$whereSql, $whereParams] = $this->where($where);
        return $this->query('UPDATE ' . $this->ident($table) . " SET {$set} WHERE {$whereSql}", [...array_values($data), ...$whereParams])->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new \InvalidArgumentException('Refusing to DELETE without a WHERE clause.');
        }
        [$whereSql, $params] = $this->where($where);
        return $this->query('DELETE FROM ' . $this->ident($table) . " WHERE {$whereSql}", $params)->rowCount();
    }

    /** Runs $callback in a transaction (nested calls use savepoints). */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $level = $this->transactionLevel++;
        $level === 0 ? $pdo->beginTransaction() : $pdo->exec("SAVEPOINT sp{$level}");
        try {
            $result = $callback($this);
            $level === 0 ? $pdo->commit() : $pdo->exec("RELEASE SAVEPOINT sp{$level}");
            return $result;
        } catch (\Throwable $e) {
            $level === 0 ? $pdo->rollBack() : $pdo->exec("ROLLBACK TO SAVEPOINT sp{$level}");
            throw $e;
        } finally {
            $this->transactionLevel--;
        }
    }

    /** Execute raw DDL (migrations only). */
    public function statement(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    /** Quote a table/column identifier, allowing "table.column". */
    public function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $name)) {
            throw new \InvalidArgumentException("Invalid identifier '{$name}'.");
        }
        return '`' . str_replace('.', '`.`', $name) . '`';
    }

    private function where(array $where): array
    {
        $sql = [];
        $params = [];
        foreach ($where as $col => $value) {
            if ($value === null) {
                $sql[] = $this->ident($col) . ' IS NULL';
            } else {
                $sql[] = $this->ident($col) . ' = ?';
                $params[] = $value;
            }
        }
        return [implode(' AND ', $sql), $params];
    }

    private function type(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }
}
