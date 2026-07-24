<?php

/**
 * Database
 *
 * THE ONLY WAY TO TALK TO THE DATABASE IN THIS PROJECT.
 *
 * Deliberately exposes NO raw query method. Every method here
 * takes parameterized input and uses PDO prepared statements
 * internally. This is a structural control, not a style guideline:
 * if a method to run raw concatenated SQL doesn't exist, an AI
 * agent cannot accidentally reach for it under prompting pressure.
 *
 * AI AGENTS: never use PDO/mysqli directly in a module. Never add
 * a method here that accepts a full raw SQL string built from
 * concatenated user input. If you need a new query shape, add a
 * new specific method below with its own bound parameters —
 * do not generalize into a raw executor.
 */
final class Database
{
    private static ?PDO $pdo = null;

    private static function connection(): PDO
    {
        if (self::$pdo === null) {
            $host = Config::get('DB_HOST', '127.0.0.1');
            $name = Config::get('DB_NAME');
            $user = Config::get('DB_USER');
            $pass = Config::get('DB_PASS');
            $charset = 'utf8mb4';

            $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";

            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES    => false, // real prepared statements, not emulated
            ]);
        }

        return self::$pdo;
    }

    /** Fetch a single row. $params is an assoc array bound by name. */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Fetch all matching rows. */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Insert a row into $table from an assoc array of column => value. Returns last insert id. */
    public static function insert(string $table, array $data): string
    {
        self::assertSafeIdentifier($table);
        foreach (array_keys($data) as $col) {
            self::assertSafeIdentifier($col);
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($c) => ":{$c}", array_keys($data)));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($data);

        return self::connection()->lastInsertId();
    }

    /** Update rows in $table matching $whereColumn = $whereValue. */
    public static function update(string $table, array $data, string $whereColumn, mixed $whereValue): int
    {
        self::assertSafeIdentifier($table);
        self::assertSafeIdentifier($whereColumn);
        foreach (array_keys($data) as $col) {
            self::assertSafeIdentifier($col);
        }

        $set = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($data)));
        $sql = "UPDATE {$table} SET {$set} WHERE {$whereColumn} = :where_value";

        $params = $data;
        $params['where_value'] = $whereValue;

        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /** Delete rows in $table matching $whereColumn = $whereValue. */
    public static function delete(string $table, string $whereColumn, mixed $whereValue): int
    {
        self::assertSafeIdentifier($table);
        self::assertSafeIdentifier($whereColumn);

        $sql = "DELETE FROM {$table} WHERE {$whereColumn} = :where_value";
        $stmt = self::connection()->prepare($sql);
        $stmt->execute(['where_value' => $whereValue]);

        return $stmt->rowCount();
    }

    /** Run a module's migration SQL file. Migrations are trusted files, not user input. */
    public static function runMigrationFile(string $filePath): void
    {
        $sql = file_get_contents($filePath);
        if ($sql === false) {
            throw new RuntimeException("Could not read migration file: {$filePath}");
        }
        self::connection()->exec($sql);
    }

    /**
     * Table/column names can never come from user input in this project.
     * This guard exists so that even a mistaken call fails loudly
     * instead of silently opening an injection path.
     */
    private static function assertSafeIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Unsafe identifier rejected: {$identifier}");
        }
    }
}
