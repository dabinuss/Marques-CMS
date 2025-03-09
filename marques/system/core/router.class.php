<?php
declare(strict_types=1);

namespace Marques\Core;

class Router extends Core {
    private $_routes;
    private $_configManager;

    public function __construct(Docker $docker) {
        parent::__construct($docker);
        $this->_configManager = $this->resolve('config');
        $this->_routes = $this->_configManager->load('routes') ?: [];
        if (!is_array($this->_routes)) {
            $this->_routes = [];
        }
    }

    public function processRequest(): array {
        $requestUri = $this->getRequestUri();

        // Standardroute
        $routeData = [
            'path' => 'home', // Standardmäßig auf die Startseite
            'params' => [],
            'query' => $_GET,
        ];

        // Entferne Query-String
        $requestUri = strtok($requestUri, '?');

        if ($requestUri !== '' && $requestUri !== '/') {
            $path = trim($requestUri, '/');

            // Sicherheitsprüfung: Erlaube nur bestimmte Zeichen im Pfad
            if (!preg_match('/^[a-zA-Z0-9\-_.\/]+$/', $path)) {
                throw new NotFoundException("Ungültiger Pfad");
            }

            // Blog-Prüfung (vereinfacht)
            if (strpos($path, 'blog') === 0) {
                if ($path === 'blog') {
                    $routeData['path'] = 'blog'; // Blog-Übersichtsseite
                } elseif (preg_match('#^blog/(?P<slug>[a-zA-Z0-9-]+)$#', $path, $matches)) {
                    // Einzelner Blog-Post (nur noch Slug!)
                    $routeData['path'] = 'blog';
                    $routeData['params'] = ['slug' => $matches['slug']];
                } else {
					// Alles andere innerhalb von /blog (z.B. Kategorien, Archive)
					$routeData['path'] = $path;
				}
            } else {
                // Reguläre Seite
                $routeData['path'] = $path;
            }
        }
        return $routeData;
    }


    private function routeExists($path, $params = []): bool {

        // Reguläre Seiten prüfen
        $contentFile = MARQUES_CONTENT_DIR . '/pages/' . $path . '.md';
        if (file_exists($contentFile)) {
            return true;
        }

        // Wenn es ein Blog-Slug ist, existiert die Route (Content::getPage() prüft, ob der Post existiert)
        if (strpos($path, 'blog') === 0) {
            return true;
        }

        return false; // Route existiert nicht
    }

    private function getRequestUri(): string
    {
        //Verwende $_SERVER['REQUEST_URI'] direkt, ohne basePath zu entfernen.
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        return $requestUri;
    }

    public function loadUrlMapping(): array {
        return $this->_configManager->loadUrlMapping() ?: [];
    }

    public function updateUrlMapping(array $mapping): bool {
        return $this->_configManager->updateUrlMapping($mapping);
    }
}