<?php
namespace Marques\Core;

/**
 * Class AppExceptions
 *
 * Eine erweiterte Exception-Klasse, die zusätzliche Funktionalitäten wie einen HTTP-Statuscode und benutzerdefinierte Fehlerdaten bereitstellt.
 */
class AppExceptions extends \Exception
{
    /**
     * HTTP-Statuscode, der für die Exception verwendet wird.
     * Standardmäßig auf 500 (Internal Server Error) gesetzt.
     *
     * @var int
     */
    protected $statusCode = 500;

    /**
     * Zusätzliche Daten, die im Fehlerkontext nützlich sein könnten.
     *
     * @var array
     */
    protected $errorData = [];

    /**
     * AppExceptions constructor.
     *
     * @param string         $message   Die Fehlermeldung.
     * @param int            $statusCode HTTP-Statuscode (z.B. 404, 500, etc.).
     * @param array          $errorData  Weitere optionale Daten, die den Fehler näher beschreiben.
     * @param \Throwable|null $previous  Die vorherige Exception (optional, für Exception-Chaining).
     */
    public function __construct(string $message = "", int $statusCode = 500, array $errorData = [], \Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        $this->errorData = $errorData;
        parent::__construct($message, 0, $previous);
    }

    /**
     * Gibt den HTTP-Statuscode zurück, der dieser Exception zugeordnet ist.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Gibt die zusätzlichen Fehlerdaten zurück.
     *
     * @return array
     */
    public function getErrorData(): array
    {
        return $this->errorData;
    }

    /**
     * Setzt den HTTP-Statuscode neu.
     *
     * @param int $statusCode
     * @return self
     */
    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Fügt zusätzliche Fehlerdaten hinzu.
     *
     * @param string $key   Der Schlüssel für die Daten.
     * @param mixed  $value Der Wert, der hinzugefügt wird.
     * @return self
     */
    public function addErrorData(string $key, $value): self
    {
        $this->errorData[$key] = $value;
        return $this;
    }

    /**
     * Erstellt eine neue AppExceptions-Instanz aus einer anderen Throwable.
     * Nützlich, um z.B. native Exceptions in eine AppExceptions zu überführen.
     *
     * @param \Throwable $e         Die ursprüngliche Exception.
     * @param int        $statusCode Der HTTP-Statuscode, der verwendet werden soll.
     * @param array      $errorData  Optionale zusätzliche Fehlerdaten.
     * @return self
     */
    public static function fromThrowable(\Throwable $e, int $statusCode = 500, array $errorData = []): self
    {
        return new self($e->getMessage(), $statusCode, $errorData, $e);
    }

    /**
     * Eine benutzerfreundliche Darstellung der Exception.
     *
     * @return string
     */
    public function __toString(): string
    {
        $baseString = sprintf(
            "Exception '%s' in %s:%d\nMessage: %s\nStatus Code: %d\n",
            get_class($this),
            $this->getFile(),
            $this->getLine(),
            $this->getMessage(),
            $this->getStatusCode()
        );

        if (!empty($this->errorData)) {
            $baseString .= "Additional Data: " . print_r($this->errorData, true) . "\n";
        }

        $baseString .= "Stack Trace:\n" . $this->getTraceAsString();
        return $baseString;
    }
}
