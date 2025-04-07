<?php
declare(strict_types=1);

namespace Marques\Http;

use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Data\Database\Config as DatabaseConfig;
use Marques\Util\SafetyXSS;
use Marques\Core\Node;
use Marques\Core\Logger;

use RuntimeException;
use InvalidArgumentException;
use UnexpectedValueException;
use LogicException;
use Throwable;

/**
 * Router - Verarbeitet HTTP-Anfragen und leitet sie an die entsprechenden Handler weiter.
 */
class Router {
    // Konstanten für häufig verwendete Werte
    private const METHOD_GET = 'GET';
    private const URL_MAPPING_TABLE = 'urlmapping';
    private const DEFAULT_CONTENT_PATH = 'home';
    private const LOG_PREFIX = [
        'error' => 'LOG ERROR',
        'warning' => 'LOG WARNING'
    ];

    // Eigenschaften
    private array $routes = [];
    private array $globalMiddleware = [];
    private array $namedRoutes = [];
    private array $regexCache = [];
    private ?Node $container = null;
    private bool $routesLoaded = false;
    private array $groupStack = [];
    private bool $persistRoutes;
    private DatabaseHandler $dbHandler;

    /**
     * Konstruktor.
     *
     * @param Node $container DI-Container.
     * @param DatabaseHandler $dbHandler Datenbank-Handler.
     * @param bool $persistRoutes true für Datenbank-Routen (Frontend), false für dynamische Routen (Admin).
     */
    public function __construct(Node $container, DatabaseHandler $dbHandler, bool $persistRoutes = true) {
        $this->container = $container;
        $this->dbHandler = $dbHandler;
        $this->persistRoutes = $persistRoutes;
    }

    /**
     * Fügt eine globale Middleware hinzu.
     */
    public function addGlobalMiddleware(callable $middleware): self {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    /**
     * Fügt eine neue Route hinzu.
     */
    public function addRoute(string $method, string $pattern, $handler, array $options = []): self {
        $method = strtoupper($method);

        // Wende Gruppenattribute an
        if (!empty($this->groupStack)) {
            $groupData = end($this->groupStack);
            if (!empty($groupData['prefix'])) {
                $pattern = '/' . trim(str_replace('//', '/', $groupData['prefix'] . '/' . $pattern), '/');
            }
            if (!empty($groupData['middleware'])) {
                $options['middleware'] = array_merge($groupData['middleware'] ?? [], $options['middleware'] ?? []);
            }
            if (!empty($groupData['name_prefix']) && isset($options['name'])) {
                $options['name'] = $groupData['name_prefix'] . $options['name'];
            }
        }
        
        // Normalisiere Pfad
        if (empty($pattern) || $pattern[0] !== '/') {
            $pattern = '/' . $pattern;
        }

        // Kompiliere Regex
        $regex = $this->compilePattern($pattern, $options);

        // Erstelle Route
        $route = [
            'method' => $method,
            'pattern' => $pattern, 
            'regex' => $regex, 
            'handler' => $handler,
            'options' => $options,
        ];

        // Speichere benannte Route
        if (isset($options['name'])) {
            $this->namedRoutes[$options['name']] = $route;
        }
        
        // Füge Route hinzu
        $this->routes[] = $route;

        return $this;
    }

    /**
     * Erstellt eine Routen-Gruppe.
     */
    public function group(array $attributes, callable $callback): self {
        if (isset($attributes['prefix'])) {
            $attributes['prefix'] = '/' . trim($attributes['prefix'], '/');
        }
        $this->groupStack[] = $attributes;
        $callback($this); 
        array_pop($this->groupStack);
        return $this;
    }

    // Convenience-Methoden für HTTP-Methoden
    public function get(string $pattern, $handler, array $options = []): self { 
        return $this->addRoute(self::METHOD_GET, $pattern, $handler, $options); 
    }
    
    public function post(string $pattern, $handler, array $options = []): self { 
        return $this->addRoute('POST', $pattern, $handler, $options); 
    }
    
    public function put(string $pattern, $handler, array $options = []): self { 
        return $this->addRoute('PUT', $pattern, $handler, $options); 
    }
    
    public function delete(string $pattern, $handler, array $options = []): self { 
        return $this->addRoute('DELETE', $pattern, $handler, $options); 
    }

    /**
     * Benennt die zuletzt hinzugefügte Route (Fluent-API).
     */
    public function name(string $name): self {
        if (empty($this->routes)) {
            throw new LogicException("Keine Route zum Benennen vorhanden. name() muss nach addRoute aufgerufen werden.");
        }
        
        $lastRouteIndex = count($this->routes) - 1;
        $lastRoute = &$this->routes[$lastRouteIndex];
        
        $lastRoute['options']['name'] = $name;
        $this->namedRoutes[$name] = $lastRoute;
        
        return $this;
    }

    /**
     * Generiert eine URL für eine benannte Route.
     */
    public function generateUrl(string $name, array $params = [], bool $absolute = false): string {
        $this->ensureRoutesLoaded();

        if (!isset($this->namedRoutes[$name])) {
            throw new RuntimeException("Route mit Namen '$name' nicht gefunden.");
        }

        $route = $this->namedRoutes[$name];
        $url = $route['pattern'];

        // Parameter ersetzen
        foreach ($params as $key => $value) {
            $placeholder = '{' . $key . '}';
            if (strpos($url, $placeholder) !== false) {
                $url = str_replace($placeholder, rawurlencode((string)$value), $url);
            }
        }

        // Prüfe auf fehlende Parameter
        if (preg_match('/{(\w+)(?::[^}]+)?}/', $url, $matches)) {
            throw new RuntimeException("Parameter '{$matches[1]}' fehlt für Route '$name'.");
        }

        // Absolute URL erstellen wenn gewünscht
        if ($absolute) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $url = rtrim("{$protocol}://{$host}", '/') . $url;
        }

        return $url;
    }

