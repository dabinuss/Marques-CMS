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
            if (file_exists($filePath)) {
                $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                $contentTypes = [
                    'css' => 'text/css',
                    'js' => 'application/javascript',
                    // andere MIME-Typen nach Bedarf...
                ];
                $contentType = $contentTypes[$extension] ?? 'application/octet-stream';
                header('Content-Type: ' . $contentType);
                readfile($filePath);
                exit;
            }
            http_response_code(404);
            echo "File not found";
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
}