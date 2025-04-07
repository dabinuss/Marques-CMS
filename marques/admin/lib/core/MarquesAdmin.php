<?php
declare(strict_types=1);

namespace Admin\Core;

// --- Kernklassen ---
use Marques\Core\Node;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Core\Logger;
use Marques\Core\Events;
use Marques\Core\Cache;
use Marques\Core\Path;
use Marques\Util\Helper;
use Marques\Service\ThemeManager;
use Marques\Service\User;
use Marques\Http\Router as AppRouter;
use Marques\Http\Request;
use Marques\Service\PageManager;
use Marques\Service\BlogManager; 
use Marques\Data\MediaManager; 

// --- Admin spezifische Klassen ---
use Admin\Http\Router as AdminRouter;
use Admin\Core\Template as AdminTemplate;
use Admin\Core\Statistics;
use Admin\Auth\Service;
use Admin\Auth\Middleware;

// --- Controller ---
use Admin\Controller\DashboardController;
use Admin\Controller\AuthController;
use Admin\Controller\PageController;
use Admin\Controller\SettingsController;
use Admin\Controller\BlogController;

use RuntimeException;
use InvalidArgumentException;
use UnexpectedValueException;

class MarquesAdmin
{
    private DatabaseHandler $dbHandler;
    private AdminTemplate $template;
    private Node $adminContainer;
    private Service $Service;
    private array $systemConfig = [];

    public function __construct(Node $rootContainer)
    {
        $this->initContainer($rootContainer);

        $this->dbHandler    = $this->adminContainer->get(DatabaseHandler::class);
        $this->Service  = $this->adminContainer->get(Service::class);
        $this->template     = $this->adminContainer->get(AdminTemplate::class);

        try {
            // Lade Basiskonfiguration für init()
            $this->systemConfig = $this->dbHandler->table('settings')->where('id', '=', 1)->first() ?: [];
        } catch (\Exception $e) {
            $this->adminContainer->get(Logger::class)->error("Admin Init: Konnte Settings nicht laden.", ['exception' => $e]);
        }
    }

    private function initContainer(Node $rootContainer): void
    {
        $this->adminContainer = new Node($rootContainer);

        // Admin Template Engine
        $this->adminContainer->register(AdminTemplate::class, function(Node $container) {
            return new AdminTemplate(
                $container->get(DatabaseHandler::class),
                $container->get(ThemeManager::class),
                $container->get(Path::class),
                $container->get(Cache::class),
                $container->get(Helper::class),
                $container->get(AdminRouter::class) // AdminRouter ist jetzt verfügbar
            );
        });

        // Admin Authentifizierungsdienst
        $this->adminContainer->register(Service::class, function(Node $container) {
             $securityConfig = [];
             try {
                 $settings = $container->get(DatabaseHandler::class)->table('settings')->select(['security'])->where('id', '=', 1)->first();
                 $securityConfig = $settings['security'] ?? [];
             } catch (\Exception $e) {
                 $container->get(Logger::class)->warning("Service Init: Konnte Security-Settings nicht laden.", ['exception' => $e]);
             }
            return new Service(
                $container->get(User::class),
                $securityConfig
            );
        });

        // Admin Statistik Dienst
        $this->adminContainer->register(Statistics::class, function(Node $container) {
            $getDep = function(string $class) use ($container) {
                return $container->get($class);
            };
            return new Statistics(
                $getDep(DatabaseHandler::class),
                $getDep(User::class),
                $getDep(PageManager::class),
                $getDep(BlogManager::class),
                $getDep(MediaManager::class) // Korrigiert: MediaManager statt Helper
            );
        });

        // Admin AdminRouter
        $this->adminContainer->register(AdminRouter::class, function(Node $container) {
            // Vorsicht vor zirkulären Abhängigkeiten!
            return new AdminRouter(
                $container, // Container wird übergeben
                $container->get(DatabaseHandler::class),
                false // Debugging-Flag
            );
        });

        // --- Admin Controller Registrierungen ---

        // Helferfunktion für DI-Auflösung (vermeidet Code-Duplizierung)
        $resolve = function(string $class) use ($rootContainer) {
            if ($rootContainer->has($class)) {
                $instance = $rootContainer->get($class);
                if (!$instance instanceof $class) {
                    throw new RuntimeException("Invalid type for dependency: $class");
                }
                return $instance;
            }
            if ($this->adminContainer->has($class)) {
                $instance = $this->adminContainer->get($class);
                if (!$instance instanceof $class) {
                    throw new RuntimeException("Invalid type for dependency: $class");
                }
                return $instance;
            }
            throw new RuntimeException("Dependency '$class' not found");
        };

        $this->adminContainer->register(DashboardController::class, function(Node $container) use ($resolve) {
            return new DashboardController(
                $container->get(AdminTemplate::class),
                $container->get(Statistics::class),
                $resolve(AdminRouter::class),
                $resolve(Helper::class),
                $resolve(DatabaseHandler::class),
                $resolve(PageManager::class),
                $resolve(BlogManager::class),
                $resolve(Cache::class),
                $resolve(User::class),
                $resolve(Logger::class)
            );
        });

        $this->adminContainer->register(AuthController::class, function(Node $container) use ($resolve) {
            return new AuthController(
                $container->get(AdminTemplate::class),
                $container->get(Service::class),
                $resolve(Helper::class),
                $resolve(AdminRouter::class)
            );
        });

        $this->adminContainer->register(PageController::class, function(Node $container) use ($resolve) {
            return new PageController(
                 $container->get(AdminTemplate::class),
                 $resolve(PageManager::class),
                 $resolve(Helper::class)
            );
        });

         $this->adminContainer->register(SettingsController::class, function(Node $container) use ($resolve) {
             return new SettingsController(
                 $container->get(AdminTemplate::class),
                 $resolve(DatabaseHandler::class),
                 $resolve(Helper::class)
             );
         });

         $this->adminContainer->register(BlogController::class, function(Node $container) use ($resolve) {
             return new BlogController(
                 $container->get(AdminTemplate::class),
                 $resolve(BlogManager::class),
                 $resolve(Helper::class)
             );
         });

         // Registriere hier weitere Controller (UserController, MediaController etc.)
    }

