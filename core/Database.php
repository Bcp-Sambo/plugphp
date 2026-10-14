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
     * Delete rows in $table whose $timestampColumn is strictly older than
     * $cutoff. Returns the number of rows removed.
     *
     * A separate method rather than a parameter on delete(), which matches a
     * single `column = value` and cannot express a range. This is the shape
     * the class header asks for: a new specific method with its own bound
     * parameters, not a general executor that takes an operator.
     *
     * For pruning expiring data — rate-limit counters, stock holds — where
     * there is no cron on this hosting and the sweep has to ride a normal
     * request.
     */
    public static function deleteOlderThan(string $table, string $timestampColumn, string $cutoff): int
    {
        self::assertSafeIdentifier($table);
        self::assertSafeIdentifier($timestampColumn);

        $sql = "DELETE FROM {$table} WHERE {$timestampColumn} < :cutoff";
        $stmt = self::connection()->prepare($sql);
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Run $fn inside a single database transaction.
     *
     * Commits when the callable returns, rolls back and rethrows if it
     * throws. The callable's return value is passed through, so a handler can
     * compute inside the transaction and use the result after it.
     *
     *     $orderId = Database::transaction(function () use ($cart) {
     *         $row = Database::fetchOne(
     *             'SELECT stock_quantity FROM products WHERE id = :id FOR UPDATE',
     *             ['id' => $cart['product_id']]
     *         );
     *         // ... check stock, insert the hold, return the id
     *     });
     *
     * WHY THIS EXISTS, AND WHY IT IS NOT OPTIONAL: `SELECT ... FOR UPDATE`
     * outside a transaction takes a row lock that autocommit releases the
     * instant the statement finishes. Two buyers then both read the same
     * "1 left" and both proceed. The code looks correct, passes every
     * single-request test, and oversells only under concurrent load — which
     * is exactly when it costs money. A FOR UPDATE read that is not wrapped
     * in this method is not protecting anything.
     *
     * AI AGENTS: this is a named primitive, not a general SQL escape hatch.
     * It runs YOUR closure; it does not take SQL. The rule against adding a
     * raw executor to this class is unchanged.
     *
     * Two things this deliberately does NOT do:
     *
     * - **It does not nest.** A second call while a transaction is open
     *   throws rather than quietly joining the outer one, because the two
     *   possible meanings (one shared transaction, or independent inner
     *   savepoints) behave differently on rollback and the caller cannot see
     *   which it got. No call site in this project needs nesting; if one ever
     *   genuinely does, add SAVEPOINT handling here with its own test rather
     *   than relaxing this check.
     * - **It must not contain DDL.** MySQL commits implicitly on CREATE,
     *   ALTER and DROP, which would end the transaction mid-closure while
     *   this method still believes it is open. Never call
     *   runMigrationFile() — or anything that migrates — from inside here.
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::connection();

        if ($pdo->inTransaction()) {
            throw new RuntimeException(
                'Database::transaction() does not nest: a transaction is already open on this '
                . 'connection. Restructure so the outermost caller owns the transaction and the '
                . 'inner work is a plain function.'
            );
        }

        $pdo->beginTransaction();

        try {
            $result = $fn();
        } catch (Throwable $e) {
            // A rollback can itself fail — a dropped connection is the usual
            // reason. The original exception is what the caller needs to see,
            // so the rollback failure goes to the error log instead of
            // replacing it.
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable $rollbackFailure) {
                error_log(
                    'Database::transaction() rollback failed after an error: '
                    . $rollbackFailure->getMessage()
                );
            }
            throw $e;
        }

        // The closure is not supposed to commit or roll back on its own. If it
        // did, commit() below would throw a confusing "no active transaction"
        // from inside this method; say what actually happened instead.
        if (!$pdo->inTransaction()) {
            throw new RuntimeException(
                'The callable passed to Database::transaction() closed the transaction itself. '
                . 'Let this method own the commit and rollback.'
            );
        }

        // Outside the try: a commit that fails is not a rolled-back
        // transaction, and must not be reported as one. It propagates as-is,
        // and the caller must treat the work as not done.
        $pdo->commit();

        return $result;
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
