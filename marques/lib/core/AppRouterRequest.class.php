<?php
declare(strict_types=1);

namespace Marques\Core;

class AppRouterRequest {
    private string $method;
    private string $path;
    private array $query;
    private array $body;
    private array $headers;

    public function __construct(array $server, array $get, array $post, array $headers) {
        $this->method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $uri = $server['REQUEST_URI'] ?? '/';
        $this->path = parse_url($uri, PHP_URL_PATH);
        $this->query = $get;
        $this->body  = $post;
        $this->headers = $headers;
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function getPath(): string {
        return $this->path;
    }

    public function getQuery(): array {
        return $this->query;
    }

    public function getBody(): array {
        return $this->body;
    }

    public function getHeader(string $name): ?string {
        return $this->headers[$name] ?? null;
    }
}
