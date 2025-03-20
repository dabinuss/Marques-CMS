<?php
declare(strict_types=1);

namespace Marques\Core;

use Marques\Core\SafetyXSS;
use Marques\Core\AppRouterRequest;
use Marques\Core\AppRouterResponse;

class AppRouter {
    private array $routes = [];
    private array $globalMiddleware = [];
    private array $regexCache = [];
    private string $configKey = 'urlmapping';
    private ?AppNode $container = null;
    private bool $persistRoutes;

    public function __construct(AppNode $container, bool $persistRoutes = true) {
        $this->container = $container;
        $this->persistRoutes = $persistRoutes;
        $this->loadRoutesFromConfig();
    }

    /**
     * Fügt einen globalen Middleware-Handler hinzu.
     * Diese werden vor der Routenauswahl in der Reihenfolge ihrer Registrierung ausgeführt.
     */
    public function addGlobalMiddleware(callable $middleware): void {
        $this->globalMiddleware[] = $middleware;
    }

    /**
     * Registriert eine neue Route.
     *
     * @param string   $method   HTTP-Methode (GET, POST, etc.)
     * @param string   $pattern  URL-Muster (z. B. "/blog/{slug}")
     * @param callable $callback Callback, der mindestens zwei Parameter erwartet: Request und $params.
     * @param array    $options  Optionen wie:
     *                           - params: eigene Regex für Platzhalter.
     *                           - middleware: Array von Middleware-Callbacks (lokal für diese Route).
     *                           - schema: Array mit Validierungsregeln für Parameter.
     *                           - handler: String (z. B. "Controller@action") für persistente Speicherung.
     */
    public function addRoute(string $method, string $pattern, callable $callback, array $options = []): void {
        $method = strtoupper($method);
        $regex = $this->getCompiledRegex($pattern, $options);
        $route = [
            'method'   => $method,
            'pattern'  => $pattern,
            'regex'    => $regex,
            'callback' => $callback,
            'options'  => $options,
        ];
        $this->routes[] = $route;
        if ($this->persistRoutes) {
            $this->persistRoute($method, $pattern, $options);
        }
    }

    /**
     * Holt oder kompiliert den Regex für ein URL-Muster.
     */
    private function getCompiledRegex(string $pattern, array $options = []): string {
        if (isset($this->regexCache[$pattern])) {
            return $this->regexCache[$pattern];
        }
        $regex = preg_replace_callback('#\{(\w+)\}#', function ($matches) use ($options) {
            $paramName = $matches[1];
            $paramPattern = $options['params'][$paramName] ?? '[^/]+';
            return '(?P<' . $paramName . '>' . $paramPattern . ')';
        }, $pattern);
        $compiled = '#^' . $regex . '$#';
        $this->regexCache[$pattern] = $compiled;
        return $compiled;
    }

