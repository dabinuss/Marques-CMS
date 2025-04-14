<?php
declare(strict_types=1);

namespace Admin\Http;

use Marques\Http\Router as AppRouter;
use Marques\Http\Request;
use Marques\Core\Node;
use Admin\Auth\Middleware;
use Admin\Auth\Service;
use Admin\Controller\DashboardController;
use Admin\Controller\AuthController;
use Admin\Controller\PageController;
use Admin\Controller\SettingsController;
use Admin\Controller\BlogController;

class Router extends AppRouter
{
    // Füge eine Eigenschaft hinzu, um den DI-Container zu speichern
    protected Node $container;

    /**
     * Konstruktor
     *
     * @param Node $container
     * @param mixed $dbHandler
     * @param bool $persistRoutes
     */
    public function __construct(Node $container, $dbHandler, bool $persistRoutes)
    {
        parent::__construct($container, $dbHandler, $persistRoutes);
        $this->container = $container;
    }

    /**
     * Liefert den DI-Container.
     *
     * @return Node
     */
    public function getContainer(): Node
    {
        return $this->container;
    }

    /**
     * Definiert alle Routen für den Admin-Bereich.
     */
    public function defineRoutes(): self
    {
        // Admin-Middleware
        $authMiddleware = $this->container->get(\Admin\Auth\Middleware::class);
        
        // CSRF-Middleware (nur für POST-Anfragen)
        $csrfMiddleware = function(Request $req, array $params, callable $next) {
            if ($req->getMethod() === 'POST') {
                $postData = $req->getAllPost();
                $token = $postData['csrf_token'] ?? '';
                if (!$this->container->get(Service::class)->validateCsrfToken($token)) {
                    throw new \RuntimeException("CSRF-Token validation failed", 403);
                }
            }
            return $next($req, $params);
        };
        
        // WICHTIG: Alle URLs mit absolutem Pfad beginnend mit "/admin"
        
        // Login/Logout-Routen (ohne Auth-Middleware)
        $this->get('/admin/login', AuthController::class . '@showLoginForm')->name('admin.login');
        $this->post('/admin/login', AuthController::class . '@handleLogin', [
            'middleware' => [$csrfMiddleware]
        ])->name('admin.login.post');
        $this->get('/admin/logout', AuthController::class . '@logout')->name('admin.logout');
        
        // Root-Admin-Routen mit Auth-Middleware
        $this->get('/admin', DashboardController::class . '@index', [
            'middleware' => [$authMiddleware]
        ])->name('admin.home');
        
        $this->get('/admin/', DashboardController::class . '@index', [
            'middleware' => [$authMiddleware]
        ])->name('admin.home.slash');
        
        // Gruppe für alle weiteren Admin-Routen
        $this->group([
            'prefix' => '/admin', 
            'middleware' => [$authMiddleware, $csrfMiddleware]
        ], function(Router $router) {
            // Dashboard
            $router->get('/dashboard', DashboardController::class . '@index')->name('admin.dashboard');
            
            // Seiten
            $router->get('/pages', PageController::class . '@list')->name('admin.pages.list');
            $router->get('/pages/add', PageController::class . '@showAddForm')->name('admin.pages.add');
            $router->post('/pages/add', PageController::class . '@handleAddForm')->name('admin.pages.add.post');
            $router->get('/pages/edit/{id}', PageController::class . '@showEditForm')->name('admin.pages.edit');
            $router->post('/pages/edit/{id}', PageController::class . '@handleEditForm')->name('admin.pages.edit.post');
            $router->post('/pages/delete/{id}', PageController::class . '@handleDelete')->name('admin.pages.delete.post');

            // Einstellungen
            $router->get('/settings', SettingsController::class . '@showForm')->name('admin.settings');
            $router->post('/settings', SettingsController::class . '@handleForm')->name('admin.settings.post');

            // Blog
            $router->get('/blog', BlogController::class . '@listPosts')->name('admin.blog.list');
            $router->get('/blog/edit/{id}', BlogController::class . '@showEditForm')->name('admin.blog.edit');
            $router->post('/blog/edit/{id}', BlogController::class . '@handleEditForm')->name('admin.blog.edit.post');
        });

        $this->get('/admin/assets/{path:.*}', function(Request $req, array $params) {
            $filePath = MARQUES_ADMIN_DIR . '/assets/' . $params['path'];
            // Absicherung: Stelle sicher, dass der reale Pfad innerhalb des erlaubten Assets-Verzeichnisses liegt.
            $realBase = realpath(MARQUES_ADMIN_DIR . '/assets');
            $realFile = realpath($filePath);
            if ($realFile === false || strpos($realFile, $realBase) !== 0) {
                http_response_code(404);
                echo "File not found";
                exit;
            }
            
            $extension = pathinfo($realFile, PATHINFO_EXTENSION);
            $contentTypes = [
                'css' => 'text/css',
                'js' => 'application/javascript',
            ];
            $contentType = $contentTypes[$extension] ?? 'application/octet-stream';
            header('Content-Type: ' . $contentType);
            readfile($realFile);
            exit;
        })->name('admin.assets');

        return $this;
    }

