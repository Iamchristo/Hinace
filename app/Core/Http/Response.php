<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Response
{
    private array $headers = [];

    private function __construct(
        public readonly string $body,
        public readonly int $status = 200,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        $response = new self($body, $status);
        $response->headers['Content-Type'] = 'text/html; charset=utf-8';

        return $response;
    }

    public static function text(string $body, int $status = 200): self
    {
        $response = new self($body, $status);
        $response->headers['Content-Type'] = 'text/plain; charset=utf-8';

        return $response;
    }

    public static function json(array $data, int $status = 200): self
    {
        $response = new self(json_encode($data, JSON_THROW_ON_ERROR), $status);
        $response->headers['Content-Type'] = 'application/json';

        return $response;
    }

    public static function redirect(string $location, int $status = 302): self
    {
        $response = new self('', $status);
        $response->headers['Location'] = $location;

        return $response;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
