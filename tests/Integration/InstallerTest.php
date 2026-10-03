<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use Macrolab\AdminAuth;
use Macrolab\Config;
use Macrolab\Context;
use Macrolab\Controller\AdminController;
use Macrolab\Csrf;
use Macrolab\Db;
use Macrolab\Http\HttpException;
use Macrolab\Http\Request;
use Macrolab\Migrator;
use PDO;

/**
 * The browser installer, run the way it is on a real server: against a
 * database with no tables at all.
 */
final class InstallerTest extends DatabaseTestCase
{
    private const TOKEN = 'installer-test-token';

    protected function setUp(): void
    {
        parent::setUp();

        $config = require dirname(__DIR__) . '/test-config.php';
        $config['app']['install_token'] = self::TOKEN;
        Config::set($config);

        $this->dropAllTables();
    }

    public function testTheInstallPageOpensOnAnEmptyDatabase(): void
    {
        $response = $this->install(new Request('GET', '/install', query: ['token' => self::TOKEN]));

        self::assertSame(200, $response->status);
    }

    public function testAWrongTokenRevealsNothingOnAnEmptyDatabase(): void
    {
        $this->expectException(HttpException::class);

        $this->install(new Request('GET', '/install', query: ['token' => 'not-the-token']));
    }

    public function testItLoadsTheSchemaCreatesTheAdministratorAndThenStopsExisting(): void
    {
        $response = $this->install(new Request('POST', '/install', post: [
            'install_token' => self::TOKEN,
            Csrf::FIELD     => Csrf::token(),
            'username'      => 'labadmin',
            'password'      => 'a long and unusual passphrase 4 the lab',
        ]));

        self::assertSame(200, $response->status);
        self::assertTrue(AdminAuth::exists(), 'the administrator account exists');
        self::assertSame([], (new Migrator(Db::get()))->pending(), 'every migration is applied');

        try {
            $this->install(new Request('GET', '/install', query: ['token' => self::TOKEN]));
            self::fail('the installer must stop existing once there is an administrator');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    private function install(Request $request): \Macrolab\Http\Response
    {
        Context::setRequest($request);

        return (new AdminController())->install($request);
    }

    private function dropAllTables(): void
    {
        $pdo = Db::get()->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
