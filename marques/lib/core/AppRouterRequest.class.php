<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * Request-Klasse für die Kapselung der Request-Daten.
 */
class AppRouterRequest {
    private array $server;
    private array $get;
    private array $post;
    private array $headers;
    
    /**
     * Konstruktor
     *
     * @param array $server SERVER-Variablen
     * @param array $get GET-Parameter
     * @param array $post POST-Parameter
     * @param array $headers HTTP-Header
     */
    public function __construct(array $server, array $get, array $post, array $headers) {
        $this->server = $server;
        $this->get = $get;
        $this->post = $post;
        $this->headers = $headers;
    }
    
    /**
     * Gibt die HTTP-Methode zurück.
     *
     * @return string HTTP-Methode (GET, POST, etc.)
     */
    public function getMethod(): string {
        return $this->server['REQUEST_METHOD'] ?? 'GET';
    }
    
    /**
     * Gibt den Request-Pfad zurück.
     *
     * @return string Request-Pfad
     */
    public function getPath(): string {
        $path = parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return $path ?: '/';
    }
    
    /**
     * Gibt einen GET-Parameter zurück.
     *
     * @param string $name Parameter-Name
     * @param mixed $default Standardwert, wenn Parameter nicht existiert
     * @return mixed Parameter-Wert oder Default
     */
    public function getQuery(string $name, $default = null) {
        return $this->get[$name] ?? $default;
    }
    
    /**
     * Gibt alle GET-Parameter zurück.
     *
     * @return array Alle GET-Parameter
     */
    public function getAllQuery(): array {
        return $this->get;
    }
    
    /**
     * Gibt einen POST-Parameter zurück.
     *
     * @param string $name Parameter-Name
     * @param mixed $default Standardwert, wenn Parameter nicht existiert
     * @return mixed Parameter-Wert oder Default
     */
    public function getPost(string $name, $default = null) {
        return $this->post[$name] ?? $default;
    }
    
    /**
     * Gibt alle POST-Parameter zurück.
     *
     * @return array Alle POST-Parameter
     */
    public function getAllPost(): array {
        return $this->post;
    }
    
    /**
     * Gibt einen HTTP-Header zurück.
     *
     * @param string $name Header-Name
     * @param mixed $default Standardwert, wenn Header nicht existiert
     * @return mixed Header-Wert oder Default
     */
    public function getHeader(string $name, $default = null) {
        return $this->headers[$name] ?? $default;
    }
    
    /**
     * Gibt alle HTTP-Header zurück.
     *
     * @return array Alle HTTP-Header
     */
    public function getAllHeaders(): array {
        return $this->headers;
    }
    
    /**
     * Überprüft, ob die Anfrage AJAX/XMLHttpRequest ist.
     *
     * @return bool True, wenn AJAX-Request
     */
    public function isAjax(): bool {
        return ($this->getHeader('X-Requested-With') === 'XMLHttpRequest');
    }
    
    /**
     * Überprüft, ob die Anfrage eine bestimmte Content-Type hat.
     *
     * @param string $contentType Zu überprüfender Content-Type
     * @return bool True, wenn Content-Type übereinstimmt
     */
    public function isContentType(string $contentType): bool {
        $currentContentType = $this->getHeader('Content-Type');
        return $currentContentType && strpos($currentContentType, $contentType) !== false;
    }
}