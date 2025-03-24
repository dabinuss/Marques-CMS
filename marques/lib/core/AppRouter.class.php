<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * AppRouter - Verbesserte Router-Implementierung
 * 
 * Features:
 * - Klare Trennung von Routing, Middleware und Handler-Ausführung
 * - Unterstützung für benannte Routen zur URL-Generierung
 * - Verbesserte Middleware-Pipeline
 * - Performance-Optimierungen durch Caching
 * - Detaillierte Fehlerbehandlung mit spezifischen Exceptions
 */
class AppRouter {
    /** @var array Registrierte Routen */
    private array $routes = [];
    
    /** @var array Globale Middleware */
    private array $globalMiddleware = [];
    
    /** @var array Benannte Routen für URL-Generierung */
    private array $namedRoutes = [];
    
    /** @var array Cache für kompilierte Regex-Patterns */
    private array $regexCache = [];
    
    /** @var AppNode Dependency Injection Container */
    private ?AppNode $container = null;
    
    /** @var bool Wurden die Routen bereits geladen? */
    private bool $routesLoaded = false;
    
    /** @var array Routen-Gruppen-Stack für verschachtelte Gruppen */
    private array $groupStack = [];
    
    /** @var string Schlüssel für Datenbankeinträge */
    private string $configKey = 'urlmapping';
    
    /** @var bool Sollen Änderungen an Routen persistiert werden? */
    private bool $persistRoutes;
    
    /** @var DatabaseHandler Datenbank-Handler */
    private ?DatabaseHandler $dbHandler = null;

    /**
     * Konstruktor
     *
     * @param AppNode $container DI-Container
     * @param bool $persistRoutes Sollen Routen in der Datenbank persistiert werden?
     */
    public function __construct(AppNode $container, bool $persistRoutes = true) {
        $this->container = $container;
        $this->persistRoutes = $persistRoutes;
        
        // Lazy-Load der DatabaseHandler-Instanz, wenn sie benötigt wird
        if ($persistRoutes) {
            $this->dbHandler = $this->container->get(DatabaseHandler::class);
        }
    }

    /**
     * Fügt einen globalen Middleware-Handler hinzu.
     * Diese werden für jede Route ausgeführt, bevor der Route-Handler aufgerufen wird.
     *
     * @param callable $middleware Middleware-Funktion
     * @return self Für Method-Chaining
     */
    public function addGlobalMiddleware(callable $middleware): self {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    /**
     * Registriert eine neue Route.
     *
     * @param string $method HTTP-Methode (GET, POST, etc.)
     * @param string $pattern URL-Muster (z.B. "/blog/{slug}")
     * @param mixed $handler Callback oder Controller-String "Controller@action"
     * @param array $options Optionen wie Middleware, Namen, Parameter-Regeln
     * @return self Für Method-Chaining
     */
    public function addRoute(string $method, string $pattern, $handler, array $options = []): self {
        $method = strtoupper($method);
        
        // Wende Gruppen-Präfixe und Middleware an, wenn in einer Gruppe
        if (!empty($this->groupStack)) {
            $groupData = end($this->groupStack);
            
            // Präfix zum Pattern hinzufügen
            if (!empty($groupData['prefix'])) {
                $pattern = rtrim($groupData['prefix'], '/') . '/' . ltrim($pattern, '/');
            }
            
            // Gruppen-Middleware zur Route hinzufügen
            if (!empty($groupData['middleware'])) {
                $options['middleware'] = array_merge(
                    $groupData['middleware'],
                    $options['middleware'] ?? []
                );
            }
            
            // Name-Präfix hinzufügen, wenn vorhanden
            if (!empty($groupData['name_prefix']) && isset($options['name'])) {
                $options['name'] = $groupData['name_prefix'] . $options['name'];
            }
        }
        
        // Kompiliere Regex für effizientes Matching
        $regex = $this->compilePattern($pattern, $options);
        
        // Route-Eintrag erstellen
        $route = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
            'options' => $options,
        ];
        
        // Bei benannten Routen für URL-Generierung speichern
        if (isset($options['name'])) {
            $this->namedRoutes[$options['name']] = $route;
        }
        
        // Route hinzufügen
        $this->routes[] = $route;
        
        // Bei aktivierter Persistenz in Datenbank speichern
        if ($this->persistRoutes) {
            $this->persistRoute($method, $pattern, $options);
        }
        
        return $this;
    }
    
