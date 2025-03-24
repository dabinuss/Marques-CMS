<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * Response-Klasse für die Kapselung der Response-Daten.
 */
class AppRouterResponse {
    private string $content;
    private int $statusCode;
    private array $headers;
    
    /**
     * Konstruktor
     *
     * @param string $content Response-Inhalt
     * @param int $statusCode HTTP-Status-Code
     * @param array $headers HTTP-Header
     */
    public function __construct(string $content = '', int $statusCode = 200, array $headers = []) {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }
    
    /**
     * Gibt den Response-Inhalt zurück.
     *
     * @return string Response-Inhalt
     */
    public function getContent(): string {
        return $this->content;
    }
    
    /**
     * Setzt den Response-Inhalt.
     *
     * @param string $content Neuer Response-Inhalt
     * @return self Für Method-Chaining
     */
    public function setContent(string $content): self {
        $this->content = $content;
        return $this;
    }
    
    /**
     * Gibt den HTTP-Status-Code zurück.
     *
     * @return int HTTP-Status-Code
     */
    public function getStatusCode(): int {
        return $this->statusCode;
    }
    
    /**
     * Setzt den HTTP-Status-Code.
     *
     * @param int $statusCode Neuer HTTP-Status-Code
     * @return self Für Method-Chaining
     */
    public function setStatusCode(int $statusCode): self {
        $this->statusCode = $statusCode;
        return $this;
    }
    
    /**
     * Fügt einen HTTP-Header hinzu.
     *
     * @param string $name Header-Name
     * @param string $value Header-Wert
     * @return self Für Method-Chaining
     */
    public function addHeader(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }
    
    /**
     * Gibt alle HTTP-Header zurück.
     *
     * @return array Alle HTTP-Header
     */
    public function getHeaders(): array {
        return $this->headers;
    }
    
    /**
     * Sendet die Response an den Client.
     */
    public function send(): void {
        // Setze HTTP-Status-Code
        http_response_code($this->statusCode);
        
        // Setze HTTP-Header
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        
        // Ausgabe des Inhalts
        echo $this->content;
        exit;
    }
}