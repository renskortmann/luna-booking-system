<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use Macrolab\Auth;
use Macrolab\Clock;
use Macrolab\Config;
use Macrolab\Db;
use Macrolab\Migrator;
use Macrolab\Settings;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the tests that need a real MySQL/MariaDB, because what they
 * are testing is the database's behaviour: row locking, unique constraints,
 * transactional writes.
 *
 * Point it at a scratch database and it rebuilds the schema before each test:
 *
 *   export MACROLAB_TEST_DB_NAME=macrolab_test
 *   export MACROLAB_TEST_DB_USER=root
 *   export MACROLAB_TEST_DB_PASS=
 *   vendor/bin/phpunit
 *
 * Without those variables the whole suite is skipped rather than failing, so
 * the unit tests still run anywhere.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        $name = getenv('MACROLAB_TEST_DB_NAME');

        if ($name === false || $name === '') {
            self::markTestSkipped(
                'Set MACROLAB_TEST_DB_NAME (and MACROLAB_TEST_DB_USER / _PASS / _HOST) to run the database tests.'
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

    /**
     * Drop everything, then apply every migration through the Migrator the
     * application itself uses.
     *
     * Naming a migration file here instead would mean this harness had to be
     * edited every time one was added, and the failure when somebody forgot
     * would be a confusing "table doesn't exist" rather than anything pointing
     * at the cause.
     */
    private function rebuildSchema(Db $db): void
    {
        $pdo = $db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        (new Migrator($db))->migrate();
    }
}
