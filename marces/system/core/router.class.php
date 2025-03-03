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
        $config = require MARCES_CONFIG_DIR . '/system.config.php';
        $blogUrlFormat = $config['blog_url_format'] ?? 'date_slash';
        
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
            
            // Je nach konfiguriertem Format unterschiedliche Muster prüfen
            $isBlogPost = false;
            
            switch ($blogUrlFormat) {
                case 'date_slash':
                    // Format: blog/YYYY/MM/DD/slug
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
                        
                        $isBlogPost = true;
                    }
                    break;
                    
                case 'date_dash':
                    // Format: blog/YYYY-MM-DD/slug
                    if (preg_match('/^blog\/(\d{4})-(\d{2})-(\d{2})\/([a-zA-Z0-9\-_]+)$/', $path, $matches)) {
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
                        
                        $isBlogPost = true;
                    }
                    break;
                    
                case 'year_month':
                    // Format: blog/YYYY/MM/slug
                    if (preg_match('/^blog\/(\d{4})\/(\d{2})\/([a-zA-Z0-9\-_]+)$/', $path, $matches)) {
                        $routeData['path'] = 'blog';
                        $routeData['params'] = [
                            'year' => $matches[1],
                            'month' => $matches[2],
                            'slug' => $matches[3]
                        ];
                        
                        // Prüfe nur Jahr und Monat
                        if ($matches[1] < 2000 || $matches[1] > 2100 || $matches[2] < 1 || $matches[2] > 12) {
                            throw new NotFoundException("Ungültiger Zeitraum im Blog-Pfad");
                        }
                        
                        $isBlogPost = true;
                    }
                    break;
                    
                case 'numeric':
                    // Format: blog/ID oder blog/YYYYMMDD-slug
                    if (preg_match('/^blog\/(\d+)$/', $path, $matches)) {
                        // Numerische ID
                        $routeData['path'] = 'blog';
                        $routeData['params'] = [
                            'id' => $matches[1]
                        ];
                        
                        $isBlogPost = true;
                    } elseif (preg_match('/^blog\/(\d{8})-([a-zA-Z0-9\-_]+)$/', $path, $matches)) {
                        // YYYYMMDD-slug Format
                        $dateStr = $matches[1];
                        $year = substr($dateStr, 0, 4);
                        $month = substr($dateStr, 4, 2);
                        $day = substr($dateStr, 6, 2);
                        
                        $routeData['path'] = 'blog';
                        $routeData['params'] = [
                            'year' => $year,
                            'month' => $month,
                            'day' => $day,
                            'slug' => $matches[2]
                        ];
                        
                        // Validiere Datum
                        if (!checkdate((int)$month, (int)$day, (int)$year)) {
                            throw new NotFoundException("Ungültiges Datum im Blog-Pfad");
                        }
                        
                        $isBlogPost = true;
                    }
                    break;
                    
                case 'post_name':
                    // Format: blog/slug
                    if (preg_match('/^blog\/([a-zA-Z0-9\-_]+)$/', $path, $matches) && $matches[1] !== 'category' && !is_numeric($matches[1])) {
                        $routeData['path'] = 'blog';
                        $routeData['params'] = [
                            'slug' => $matches[1]
                        ];
                        
                        $isBlogPost = true;
                    }
                    break;
                    
                default:
                    // Standard: date_slash Format
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
                        
                        $isBlogPost = true;
                    }
            }
            
            // Wenn kein Blog-Beitrag erkannt wurde, überprüfe andere Blog-bezogene Routen
            if (!$isBlogPost) {
                // Auf Blog-Kategorie prüfen
                if (preg_match('/^blog\/category\/([a-zA-Z0-9\-_]+)$/', $path, $matches)) {
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
        // Konfiguration laden
        $config = require MARCES_CONFIG_DIR . '/system.config.php';
        $blogUrlFormat = $config['blog_url_format'] ?? 'date_slash';
        
        // Für Blog-Beiträge die tatsächliche Datei oder Daten prüfen
        if ($path === 'blog') {
            $blogManager = new \Marces\Core\BlogManager();
            
            // Prüfen, welche Parameter vorhanden sind, abhängig vom Format
            switch ($blogUrlFormat) {
                case 'date_slash':
                case 'date_dash':
                    if (isset($params['year'], $params['month'], $params['day'], $params['slug'])) {
                        $blogFile = MARCES_CONTENT_DIR . '/blog/' . 
                                    $params['year'] . '-' . 
                                    $params['month'] . '-' . 
                                    $params['day'] . '-' . 
                                    $params['slug'] . '.md';
                        return file_exists($blogFile);
                    }
                    break;
                    
                case 'year_month':
                    if (isset($params['year'], $params['month'], $params['slug'])) {
                        // Suche nach Dateien, die mit dem Muster YYYY-MM-* beginnen
                        $pattern = MARCES_CONTENT_DIR . '/blog/' . 
                                $params['year'] . '-' . 
                                $params['month'] . '-*-' . 
                                $params['slug'] . '.md';
                        $files = glob($pattern);
                        return !empty($files);
                    }
                    break;
                    
                case 'numeric':
                    if (isset($params['id'])) {
                        // Numerische ID: Suche nach Blog-Einträgen durch ID
                        return $blogManager->postExistsById($params['id']);
                    } elseif (isset($params['year'], $params['month'], $params['day'], $params['slug'])) {
                        $blogFile = MARCES_CONTENT_DIR . '/blog/' . 
                                    $params['year'] . '-' . 
                                    $params['month'] . '-' . 
                                    $params['day'] . '-' . 
                                    $params['slug'] . '.md';
                        return file_exists($blogFile);
                    }
                    break;
                    
                case 'post_name':
                    if (isset($params['slug'])) {
                        // Nur Slug: Suche nach allen Dateien, die mit diesem Slug enden
                        $pattern = MARCES_CONTENT_DIR . '/blog/*-' . $params['slug'] . '.md';
                        $files = glob($pattern);
                        return !empty($files);
                    }
                    break;
                    
                default:
                    // Standard: date_slash Format
                    if (isset($params['year'], $params['month'], $params['day'], $params['slug'])) {
                        $blogFile = MARCES_CONTENT_DIR . '/blog/' . 
                                    $params['year'] . '-' . 
                                    $params['month'] . '-' . 
                                    $params['day'] . '-' . 
                                    $params['slug'] . '.md';
                        return file_exists($blogFile);
                    }
            }
            
            return false;
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