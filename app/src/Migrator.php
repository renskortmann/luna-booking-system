<?php

declare(strict_types=1);

namespace Luna;

use RuntimeException;
use Throwable;

/**
 * Applies the SQL files in app/migrations, in filename order, and records what
 * it has done.
 *
 * Used both by app/cli/migrate.php and by the browser installer, because TU
 * Delft LAMP hosting offers no SSH or SFTP - only FTP and the Plesk panel - so
 * the command line cannot be the only way to load the schema.
 */
final class Migrator
{
    private readonly string $directory;

    public function __construct(
        private readonly Db $db,
        ?string $directory = null,
    ) {
        $this->directory = $directory ?? dirname(__DIR__) . '/migrations';
    }

    /** Whether a given table exists, without throwing if it does not. */
    public function hasTable(string $table): bool
    {
        try {
            $found = $this->db->value(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );

            return (int) $found > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function ensureLedger(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                filename   VARCHAR(191) NOT NULL,
                applied_at DATETIME     NOT NULL,
                PRIMARY KEY (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * @return list<string>
     */
    public function applied(): array
    {
        if (!$this->hasTable('migrations')) {
            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['filename'],
            $this->db->all('SELECT filename FROM migrations ORDER BY filename')
        );
    }

    /**
     * @return list<string>
     */
    public function available(): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files);

        return array_map('basename', $files);
    }

    /**
     * @return list<string>
     */
    public function pending(): array
    {
        return array_values(array_diff($this->available(), $this->applied()));
    }

    /**
     * Apply everything outstanding.
     *
     * DDL in MySQL is not transactional, so statements are sent one at a time
     * and a failure stops the run. The failed migration stays unrecorded, so
     * it is retried once the cause is fixed.
     *
     * @return list<string> the migrations applied, in order
     */
    public function migrate(): array
    {
        $this->ensureLedger();

        $done = [];

        foreach ($this->pending() as $name) {
            $sql = (string) file_get_contents($this->directory . '/' . $name);

            foreach (self::split($sql) as $statement) {
                try {
                    $this->db->pdo()->exec($statement);
                } catch (Throwable $e) {
                    throw new RuntimeException(
                        'Migration ' . $name . ' failed: ' . $e->getMessage()
                        . ' (statement: ' . self::summarise($statement) . ')',
                        0,
                        $e
                    );
                }
            }

            $this->db->insert('migrations', [
                'filename'   => $name,
                'applied_at' => Clock::sql(),
            ]);

            $done[] = $name;
        }

        return $done;
    }

    /**
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        $statements = [];
        foreach (explode(';', $withoutComments) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $statements[] = $chunk;
            }
        }

        return $statements;
    }

    private static function summarise(string $statement): string
    {
        $oneLine = preg_replace('/\s+/', ' ', $statement) ?? $statement;

        return mb_substr(trim($oneLine), 0, 120);
    }
}
