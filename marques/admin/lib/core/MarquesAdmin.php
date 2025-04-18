<?php
declare(strict_types=1);

namespace Admin\Core;

use Marques\Core\Node;
use Marques\Core\TokenParser;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Core\Logger;
use Marques\Core\Events;
use Marques\Core\Cache;
use Marques\Filesystem\PathRegistry;
use Marques\Filesystem\FileManager;
use Marques\Util\Helper;
use Marques\Service\ThemeManager;
use Marques\Service\User;
use Marques\Service\PageManager;
use Marques\Service\BlogManager;
use Marques\Data\MediaManager;
use Marques\Util\SafetyXSS;
use Marques\Util\ExceptionHandler;
use Admin\Http\Router as AdminRouter;
use Admin\Core\Template as AdminTemplate;
use Admin\Core\Statistics;
use Admin\Auth\Service;
use Admin\Controller\DashboardController;
use Admin\Controller\AuthController;
use Admin\Controller\PageController;
use Admin\Controller\SettingsController;
use Admin\Controller\BlogController;
use RuntimeException;

/**
 * Main class for Marques Admin.
 * Handles admin initialization, container setup, session, and request routing.
 */
class MarquesAdmin
{
    private DatabaseHandler $dbHandler;
    private AdminTemplate $template;
    private Node $adminContainer;
    private Service $Service;
    private array $systemConfig = [];
    private ExceptionHandler $exceptionHandler;
    private Logger $logger;

    /**
     * Constructor initializes the admin container and loads system configuration.
     *
     * @param Node $rootContainer Dependency injection root container
     */
    public function __construct(Node $rootContainer)
    {
        $this->initContainer($rootContainer);

        $this->dbHandler    = $this->adminContainer->get(DatabaseHandler::class);
        $this->Service      = $this->adminContainer->get(Service::class);
        $this->template     = $this->adminContainer->get(AdminTemplate::class);
        $this->logger       = $this->adminContainer->get(Logger::class);

        try {
            $this->systemConfig = $this->dbHandler->table('settings')->where('id', '=', 1)->first() ?: [];

            $debugMode = $this->systemConfig['debug'] ?? false;
            $this->exceptionHandler = new ExceptionHandler($debugMode, $this->logger);
            $this->exceptionHandler->register();
        } catch (\Exception $e) {
            $this->logger->error("Admin Init: Could not load settings.", ['exception' => $e]);
        }
    }

