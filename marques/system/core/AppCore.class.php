<?php
declare(strict_types=1);

namespace Marques\Core;

abstract class AppCore
{
    protected ConfigManager $configManager;
    protected AppLogger $log;
    protected EventManager $eventManager;
    protected User $user; // User-Instanz

    public function __construct()
    {
        // Diese Dienste werden *immer* benötigt und sind für alle Subklassen relevant.
        $this->configManager = ConfigManager::getInstance();
        $this->log = AppLogger::getInstance();          // AppLogger direkt instanziieren
        $this->eventManager = new EventManager(); // EventManager direkt instanziieren
        $this->user = new User();
    }

    /**
     * Zentrale Fehlerbehandlung.
     *
     * @param \Exception $e Die aufgetretene Exception.
     * @param string $message Eine optionale, spezifische Fehlermeldung.
     */
    protected function handleException(\Exception $e, string $message = ''): void
    {
        // Standardnachricht, falls keine spezifische Nachricht übergeben wurde
        if (empty($message)) {
            $message = 'Ein unerwarteter Fehler ist aufgetreten.';
        }

        // Loggen des Fehlers (immer)
        $this->log->error($message, [
            'exception_message' => $e->getMessage(),
            'file'            => $e->getFile(),
            'line'            => $e->getLine(),
            'trace'           => $e->getTraceAsString(),
        ]);

        // Event auslösen
        $this->triggerEvent('exception_occurred', ['exception' => $e, 'message' => $message]);

        // HTTP-Statuscode bestimmen (Beispiel)
        $statusCode = 500;
        if ($e instanceof NotFoundException) {
            $statusCode = 404;
        } elseif ($e instanceof PermissionException) {
            $statusCode = 403;
        }
        http_response_code($statusCode);

        // Daten für Fehlerseite vorbereiten
        $errorData = [
            'message' => $message,
            'code'    => $statusCode,
            'debug'   => $this->getConfig('debug', false), // Debug-Modus aus Config, über Methode
            'exception' => $e // Das Exception-Objekt für Debug-Zwecke
        ];

        // Fehlerseite anzeigen (Beispiel - hier vereinfacht)
        echo '<!DOCTYPE html><html><head><title>Fehler ' . $statusCode . '</title></head>';
        echo '<body><h1>Fehler ' . $statusCode . '</h1><p>' . htmlspecialchars($message) . '</p>';
        if ($errorData['debug']) {
            echo '<pre>' . print_r($errorData, true) . '</pre>';
        }
        echo '</body></html>';
        exit;
    }

    /**
     * Holt einen Konfigurationswert.
     *
     * @param string $key Der Schlüssel des Konfigurationswerts.
     * @param mixed $default Der Standardwert, falls der Schlüssel nicht existiert.
     * @return mixed Der Konfigurationswert oder der Standardwert.
     */
    protected function getConfig(string $key, $default = null)
    {
        return $this->configManager->get($key, $default);
    }

    /**
     * Löst ein Event aus.
     *
     * @param string $eventName Der Name des Events.
     * @param mixed $data Optionale Daten, die an den Event-Handler übergeben werden.
     * @return mixed Das Ergebnis des Event-Handlers (falls vorhanden).
     */
    protected function triggerEvent(string $eventName, $data = null)
    {
        return $this->eventManager->trigger($eventName, $data);
    }

    // Weitere gemeinsame Methoden können hier hinzugefügt werden, z.B.:
    // protected function getDatabaseConnection() { ... }
    // protected function sanitizeInput(string $input): string { ... }

    // Optionale abstrakte Methoden (Beispiele):
    // abstract public function init(): void; // Wenn *jede* Subklasse eine init-Methode haben *muss*
    // abstract protected function authorize(): bool; // Wenn *jede* Subklasse eine Autorisierung durchführen *muss*
}