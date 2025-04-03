<?php
declare(strict_types=1);

namespace Marques\Admin;

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
use Marques\Http\Router;
use Marques\Http\Request;
use Marques\Service\PageManager;
use Marques\Service\BlogManager; 
use Marques\Data\MediaManager; 
use Marques\Admin\AdminRouter;

// --- Admin spezifische Klassen ---
use Marques\Admin\AdminTemplate;
use Marques\Admin\AdminAuthService;
use Marques\Admin\AdminStatistics;

// --- Controller ---
use Marques\Admin\Controller\DashboardController;
use Marques\Admin\Controller\AuthController;
use Marques\Admin\Controller\PageController;
use Marques\Admin\Controller\SettingsController;
use Marques\Admin\Controller\BlogController;

use RuntimeException;
use InvalidArgumentException;
use UnexpectedValueException;

class MarquesAdmin
{
    private DatabaseHandler $dbHandler;
    private AdminTemplate $template;
    private Node $adminContainer;
    private AdminAuthService $authService;
    private array $systemConfig = [];

    public function __construct(Node $rootContainer)
    {
        $this->initContainer($rootContainer);

        $this->dbHandler    = $this->adminContainer->get(DatabaseHandler::class);
        $this->authService  = $this->adminContainer->get(AdminAuthService::class);
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
                $container->get(AdminRouter::class),
            );
        });

        // Admin Authentifizierungsdienst
        $this->adminContainer->register(AdminAuthService::class, function(Node $container) {
             $securityConfig = [];
             try {
                 $settings = $container->get(DatabaseHandler::class)->table('settings')->select(['security'])->where('id', '=', 1)->first();
                 $securityConfig = $settings['security'] ?? [];
             } catch (\Exception $e) {
                 $container->get(Logger::class)->warning("AuthService Init: Konnte Security-Settings nicht laden.", ['exception' => $e]);
             }
            return new AdminAuthService(
                $container->get(User::class),
                $securityConfig
            );
        });

        // Admin Statistik Dienst
        $this->adminContainer->register(AdminStatistics::class, function(Node $container) {
            $getDep = function(string $class) use ($container) {
                return $container->get($class);
            };
            return new AdminStatistics(
                $getDep(DatabaseHandler::class),
                $getDep(User::class),
                $getDep(PageManager::class),
                $getDep(BlogManager::class),
                $getDep(MediaManager::class) // Korrigiert: MediaManager statt Helper
            );
        });

        // Admin Router
        $this->adminContainer->register(AdminRouter::class, function(Node $container) {
            return new AdminRouter(
                $container, // Konsistente DI
                $container->get(DatabaseHandler::class),
                false
            )->defineRoutes();
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
                $container->get(AdminStatistics::class),
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
                $container->get(AdminAuthService::class),
                $resolve(Helper::class)
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

    /**
     * Definiert die Routen für den Admin-Bereich.
     */
    private function defineAdminRoutes(Router $router): Router
    {
        // Login / Logout (kein Auth-Schutz)
        $router->get('/login', AuthController::class . '@showLoginForm')->name('admin.login');
        $router->post('/login', AuthController::class . '@handleLogin')->name('admin.login.post');
        $router->get('/logout', AuthController::class . '@logout')->name('admin.logout');

        // Geschützte Routen mit Middleware
        $authMiddleware = new AdminAuthMiddleware($this->authService);

        $csrfMiddleware = function(Request $req, array $params, callable $next) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $token = $_POST['csrf_token'] ?? '';
                if (!$this->authService->validateCsrfToken($token)) {
                    throw new UnexpectedValueException("CSRF-Token validation failed", 403);
                }
            }
            return $next($req, $params);
        };

        $router->group(['middleware' => [$authMiddleware, $csrfMiddleware]], function(Router $router) 
        {
            // Dashboard
            $router->get('/dashboard', DashboardController::class . '@index')->name('admin.dashboard');
            $router->get('/', DashboardController::class . '@index')->name('admin.dashboard');
    
            // Seiten
            $router->get('/pages', PageController::class . '@list')->name('admin.pages.list');
            $router->get('/pages/add', PageController::class . '@showAddForm')->name('admin.pages.add');
            $router->post('/pages/add', PageController::class . '@handleAddForm')->name('admin.pages.add.post');
            $router->get('/pages/edit/{id:\d+}', PageController::class . '@showEditForm')->name('admin.pages.edit');
            $router->post('/pages/edit/{id:\d+}', PageController::class . '@handleEditForm')->name('admin.pages.edit.post');
            $router->post('/pages/delete/{id:\d+}', PageController::class . '@handleDelete')->name('admin.pages.delete.post');
    
            // Einstellungen
            $router->get('/settings', SettingsController::class . '@showForm')->name('admin.settings');
            $router->post('/settings', SettingsController::class . '@handleForm')->name('admin.settings.post');
    
            // Blog
            $router->get('/blog', BlogController::class . '@listPosts')->name('admin.blog.list');
            $router->get('/blog/edit/{id:\d+}', BlogController::class . '@showEditForm')->name('admin.blog.edit');
            $router->post('/blog/edit/{id:\d+}', BlogController::class . '@handleEditForm')->name('admin.blog.edit.post');
        });

        return $router;
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

        // --- Einfache Auth-Prüfung (Platzhalter - durch Middleware ersetzen!) ---
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
        // Bereinige den Pfad, entferne Query-String und evtl. Basis-Verzeichnis, falls Subfolder-Installation
        $basePath = ''; // Anpassen, falls CMS in einem Unterordner läuft
        $requestPath = str_replace($basePath, '', $requestPath);

        $isAdminPath = strpos($requestPath, MARQUES_ADMIN_DIR) === 0;
        $loginPath = MARQUES_ADMIN_DIR . '/login';

        if ($isAdminPath && $requestPath !== $loginPath && !$this->authService->isLoggedIn()) {
             // Baue Login-URL korrekt zusammen (relative Pfade vermeiden)
             $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
             $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
             // Annahme: Admin index.php liegt im MARQUES_ADMIN_DIR Pfad
             $loginUrl = $scheme . '://' . $host . $basePath . MARQUES_ADMIN_DIR . '/index.php'; // Oder direkter Pfad zur Login-Route, falls .htaccess aktiv ist
             // Wenn .htaccess aktiv ist und /admin/login funktioniert:
             // $loginUrl = $scheme . '://' . $host . $basePath . MARQUES_ADMIN_DIR . '/login';

             header('Location: ' . $loginUrl, true, 302); // Expliziter Redirect-Code
             exit;
        }
        // --- Ende Auth-Prüfung ---

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
    
        } catch (RuntimeException | InvalidArgumentException | UnexpectedValueException $e) {
            // Spezifische Exception-Typen abfangen
            $statusCode = $e->getCode() ?: 500;
            http_response_code($statusCode);
            $this->adminContainer->get(Logger::class)->warning(
                "Router Exception: " . $e->getMessage(), 
                ['exception' => $e, 'code' => $statusCode]
            );
            
            try {
                $this->template->render(
                    ['error_code' => $statusCode, 'error_message' => $e->getMessage()],
                    'error'
                );
            } catch (\Exception $renderError) {
                echo "<h1>Fehler {$statusCode}</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
                error_log("Fehler beim Rendern der Fehlerseite: " . $renderError->getMessage());
            }
            exit;
        } catch (\Exception $e) {
            $this->handleException($e);
            exit;
        }
    }

    private function handleException(\Exception $e): void
    {
        $showDetails = ($this->systemConfig['debug'] ?? false);
        $safeMessage = htmlspecialchars($e->getMessage(), ENT_QUOTES);
        
        try {
            $this->template->render([
                'error_code' => 500,
                'error_message' => 'Ein interner Serverfehler ist aufgetreten.',
                'exception_details' => $showDetails ? [
                    'message' => $safeMessage,
                    'trace' => array_map(
                        fn($t) => htmlspecialchars(print_r($t, true), ENT_QUOTES),
                        $e->getTrace()
                    )
                ] : null
            ], 'error');
        } catch (\Exception $renderError) {
            echo '<h1>500 Internal Server Error</h1>';
            if ($showDetails) {
                echo '<pre>' . $safeMessage . '</pre>';
            }
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