    /**
     * Erstellt eine Route-Gruppe mit gemeinsamen Attributen.
     *
     * @param array $attributes Gruppen-Attribute (prefix, middleware, name_prefix)
     * @param callable $callback Funktion, die Routen zur Gruppe hinzufügt
     * @return self Für Method-Chaining
     */
    public function group(array $attributes, callable $callback): self {
        // Füge neue Gruppe zum Stack hinzu
        $this->groupStack[] = $attributes;
        
        // Rufe Callback auf, um Routen zur Gruppe hinzuzufügen
        $callback($this);
        
        // Entferne Gruppe vom Stack
        array_pop($this->groupStack);
        
        return $this;
    }
    
    /**
     * Shortcut für GET-Routen
     */
    public function get(string $pattern, $handler, array $options = []): self {
        return $this->addRoute('GET', $pattern, $handler, $options);
    }
    
    /**
     * Shortcut für POST-Routen
     */
    public function post(string $pattern, $handler, array $options = []): self {
        return $this->addRoute('POST', $pattern, $handler, $options);
    }
    
    /**
     * Shortcut für PUT-Routen
     */
    public function put(string $pattern, $handler, array $options = []): self {
        return $this->addRoute('PUT', $pattern, $handler, $options);
    }
    
    /**
     * Shortcut für DELETE-Routen
     */
    public function delete(string $pattern, $handler, array $options = []): self {
        return $this->addRoute('DELETE', $pattern, $handler, $options);
    }
    
    /**
     * Generiert eine URL für eine benannte Route.
     *
     * @param string $name Name der Route
     * @param array $params Parameter für die URL
     * @param bool $absolute Absolute URL generieren?
     * @return string Die generierte URL
     */
    public function generateUrl(string $name, array $params = [], bool $absolute = false): string {
        $this->ensureRoutesLoaded();
        
        if (!isset($this->namedRoutes[$name])) {
            throw new \RuntimeException("Route mit Namen '$name' nicht gefunden");
        }
        
        $route = $this->namedRoutes[$name];
        $url = $route['pattern'];
        
        // Ersetze Parameter in der URL
        foreach ($params as $key => $value) {
            $url = str_replace("{{$key}}", (string)$value, $url);
        }
        
        // Überprüfe, ob noch unerfüllte Parameter vorhanden sind
        if (preg_match('/{(\w+)}/', $url, $matches)) {
            throw new \RuntimeException(
                "Parameter '{$matches[1]}' fehlt für Route '$name'"
            );
        }
        
        // Absolute URL generieren, wenn erforderlich
        if ($absolute) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $url = "$protocol://$host$url";
        }
        