    /**
     * Erstellt einen Request aus den globalen Variablen.
     */
    private function createRequestFromGlobals(): AppRouterRequest {
        $server = $_SERVER;
        $get    = $_GET;
        $post   = $_POST;
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
     * Verarbeitet den Request, führt globale Middleware aus und leitet den Request an den passenden Routen-Handler weiter.
     */
    public function processRequest(): array {
        $request = $this->createRequestFromGlobals();
        $chain = $this->buildMiddlewareChain(function(AppRouterRequest $req): array {
            foreach ($this->routes as $route) {
                if ($req->getMethod() !== $route['method']) {
                    continue;
                }
                if (preg_match($route['regex'], $req->getPath(), $matches)) {
                    $params = [];
                    foreach ($matches as $key => $value) {
                        if (!is_int($key)) {
                            // Nur Sanitization hier; die Validierung erfolgt separat.
                            $params[$key] = SafetyXSS::escapeOutput(trim($value), 'html');
                        }
                    }
                    // Parameter-Validierung anhand des definierten Schemas (sofern vorhanden)
                    if (isset($route['options']['schema']) && is_array($route['options']['schema'])) {
                        if (!$this->validateParameters($params, $route['options']['schema'])) {
                            throw new \Exception('Invalid parameters', 400);
                        }
                    }
                    // Lokale Middleware-Kette für diese Route
                    if (isset($route['options']['middleware']) && is_array($route['options']['middleware'])) {
                        foreach ($route['options']['middleware'] as $mw) {
                            if (is_callable($mw)) {
                                $result = $mw($req, $params);
                                if ($result instanceof AppRouterResponse) {
                                    // Alternativ: Man könnte hier auch das Response-Objekt direkt senden.
                                    throw new \Exception('Request interrupted by middleware', 403);
                                }
                            }
                        }
                    }
                    $routeInfo = call_user_func($route['callback'], $req, $params);
                    if (!is_array($routeInfo) || !isset($routeInfo['path'])) {
                        throw new \Exception('Route callback did not return valid route info', 500);
                    }
                    return $routeInfo;
                }
            }
            throw new \Exception('Route not found', 404);
        });
        return $chain($request);
    }    

    /**
     * Baut die globale Middleware-Chain auf, die den finalen Handler umschließt.
     */
    private function buildMiddlewareChain(callable $finalHandler): callable {
        $chain = $finalHandler;
        foreach (array_reverse($this->globalMiddleware) as $middleware) {
            $next = $chain;
            $chain = function(AppRouterRequest $req) use ($middleware, $next) {
                return $middleware($req, $next);
            };
        }
        return $chain;
    }

    /**
     * Validiert Parameter anhand eines Schemas.
     *
     * Beispiel-Schema:
     * [
     *   'id' => ['type' => 'integer', 'min' => 1],
     *   'slug' => ['type' => 'string', 'pattern' => '/^[a-z0-9-]+$/']
     * ]
     */
    private function validateParameters(array $params, array $schema): bool {
        foreach ($schema as $key => $rules) {
            if (!isset($params[$key])) {
                return false;
            }
            $value = $params[$key];
            if ($rules['type'] === 'integer') {
                if (!is_numeric($value)) {
                    return false;
                }
                $intValue = (int)$value;
                if (isset($rules['min']) && $intValue < $rules['min']) {
                    return false;
                }
                if (isset($rules['max']) && $intValue > $rules['max']) {
                    return false;
                }
            } elseif ($rules['type'] === 'string') {
                if (!is_string($value)) {
                    return false;
                }
                if (isset($rules['pattern']) && !preg_match($rules['pattern'], $value)) {
                    return false;
                }
            }
            // Weitere Typen können hier ergänzt werden.
        }
        return true;
    }

    /**
     * Persistiert eine Route in der Konfigurationsdatei.
     */
    private function persistRoute(string $method, string $pattern, array $options = []): void {
        $currentRoutes = AppConfig::getInstance()->loadUrlMapping();
    
        // Stelle sicher, dass alle Einträge als Array vorliegen:
        foreach ($currentRoutes as $i => $route) {
            if (is_string($route)) {
                // Konvertiere einen alten String-Eintrag in ein Array
                $currentRoutes[$i] = [
                    'method'  => 'GET',        // Standardwert (ggf. anpassen)
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
        $found = false;
        foreach ($currentRoutes as $i => $route) {
            if ($route['method'] === $method && $route['pattern'] === $pattern) {
                $currentRoutes[$i] = $newRoute;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $currentRoutes[] = $newRoute;
        }
        AppConfig::getInstance()->updateUrlMapping($currentRoutes);
    }
    

    /**
     * Lädt persistierte Routen aus der Konfigurationsdatei und registriert sie.
     */
    public function loadRoutesFromConfig(): void {
        $urlMappings = AppConfig::getInstance()->loadUrlMapping();
        
        if (!empty($urlMappings)) {
            foreach ($urlMappings as $routeConfig) {
                // Falls der Eintrag als String vorliegt, in Array umwandeln:
                if (is_string($routeConfig)) {
                    $routeConfig = [
                        'method'  => 'GET',
                        'pattern' => $routeConfig,
                        'handler' => '',  // Hier ggf. einen Default-Handler definieren
                        'options' => [],
                    ];
                }
                // Registriere die Route
                $handlerString = $routeConfig['handler'] ?? '';
                if (!empty($handlerString)) {
                    if (strpos($handlerString, '@') === false) {
                        throw new \Exception('Invalid handler string for route: missing "@"', 500);
                    }
                    list($controllerClass, $action) = explode('@', $handlerString);
                    if ($controllerClass === 'Marques\\Core\\PageManager' && $action === 'getPage') {
                        $routeCallback = function(AppRouterRequest $req, array $params) {
                            $pageId = $params['page'] ?? ltrim($req->getPath(), '/');
                            $pageManager = new \Marques\Core\PageManager();
                            $pageData = $pageManager->getPage($pageId);
                            if (!$pageData) {
                                throw new \Exception('Page not found', 404);
                            }
                            return $pageData;
                        };
                    } else {
                        $allowedControllers = [
                            'Marques\\Controller\\BlogController',
                            'Marques\\Controller\\UserController'
                        ];
                        if (!in_array($controllerClass, $allowedControllers, true)) {
                            throw new \Exception('Controller not allowed.');
                        }
                        $routeCallback = function(AppRouterRequest $req, array $params) use ($controllerClass, $action) {
                            if ($this->container && $this->container->has($controllerClass)) {
                                $controller = $this->container->get($controllerClass);
                            } else {
                                $controller = new $controllerClass();
                            }
                            return call_user_func([$controller, $action], $req, $params);
                        };
                    }
                } else {
                    $routeCallback = function(AppRouterRequest $req, array $params) {
                        $normalizedPath = ltrim($req->getPath(), '/');
                        if ($normalizedPath === '') {
                            $normalizedPath = 'home';
                        }
                        return [
                            'path'    => $normalizedPath,
                            'params'  => $params,
                            'message' => 'No controller defined for this route.'
                        ];
                    };
                }
                $this->addRoute(
                    $routeConfig['method'],
                    $routeConfig['pattern'],
                    $routeCallback,
                    $routeConfig['options'] ?? []
                );
            }
        }
    }    

}
