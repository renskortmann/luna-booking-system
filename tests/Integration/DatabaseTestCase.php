<?php

declare(strict_types=1);

namespace Luna\Tests\Integration;

use Luna\Auth;
use Luna\Clock;
use Luna\Config;
use Luna\Db;
use Luna\Settings;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the tests that need a real MySQL/MariaDB, because what they
 * are testing is the database's behaviour: row locking, unique constraints,
 * transactional writes.
 *
 * Point it at a scratch database and it rebuilds the schema before each test:
 *
 *   export LUNA_TEST_DB_NAME=luna_test
 *   export LUNA_TEST_DB_USER=root
 *   export LUNA_TEST_DB_PASS=
 *   vendor/bin/phpunit
 *
 * Without those variables the whole suite is skipped rather than failing, so
 * the unit tests still run anywhere.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        $name = getenv('LUNA_TEST_DB_NAME');

        if ($name === false || $name === '') {
            self::markTestSkipped(
                'Set LUNA_TEST_DB_NAME (and LUNA_TEST_DB_USER / _PASS / _HOST) to run the database tests.'
            );
        }

        Config::set(require dirname(__DIR__) . '/test-config.php');

        date_default_timezone_set('UTC');

        $db = Db::init((array) Config::get('db'));
        $this->rebuildSchema($db);

        Settings::flush();
        Auth::resetCache();
        Clock::unfreeze();

        // Each test gets a clean session; the CLI has no cookies, so PHP's file
        // handler is used and simply reset here.
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        Auth::resetCache();
        $_SESSION = [];
    }

    private function rebuildSchema(Db $db): void
    {
        $pdo = $db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/app/migrations/001_init.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }
}
