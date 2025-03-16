<?php
declare(strict_types=1);

namespace Marques\Core;

abstract class AppCore
{
    protected AppPath $appPath;
    protected AppLogger $log;
    protected EventManager $eventManager;
    protected User $user; // User-Instanz

    public function __construct()
    {
        $this->appPath = AppPath::getInstance();
        $this->log = AppLogger::getInstance();
        $this->eventManager = new EventManager();
        $this->user = new User();
    }

    /**
     * Wrapper für den Zugriff auf Konfigurationswerte.
     * Diese Methode sorgt für Kompatibilität, indem sie intern 
     * AppSettings verwendet, um die Einstellungen abzurufen.
     *
     * @param string $key Der Schlüssel des Konfigurationswerts.
     * @param mixed $default Standardwert, falls der Schlüssel nicht existiert.
     * @return mixed
     */
    protected function getConfig(string $key, $default = null)
    {
        return AppSettings::getInstance()->getSetting($key, $default);
    }

    /**
     * Löst ein Event aus.
     *
     * @param string $eventName Name des Events.
     * @param mixed $data Optionale Daten für das Event.
     * @return mixed
     */
    protected function triggerEvent(string $eventName, $data = null)
    {
        return $this->eventManager->trigger($eventName, $data);
    }

    /**
     * Zentrale Fehlerbehandlung.
     *
     * @param \Exception $e Die aufgetretene Exception.
     * @param string $message Optionale Fehlermeldung.
     */
    protected function handleException(\Exception $e, string $message = ''): void
    {
        if (empty($message)) {
            $message = 'Ein unerwarteter Fehler ist aufgetreten.';
        }

        $this->log->error($message, [
            'exception_message' => $e->getMessage(),
            'file'              => $e->getFile(),
            'line'              => $e->getLine(),
            'trace'             => $e->getTraceAsString(),
        ]);

        $this->triggerEvent('exception_occurred', ['exception' => $e, 'message' => $message]);

        $statusCode = 500;
        if ($e instanceof NotFoundException) {
            $statusCode = 404;
        } elseif ($e instanceof PermissionException) {
            $statusCode = 403;
        }
        http_response_code($statusCode);

        $errorData = [
            'message'   => $message,
            'code'      => $statusCode,
            'debug'     => $this->getConfig('debug', false),
            'exception' => $e
        ];

        echo '<!DOCTYPE html><html><head><title>Fehler ' . $statusCode . '</title></head>';
        echo '<body><h1>Fehler ' . $statusCode . '</h1><p>' . htmlspecialchars($message) . '</p>';
        if ($errorData['debug']) {
            echo '<pre>' . print_r($errorData, true) . '</pre>';
        }
        echo '</body></html>';
        exit;
    }
}