<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use Macrolab\Http\HttpException;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesAStaticRoute(): void
    {
        $router = new Router();
        $router->get('/', fn (): Response => Response::text('calendar'));

        self::assertSame('calendar', $router->dispatch($this->request('GET', '/'))->body);
    }

    public function testPassesPlaceholdersToTheHandlerInOrder(): void
    {
        $router = new Router();
        $router->post('/api/bookings/{id}/cancel',
            fn (Request $r, string $id): Response => Response::text('cancel:' . $id));

        self::assertSame('cancel:42',
            $router->dispatch($this->request('POST', '/api/bookings/42/cancel'))->body);
    }

    public function testAPlaceholderDoesNotSwallowASlash(): void
    {
        $router = new Router();
        $router->post('/api/bookings/{id}', fn (): Response => Response::text('update'));

        // Must not match /api/bookings/42/cancel.
        $this->expectException(HttpException::class);
        $router->dispatch($this->request('POST', '/api/bookings/42/cancel'));
    }

    public function testMoreSpecificRouteStillMatchesWhenBothAreRegistered(): void
    {
        $router = new Router();
        $router->post('/api/bookings/{id}', fn (): Response => Response::text('update'));
        $router->post('/api/bookings/{id}/cancel', fn (): Response => Response::text('cancel'));

        self::assertSame('update', $router->dispatch($this->request('POST', '/api/bookings/7'))->body);
        self::assertSame('cancel', $router->dispatch($this->request('POST', '/api/bookings/7/cancel'))->body);
    }

    public function testUnknownPathIsNotFound(): void
    {
        $router = new Router();
        $router->get('/', fn (): Response => Response::text('x'));

        try {
            $router->dispatch($this->request('GET', '/nowhere'));
            self::fail('expected a 404');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testKnownPathWithTheWrongMethodIsMethodNotAllowed(): void
    {
        $router = new Router();
        $router->get('/login', fn (): Response => Response::text('form'));

        try {
            $router->dispatch($this->request('POST', '/login'));
            self::fail('expected a 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
        }
    }

    public function testFormRegistersBothMethods(): void
    {
        $router = new Router();
        $router->form('/login', fn (Request $r): Response => Response::text($r->method));

        self::assertSame('GET', $router->dispatch($this->request('GET', '/login'))->body);
        self::assertSame('POST', $router->dispatch($this->request('POST', '/login'))->body);
    }

    public function testDotsInAPatternAreLiteral(): void
    {
        $router = new Router();
        $router->get('/a.b', fn (): Response => Response::text('exact'));

        $this->expectException(HttpException::class);
        $router->dispatch($this->request('GET', '/axb'));
    }

    private function request(string $method, string $path): Request
    {
        return new Request(method: $method, path: $path);
    }
}
