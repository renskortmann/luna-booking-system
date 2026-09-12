<?php

declare(strict_types=1);

namespace Luna;

use Luna\Http\HttpException;
use Luna\Http\Request;
use Luna\Http\Response;

/**
 * A route table small enough to read in one screen. Patterns use {name}
 * placeholders, which match a single path segment and are handed to the
 * handler as string arguments after the Request.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable|array{0: class-string, 1: string}}> */
    private array $routes = [];

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /**
     * Register the same handler for GET and POST, for pages that render a form
     * and also accept its submission.
     *
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function form(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
        $this->add('POST', $pattern, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function add(string $method, string $pattern, callable|array $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => $this->compile($pattern),
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }

            $pathMatched = true;

            if ($route['method'] !== $request->method) {
                continue;
            }

            // Only the {name} captures, in the order they appear in the pattern.
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[] = $value;
                }
            }

            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $method] = $handler;
                $handler = [new $class(), $method];
            }

            /** @var Response $response */
            $response = $handler($request, ...$params);

            return $response;
        }

        throw $pathMatched ? HttpException::methodNotAllowed() : HttpException::notFound();
    }

    private function compile(string $pattern): string
    {
        // Quote everything, then un-quote the placeholder braces so that only
        // {name} is treated as a pattern and every other character is literal.
        $quoted = str_replace(['\\{', '\\}'], ['{', '}'], preg_quote($pattern, '#'));

        $regex = preg_replace_callback(
            '/\{([a-z_][a-z0-9_]*)\}/i',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $quoted
        );

        return '#^' . $regex . '$#';
    }
}
