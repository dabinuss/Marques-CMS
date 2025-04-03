<?php
declare(strict_types=1);

namespace Marques\Admin;

use Marques\Http\Router;
use Marques\Http\Request;
use Marques\Core\Node;
use Marques\Admin\AdminAuthMiddleware;
use Marques\Admin\Controller\DashboardController;
use Marques\Admin\Controller\AuthController;
use Marques\Admin\Controller\PageController;
use Marques\Admin\Controller\SettingsController;
use Marques\Admin\Controller\BlogController;

class AdminRouter extends Router
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
        // Login / Logout (kein Auth-Schutz)
        $this->get('/login', AuthController::class . '@showLoginForm')->name('admin.login');
        $this->post('/login', AuthController::class . '@handleLogin')->name('admin.login.post');
        $this->get('/logout', AuthController::class . '@logout')->name('admin.logout');

        $authMiddleware = new AdminAuthMiddleware($this->container->get(AdminAuthService::class));

        $this->group(['middleware' => [$authMiddleware]], function(AdminRouter $router) {
            // Dashboard
            $router->get('/dashboard', DashboardController::class . '@index')->name('admin.dashboard');
            $router->get('/', DashboardController::class . '@index')->name('admin.home');

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

        return $this;
    }

    /**
     * Generiert eine URL basierend auf dem Routen-Namen und Parametern.
     */
    public function getAdminUrl(string $routeName, array $params = []): string
    {
        return $this->generateUrl($routeName, $params);
    }
}
