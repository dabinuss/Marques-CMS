<?php
declare(strict_types=1);

namespace Admin\Core;

use Marques\Core\Controller as AppController;
use Marques\Core\Node;
use Admin\Http\Router as AdminRouter;
use Marques\Http\Response\ViewResponse;
use Marques\Http\Response\RedirectResponse;

/**
 * Basisklasse für alle Admin-Controller
 */
class Controller extends AppController
{
    protected AdminRouter $adminRouter;
    
    /**
     * Konstruktor mit erweiterten Admin-Abhängigkeiten
     */
    public function __construct(Node $container)
    {
        parent::__construct($container);
        $this->adminRouter = $container->get(AdminRouter::class);
    }
    
    /**
     * Gibt eine Admin-URL zurück
     * 
     * @param string $routeName Name der Admin-Route
     * @param array $params Parameter für die Route
     * @return string Vollständige URL
     */
    protected function adminUrl(string $routeName, array $params = []): string
    {
        return $this->adminRouter->getAdminUrl($routeName, $params);
    }
    
    /**
     * Leitet zu einer Admin-Route weiter
     * 
     * @param string $routeName Name der Admin-Route
     * @param array $params Parameter für die Route
     * @return RedirectResponse Response-Objekt für die Weiterleitung
     */
    protected function redirectToRoute(string $routeName, array $params = []): RedirectResponse
    {
        return $this->redirect($this->adminRouter->getAdminUrl($routeName, $params));
    }
}