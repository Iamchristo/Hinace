<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Request
{
    public array $routeParams = [];

    /** Set by AuthMiddleware once a session is validated. */
    public ?array $user = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $cookies,
        public readonly array $server,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return new self(
            method: $_SERVER['REQUEST_METHOD'] ?? 'GET',
            path: rtrim($path, '/') === '' ? '/' : rtrim($path, '/'),
            query: $_GET,
            body: $_POST,
            cookies: $_COOKIE,
            server: $_SERVER,
        );
    }

    public function ip(): ?string
    {
        return $this->server['REMOTE_ADDR'] ?? null;
    }

    public function userAgent(): ?string
    {
        return $this->server['HTTP_USER_AGENT'] ?? null;
    }

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->routeParams[$name] ?? $default;
    }

    public function input(string $name, mixed $default = null): mixed
    {
        return $this->body[$name] ?? $this->query[$name] ?? $default;
    }
}