    /**
     * Generiert eine URL basierend auf dem Routen-Namen und Parametern.
     */
    public function getAdminUrl(string $routeName, array $params = []): string {
        $url = parent::generateUrl($routeName, $params);
    
        // Wenn Redirect auf Login zeigen würde → Loop!
        if ($this->isRedirectLoop($params['redirect'] ?? '')) {
            unset($params['redirect']);
            $url = parent::generateUrl($routeName, $params);
        }
    
        return $url;
    }

    /**
     * Prüft, ob ein Redirect auf die Login-Seite eine Redirect-Schleife erzeugen würde.
     */
    public function isRedirectLoop(string $targetUrl): bool {
        $parsed = parse_url($targetUrl);
        $redirectPath = $parsed['path'] ?? '';
    
        // Login-URL erkennen
        $loginPath = MARQUES_ADMIN_DIR . '/login';
    
        // Fall: redirect führt wieder auf Login
        if (rtrim($redirectPath, '/') === $loginPath) {
            return true;
        }
    
        return false;
    }

    /**
     * Adaptiert die processRequest-Methode für Admin-Anwendungen.
     * Nutzt die Basisfunktionalität, verarbeitet aber das Ergebnis spezifisch für Admin-Controller.
     */
    public function processRequest(): mixed
    {
        try {
            // Stelle sicher, dass Routen definiert sind
            $this->defineRoutes();
            
            // Rufe die Eltern-Implementierung auf, um Route zu finden
            $routeInfo = parent::processRequest();
            
            // Wenn das Ergebnis ein Array mit path/params ist, 
            // müssen wir den Controller selbst aufrufen
            if (is_array($routeInfo) && isset($routeInfo['path']) && isset($routeInfo['params'])) {
                $request = $this->createRequestFromGlobals();
                $path = $routeInfo['path'];
                $params = $routeInfo['params'];
                
                // Da wir keinen direkten Zugriff auf $routes haben, 
                // müssen wir unsere eigene Logik zur Controller-Auflösung verwenden
                
                // Pfad zu Controller-Klasse und Methode herleiten
                // z.B. /admin/dashboard zu DashboardController@index
                
                // Admin-Pfad normalisieren
                $adminPath = str_replace('/admin', '', '/' . trim($path, '/'));
                if (empty($adminPath)) {
                    $adminPath = '/dashboard'; // Standard-Controller für /admin
                }
                
                // Controller-Klasse und Methode bestimmen
                $segments = explode('/', trim($adminPath, '/'));
                $controllerName = ucfirst($segments[0] ?? 'dashboard') . 'Controller';
                $methodName = $segments[1] ?? 'index';
                
                // Vollständigen Controller-Klassennamen erstellen
                $controllerClass = 'Admin\\Controller\\' . $controllerName;
                
                // Existiert die Klasse?
                if (class_exists($controllerClass)) {
                    try {
                        // Controller instanziieren
                        $controller = $this->container->get($controllerClass);
                        
                        // Controller-Methode aufrufen, wenn vorhanden
                        if (method_exists($controller, $methodName)) {
                            return $controller->$methodName($request, $params);
                        }
                    } catch (\Exception $e) {
                        error_log("Fehler beim Instanziieren von $controllerClass: " . $e->getMessage());
                    }
                }
                
                // Alternative Auflösung: Wir kennen einige Controller aus defineRoutes
                $knownControllers = [
                    'dashboard' => DashboardController::class,
                    'login' => AuthController::class,
                    'logout' => AuthController::class,
                    'pages' => PageController::class,
                    'settings' => SettingsController::class,
                    'blog' => BlogController::class
                ];
                
                $segment = strtolower($segments[0] ?? 'dashboard');
                if (isset($knownControllers[$segment])) {
                    $controllerClass = $knownControllers[$segment];
                    $methodName = $segments[1] ?? ($segment === 'login' ? 'showLoginForm' : 'index');
                    
                    try {
                        $controller = $this->container->get($controllerClass);
                        if (method_exists($controller, $methodName)) {
                            return $controller->$methodName($request, $params);
                        }
                    } catch (\Exception $e) {
                        error_log("Fehler beim Instanziieren von $controllerClass: " . $e->getMessage());
                    }
                }
            }
            
            // Wenn es kein path/params Array ist oder kein Controller gefunden wurde,
            // geben wir das ursprüngliche Ergebnis zurück
            return $routeInfo;
            
        } catch (\Exception $e) {
            error_log("Admin Router Fehler: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Hilfsfunktion zum Erzeugen eines Request-Objekts aus globalen Variablen.
     * 
     * @return \Marques\Http\Request
     */
    private function createRequestFromGlobals(): \Marques\Http\Request
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        return new \Marques\Http\Request($_SERVER, $_GET, $_POST, $headers);
    }
}