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
use Marques\Service\PageManager;
use Marques\Service\BlogManager; 
use Marques\Data\MediaManager; 
use Marques\Util\SafetyXSS;
use Marques\Util\ExceptionHandler;

// --- Admin spezifische Klassen ---
use Admin\Http\Router as AdminRouter;
use Admin\Core\Template as AdminTemplate;
use Admin\Core\Statistics;
use Admin\Auth\Service;

// --- Controller ---
use Admin\Controller\DashboardController;
use Admin\Controller\AuthController;
use Admin\Controller\PageController;
use Admin\Controller\SettingsController;
use Admin\Controller\BlogController;

use RuntimeException;

class MarquesAdmin
{
    private DatabaseHandler $dbHandler;
    private AdminTemplate $template;
    private Node $adminContainer;
    private Service $Service;
    private array $systemConfig = [];
    private ExceptionHandler $exceptionHandler;
    private Logger $logger;

    public function __construct(Node $rootContainer)
    {
        $this->initContainer($rootContainer);

        $this->dbHandler    = $this->adminContainer->get(DatabaseHandler::class);
        $this->Service      = $this->adminContainer->get(Service::class);
        $this->template     = $this->adminContainer->get(AdminTemplate::class);
        $this->logger       = $this->adminContainer->get(Logger::class);

        try {
            // Lade Basiskonfiguration für init()
            $this->systemConfig = $this->dbHandler->table('settings')->where('id', '=', 1)->first() ?: [];

            $debugMode = $this->systemConfig['debug'] ?? false;
            $this->exceptionHandler = new ExceptionHandler($debugMode, $this->logger);
            $this->exceptionHandler->register();
        } catch (\Exception $e) {
            $this->logger->error("Admin Init: Konnte Settings nicht laden.", ['exception' => $e]);
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
                $container->get(AdminRouter::class), // AdminRouter ist jetzt verfügbar
                $container->get(SafetyXSS::class), // Sicherheitstools für XSS-Schutz
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

        $this->adminContainer->register(\Admin\Auth\Middleware::class, function(Node $container) {
            return new \Admin\Auth\Middleware(
                $container->get(Service::class),
                $container->get(AdminRouter::class) // Router hier übergeben!
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

        $this->adminContainer->register(AuthController::class, function(Node $container) {
            // Neuer Ansatz: Übergebe den Container an den Controller
            return new AuthController($container);
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

        $this->adminContainer->register(SafetyXSS::class, function(Node $container) {
        return new SafetyXSS();
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
        }
        
        // Generiere immer einen CSRF-Token (oder stelle sicher, dass einer existiert)
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public function init(): void
    {
        // Starte ein Output-Buffer
        ob_start();

        $this->startSession();

        // Request-Path + volle URI holen
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
        $fullRequestUri = $_SERVER['REQUEST_URI'] ?? '';
    
        // Normalisiere Admin-Path
        $adminRegex = '#^' . preg_quote(MARQUES_ADMIN_DIR, '#') . '(/|$)#';
        $isAdminPath = preg_match($adminRegex, $requestPath);
    
        // Login-/Assets-Erkennung (Login **ohne Query beachten**)
        $loginPath = MARQUES_ADMIN_DIR . '/login';
        $isLoginPath = rtrim($requestPath, '/') === $loginPath;
        $isAssetsPath = strpos($requestPath, MARQUES_ADMIN_DIR . '/assets') === 0;

        // Fehler-/Debug-Einstellungen
        $debugMode = $this->systemConfig['debug'] ?? false;
        error_reporting($debugMode ? E_ALL : 0);
        ini_set('display_errors', $debugMode ? '1' : '0');
        date_default_timezone_set($this->systemConfig['timezone'] ?? 'UTC');
    
        // CSRF Token initialisieren
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
    
            // Routen definieren
            if (method_exists($adminRouter, 'defineRoutes')) {
                $adminRouter->defineRoutes();
            }
            if (method_exists($adminRouter, 'ensureRoutes')) {
                $adminRouter->ensureRoutes();
            }
    
            // Anfrage verarbeiten
            $routeResult = $adminRouter->processRequest();
    
            // Response verarbeiten
            if ($routeResult instanceof \Marques\Http\Response\ViewResponse) {

                try {
                    // Versuchen wir dashboard als Fallback-Template
                    $this->template->render([
                        'username' => $_SESSION['marques_user']['username'] ?? 'Gast',
                        'title' => 'Administration',
                        'content' => 'Willkommen im Administrationsbereich. Bitte melden Sie sich an.',
                        // Ggf. weitere benötigte Variablen
                    ], 'dashboard');  // Versuche, ein bekanntes Admin-Template zu verwenden
                } catch (\Exception $templateEx) {
                    // Falls dashboard-Template auch nicht existiert, versuchen wir login
                    try {
                        $this->template->render([
                            'username' => $_SESSION['marques_user']['username'] ?? '',
                            'title' => 'Admin Login',
                            // Ggf. weitere benötigte Variablen für Login
                        ], 'login');
                    } catch (\Exception $loginEx) {
                        // Letzter Versuch: Direkte Ausgabe, wenn kein Template funktioniert
                        echo '<!DOCTYPE html><html><head><title>Admin</title></head><body>';
                        echo '<h1>Administration</h1>';
                        echo '<p>Es gab ein Problem beim Laden der Administrationsseite.</p>';
                        echo '<p>Bitte kontaktieren Sie den Systemadministrator.</p>';
                        echo '</body></html>';
                        
                        // Logge den Fehler
                        error_log("Fehler beim Laden der Admin-Templates: " . $templateEx->getMessage());
                        error_log("Login-Template-Fehler: " . $loginEx->getMessage());
                    }
                }
            } elseif ($routeResult instanceof \Marques\Http\Response) {
                // Andere Response-Objekte normal ausführen
                $routeResult->execute();
            } elseif (is_array($routeResult) && isset($routeResult['template'])) {
                // Rückwärtskompatibilität: Array mit Template-Key
                $templateKey = $routeResult['template'];
                unset($routeResult['template']);
                $this->template->render($routeResult, $templateKey);
            } elseif ($routeResult === null) {
                // Controller hat bereits Output generiert (alte Methode)
                // Nichts tun
            } else {
                // Fehlerfall
                throw new \RuntimeException(
                    'Controller hat ungültiges Ergebnis zurückgegeben. Erwartet: Response-Objekt, Array mit template-Key oder null.'
                );
            }
    
            $this->triggerEvent('after_render');
    
        } catch (\Exception $e) {
            // Zentrale Fehlerbehandlung
            try {
                // Versuche, einen Fehler anzuzeigen
                echo '<!DOCTYPE html><html><head><title>Fehler</title></head><body>';
                echo '<h1>Ein Fehler ist aufgetreten</h1>';
                echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '</body></html>';
            } catch (\Exception $displayEx) {
                // Letzte Möglichkeit: Plain text
                echo "FEHLER: " . $e->getMessage();
            }
            
            // Logge den ursprünglichen Fehler
            error_log("FATAL ERROR in run(): " . $e->getMessage());
            error_log("Trace: " . $e->getTraceAsString());
        } finally {
            // Output-Buffer-Handling
            if (ob_get_level() > 0 && !headers_sent()) {
                ob_end_flush();
            } elseif (ob_get_level() > 0) {
                error_log("[Run] WARNING: Output buffer still active but headers already sent!");
                ob_end_clean();
            }
        }
    }

    // Kleine Helfermethode (optional)
    private function getErrorMessageForCode(int $code): string {
        switch ($code) {
            case 404: return 'Die angeforderte Seite wurde nicht gefunden.';
            case 403: return 'Zugriff verweigert.';
            case 500: return 'Ein interner Serverfehler ist aufgetreten.';
            default: return 'Ein unerwarteter Fehler ist aufgetreten.2';
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

    /**
     * Prüft, ob eine Redirect-URL sicher ist.
     * 
     * @param string $url Die zu prüfende URL
     * @return bool True, wenn die URL als sicher gilt
     */
    private function isValidRedirectUrl(string $url): bool {
        // Akzeptiere nur relative URLs, die mit / beginnen
        if (substr($url, 0, 1) !== '/') {
            return false;
        }
        
        // Verhindere Protocol-Relative URLs (//example.com)
        if (substr($url, 0, 2) === '//') {
            return false;
        }
        
        // Optional: Beschränke auf bestimmte Pfade
        if (substr($url, 0, 7) === '/admin/') {
            return true;
        }
        
        return false;
    }
}