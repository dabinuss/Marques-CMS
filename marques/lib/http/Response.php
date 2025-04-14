<?php
declare(strict_types=1);

namespace Marques\Http;

/**
 * Response-Klasse für die Kapselung der Response-Daten.
 */
abstract class Response {
    protected string $content = '';
    protected int $statusCode = 200;
    protected array $headers = [];
    
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
     * Alias für withHeader (Kompatibilität mit dem neuen Pattern)
     */
    public function withHeader(string $name, string $value): self {
        return $this->addHeader($name, $value);
    }
    
    /**
     * Alias für setStatusCode (Kompatibilität mit dem neuen Pattern)
     */
    public function withStatus(int $statusCode): self {
        return $this->setStatusCode($statusCode);
    }
    
    /**
     * Sendet die HTTP-Header
     */
    protected function sendHeaders(): void {
        if (headers_sent()) {
            return;
        }

        http_response_code($this->statusCode);
        
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
    }
    
    /**
     * Führt die Response aus (abstrakte Methode für das neue Pattern)
     */
    abstract public function execute(): void;
    
    /**
     * Sendet die Response an den Client (bestehende Methode)
     * @deprecated Bitte stattdessen execute() verwenden
     */
    public function send(): void {
        $this->execute();
    }
}