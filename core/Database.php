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

    /**
     * Run a migration SQL file exactly once.
     *
     * Migrations are trusted files shipped with the kit, never user input.
     * Every applied file is recorded in `migrations_log`, so re-running the
     * install flow — or applying an update package that ships old migrations
     * alongside new ones — silently skips work that is already done instead
     * of re-executing it.
     *
     * AI AGENTS: never bypass this to exec() DDL directly. A migration that
     * is not logged here is a migration the updater cannot reason about.
     */
    public static function runMigrationFile(string $filePath): void
    {
        $sql = file_get_contents($filePath);
        if ($sql === false) {
            throw new RuntimeException("Could not read migration file: {$filePath}");
        }

        self::ensureMigrationsLog();

        $key = self::migrationKey($filePath);
        if (self::migrationWasApplied($key)) {
            return;
        }

        self::connection()->exec($sql);
        self::logMigration($key);
    }

    /** True if $migrationKey is already recorded in migrations_log. */
    public static function migrationWasApplied(string $migrationKey): bool
    {
        self::ensureMigrationsLog();

        return self::fetchOne(
            'SELECT id FROM migrations_log WHERE migration_file = :file',
            ['file' => $migrationKey]
        ) !== null;
    }

    /**
     * Identify a migration by its path relative to the project root
     * (e.g. "modules/blog/migrations/001_create_posts.sql") rather than by
     * bare filename. Two modules can legitimately both ship an
     * "001_create_items.sql"; a bare basename would make the second one
     * look already-applied and silently skip its table.
     */
    private static function migrationKey(string $filePath): string
    {
        $root = realpath(__DIR__ . '/..');
        $real = realpath($filePath);

        if ($real === false) {
            // File is unreadable; fall back to the path as given so the
            // caller still gets a deterministic key rather than a crash.
            $real = $filePath;
        }

        $real = str_replace('\\', '/', $real);

        if ($root !== false) {
            $root = str_replace('\\', '/', $root) . '/';
            if (str_starts_with($real, $root)) {
                $real = substr($real, strlen($root));
            }
        }

        return $real;
    }

    /**
     * Create migrations_log if it is missing. Run directly rather than through
     * runMigrationFile(), which would need the table to already exist in order
     * to decide whether to create it.
     */
    private static function ensureMigrationsLog(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        self::connection()->exec(
            'CREATE TABLE IF NOT EXISTS migrations_log ('
            . ' id INT AUTO_INCREMENT PRIMARY KEY,'
            . ' migration_file VARCHAR(255) NOT NULL UNIQUE,'
            . ' applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $ensured = true;
    }

    private static function logMigration(string $migrationKey): void
    {
        try {
            self::insert('migrations_log', ['migration_file' => $migrationKey]);
        } catch (PDOException $e) {
            // A concurrent request logged the same migration first. The
            // UNIQUE constraint did its job; the migration ran either way.
            if (!str_contains($e->getMessage(), '1062')) {
                throw $e;
            }
        }
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