    /**
     * Sets up the admin dependency injection container and registers all admin services and controllers.
     *
     * @param Node $rootContainer
     */
    private function initContainer(Node $rootContainer): void
    {
        $this->adminContainer = new Node($rootContainer);

        $this->adminContainer->register(AdminTemplate::class, function(Node $container) {
            return new AdminTemplate(
                $container->get(DatabaseHandler::class),
                $container->get(ThemeManager::class),
                $container->get(PathRegistry::class),
                $container->get(Cache::class),
                $container->get(Helper::class),
                $container->get(AdminRouter::class),
                $container->get(SafetyXSS::class),
                $container->get(TokenParser::class),
                $container->get(FileManager::class),
            );
        });

        $this->adminContainer->register(Service::class, function(Node $container) {
            $securityConfig = [];
            try {
                $settings = $container->get(DatabaseHandler::class)
                    ->table('settings')
                    ->select(['security'])
                    ->where('id', '=', 1)
                    ->first();
                $securityConfig = $settings['security'] ?? [];
            } catch (\Exception $e) {
                $container->get(Logger::class)
                    ->warning("Service Init: Could not load security settings.", ['exception' => $e]);
            }
            return new Service(
                $container->get(User::class),
                $securityConfig,
                $container->get(PathRegistry::class)
            );
        });

        $this->adminContainer->register(\Admin\Auth\Middleware::class, function(Node $container) {
            return new \Admin\Auth\Middleware(
                $container->get(Service::class),
                $container->get(AdminRouter::class)
            );
        });

        $this->adminContainer->register(Statistics::class, function(Node $container) {
            return new Statistics(
                $container->get(DatabaseHandler::class),
                $container->get(User::class),
                $container->get(PageManager::class),
                $container->get(BlogManager::class),
                $container->get(MediaManager::class)
            );
        });

        $this->adminContainer->register(AdminRouter::class, function(Node $container) {
            return new AdminRouter(
                $container,
                $container->get(DatabaseHandler::class),
                false
            );
        });

        $this->adminContainer->register(\Marques\Core\AssetManager::class, function(Node $container) use ($rootContainer) {
            $assetManager = $rootContainer->get(\Marques\Core\AssetManager::class);
            $helper = $container->get(Helper::class);
            $assetManager->setBaseUrl($helper->getSiteUrl('admin'));
            return $assetManager;
        });

        // Dependency resolver for avoiding code duplication
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
                $resolve(Logger::class),
                $resolve(PathRegistry::class)
            );
        });

        $this->adminContainer->register(AuthController::class, function(Node $container) {
            return new AuthController($container);
        });

        $this->adminContainer->register(PageController::class, function(Node $container) use ($resolve) {
            return new PageController(
                 $container->get(AdminTemplate::class),
                 $resolve(PageManager::class),
                 $resolve(Helper::class),
                 $resolve(FileManager::class),
                 $resolve(PathRegistry::class)
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

        $this->adminContainer->register(FileManager::class, function(Node $container) use ($rootContainer) {
            // Den FileManager vom Root-Container wiederverwenden statt einen neuen zu erstellen
            $fileManager = $rootContainer->get(FileManager::class);
            // Für die Admin-Templates konfigurieren
            $fileManager->useDirectory('backend_templates');
            return $fileManager;
        });

        $this->adminContainer->register(PageManager::class, function(Node $container) use ($resolve) {
            return new PageManager(
                $container->get(DatabaseHandler::class),
                $resolve(PathRegistry::class),
                $resolve(FileManager::class)
            );
        });

        // More controller registrations can go here
    }

    /**
     * Returns the admin DI container.
     *
     * @return Node
     */
    public function getContainer(): Node
    {
        return $this->adminContainer;
    }

    /**
     * Starts a secure session if not already started and generates a CSRF token.
     *
     * @return void
     */
    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                         ($_SERVER['SERVER_PORT'] ?? '') === '443';

            session_start([
                'cookie_httponly' => true,
                'cookie_secure' => $isSecure,
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => true
            ]);
            error_log("Session started with ID: " . session_id());
        } else {
            error_log("Session already active with ID: " . session_id());
        }

        $this->Service->generateCsrfToken();
    }

    /**
     * Initializes the admin environment: starts session, output buffering, and sets up error reporting.
     *
     * @return void
     */
    public function init(): void
    {
        ob_start();
        $this->startSession();

        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
        $fullRequestUri = $_SERVER['REQUEST_URI'] ?? '';

        $adminRegex = '#^' . preg_quote(MARQUES_ADMIN_DIR, '#') . '(/|$)#';
        $isAdminPath = preg_match($adminRegex, $requestPath);

        $loginPath = MARQUES_ADMIN_DIR . '/login';
        $isLoginPath = rtrim($requestPath, '/') === $loginPath;
        $isAssetsPath = strpos($requestPath, MARQUES_ADMIN_DIR . '/assets') === 0;

        $debugMode = $this->systemConfig['debug'] ?? false;
        error_reporting($debugMode ? E_ALL : 0);
        ini_set('display_errors', $debugMode ? '1' : '0');
        date_default_timezone_set($this->systemConfig['timezone'] ?? 'UTC');

        // CSRF‑Token wird jetzt schon in startSession() erzeugt, doppelten Aufruf entfernt
    }

    /**
     * Main entry point: handles admin request routing and error handling.
     *
     * @return void
     */
    public function run(): void
    {
        try {
            $this->triggerEvent('before_request');
            /** @var AdminRouter $adminRouter */
            $adminRouter = $this->adminContainer->get(AdminRouter::class);
    
            if (method_exists($adminRouter, 'defineRoutes')) {
                $adminRouter->defineRoutes();
            }
            if (method_exists($adminRouter, 'ensureRoutes')) {
                $adminRouter->ensureRoutes();
            }
    
            $routeResult = $adminRouter->processRequest();
    
            if ($routeResult instanceof \Marques\Http\Response\ViewResponse) {
                // ViewResponse-Objekt direkt ausführen lassen, anstatt manuell zu rendern
                // Dies erlaubt dem ViewResponse-Objekt, das richtige Template zu verwenden
                try {
                    $routeResult->execute();
                } catch (\Exception $viewResponseEx) {
                    // Fallback bei Fehlern in ViewResponse
                    error_log("ViewResponse execution failed: " . $viewResponseEx->getMessage());
                    
                    // Prüfen, ob es sich um einen angemeldeten Benutzer handelt
                    $isLoggedIn = isset($_SESSION['marques_user']) && !empty($_SESSION['marques_user']['username']);
                    
                    if ($isLoggedIn) {
                        // Bei angemeldetem Benutzer Dashboard versuchen
                        try {
                            $this->template->render([
                                'username' => $_SESSION['marques_user']['username'],
                                'title' => 'Administration',
                                'content' => 'Welcome to the administration area.',
                            ], 'dashboard');
                        } catch (\Exception $dashboardEx) {
                            error_log("Dashboard fallback failed: " . $dashboardEx->getMessage());
                            $this->renderEmergencyPage("Administration", "Error loading dashboard.");
                        }
                    } else {
                        // Bei nicht angemeldetem Benutzer Login versuchen
                        try {
                            $this->template->render([
                                'username' => '',
                                'title' => 'Admin Login',
                            ], 'login');
                        } catch (\Exception $loginEx) {
                            error_log("Login fallback failed: " . $loginEx->getMessage());
                            $this->renderEmergencyPage("Admin Login", "Error loading login page.");
                        }
                    }
                }
            } elseif ($routeResult instanceof \Marques\Http\Response) {
                $routeResult->execute();
            } elseif (is_array($routeResult) && isset($routeResult['template'])) {
                $templateKey = $routeResult['template'];
                unset($routeResult['template']);
                $this->template->render($routeResult, $templateKey);
            } elseif ($routeResult === null) {
                // no action required
            } else {
                throw new RuntimeException(
                    'Controller returned invalid result. Expected: Response object, array with template-key or null.'
                );
            }
    
            $this->triggerEvent('after_render');
        } catch (\Exception $e) {
            $this->renderEmergencyPage("Error", $e->getMessage());
            error_log("FATAL ERROR in run(): " . $e->getMessage());
            error_log("Trace: " . $e->getTraceAsString());
        } finally {
            if (ob_get_level() > 0) {
                if (!headers_sent()) {
                    ob_end_flush();
                } else {
                    error_log("[Run] WARNING: Output buffer still active but headers already sent!");
                    ob_end_clean();
                }
            }
        }
    }
    
    /**
     * Rendert eine einfache Notfallseite bei Fehlern.
     * 
     * @param string $title Seitentitel
     * @param string $message Nachricht für den Benutzer
     */
    private function renderEmergencyPage(string $title, string $message): void {
        try {
            echo '<!DOCTYPE html><html><head><title>' . htmlspecialchars($title) . '</title></head><body>';
            echo '<h1>' . htmlspecialchars($title) . '</h1>';
            echo '<p>' . htmlspecialchars($message) . '</p>';
            echo '<p>Please contact your system administrator if this problem persists.</p>';
            echo '</body></html>';
        } catch (\Exception $displayEx) {
            echo "ERROR: Unable to display error page: " . $displayEx->getMessage();
        }
    }

    /**
     * Returns a user-friendly error message for a given HTTP error code.
     *
     * @param int $code HTTP error code
     * @return string
     */
    private function getErrorMessageForCode(int $code): string
    {
        return match($code) {
            404 => 'The requested page was not found.',
            403 => 'Access denied.',
            500 => 'An internal server error occurred.',
            default => 'An unexpected error occurred.',
        };
    }

    /**
     * Triggers an event if the Events class is registered.
     *
     * @param string $event Event name
     * @param mixed $data Optional event data
     * @return mixed
     */
    private function triggerEvent(string $event, $data = null)
    {
        try {
            if ($this->adminContainer->has(Events::class)) {
                $eventManager = $this->adminContainer->get(Events::class);
                return $eventManager->trigger($event, $data);
            }
        } catch (\Exception $e) {
            try {
                $this->adminContainer->get(Logger::class)->error("Event manager error ($event): " . $e->getMessage());
            } catch (\Exception $logError) {
                error_log("ERROR LOGGING (Event): " . $logError->getMessage());
            }
        }
        return $data;
    }

    /**
     * Checks if a redirect URL is safe (relative, starts with /, not protocol-relative).
     *
     * @param string $url The URL to validate
     * @return bool True if the URL is considered safe
     */
    private function isValidRedirectUrl(string $url): bool
    {
        if ($url === '' || $url[0] !== '/') {
            return false;
        }

        if (isset($url[1]) && $url[0] === '/' && $url[1] === '/') {
            return false;
        }

        if (str_starts_with($url, '/admin/')) {
            return true;
        }

        return false;
    }
}