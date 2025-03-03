<?php
/**
 * marces CMS - Router Klasse
 * 
 * Behandelt URL-Routing.
 *
 * @package marces
 * @subpackage core
 */

namespace Marces\Core;

class Router {
    /**
     * @var array Routen-Konfiguration
     */
    private $_routes;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        // Routen aus Konfiguration laden
        $this->_routes = require MARCES_CONFIG_DIR . '/routes.config.php';
    }
    
    /**
     * Verarbeitet die aktuelle Anfrage und bestimmt die Route
     *
     * @return array Routeninformationen einschließlich Pfad, Parameter usw.
     * @throws NotFoundException Wenn die Route nicht gefunden wird
     */
    public function processRequest() {
        // Request-URI abrufen
        $requestUri = $this->getRequestUri();
        
        // Standard-Routendaten
        $routeData = [
            'path' => 'home', // Standardpfad
            'params' => [],
            'query' => $_GET
        ];
        
        // Wenn die URI nicht leer ist oder nur '/', verarbeiten
        if ($requestUri !== '' && $requestUri !== '/') {
            // Query-String entfernen
            $path = parse_url($requestUri, PHP_URL_PATH);
            
            // Führende und nachfolgende Schrägstriche entfernen
            $path = trim($path, '/');
            
            // Pfadvalidierung - nur alphanumerische Zeichen, Bindestriche, Unterstriche und Schrägstriche erlauben
            if (!preg_match('/^[a-zA-Z0-9\-_\/]+$/', $path)) {
                throw new NotFoundException("Ungültiger Pfad: " . htmlspecialchars($path));
            }
            
            // Auf Blog-Pfadmuster prüfen (yyyy/mm/dd/slug)
            if (preg_match('/^blog\/(\d{4})\/(\d{2})\/(\d{2})\/([a-zA-Z0-9\-_]+)$/', $path, $matches)) {
                $routeData['path'] = 'blog';
                $routeData['params'] = [
                    'year' => $matches[1],
                    'month' => $matches[2],
                    'day' => $matches[3],
                    'slug' => $matches[4]
                ];
                
                // Validiere Datum
                if (!checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])) {
                    throw new NotFoundException("Ungültiges Datum im Blog-Pfad");
                }
            } 
            // Auf Blog-Kategorie prüfen
            elseif (preg_match('/^blog\/category\/([a-zA-Z0-9\-_]+)$/', $path, $matches)) {
                $routeData['path'] = 'blog-category';
                $routeData['params'] = [
                    'category' => $matches[1]
                ];
            }
            // Auf Blog-Archiv prüfen
            elseif (preg_match('/^blog\/(\d{4})\/(\d{2})$/', $path, $matches)) {
                $routeData['path'] = 'blog-archive';
                $routeData['params'] = [
                    'year' => $matches[1],
                    'month' => $matches[2]
                ];
                
                // Validiere Jahr und Monat
                if ($matches[1] < 2000 || $matches[1] > 2100 || $matches[2] < 1 || $matches[2] > 12) {
                    throw new NotFoundException("Ungültiger Zeitraum im Blog-Archiv");
                }
            }
            // Auf Blog-Index prüfen
            elseif ($path === 'blog') {
                $routeData['path'] = 'blog-index';
            }
            // Ansonsten ist es eine reguläre Seite
            else {
                $routeData['path'] = $path;
            }
        }
        
        // Prüfen, ob die Route existiert
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
    private function routeExists($path, $params = []) {
        // Für Blog-Beiträge die tatsächliche Datei prüfen
        if ($path === 'blog' && isset($params['year'], $params['month'], $params['day'], $params['slug'])) {
            $blogFile = MARCES_CONTENT_DIR . '/blog/' . 
                        $params['year'] . '-' . 
                        $params['month'] . '-' . 
                        $params['day'] . '-' . 
                        $params['slug'] . '.md';
            return file_exists($blogFile);
        }
        
        // Für Blog-Kategorien und -Archive zunächst die vordefinierten Routen prüfen
        if (in_array($path, ['blog-category', 'blog-archive', 'blog-index'])) {
            return isset($this->_routes['patterns'][$path]) || isset($this->_routes['paths'][$path]);
        }
        
        // Reguläre Seiten: prüfen, ob Datei existiert
        $contentFile = MARCES_CONTENT_DIR . '/pages/' . $path . '.md';
        if (file_exists($contentFile)) {
            return true;
        }
        
        // Vordefinierte Routen prüfen
        return isset($this->_routes['paths'][$path]);
    }
    
    /**
     * Gibt die normalisierte Request-URI zurück
     *
     * @return string Normalisierte Request-URI
     */
    private function getRequestUri() {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Basispfad abschneiden, falls nötig
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        if ($basePath !== '/' && strpos($requestUri, $basePath) === 0) {
            $requestUri = substr($requestUri, strlen($basePath));
        }
        
        return $requestUri;
    }
}