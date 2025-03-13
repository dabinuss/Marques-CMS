<?php
declare(strict_types=1);

/**
 * marques CMS - Router Klasse
 * 
 * Behandelt URL-Routing.
 *
 * @package marques
 * @subpackage core
 */

namespace Marques\Core;

class Router {
    /**
     * @var array Routen-Konfiguration
     */
    private $_routes;
    
    /**
     * @var AppConfig Instance des AppConfig
     */
    private $_configManager;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        // AppConfig-Instanz holen
        $this->_configManager = AppConfig::getInstance();
        
        // Routen aus Konfiguration laden
        $this->_routes = $this->_configManager->load('routes') ?: [];
    }
    
    /**
     * Verarbeitet die aktuelle Anfrage und bestimmt die Route
     *
     * @return array Routeninformationen einschließlich Pfad, Parameter usw.
     * @throws NotFoundException Wenn die Route nicht gefunden wird
     */
    public function processRequest(): array {
        $requestUri = $this->getRequestUri();
        $systemConfig = $this->_configManager->load('system') ?: [];
        $blogUrlFormat = $systemConfig['blog_url_format'] ?? 'date_slash';
        
        // Standardroute
        $routeData = [
            'path' => 'home',
            'params' => [],
            'query' => $_GET
        ];
        
        if ($requestUri !== '' && $requestUri !== '/') {
            $path = parse_url($requestUri, PHP_URL_PATH);
            $path = trim($path, '/');
            
            // Asset-Pfade ausschließen
            if (strpos($path, 'themes/') === 0 || strpos($path, 'assets/') === 0) {
                throw new NotFoundException("Statischer Asset-Pfad: " . htmlspecialchars($path));
            }
            
            if (!preg_match('/^[a-zA-Z0-9\-_\/]+$/', $path)) {
                throw new NotFoundException("Ungültiger Pfad: " . htmlspecialchars($path));
            }
            
            $isBlogPost = false;
            switch ($blogUrlFormat) {
                case 'internal':
                    // Erwartete URL: blog/{id} (z.B. blog/000-25C)
                    if (preg_match('/^blog\/([0-9]{3}-[0-9]{2}[A-L])$/', $path, $matches)) {
                        $routeData['path'] = 'blog';
                        $routeData['params'] = ['id' => $matches[1]];
                        // Hier könnte optional das URL-Mapping geladen werden, um z.B. Redirects vorzunehmen
                        $isBlogPost = true;
                    }
                    break;
                    
                case 'date_slash':
                    // Erwartet: blog/YYYY/MM/DD/slug
                    if (preg_match('/^blog\/(\d{4})\/(\d{2})\/(\d{2})\/([a-zA-Z0-9\-_]+)$/', $path, $matches)) {
                        $routeData['path'] = 'blog';
                        $routeData['params'] = [
                            'year'  => $matches[1],
                            'month' => $matches[2],
                            'day'   => $matches[3],
                            'slug'  => $matches[4]
                        ];
                        if (!checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])) {
                            throw new NotFoundException("Ungültiges Datum im Blog-Pfad");
                        }
                        $isBlogPost = true;
                    }
                    break;
                    
                case 'post_name':
                    // Erwartet: blog/slug
                    if (preg_match('/^blog\/([a-zA-Z0-9\-_]+)$/', $path, $matches)) {
                        $routeData['path'] = 'blog';
                        $routeData['params'] = ['slug' => $matches[1]];
                        $isBlogPost = true;
                    }
                    break;
                    
                case 'year_month':
                    // Erwartet: blog/YYYY/MM/slug
                    if (preg_match('/^blog\/(\d{4})\/(\d{2})\/([a-zA-Z0-9\-_]+)$/', $path, $matches)) {
                        $routeData['path'] = 'blog';
                        $routeData['params'] = [
                            'year'  => $matches[1],
                            'month' => $matches[2],
                            'slug'  => $matches[3]
                        ];
                        $isBlogPost = true;
                    }
                    break;
                    
                default:
                    // Fallback: Versuche internal
                    if (preg_match('/^blog\/([0-9]{3}-[0-9]{2}[A-L])$/', $path, $matches)) {
                        $routeData['path'] = 'blog';
                        $routeData['params'] = ['id' => $matches[1]];
                        $isBlogPost = true;
                    }
            }
            
            // Alternative Blog-bezogene Routen (Kategorie, Archiv)
            if (!$isBlogPost) {
                if (preg_match('/^blog\/category\/([a-zA-Z0-9\-_]+)$/', $path, $matches)) {
                    $routeData['path'] = 'blog-category';
                    $routeData['params'] = ['category' => $matches[1]];
                } elseif (preg_match('/^blog\/(\d{4})\/(\d{2})$/', $path, $matches)) {
                    $routeData['path'] = 'blog-archive';
                    $routeData['params'] = ['year' => $matches[1], 'month' => $matches[2]];
                    if ($matches[1] < 2000 || $matches[1] > 2100 || $matches[2] < 1 || $matches[2] > 12) {
                        throw new NotFoundException("Ungültiger Zeitraum im Blog-Archiv");
                    }
                } elseif ($path === 'blog') {
                    $routeData['path'] = 'blog-index';
                } else {
                    $routeData['path'] = $path;
                }
            }
        }
        
        if (!$this->routeExists($routeData['path'], $routeData['params'] ?? [])) {
            throw new NotFoundException("Seite nicht gefunden: " . $routeData['path']);
        }
        
        return $routeData;
    }
    
    /**
     * Prüft, ob eine Route existiert
     *
     * @param string $path Der Routenpfad
     * @param array $params Optional: Route-Parameter
     * @return bool True, wenn die Route existiert
     */
    private function routeExists($path, $params = []): bool {
        if ($path === 'blog') {
            $blogManager = new BlogManager();
            $systemConfig = $this->_configManager->load('system') ?: [];
            $blogUrlFormat = $systemConfig['blog_url_format'] ?? 'internal';
            switch ($blogUrlFormat) {
                case 'internal':
                    if (isset($params['id'])) {
                        return $blogManager->postExistsById($params['id']);
                    }
                    break;
                case 'date_slash':
                    if (isset($params['year'], $params['month'], $params['day'], $params['slug'])) {
                        $blogFile = MARQUES_CONTENT_DIR . '/blog/' .
                                    $params['year'] . '/' .
                                    $params['month'] . '/' .
                                    $params['year'] . '-' . $params['month'] . '-' . $params['day'] . '-' . $params['slug'] . '.md';
                        return file_exists($blogFile);
                    }
                    break;
                case 'post_name':
                    if (isset($params['slug'])) {
                        $files = glob(MARQUES_CONTENT_DIR . '/blog/*/*/*-' . $params['slug'] . '.md');
                        return !empty($files);
                    }
                    break;
                case 'year_month':
                    if (isset($params['year'], $params['month'], $params['slug'])) {
                        $files = glob(MARQUES_CONTENT_DIR . '/blog/' .
                                     $params['year'] . '/' .
                                     $params['month'] . '/*-' . $params['slug'] . '.md');
                        return !empty($files);
                    }
                    break;
                default:
                    if (isset($params['id'])) {
                        return $blogManager->postExistsById($params['id']);
                    }
            }
            return false;
        }
        
        if (in_array($path, ['blog-category', 'blog-archive', 'blog-index'])) {
            return isset($this->_routes['patterns'][$path]) || isset($this->_routes['paths'][$path]);
        }
        
        $contentFile = MARQUES_CONTENT_DIR . '/pages/' . $path . '.md';
        if (file_exists($contentFile)) {
            return true;
        }
        return isset($this->_routes['paths'][$path]);
    }
    
    /**
     * Gibt die normalisierte Request-URI zurück
     *
     * @return string Normalisierte Request-URI
     */
    private function getRequestUri(): string {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        if ($basePath !== '/' && strpos($requestUri, $basePath) === 0) {
            $requestUri = substr($requestUri, strlen($basePath));
        }
        return $requestUri;
    }

    /**
     * Lädt das URL-Mapping.
     * Hier wird die Datei "urlmapping.config.json" aus dem Konfigurationsverzeichnis verwendet.
     *
     * @return array
     */
    public function loadUrlMapping(): array {
        return $this->_configManager->loadUrlMapping();
    }
    
    /**
     * Aktualisiert das URL-Mapping.
     *
     * @param array $mapping
     * @return bool
     */
    public function updateUrlMapping(array $mapping): bool {
        return $this->_configManager->updateUrlMapping($mapping);
    }
}