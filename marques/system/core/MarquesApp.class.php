<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * marques CMS - Hauptanwendungsklasse (kombiniert Bootstrap und Application)
 */
class MarquesApp extends AppCore
{
    private Router $router;
    private Template $template;
    private AppContainer $appcontainer;

    public function __construct()
    {
        parent::__construct();

        // AppContainer als Klassen-Property initialisieren
        $this->appcontainer = new AppContainer();
        $this->appcontainer->register(SettingsManager::class);
        $this->appcontainer->register(User::class);

        $this->router = new Router();
        $this->template = new Template();
    }

    public function init(): void
    {
        // Session starten
        session_start();

        // Direkten Zugriff verhindern
        if (!defined('MARQUES_ROOT_DIR')) {
            exit('Direkter Zugriff ist nicht erlaubt.');
        }

        $systemConfig = $this->configManager->load('system') ?: [];
        $this->appcontainer->register('config', $systemConfig);

        // Systemeinstellungen laden
        $settings = $this->appcontainer->get(SettingsManager::class);

        // Fehlerberichterstattung einrichten
        if ($settings->getSetting('debug', false)) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        } else {
            error_reporting(0);
            ini_set('display_errors', 0);
        }

        // Zeitzone einrichten
        date_default_timezone_set($settings->getSetting('timezone', 'UTC'));

        // Wartungsmodus prüfen (außer für Admin-Bereich)
        if (!defined('IS_ADMIN') && $settings->getSetting('maintenance_mode', false)) {
            $maintenance_message = $settings->getSetting('maintenance_message', 'Die Website wird aktuell gewartet.');

            // User bereits in AppCore initialisiert
            if (!$this->user->isAdmin()) {
                header('HTTP/1.1 503 Service Temporarily Unavailable');
                header('Status: 503 Service Temporarily Unavailable');
                header('Retry-After: 3600'); // Eine Stunde
                echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Wartungsmodus - ' . htmlspecialchars($settings->getSetting('site_name', 'marques CMS')) . '</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;background-color:#f8f9fa;color:#212529;margin:0;padding:0;display:flex;height:100vh;align-items:center;justify-content:center}.maintenance-container{text-align:center;max-width:600px;padding:2rem;background-color:white;border-radius:.5rem;box-shadow:0 4px 6px rgba(0,0,0,.1)}h1{color:#343a40;margin-top:0}p{font-size:1.1rem;line-height:1.6;color:#6c757d}.icon{font-size:4rem;margin-bottom:1rem;color:#007bff}</style></head><body><div class="maintenance-container"><div class="icon">⚙️</div><h1>Website wird gewartet</h1><p>' . htmlspecialchars($maintenance_message) . '</p></div></body></html>';
                exit;
            }
        }

        // Nur Seitenaufrufe von echten Benutzern erfassen (keine Bots, keine Admin-Besuche)
        if (!defined('IS_ADMIN') && !preg_match('/(bot|crawler|spider|slurp|bingbot|googlebot)/i', $_SERVER['HTTP_USER_AGENT'] ?? '')) {
            $statsDir = $this->appPath->combine('logs', 'stats');
            if (!is_dir($statsDir)) {
                @mkdir($statsDir, 0755, true);
            }
            $logData = [
                'time'       => date('Y-m-d H:i:s'),
                'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
                'url'        => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") 
                                 . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}",
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'referrer'   => $_SERVER['HTTP_REFERER'] ?? ''
            ];
            $parts = explode('.', $logData['ip']);
            if (count($parts) === 4) {
                $logData['ip'] = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
            }
            $logFile = $statsDir . '/' . date('Y-m-d') . '.log';
            @file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND);
        }

        // Hilfsfunktionen laden (z. B. Exceptions)
        require_once $this->appPath->combine('core', 'Exceptions.inc.php');

        // Hilfsfunktionen für das Theme-System (bleiben global)
        //\marques_init_default_theme();
    }

    public function run(): void
    {
        try {
            $this->triggerEvent('before_request');
            $route = $this->router->processRequest();
            $route = $this->triggerEvent('after_routing', $route);
            $content = new Content();
            $pageData = $content->getPage($route['path']);
            $pageData = $this->triggerEvent('before_render', $pageData);
            $this->template->render($pageData);
            $this->triggerEvent('after_render');
        } catch (\Exception $e) {
            $this->handleException($e);
        }
    }
}
