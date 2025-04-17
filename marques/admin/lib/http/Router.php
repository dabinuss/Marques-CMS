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

/**
 * Router for the admin backend.
 *
 * Registers and dispatches all admin‑specific routes while maintaining full
 * compatibility with the base application router.
 */
class Router extends AppRouter
{
    /** Dependency‑injection container instance */
    protected Node $container;

    /**
     * @param Node  $container     DI container
     * @param mixed $dbHandler     Database handler implementation
     * @param bool  $persistRoutes Whether admin routes should be persisted
     */
    public function __construct(Node $container, $dbHandler, bool $persistRoutes)
    {
        parent::__construct($container, $dbHandler, $persistRoutes);
        $this->container = $container;
    }

    /**
     * Returns the DI container instance.
     */
    public function getContainer(): Node
    {
        return $this->container;
    }

    /**
     * Defines every admin route and applies the required middleware.
     */
    public function defineRoutes(): self
    {
        $authMiddleware  = $this->container->get(Middleware::class);
        $csrfMiddleware  = function (Request $req, array $params, callable $next) {
            if ($req->getMethod() === 'POST') {
                $postData = $req->getAllPost();
                $token    = $postData['csrf_token'] ?? '';
                if (!$this->container->get(Service::class)->validateCsrfToken($token)) {
                    throw new \RuntimeException('CSRF‑Token validation failed', 403);
                }
            }
            return $next($req, $params);
        };

        $this->get('/admin/login', AuthController::class . '@showLoginForm')->name('admin.login');
        $this->post('/admin/login', AuthController::class . '@handleLogin')->name('admin.login.post');
        $this->get('/admin/logout', AuthController::class . '@logout')->name('admin.logout');

        $this->get('/admin', DashboardController::class . '@index', [
            'middleware' => [$authMiddleware],
        ])->name('admin.home');

        $this->get('/admin/', DashboardController::class . '@index', [
            'middleware' => [$authMiddleware],
        ])->name('admin.home.slash');

        $this->group([
            'prefix'     => '/admin',
            'middleware' => [$authMiddleware, $csrfMiddleware],
        ], function (Router $router) {
            $router->get('/dashboard', DashboardController::class . '@index')->name('admin.dashboard');

            $router->get('/pages', PageController::class . '@list')->name('admin.pages.list');
            $router->get('/pages/add', PageController::class . '@showAddForm')->name('admin.pages.add');
            $router->post('/pages/add', PageController::class . '@handleAddForm')->name('admin.pages.add.post');
            $router->get('/pages/edit/{id}', PageController::class . '@showEditForm')->name('admin.pages.edit');
            $router->post('/pages/edit/{id}', PageController::class . '@handleEditForm')->name('admin.pages.edit.post');
            $router->post('/pages/delete/{id}', PageController::class . '@handleDelete')->name('admin.pages.delete.post');

            $router->get('/settings', SettingsController::class . '@showForm')->name('admin.settings');
            $router->post('/settings', SettingsController::class . '@handleForm')->name('admin.settings.post');

            $router->get('/blog', BlogController::class . '@listPosts')->name('admin.blog.list');
            $router->get('/blog/edit/{id}', BlogController::class . '@showEditForm')->name('admin.blog.edit');
            $router->post('/blog/edit/{id}', BlogController::class . '@handleEditForm')->name('admin.blog.edit.post');
        });

        $this->get('/admin/assets/{path:.*}', function (Request $req, array $params) {
            $filePath  = MARQUES_ADMIN_DIR . '/assets/' . $params['path'];
            $realBase  = realpath(MARQUES_ADMIN_DIR . '/assets');
            $realFile  = realpath($filePath);

            if ($realFile === false || strpos($realFile, $realBase) !== 0) {
                http_response_code(404);
                echo 'File not found';
                exit;
            }

            $extension     = pathinfo($realFile, PATHINFO_EXTENSION);
            $contentTypes  = [
                'css' => 'text/css',
                'js'  => 'application/javascript',
            ];
            $contentType   = $contentTypes[$extension] ?? 'application/octet‑stream';
            header('Content‑Type: ' . $contentType);
            readfile($realFile);
            exit;
        })->name('admin.assets');

        return $this;
    }

    /**
     * Generates an admin URL by name while avoiding redirect loops.
     */
    public function getAdminUrl(string $routeName, array $params = []): string
    {
        $url = parent::generateUrl($routeName, $params);

        if ($this->isRedirectLoop($params['redirect'] ?? '')) {
            unset($params['redirect']);
            $url = parent::generateUrl($routeName, $params);
        }

        return $url;
    }

    /**
     * Checks whether a given target URL would cause a login redirect loop.
     */
    public function isRedirectLoop(string $targetUrl): bool
    {
        $parsed       = parse_url($targetUrl);
        $redirectPath = $parsed['path'] ?? '';
        $loginPath    = MARQUES_ADMIN_DIR . '/login';

        return rtrim($redirectPath, '/') === $loginPath;
    }

    /**
     * Handles admin requests and resolves controller actions on‑the‑fly.
     */
    public function processRequest(): mixed
    {
        try {
            $this->defineRoutes();
            $routeInfo = parent::processRequest();

            if (is_array($routeInfo) && isset($routeInfo['path'], $routeInfo['params'])) {
                $request = $this->createRequestFromGlobals();
                $path    = $routeInfo['path'];
                $params  = $routeInfo['params'];

                $adminPath = str_replace('/admin', '', '/' . trim($path, '/'));
                if ($adminPath === '') {
                    $adminPath = '/dashboard';
                }

                $segments        = explode('/', trim($adminPath, '/'));
                $controllerName  = ucfirst($segments[0] ?? 'dashboard') . 'Controller';
                $methodName      = $segments[1] ?? 'index';
                $controllerClass = 'Admin\\Controller\\' . $controllerName;

                if (class_exists($controllerClass)) {
                    $controller = $this->container->get($controllerClass);
                    if (method_exists($controller, $methodName)) {
                        return $controller->$methodName($request, $params);
                    }
                }

                $knownControllers = [
                    'dashboard' => DashboardController::class,
                    'login'     => AuthController::class,
                    'logout'    => AuthController::class,
                    'pages'     => PageController::class,
                    'settings'  => SettingsController::class,
                    'blog'      => BlogController::class,
                ];

                $segment = strtolower($segments[0] ?? 'dashboard');
                if (isset($knownControllers[$segment])) {
                    $controllerClass = $knownControllers[$segment];
                    $methodName      = $segments[1] ?? ($segment === 'login' ? 'showLoginForm' : 'index');
                    $controller      = $this->container->get($controllerClass);
                    if (method_exists($controller, $methodName)) {
                        return $controller->$methodName($request, $params);
                    }
                }
            }

            return $routeInfo;
        } catch (\Exception $e) {
            error_log('Admin Router Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Creates a Request instance from PHP superglobals.
     */
    private function createRequestFromGlobals(): Request
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        return new Request($_SERVER, $_GET, $_POST, $headers);
    }
}