    /**
     * Verarbeitet eingehende Requests - Hauptmethode des Routers.
     */
    public function dispatch(?Request $request = null): mixed {
        try {
            $this->ensureRoutesLoaded();
            $request = $request ?? $this->createRequestFromGlobals();
        
            // Kern-Pipeline definieren
            $pipeline = function(Request $req, array $params = []) {
                $matchResult = $this->findMatchingRoute($req);
                
                if (!$matchResult) {
                    throw new RuntimeException(
                        "Seite nicht gefunden [" . $req->getMethod() . ": " . 
                        SafetyXSS::escapeOutput($req->getPath()) . "]", 
                        404
                    );
                }
                
                $matchedRoute = $matchResult['route'];
                $params = $matchResult['params'] ?? [];
        
                // Parameter validieren
                if (!empty($matchedRoute['options']['schema']) && 
                    !$this->validateParameters($params, $matchedRoute['options']['schema'])) {
                    throw new InvalidArgumentException(
                        "Ungültige Parameter für Route '" . $matchedRoute['pattern'] . "'.", 
                        400
                    );
                }
        
                // Handler-Ausführung definieren
                $handlerExecution = function(Request $finalReq, array $finalParams = []) use ($matchedRoute) {
                    return $this->executeHandler($matchedRoute['handler'], $finalReq, $finalParams);
                };
        
                // Middleware anwenden oder direkt ausführen
                if (!empty($matchedRoute['options']['middleware'])) {
                    $routeMiddlewareChain = $this->buildMiddlewareChain(
                        $handlerExecution, 
                        $matchedRoute['options']['middleware']
                    );
                    return $routeMiddlewareChain($req, $params);
                } else {
                    return $handlerExecution($req, $params);
                }
            };
        
            // Globale Middleware + Kern-Pipeline ausführen
            $globalChain = $this->buildMiddlewareChain($pipeline, $this->globalMiddleware);
            return $globalChain($request, []);
        } catch (RuntimeException $e) {
            error_log("Router Dispatch Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verarbeitet Anfragen und behandelt Fehler - wird von MarquesApp genutzt.
     */
    public function processRequest(): mixed {
        try {
            $request = $this->createRequestFromGlobals();
            $result = $this->dispatch($request);
            
            // Standardisierte Rückgabe
            if (!is_array($result)) {
                $path = trim($request->getPath(), '/');
                $path = empty($path) ? self::DEFAULT_CONTENT_PATH : $path;
                
                $result = [
                    'path' => $path,
                    'params' => []
                ];
            }
            
            return $result;
            
        } catch (RuntimeException | InvalidArgumentException | UnexpectedValueException $e) {
            // Content-Datei-Fallback für 404-Fehler
            if ($e->getCode() === 404 || strpos($e->getMessage(), 'nicht gefunden') !== false) {
                $path = trim($_SERVER['REQUEST_URI'] ?? '/', '/');
                $path = empty($path) ? self::DEFAULT_CONTENT_PATH : $path;
                
                $pagePath = MARQUES_CONTENT_DIR . '/pages/' . $path . '.md';
                
                if (file_exists($pagePath)) {
                    error_log("Route nicht gefunden, aber Content-Datei existiert: {$pagePath}");
                    return [
                        'path' => $path,
                        'params' => []
                    ];
                }
            }
            
            // Fehler protokollieren
            $this->logError("Router-Fehler: " . $e->getMessage(), [
                'exception' => $e,
                'path' => $_SERVER['REQUEST_URI'] ?? '/'
            ]);
            
            throw $e;
            
        } catch (Throwable $e) {
            error_log("Schwerer Router-Fehler: " . $e->getMessage());
            throw new RuntimeException("Interner Router-Fehler: " . $e->getMessage(), 500, $e);
        }
    }

    /**
     * Stellt sicher, dass die Routen-Tabelle initialisiert ist.
     */
    public function ensureRoutes(): void {
        if (!$this->persistRoutes || !$this->dbHandler) {
            return;
        }
        
        try {
            // Prüfe, ob die Tabelle existiert
            $db = $this->dbHandler->getLibraryDatabase();
            if (!$db->hasTable(self::URL_MAPPING_TABLE)) {
                error_log("URL-Mapping-Tabelle existiert nicht - erzwinge Neuinitialisierung");
                new DatabaseConfig($this->dbHandler);
                $this->resetRoutesState();
                return;
            }
            
            // Prüfe, ob die Tabelle Einträge hat
            try {
                $firstEntry = $this->dbHandler->table(self::URL_MAPPING_TABLE)->first();
                if ($firstEntry === null) {
                    error_log("URL-Mapping-Tabelle ist leer - erzwinge Neuinitialisierung");
                    new DatabaseConfig($this->dbHandler);
                    $this->resetRoutesState();
                }
            } catch (\Exception $e) {
                error_log("Fehler beim Prüfen der URL-Mapping-Tabelle: " . $e->getMessage());
            }
        } catch (Throwable $e) {
            error_log("Schwerwiegender Fehler bei Routen-Initialisierung: " . $e->getMessage());
        }
    }

    /**
     * Setzt den Router-Zustand zurück und erzwingt Neuladen der Routen.
     */
    private function resetRoutesState(): void {
        $this->routes = [];
        $this->namedRoutes = [];
        $this->routesLoaded = false;
        $this->loadRoutesFromConfig();
    }

    /**
     * Stellt sicher, dass Routen geladen sind.
     */
    private function ensureRoutesLoaded(): void {
        if (!$this->routesLoaded) {
            if ($this->persistRoutes) {
                $this->loadRoutesFromConfig();
            }
            $this->routesLoaded = true;
        }
    }

    /**
     * Erstellt ein Request-Objekt aus globalen Variablen.
     */
    private function createRequestFromGlobals(): Request {
        $headers = function_exists('getallheaders') ? getallheaders() : $this->getHeadersFromServer($_SERVER);
        return new Request($_SERVER, $_GET, $_POST, $headers);
    }

    /**
     * Extrahiert HTTP-Header aus $_SERVER als Fallback.
     */
    private function getHeadersFromServer(array $server): array {
        $headers = [];
        foreach ($server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    /**
     * Baut eine Middleware-Kette auf.
     */
    private function buildMiddlewareChain(callable $finalHandler, array $middlewares): callable {
        $chain = $finalHandler;
        foreach (array_reverse($middlewares) as $middleware) {
            $next = $chain;
            $chain = function(Request $req, array $params = []) use ($middleware, $next) {
                return $middleware($req, $params, $next);
            };
        }
        return $chain;
    }

    /**
     * Kompiliert ein URL-Pattern zu einer Regex.
     */
    private function compilePattern(string $pattern, array $options = []): string {
        $cacheKey = $pattern . json_encode($options['params'] ?? []);
        if (isset($this->regexCache[$cacheKey])) {
            return $this->regexCache[$cacheKey];
        }

        $regex = preg_replace_callback('#\{(\w+)(?::([^}]+))?\}#u', function ($matches) use ($pattern, $options) {
            $paramName = $matches[1];
            $paramPattern = $matches[2] ?? ($options['params'][$paramName] ?? '[^/]+');

            // Validiere Regex-Muster
            try {
                $isValidPattern = @preg_match('#^' . $paramPattern . '$#u', '') !== false;
                if (!$isValidPattern && $paramPattern !== '[^/]+') {
                    error_log("Ungültiges Regex-Muster '$paramPattern' in '$pattern'. Verwende Fallback.");
                    $paramPattern = '[^/]+';
                }
            } catch (Throwable $e) {
                error_log("Regex-Fehler: " . $e->getMessage());
                $paramPattern = '[^/]+';
            }
            
            return '(?P<' . $paramName . '>' . $paramPattern . ')';
        }, $pattern);

        // Kompiliere mit UTF-8-Unterstützung und exakter Übereinstimmung
        $compiled = '#^' . $regex . '$#u';
        $this->regexCache[$cacheKey] = $compiled;
        return $compiled;
    }

    /**
     * Findet eine passende Route für eine Anfrage.
     */
    private function findMatchingRoute(Request $request): ?array {
        $path = '/' . trim($request->getPath(), '/');
        $method = $request->getMethod();
        
        // Root-Pfad Behandlung
        if (empty(trim($path, '/'))) {
            $path = '/';
            error_log("Root-Pfad '/' erkannt");
        }
        
        error_log("Suche Route für: {$method} {$path}");
        
        // Doppelte Slashes korrigieren
        if (strpos($path, '//') !== false) {
            $path = preg_replace('#/{2,}#', '/', $path);
        }
        
        // Prüfe alle Routen
        foreach ($this->routes as $route) {
            // Methode muss übereinstimmen
            if ($method !== $route['method']) {
                continue;
            }
            
            // Stelle sicher, dass Regex vorhanden ist
            $regex = $route['regex'] ?? '';
            if (empty($regex)) {
                try {
                    $regex = $this->compilePattern($route['pattern'] ?? '/', $route['options'] ?? []);
                } catch (Throwable $e) {
                    error_log("Regex-Fehler für '{$route['pattern']}': " . $e->getMessage());
                    continue;
                }
            }
            
            // Prüfe Übereinstimmung
            try {
                $matched = @preg_match($regex, $path, $matches);
                
                if ($matched === 1) {
                    // Parameter extrahieren (benannte Gruppen)
                    $params = array_filter($matches, function($key) {
                        return is_string($key) && !is_numeric($key);
                    }, ARRAY_FILTER_USE_KEY);
                    
                    return [
                        'route' => $route,
                        'params' => $params
                    ];
                }
            } catch (Throwable $e) {
                error_log("Match-Fehler für '{$route['pattern']}': " . $e->getMessage());
            }
        }
        
        // Fallback für Root-Pfad
        if ($path === '/') {
            return [
                'route' => [
                    'method' => self::METHOD_GET,
                    'pattern' => '/',
                    'regex' => '#^/$#',
                    'handler' => '',
                    'options' => ['name' => 'home.fallback']
                ],
                'params' => []
            ];
        }
        
        // Fallback für existierende Content-Dateien
        $contentPath = trim($path, '/') ?: self::DEFAULT_CONTENT_PATH;
        $pagePath = MARQUES_CONTENT_DIR . '/pages/' . $contentPath . '.md';
        
        if (file_exists($pagePath)) {
            return [
                'route' => [
                    'method' => self::METHOD_GET,
                    'pattern' => $path,
                    'regex' => '#^' . preg_quote($path, '#') . '$#',
                    'handler' => '',
                    'options' => ['name' => 'content.fallback']
                ],
                'params' => []
            ];
        }
        
        return null;
    }

    /**
     * Führt einen Route-Handler aus.
     */
    private function executeHandler($handler, Request $request, array $params): mixed {
        // Closure direkt ausführen
        if ($handler instanceof \Closure) {
            return $handler($request, $params);
        }

        // Controller@action Notation
        if (is_string($handler) && strpos($handler, '@') !== false) {
            list($controllerClass, $action) = explode('@', $handler, 2);

            if (!class_exists($controllerClass)) {
                throw new RuntimeException("Controller '$controllerClass' nicht gefunden.", 500);
            }

            if (!$this->container) {
                throw new RuntimeException("DI Container nicht verfügbar.", 500);
            }

            try {
                $controller = $this->container->get($controllerClass);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "Fehler beim Instanziieren von '$controllerClass': " . $e->getMessage(), 
                    500, 
                    $e
                );
            }

            if (!method_exists($controller, $action)) {
                throw new RuntimeException("Action '$action' in '$controllerClass' nicht gefunden.", 500);
            }

            return $controller->$action($request, $params);
        }

        // Leerer Handler -> Standard Content-Handler
        if (empty($handler)) {
            $normalizedPath = ltrim($request->getPath(), '/') ?: self::DEFAULT_CONTENT_PATH;
            
            // Versuch PageManager zu nutzen, wenn vorhanden
            if ($this->container && $this->container->has('PageManager')) {
                try {
                    $pageManager = $this->container->get('PageManager');
                    // Hier könnte man renderPage oder andere Methoden aufrufen
                } catch (\Exception $e) {
                    // Ignorieren und zum Fallback gehen
                }
            }
            
            // Standard-Array zurückgeben
            return [
                'path' => $normalizedPath,
                'params' => $params
            ];
        }

        // Ungültiger Handler-Typ
        throw new UnexpectedValueException("Ungültiger Handler-Typ: " . gettype($handler), 500);
    }

    /**
     * Validiert URL-Parameter anhand eines Schemas.
     */
    private function validateParameters(array $params, array $schema): bool {
        foreach ($schema as $key => $rules) {
            // Prüfe Vorhandensein
            if (!isset($params[$key])) {
                if (!empty($rules['required'])) return false;
                continue;
            }
            
            $value = $params[$key];
            
            // Typprüfung
            if (isset($rules['type'])) {
                switch ($rules['type']) {
                    case 'integer':
                        if (filter_var($value, FILTER_VALIDATE_INT) === false) return false;
                        $intValue = (int)$value;
                        if (isset($rules['min']) && $intValue < $rules['min']) return false;
                        if (isset($rules['max']) && $intValue > $rules['max']) return false;
                        break;
                        
                    case 'string':
                        if (!is_string($value)) return false;
                        if (isset($rules['pattern']) && !preg_match($rules['pattern'], $value)) return false;
                        if (isset($rules['min_length']) && mb_strlen($value, 'UTF-8') < $rules['min_length']) return false;
                        if (isset($rules['max_length']) && mb_strlen($value, 'UTF-8') > $rules['max_length']) return false;
                        break;
                        
                    case 'boolean':
                        if (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) return false;
                        break;
                        
                    case 'date':
                        $format = $rules['format'] ?? 'Y-m-d';
                        $date = \DateTime::createFromFormat($format, $value);
                        if (!$date || $date->format($format) !== $value) return false;
                        break;
                        
                    case 'float':
                        if (filter_var($value, FILTER_VALIDATE_FLOAT) === false) return false;
                        break;
                        
                    case 'email':
                        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) return false;
                        break;
                        
                    case 'url':
                        if (filter_var($value, FILTER_VALIDATE_URL) === false) return false;
                        break;
                }
            }
            
            // Zusätzliche Validierungen
            if (isset($rules['enum']) && is_array($rules['enum']) && !in_array($value, $rules['enum'], true)) {
                return false;
            }
            
            if (isset($rules['callback']) && is_callable($rules['callback']) && !($rules['callback']($value))) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Lädt Routen aus der Datenbank.
     */
    private function loadRoutesFromConfig(): void {
        if (!$this->persistRoutes) return;
        
        try {
            $mappingTable = $this->dbHandler->table(self::URL_MAPPING_TABLE);
            $urlMappings = $mappingTable->find();
            
            if (empty($urlMappings)) {
                $this->addDefaultFallbackRoutes();
                return;
            }
            
            // Verarbeite alle Routen aus der DB
            foreach ($urlMappings as $routeConfig) {
                // Überspringe ungültige Einträge
                if (!is_array($routeConfig) || empty($routeConfig['pattern'])) {
                    $this->logError('Ungültiger Routen-Eintrag: ' . json_encode($routeConfig));
                    continue;
                }
                
                // Extrahiere Routendaten
                $method = strtoupper($routeConfig['method'] ?? self::METHOD_GET);
                $pattern = $routeConfig['pattern'];
                $handler = $routeConfig['handler'] ?? '';
                $options = $this->parseOptionsJson($routeConfig['options'] ?? '{}');
                
                // Stelle Regex sicher
                $regex = $routeConfig['regex'] ?? '';
                if (empty($regex)) {
                    $regex = $this->compilePattern($pattern, $options);
                }
                
                // Füge Route hinzu
                $route = [
                    'method' => $method,
                    'pattern' => $pattern,
                    'regex' => $regex,
                    'handler' => $handler,
                    'options' => $options
                ];
                
                $this->routes[] = $route;
                if (isset($options['name'])) {
                    $this->namedRoutes[$options['name']] = $route;
                }
            }
            
            // Stelle essenzielle Routen sicher
            $this->ensureEssentialRoutes();
            
        } catch (\Exception $e) {
            $this->logError('Fehler beim Laden der Routen: ' . $e->getMessage());
            $this->routes = [];
            $this->namedRoutes = [];
            $this->addDefaultFallbackRoutes();
        }
    }

    /**
     * Parst JSON-Optionen mit Fehlerbehandlung.
     */
    private function parseOptionsJson(string $optionsJson): array {
        $options = json_decode($optionsJson, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError("JSON-Dekodierfehler: " . json_last_error_msg());
            return [];
        }
        
        return $options;
    }

    /**
     * Stellt sicher, dass essentielle Routen vorhanden sind.
     */
    private function ensureEssentialRoutes(): void {
        $hasRootRoute = false;
        $hasCatchAllRoute = false;
        
        foreach ($this->routes as $route) {
            if ($route['method'] === self::METHOD_GET && $route['pattern'] === '/') {
                $hasRootRoute = true;
            }
            if ($route['method'] === self::METHOD_GET && strpos($route['pattern'], '{path:') !== false) {
                $hasCatchAllRoute = true;
            }
        }
        
        // Root-Route hinzufügen
        if (!$hasRootRoute) {
            $this->addRoute(self::METHOD_GET, '/', '', ['name' => 'home.default']);
        }
        
        // Catch-All Route hinzufügen
        if (!$hasCatchAllRoute) {
            $this->addRoute(self::METHOD_GET, '/{path:.+}', '', ['name' => 'page.any.fallback']);
        }
    }
    
    /**
     * Fügt Minimale Fallback-Routen hinzu.
     */
    private function addDefaultFallbackRoutes(): void {
        $this->logWarning("Füge Fallback-Routen hinzu");
        $this->addRoute(self::METHOD_GET, '/', '', ['name' => 'home.fallback']);
        $this->addRoute(self::METHOD_GET, '/{path:.+}', '', ['name' => 'page.any.fallback']);
    }

    /**
     * Protokolliert Fehler.
     */
    private function logError(string $message, array $context = []): void {
        if ($this->container && $this->container->has(Logger::class)) {
            try { 
                $this->container->get(Logger::class)->error($message, $context); 
            } catch (\Exception $e) { 
                error_log(self::LOG_PREFIX['error'] . ": " . $message);
            }
        } else {
            error_log(self::LOG_PREFIX['error'] . ": " . $message);
        }
    }

    /**
     * Protokolliert Warnungen.
     */
    private function logWarning(string $message, array $context = []): void {
        if ($this->container && $this->container->has(Logger::class)) {
            try { 
                $this->container->get(Logger::class)->warning($message, $context); 
            } catch (\Exception $e) { 
                error_log(self::LOG_PREFIX['warning'] . ": " . $message);
            }
        } else {
            error_log(self::LOG_PREFIX['warning'] . ": " . $message);
        }
    }
}