        return $url;
    }
    
    /**
     * Verarbeitet eingehende Requests und führt den passenden Route-Handler aus.
     *
     * @param AppRouterRequest|null $request Request-Objekt (oder null für globalen Request)
     * @return mixed Ergebnis des Route-Handlers
     */
    public function dispatch(?AppRouterRequest $request = null): array {
        // Stelle sicher, dass Routen geladen sind
        $this->ensureRoutesLoaded();
        
        // Erstelle Request aus globalen Variablen, wenn keiner übergeben wurde
        if ($request === null) {
            $request = $this->createRequestFromGlobals();
        }
        
        // Verarbeitungs-Pipeline mit globaler Middleware
        $pipeline = function(AppRouterRequest $req) {
            $path = trim($req->getPath(), '/');
            if (empty($path)) {
                $path = 'home';
            }
            
            // Finde passende Route
            $matchedRoute = null;
            $params = [];
            
            foreach ($this->routes as $route) {
                if ($req->getMethod() !== $route['method']) {
                    continue;
                }
                
                // Prüfe, ob die Route passt
                if (preg_match($route['regex'], '/' . $path, $matches)) {
                    $matchedRoute = $route;
                    
                    // Extrahiere Parameter
                    $params = [];
                    foreach ($matches as $key => $value) {
                        if (!is_int($key)) {
                            $params[$key] = SafetyXSS::escapeOutput(trim($value), 'html');
                        }
                    }
                    break;
                }
            }
            
            // Wenn keine Route gefunden wurde
            if (!$matchedRoute) {
                throw new \RuntimeException("Keine Route gefunden für: " . $req->getPath());
            }
            
            // Validiere Parameter, falls Schema definiert
            if (isset($matchedRoute['options']['schema']) && is_array($matchedRoute['options']['schema'])) {
                if (!$this->validateParameters($params, $matchedRoute['options']['schema'])) {
                    throw new \RuntimeException("Ungültige Parameter");
                }
            }
            
            // Führe route-spezifische Middleware aus
            if (isset($matchedRoute['options']['middleware']) && is_array($matchedRoute['options']['middleware'])) {
                foreach ($matchedRoute['options']['middleware'] as $middleware) {
                    if (is_callable($middleware)) {
                        $result = $middleware($req, $params);
                        if ($result instanceof AppRouterResponse) {
                            throw new \Exception('Request durch Middleware unterbrochen', 403);
                        }
                    }
                }
            }
            
            // Führe Route-Handler aus
            return $this->executeHandler($matchedRoute['handler'], $req, $params);
        };
        
        // Baue Middleware-Kette auf
        $chain = $this->buildMiddlewareChain($pipeline);
        
        // Führe Verarbeitungspipeline aus
        return $chain($request);
    }
    
    /**
     * Prozessiert den eingehenden Request, führt Middleware aus und gibt Routen-Informationen zurück.
     *
     * @return array Routen-Informationen für Content-Verarbeitung
     */
    public function processRequest(): array {
        $request = $this->createRequestFromGlobals();
        
        try {
            return $this->dispatch($request);
        } catch (\RuntimeException $e) {
            // Fallback für nicht gefundene Routen: versuche als Seiteninhalt zu interpretieren
            $path = trim($request->getPath(), '/');
            if (empty($path)) {
                $path = 'home';
            }
            
            return [
                'path' => $path,
                'params' => [],
                'message' => 'Keine spezifische Route gefunden, interpretiere als Content-Seite'
            ];
        } catch (\RuntimeException $e) {
            throw new \Exception("Ungültige Parameter: " . $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            throw new \Exception("Handler nicht gefunden: " . $e->getMessage(), 500);
        }
    }
    
    /**
     * Stellt sicher, dass Routen geladen wurden.
     */
    private function ensureRoutesLoaded(): void {
        if ($this->routesLoaded) {
            return;
        }
        
        $this->loadRoutesFromConfig();
        $this->routesLoaded = true;
    }
    
    /**
     * Erstellt einen Request aus den globalen Variablen.
     *
     * @return AppRouterRequest Request-Objekt
     */
    private function createRequestFromGlobals(): AppRouterRequest {
        $server = $_SERVER;
        $get = $_GET;
        $post = $_POST;
        $headers = [];
        
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            foreach ($server as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                    $headers[$headerName] = $value;
                }
            }
        }
        
        return new AppRouterRequest($server, $get, $post, $headers);
    }
    
    /**
     * Baut die globale Middleware-Chain auf, die den finalen Handler umschließt.
     *
     * @param callable $finalHandler Finaler Handler
     * @return callable Komplette Middleware-Chain
     */
    private function buildMiddlewareChain(callable $finalHandler): callable {
        $chain = $finalHandler;
        
        // Füge globale Middleware in umgekehrter Reihenfolge hinzu (letzte zuerst)
        foreach (array_reverse($this->globalMiddleware) as $middleware) {
            $next = $chain;
            $chain = function(AppRouterRequest $req) use ($middleware, $next) {
                return $middleware($req, $next);
            };
        }
        
        return $chain;
    }
    
    /**
     * Kompiliert ein URL-Pattern zu einem regulären Ausdruck.
     *
     * @param string $pattern URL-Pattern
     * @param array $options Optionen mit Parameter-Regeln
     * @return string Kompilierter Regex
     */
    private function compilePattern(string $pattern, array $options = []): string {
        // Prüfe Cache
        if (isset($this->regexCache[$pattern])) {
            return $this->regexCache[$pattern];
        }
        
        // Ersetze Parameter-Platzhalter durch benannte Capture-Groups
        $regex = preg_replace_callback('#\{(\w+)\}#', function ($matches) use ($options) {
            $paramName = $matches[1];
            // Verwende benutzerdefiniertes Regex-Muster, falls vorhanden
            $paramPattern = $options['params'][$paramName] ?? '[^/]+';
            return '(?P<' . $paramName . '>' . $paramPattern . ')';
        }, $pattern);
        
        // Stelle sicher, dass der Regex exakt übereinstimmt
        $compiled = '#^' . $regex . '$#';
        
        // Cache für zukünftige Verwendung
        $this->regexCache[$pattern] = $compiled;
        
        return $compiled;
    }
    
    /**
     * Führt einen Route-Handler aus (Callback oder Controller-Action).
     *
     * @param mixed $handler Callback oder Controller-String
     * @param AppRouterRequest $request Request-Objekt
     * @param array $params Route-Parameter
     * @return array Ergebnis des Handlers
     */
    private function executeHandler($handler, AppRouterRequest $request, array $params): array {
        // Direktes Callback
        if (is_callable($handler)) {
            return call_user_func($handler, $request, $params);
        }
        
        // Controller@action String
        if (is_string($handler) && strpos($handler, '@') !== false) {
            list($controllerClass, $action) = explode('@', $handler);
            
            // Spezialfall für PageManager
            if ($controllerClass === 'Marques\\Core\\PageManager' && $action === 'getPage') {
                $pageId = $params['page'] ?? ltrim($request->getPath(), '/');
                if (empty($pageId)) {
                    $pageId = 'home';
                }
                
                // Lazy-Load PageManager aus Container oder instanziiere neu
                if ($this->container->has('Marques\\Core\\PageManager')) {
                    $pageManager = $this->container->get('Marques\\Core\\PageManager');
                } else {
                    $pageManager = new \Marques\Core\PageManager($this->container->get(DatabaseHandler::class));
                }
                
                return [
                    'path' => $pageId,
                    'params' => $params,
                    'message' => 'Page Manager Route'
                ];
            }
            
            // Whitelist für erlaubte Controller
            $allowedControllers = [
                'Marques\\Controller\\BlogController',
                'Marques\\Controller\\UserController'
                // Weitere erlaubte Controller hier hinzufügen
            ];
            
            if (!in_array($controllerClass, $allowedControllers, true)) {
                throw new \RuntimeException(
                    "Controller '$controllerClass' ist nicht erlaubt"
                );
            }
            
            // Versuche, Controller aus Container zu holen oder neu zu instanziieren
            if ($this->container->has($controllerClass)) {
                $controller = $this->container->get($controllerClass);
            } else {
                $controller = new $controllerClass();
            }
            
            // Stelle sicher, dass die Action existiert
            if (!method_exists($controller, $action)) {
                throw new \RuntimeException(
                    "Action '$action' in Controller '$controllerClass' nicht gefunden"
                );
            }
            
            // Führe Controller-Action aus
            return call_user_func([$controller, $action], $request, $params);
        }
        
        // Falls Handler weder Callback noch gültiger Controller-String ist
        throw new \RuntimeException(
            "Ungültiger Route-Handler: " . (is_string($handler) ? $handler : gettype($handler))
        );
    }
    
    /**
     * Validiert Parameter anhand eines Schemas.
     *
     * @param array $params Parameter-Array
     * @param array $schema Validierungs-Schema
     * @return bool True wenn alle Parameter gültig sind
     */
    private function validateParameters(array $params, array $schema): bool {
        foreach ($schema as $key => $rules) {
            if (!isset($params[$key]) && isset($rules['required']) && $rules['required']) {
                return false;
            }
            
            if (!isset($params[$key])) {
                continue;
            }
            
            $value = $params[$key];
            
            if (isset($rules['type'])) {
                switch ($rules['type']) {
                    case 'integer':
                        if (!is_numeric($value) || (int)$value != $value) {
                            return false;
                        }
                        $intValue = (int)$value;
                        if (isset($rules['min']) && $intValue < $rules['min']) {
                            return false;
                        }
                        if (isset($rules['max']) && $intValue > $rules['max']) {
                            return false;
                        }
                        break;
                        
                    case 'string':
                        if (!is_string($value)) {
                            return false;
                        }
                        if (isset($rules['pattern']) && !preg_match($rules['pattern'], $value)) {
                            return false;
                        }
                        if (isset($rules['min_length']) && strlen($value) < $rules['min_length']) {
                            return false;
                        }
                        if (isset($rules['max_length']) && strlen($value) > $rules['max_length']) {
                            return false;
                        }
                        break;
                        
                    case 'boolean':
                        if (!is_bool($value) && !in_array($value, ['0', '1', 'true', 'false'], true)) {
                            return false;
                        }
                        break;
                        
                    case 'date':
                        $date = \DateTime::createFromFormat($rules['format'] ?? 'Y-m-d', $value);
                        if (!$date || $date->format($rules['format'] ?? 'Y-m-d') !== $value) {
                            return false;
                        }
                        break;
                }
            }
            
            if (isset($rules['enum']) && !in_array($value, $rules['enum'], true)) {
                return false;
            }
            
            if (isset($rules['callback']) && is_callable($rules['callback'])) {
                if (!call_user_func($rules['callback'], $value)) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Persistiert eine Route in der Datenbank.
     *
     * @param string $method HTTP-Methode
     * @param string $pattern URL-Pattern
     * @param array $options Routen-Optionen
     */
    private function persistRoute(string $method, string $pattern, array $options = []): void {
        $currentRoutes = AppConfig::getInstance()->loadUrlMapping();
    
        // Stelle sicher, dass alle Einträge als Array vorliegen
        foreach ($currentRoutes as $i => $route) {
            if (is_string($route)) {
                // Konvertiere einen alten String-Eintrag in ein Array
                $currentRoutes[$i] = [
                    'method'  => 'GET',
                    'pattern' => $route,
                    'handler' => '',
                    'options' => []
                ];
            }
        }
    
        $newRoute = [
            'method'  => $method,
            'pattern' => $pattern,
            'handler' => $options['handler'] ?? '',
            'options' => $options,
        ];
        
        // Überprüfe, ob diese Route bereits existiert
        $found = false;
        foreach ($currentRoutes as $i => $route) {
            if ($route['method'] === $method && $route['pattern'] === $pattern) {
                $currentRoutes[$i] = $newRoute;
                $found = true;
                break;
            }
        }
        
        // Füge neue Route hinzu, wenn sie noch nicht existiert
        if (!$found) {
            $currentRoutes[] = $newRoute;
        }
        
        // Speichere aktualisierte Routen in der Datenbank
        AppConfig::getInstance()->updateUrlMapping($currentRoutes);
    }
    
    /**
     * Lädt persistierte Routen aus der Datenbank und registriert sie.
     */
    private function loadRoutesFromConfig(): void {
        try {
            // Lade Routen aus Datenbank
            $systemConfig = $this->dbHandler->getAllSettings();
            $urlMappings = $systemConfig[$this->configKey] ?? [];
            
            // Wenn keine Routen definiert sind, füge eine Default-Route für die Startseite hinzu
            if (empty($urlMappings)) {
                $this->addDefaultRoutes();
                return;
            }
            
            // Registriere alle konfigurierten Routen
            foreach ($urlMappings as $routeConfig) {
                // Konvertiere alte String-Formate
                if (is_string($routeConfig)) {
                    $routeConfig = [
                        'method'  => 'GET',
                        'pattern' => $routeConfig,
                        'handler' => '',
                        'options' => [],
                    ];
                }
                
                $method = $routeConfig['method'] ?? 'GET';
                $pattern = $routeConfig['pattern'] ?? '';
                $handlerString = $routeConfig['handler'] ?? '';
                $options = $routeConfig['options'] ?? [];
                
                // Erstelle Handler basierend auf dem Handler-String
                if (!empty($handlerString)) {
                    $handler = $this->createHandlerFromString($handlerString);
                } else {
                    // Default-Handler für Routen ohne expliziten Handler
                    $handler = function(AppRouterRequest $req, array $params) {
                        $normalizedPath = ltrim($req->getPath(), '/');
                        if (empty($normalizedPath)) {
                            $normalizedPath = 'home';
                        }
                        return [
                            'path'    => $normalizedPath,
                            'params'  => $params,
                            'message' => 'Default Route Handler'
                        ];
                    };
                }
                
                // Registriere Route ohne erneute Persistierung zu vermeiden (rekursives Speichern)
                $this->routes[] = [
                    'method' => $method,
                    'pattern' => $pattern, 
                    'regex' => $this->compilePattern($pattern, $options),
                    'handler' => $handler,
                    'options' => $options
                ];
                
                // Für benannte Routen auch im namedRoutes-Array speichern
                if (isset($options['name'])) {
                    $this->namedRoutes[$options['name']] = end($this->routes);
                }
            }
        } catch (\Exception $e) {
            // Fallback zu Standard-Routen bei Fehler
            error_log('Fehler beim Laden der Routen: ' . $e->getMessage());
            $this->addDefaultRoutes();
        }
    }
    
    /**
     * Fügt Standard-Routen hinzu, wenn keine konfiguriert sind.
     */
    private function addDefaultRoutes(): void {
        // Startseite
        $this->addRoute('GET', '/', function(AppRouterRequest $req, array $params) {
            return [
                'path' => 'home',
                'params' => [],
                'message' => 'Default homepage route'
            ];
        }, ['name' => 'home']);
        
        // Blog-Beitrag mit Slug
        $this->addRoute('GET', '/blog/{slug}', function(AppRouterRequest $req, array $params) {
            return [
                'path' => 'blog',
                'params' => $params,
                'message' => 'Default blog post route'
            ];
        }, [
            'name' => 'blog.show',
            'params' => ['slug' => '[a-z0-9\-]+']
        ]);
        
        // Blog-Liste
        $this->addRoute('GET', '/blog', function(AppRouterRequest $req, array $params) {
            return [
                'path' => 'blog-list',
                'params' => [],
                'message' => 'Default blog list route'
            ];
        }, ['name' => 'blog.list']);
        
        // Blog-Kategorie
        $this->addRoute('GET', '/blog/category/{category}', function(AppRouterRequest $req, array $params) {
            return [
                'path' => 'blog-category',
                'params' => $params,
                'message' => 'Default blog category route'
            ];
        }, [
            'name' => 'blog.category',
            'params' => ['category' => '[a-z0-9\-]+']
        ]);
        
        // Blog-Archiv
        $this->addRoute('GET', '/blog/archive/{year}/{month}', function(AppRouterRequest $req, array $params) {
            return [
                'path' => 'blog-archive',
                'params' => $params,
                'message' => 'Default blog archive route'
            ];
        }, [
            'name' => 'blog.archive',
            'params' => [
                'year' => '\d{4}',
                'month' => '\d{2}'
            ]
        ]);
    }
    
    /**
     * Erstellt einen Handler aus einem Handler-String (Controller@action).
     *
     * @param string $handlerString Handler-String (z.B. "Controller@action")
     * @return callable Handler-Funktion
     */
    private function createHandlerFromString(string $handlerString): callable {
        if (strpos($handlerString, '@') === false) {
            throw new \RuntimeException(
                "Ungültiger Handler-String: '$handlerString' (@ fehlt)"
            );
        }
        
        list($controllerClass, $action) = explode('@', $handlerString);
        
        if ($controllerClass === 'Marques\\Core\\PageManager' && $action === 'getPage') {
            // Spezieller Handler für PageManager
            return function(AppRouterRequest $req, array $params) use ($handlerString) {
                $pageId = $params['page'] ?? ltrim($req->getPath(), '/');
                if (empty($pageId)) {
                    $pageId = 'home';
                }
                
                return [
                    'path' => $pageId,
                    'params' => $params,
                    'handler' => $handlerString
                ];
            };
        }
        
        // Whitelist für erlaubte Controller
        $allowedControllers = [
            'Marques\\Controller\\BlogController',
            'Marques\\Controller\\UserController'
            // Weitere erlaubte Controller hier hinzufügen
        ];
        
        if (!in_array($controllerClass, $allowedControllers, true)) {
            throw new \RuntimeException(
                "Controller '$controllerClass' ist nicht erlaubt"
            );
        }
        
        // Erstelle Closure, das den Controller aus dem Container holt und die Action aufruft
        return function(AppRouterRequest $req, array $params) use ($controllerClass, $action) {
            // Holen vom Container oder instanziieren
            if ($this->container->has($controllerClass)) {
                $controller = $this->container->get($controllerClass);
            } else {
                if (!class_exists($controllerClass)) {
                    throw new \RuntimeException(
                        "Controller-Klasse '$controllerClass' nicht gefunden"
                    );
                }
                $controller = new $controllerClass();
            }
            
            // Überprüfe, ob die Action existiert
            if (!method_exists($controller, $action)) {
                throw new \RuntimeException(
                    "Action '$action' in Controller '$controllerClass' nicht gefunden"
                );
            }
            
            // Rufe Controller-Action auf
            return call_user_func([$controller, $action], $req, $params);
        };
    }
}