    public function getContainer(): Node
    {
        return $this->adminContainer;
    }

    private function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $isSecure = ($_SERVER['HTTPS'] ?? '') !== '' || 
                       ($_SERVER['SERVER_PORT'] ?? '') === '443';
            
            session_start([
                'cookie_httponly' => true,
                'cookie_secure' => $isSecure,
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => true
            ]);
            
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
        }
    }

    public function init(): void
    {
        $this->startSession();

        if (!defined('MARQUES_ROOT_DIR')) exit('Direkter Zugriff ist nicht erlaubt.');

        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
        $basePath = '';
        $requestPath = str_replace($basePath, '', $requestPath);

        $isAdminPath = strpos($requestPath, MARQUES_ADMIN_DIR) === 0;
        $loginPath = MARQUES_ADMIN_DIR . '/login';

        /*
        if ($isAdminPath && $requestPath !== $loginPath && !$this->Service->isLoggedIn()) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $loginUrl = $scheme . '://' . $host . $basePath . MARQUES_ADMIN_DIR . '/login';
    
            header('Location: ' . $loginUrl, true, 302);
            exit;
        }
*/
        if ($isAdminPath && strpos($requestPath, $loginPath) !== 0 && !$this->Service->isLoggedIn()) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $loginUrl = $scheme . '://' . $host . $basePath . MARQUES_ADMIN_DIR . '/login';

            header('Location: ' . $loginUrl, true, 302);
            exit;
        }

        // Fehler-Reporting und Zeitzone
        $debugMode = $this->systemConfig['debug'] ?? false;
        error_reporting($debugMode ? E_ALL : 0);
        ini_set('display_errors', $debugMode ? '1' : '0');
        date_default_timezone_set($this->systemConfig['timezone'] ?? 'UTC');

        // CSRF Token in Session
        if (empty($_SESSION['csrf_token'])) {
             $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public function run(): void
    {
        try {
            $this->triggerEvent('before_request');
    
            /** @var AdminRouter $adminRouter */
            $adminRouter = $this->adminContainer->get(AdminRouter::class);
            
            // Stelle sicher, dass die erforderlichen Routen vorhanden sind
            if (method_exists($adminRouter, 'defineRoutes')) {
                $adminRouter->defineRoutes();
            }
            
            // Prüfe auf erforderliche Routen
            if (method_exists($adminRouter, 'ensureRoutes')) {
                $adminRouter->ensureRoutes();
            }
            
            // Verarbeite die Anfrage
            $routeResult = $adminRouter->processRequest();
            
            // Wenn ein Array zurückgegeben wurde, übergib es an das Template
            if (is_array($routeResult)) {
                // Extrahiere templateKey wenn vorhanden
                $templateKey = 'dashboard';
                if (isset($routeResult['template'])) {
                    $templateKey = $routeResult['template'];
                    unset($routeResult['template']);
                }
                // Render the template with the route result data
                $this->template->render($routeResult, $templateKey);
            }
    
            $this->triggerEvent('after_render');
    
        } catch (\Exception $e) {
            $this->handleException($e);
            exit;
        }
    }

    private function handleException(\Exception $e): void
    {
        $statusCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
        http_response_code($statusCode); // Status Code setzen
    
        // Fehler loggen (wichtig!)
        try {
             $this->adminContainer->get(Logger::class)->error($e->getMessage(), ['exception' => $e]);
        } catch (\Throwable $logErr) {
             error_log("FEHLER BEIM LOGGEN in handleException: " . $logErr->getMessage());
             error_log("Ursprünglicher Fehler: " . $e->getMessage());
        }
    
    
        echo '<h1>Fehler ' . $statusCode . '</h1>';
        echo '<p>Ein Problem ist aufgetreten.</p>';
        // Debug-Infos nur wenn aktiviert
        $showDetails = ($this->systemConfig['debug'] ?? false);
        if ($showDetails) {
            echo '<pre>';
            echo 'Meldung: ' . htmlspecialchars($e->getMessage()) . "\n";
            echo 'Datei: ' . htmlspecialchars($e->getFile()) . ' (Zeile ' . $e->getLine() . ")\n";
            echo "Trace:\n" . htmlspecialchars($e->getTraceAsString());
            echo '</pre>';
        }
    
        /* // Temporär auskommentiert:
        try {
             $this->template->render([
                 'error_code' => $statusCode,
                 'error_message' => $this->getErrorMessageForCode($statusCode), // Evtl. Helfermethode erstellen
                 'exception_details' => $showDetails ? [
                     'message' => htmlspecialchars($e->getMessage(), ENT_QUOTES),
                     'trace' => array_map(
                         fn($t) => htmlspecialchars(print_r($t, true), ENT_QUOTES),
                         $e->getTrace()
                     )
                 ] : null
             ], 'error');
         } catch (\Exception $renderError) {
             error_log("FEHLER BEIM RENDERN DER FEHLERSEITE: " . $renderError->getMessage());
             // Fallback, falls das Rendern fehlschlägt
             echo '<h1>Fehler ' . $statusCode . '</h1><p>Ein kritisches Problem ist aufgetreten, die Fehlerseite konnte nicht angezeigt werden.</p>';
             if ($showDetails) {
                 echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
             }
         }
        */
    
        exit; // Wichtig: Skript beenden
    }
    
    // Kleine Helfermethode (optional)
    private function getErrorMessageForCode(int $code): string {
        switch ($code) {
            case 404: return 'Die angeforderte Seite wurde nicht gefunden.';
            case 403: return 'Zugriff verweigert.';
            case 500: return 'Ein interner Serverfehler ist aufgetreten.';
            default: return 'Ein unerwarteter Fehler ist aufgetreten.';
        }
    }

    private function triggerEvent(string $event, $data = null)
    {
        try {
            // Prüfen, ob EventManager registriert ist
            if ($this->adminContainer->has(Events::class)) {
                $eventManager = $this->adminContainer->get(Events::class);
                return $eventManager->trigger($event, $data);
            }
        } catch (\Exception $e) {
             try {
                  $this->adminContainer->get(Logger::class)->error("Fehler im Event-Manager ($event): " . $e->getMessage());
             } catch (\Exception $logError) {
                  error_log("FEHLER BEIM LOGGEN (Event): " . $logError->getMessage());
             }
        }
        return $data; // Ursprüngliche Daten zurückgeben im Fehlerfall
    }
}