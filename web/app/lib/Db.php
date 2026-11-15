<?php
declare(strict_types=1);
namespace HMD;

use PDO;

/** Thin PDO wrapper. Every query uses bound parameters. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function connect(array $c): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    public static function set(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            self::$pdo = self::connect(App::config()['db']);
        }
        return self::$pdo;
    }

    public static function all(string $sql, array $p = []): array
    {
        $s = self::pdo()->prepare($sql);
        $s->execute($p);
        return $s->fetchAll();
    }

    public static function one(string $sql, array $p = []): ?array
    {
        $s = self::pdo()->prepare($sql);
        $s->execute($p);
        $r = $s->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $p = []): mixed
    {
        $s = self::pdo()->prepare($sql);
        $s->execute($p);
        $v = $s->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function run(string $sql, array $p = []): int
    {
        $s = self::pdo()->prepare($sql);
        $s->execute($p);
        return $s->rowCount();
    }

    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($row));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $row, string $where, array $wp): int
    {
        $set = implode(',', array_map(static fn($c) => "$c=?", array_keys($row)));
        return self::run("UPDATE $table SET $set WHERE $where", array_merge(array_values($row), $wp));
    }

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** "?,?,?" for IN lists. */
    public static function in(array $v): string
    {
        return implode(',', array_fill(0, max(1, count($v)), '?'));
    }
}
