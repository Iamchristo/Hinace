<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Router
{
    /** @var array<string, array<int, array{pattern: string, handler: callable, middleware: array}>> */
    private array $routes = [];

    /** @var callable[] */
    private array $groupMiddleware = [];

    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function group(array $middleware, callable $callback): void
    {
        $previous = $this->groupMiddleware;
        $this->groupMiddleware = [...$previous, ...$middleware];
        $callback($this);
        $this->groupMiddleware = $previous;
    }

    private function add(string $method, string $path, callable $handler, array $middleware): void
    {
        $this->routes[$method][] = [
            'pattern' => $this->compilePattern($path),
            'handler' => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method;
        $path = $request->path;

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                $params = array_filter($matches, static fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY);
                $request->routeParams = $params;

                $next = function (Request $req) use ($route) {
                    return ($route['handler'])($req);
                };

                foreach (array_reverse($route['middleware']) as $middleware) {
                    $next = function (Request $req) use ($middleware, $next) {
                        return $middleware($req, $next);
                    };
                }

                return $next($request);
            }
        }

        return Response::text('Not Found', 404);
    }

    private function compilePattern(string $path): string
    {
        $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path);

        return '#^' . $pattern . '$#';
    }
}
