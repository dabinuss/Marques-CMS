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
 * Application‑wide HTTP router.
 *
 * Registers routes, resolves handlers, executes middleware and provides
 * graceful fallbacks for CMS‑driven content pages.
 */
class Router
{
    private const METHOD_GET            = 'GET';
    private const URL_MAPPING_TABLE     = 'urlmapping';
    private const DEFAULT_CONTENT_PATH  = 'home';
    private const LOG_PREFIX            = ['error' => 'LOG ERROR', 'warning' => 'LOG WARNING'];

    private array $routes             = [];
    private array $globalMiddleware   = [];
    private array $namedRoutes        = [];
    private array $regexCache         = [];
    private ?Node $container          = null;
    private bool $routesLoaded        = false;
    private array $groupStack         = [];
    private bool $persistRoutes;
    private DatabaseHandler $dbHandler;
    private array $routeMatchCache    = [];

    /**
     * @param Node             $container     DI container
     * @param DatabaseHandler  $dbHandler     Database handler
     * @param bool             $persistRoutes Whether routes are persisted
     */
    public function __construct(Node $container, DatabaseHandler $dbHandler, bool $persistRoutes = true)
    {
        $this->container     = $container;
        $this->dbHandler     = $dbHandler;
        $this->persistRoutes = $persistRoutes;
    }

    /**
     * Registers a global middleware callback.
     */
    public function addGlobalMiddleware(callable $middleware): self
    {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    /**
     * Adds a new route definition.
     */
    public function addRoute(string $method, string $pattern, $handler, array $options = []): self
    {
        $method = strtoupper($method);

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

        if ($pattern === '' || $pattern[0] !== '/') {
            $pattern = '/' . $pattern;
        }

        $regex = $this->compilePattern($pattern, $options);

        $route = [
            'method'  => $method,
            'pattern' => $pattern,
            'regex'   => $regex,
            'handler' => $handler,
            'options' => $options,
        ];

        if (isset($options['name'])) {
            $this->namedRoutes[$options['name']] = $route;
        }

        $this->routes[] = $route;

        return $this;
    }

    /**
     * Groups routes under shared attributes.
     */
    public function group(array $attributes, callable $callback): self
    {
        if (isset($attributes['prefix'])) {
            $attributes['prefix'] = '/' . trim($attributes['prefix'], '/');
        }
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
        return $this;
    }

    public function get(string $pattern, $handler, array $options = []): self
    {
        return $this->addRoute(self::METHOD_GET, $pattern, $handler, $options);
    }

    public function post(string $pattern, $handler, array $options = []): self
    {
        return $this->addRoute('POST', $pattern, $handler, $options);
    }

    public function put(string $pattern, $handler, array $options = []): self
    {
        return $this->addRoute('PUT', $pattern, $handler, $options);
    }

    public function delete(string $pattern, $handler, array $options = []): self
    {
        return $this->addRoute('DELETE', $pattern, $handler, $options);
    }

    /**
     * Assigns a name to the most recently added route.
     */
    public function name(string $name): self
    {
        if ($this->routes === []) {
            throw new LogicException('No route available to name.');
        }

        $lastIndex = array_key_last($this->routes);
        $this->routes[$lastIndex]['options']['name'] = $name;
        $this->namedRoutes[$name] = $this->routes[$lastIndex];

        return $this;
    }

    /**
     * Generates a URL from a named route.
     */
    public function generateUrl(string $name, array $params = [], bool $absolute = false): string
    {
        $this->ensureRoutesLoaded();

        if (!isset($this->namedRoutes[$name])) {
            throw new RuntimeException("Route '{$name}' not found.");
        }

        $route = $this->namedRoutes[$name];
        $url   = $route['pattern'];

        $url = preg_replace_callback('/\{(\w+)\}/', static function ($m) use ($params) {
            return isset($params[$m[1]]) ? rawurlencode((string)$params[$m[1]]) : $m[0];
        }, $url);

        if (preg_match('/{(\w+)(?::[^}]+)?}/', $url, $missing)) {
            throw new RuntimeException("Missing parameter '{$missing[1]}' for route '{$name}'.");
        }

        if ($absolute) {
            $protocol = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $url      = rtrim("{$protocol}://{$host}", '/') . $url;
        }

        return $url;
    }

    /**
     * Dispatches the current Request through middleware and route handler.
     */
    public function dispatch(?Request $request = null): mixed
    {
        try {
            $this->ensureRoutesLoaded();
            $request ??= $this->createRequestFromGlobals();

            $pipeline = function (Request $req, array $params = []) {
                $match = $this->findMatchingRoute($req);

                if (!$match) {
                    throw new RuntimeException(
                        'Page not found [' . $req->getMethod() . ': ' . SafetyXSS::escapeOutput($req->getPath()) . ']',
                        404
                    );
                }

                $route  = $match['route'];
                $params = $match['params'] ?? [];

                if (!empty($route['options']['schema']) &&
                    !$this->validateParameters($params, $route['options']['schema'])) {
                    throw new InvalidArgumentException('Invalid parameters for route.', 400);
                }

                $executor = function (Request $r, array $p = []) use ($route) {
                    return $this->executeHandler($route['handler'], $r, $p);
                };

                return !empty($route['options']['middleware'])
                    ? ($this->buildMiddlewareChain($executor, $route['options']['middleware']))($req, $params)
                    : $executor($req, $params);
            };

            return ($this->buildMiddlewareChain($pipeline, $this->globalMiddleware))($request, []);
        } catch (RuntimeException $e) {
            error_log('Router Dispatch Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Processes the current request and normalises the result for the CMS.
     */
    public function processRequest(): mixed
    {
        try {
            $request = $this->createRequestFromGlobals();
            $result  = $this->dispatch($request);

            if (!is_array($result)) {
                $path   = trim($request->getPath(), '/');
                $path   = $path === '' ? self::DEFAULT_CONTENT_PATH : $path;
                $result = ['path' => $path, 'params' => []];
            }

            return $result;
        } catch (RuntimeException | InvalidArgumentException | UnexpectedValueException $e) {
            if ($e->getCode() === 404 || str_contains($e->getMessage(), 'not found')) {
                $path = trim($_SERVER['REQUEST_URI'] ?? '/', '/');
                $path = $path === '' ? self::DEFAULT_CONTENT_PATH : $path;
                $page = MARQUES_CONTENT_DIR . '/pages/' . $path . '.md';

                if (file_exists($page)) {
                    error_log("Route fallback to content file: {$page}");
                    return ['path' => $path, 'params' => []];
                }
            }

            $this->logError('Router error: ' . $e->getMessage(), ['exception' => $e, 'path' => $_SERVER['REQUEST_URI'] ?? '/']);
            throw $e;
        } catch (Throwable $e) {
            error_log('Critical Router Error: ' . $e->getMessage());
            throw new RuntimeException('Internal router error', 500, $e);
        }
    }

    /**
     * Validates that the necessary URL‑mapping table exists.
     */
    public function ensureRoutes(): void
    {
        if (!$this->persistRoutes) {
            return;
        }

        try {
            $db = $this->dbHandler->getLibraryDatabase();
            if (!$db->hasTable(self::URL_MAPPING_TABLE)) {
                error_log('URL‑mapping table missing – forcing reinitialisation');
                new DatabaseConfig($this->dbHandler);
                $this->resetRoutesState();
                return;
            }

            try {
                if ($this->dbHandler->table(self::URL_MAPPING_TABLE)->first() === null) {
                    error_log('URL‑mapping table empty – forcing reinitialisation');
                    new DatabaseConfig($this->dbHandler);
                    $this->resetRoutesState();
                }
            } catch (\Exception $e) {
                error_log('URL‑mapping table check failed: ' . $e->getMessage());
            }
        } catch (Throwable $e) {
            error_log('Route initialisation error: ' . $e->getMessage());
        }
    }

    /**
     * Clears cached routes and reloads them from configuration.
     */
    private function resetRoutesState(): void
    {
        $this->routes       = [];
        $this->namedRoutes  = [];
        $this->routesLoaded = false;
        $this->loadRoutesFromConfig();
    }

    /**
     * Ensures routes are loaded into memory.
     */
    private function ensureRoutesLoaded(): void
    {
        if (!$this->routesLoaded) {
            if ($this->persistRoutes) {
                $this->loadRoutesFromConfig();
            }
            $this->routesLoaded = true;
        }
    }

    /**
     * Creates a Request object from PHP superglobals.
     */
    private function createRequestFromGlobals(): Request
    {
        $headers = function_exists('getallheaders') ? getallheaders() : $this->getHeadersFromServer($_SERVER);
        return new Request($_SERVER, $_GET, $_POST, $headers);
    }

    /**
     * Extracts HTTP headers from the $_SERVER array when getallheaders() is unavailable.
     */
    private function getHeadersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name            = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name]  = $value;
            }
        }
        return $headers;
    }

    /**
     * Builds a middleware execution chain.
     */
    private function buildMiddlewareChain(callable $finalHandler, array $middlewares): callable
    {
        $chain = $finalHandler;
        foreach (array_reverse($middlewares) as $middleware) {
            $next  = $chain;
            $chain = static fn(Request $r, array $p = []) => $middleware($r, $p, $next);
        }
        return $chain;
    }

    /**
     * Compiles a URL pattern to a regex, with result caching and complexity guards.
     */
    private function compilePattern(string $pattern, array $options = []): string
    {
        $key = $pattern . json_encode($options['params'] ?? []);
        if (isset($this->regexCache[$key])) {
            return $this->regexCache[$key];
        }

        $regex = preg_replace_callback('#\{(\w+)(?::([^}]+))?\}#u', function ($m) use ($options, $pattern) {
            $name   = $m[1];
            $subExp = $m[2] ?? ($options['params'][$name] ?? '[^/]+');
            $qCount = substr_count($subExp, '*') + substr_count($subExp, '+');
            if ($qCount > 5) {
                error_log("Complex regex '{$subExp}' in '{$pattern}', using fallback.");
                $subExp = '[^/]+';
            }
            if (@preg_match('#^' . $subExp . '$#u', '') === false) {
                error_log("Invalid regex '{$subExp}' in '{$pattern}', using fallback.");
                $subExp = '[^/]+';
            }
            return '(?P<' . $name . '>' . $subExp . ')';
        }, $pattern);

        return $this->regexCache[$key] = '#^' . $regex . '$#u';
    }

    /**
     * Finds a matching route for the given request.
     */
    private function findMatchingRoute(Request $request): ?array
    {
        $path   = '/' . trim($request->getPath(), '/');
        $method = $request->getMethod();
        $key    = $method . ':' . $path;

        if (isset($this->routeMatchCache[$key])) {
            return $this->routeMatchCache[$key];
        }

        if ($path === '') {
            $path = '/';
        }

        if (str_contains($path, '//')) {
            $path = preg_replace('#/{2,}#', '/', $path);
        }

        foreach ($this->routes as $route) {
            if ($method !== $route['method']) {
                continue;
            }
            $regex = $route['regex'] ?? '';
            if ($regex === '') {
                try {
                    $regex = $this->compilePattern($route['pattern'], $route['options'] ?? []);
                } catch (Throwable $e) {
                    error_log("Regex compile failure for '{$route['pattern']}': " . $e->getMessage());
                    continue;
                }
            }

            try {
                if (@preg_match($regex, $path, $matches) === 1) {
                    $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                    return $this->routeMatchCache[$key] = ['route' => $route, 'params' => $params];
                }
            } catch (Throwable $e) {
                error_log("Regex match failure for '{$route['pattern']}': " . $e->getMessage());
            }
        }

        if ($path === '/') {
            return ['route' => ['method' => self::METHOD_GET, 'pattern' => '/', 'regex' => '#^/$#', 'handler' => '', 'options' => ['name' => 'home.fallback']], 'params' => []];
        }

        $contentPath = trim($path, '/') ?: self::DEFAULT_CONTENT_PATH;
        $pagePath    = MARQUES_CONTENT_DIR . '/pages/' . $contentPath . '.md';
        if (file_exists($pagePath)) {
            return ['route' => ['method' => self::METHOD_GET, 'pattern' => $path, 'regex' => '#^' . preg_quote($path, '#') . '$#', 'handler' => '', 'options' => ['name' => 'content.fallback']], 'params' => []];
        }

        return $this->routeMatchCache[$key] = null;
    }

    /**
     * Executes the route handler.
     */
    private function executeHandler($handler, Request $request, array $params): mixed
    {
        if ($handler instanceof \Closure) {
            return $handler($request, $params);
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$controllerClass, $action] = explode('@', $handler, 2);

            if (!class_exists($controllerClass)) {
                throw new RuntimeException("Controller '{$controllerClass}' not found.", 500);
            }

            if (!$this->container) {
                throw new RuntimeException('DI container unavailable.', 500);
            }

            try {
                $controller = $this->container->get($controllerClass);
            } catch (Throwable $e) {
                throw new RuntimeException("Failed instantiating '{$controllerClass}': " . $e->getMessage(), 500, $e);
            }

            if (!method_exists($controller, $action)) {
                throw new RuntimeException("Action '{$action}' not found in '{$controllerClass}'.", 500);
            }
            return $controller->$action($request, $params);
        }

        if ($handler === '' || $handler === null) {
            $path = ltrim($request->getPath(), '/') ?: self::DEFAULT_CONTENT_PATH;

            if ($this->container && $this->container->has('PageManager')) {
                try {
                    $pageManager = $this->container->get('PageManager');
                } catch (\Exception $e) {
                }
            }
            return ['path' => $path, 'params' => $params];
        }

        throw new UnexpectedValueException('Invalid handler type: ' . gettype($handler), 500);
    }

    /**
     * Validates route parameters against a schema definition.
     */
    private function validateParameters(array $params, array $schema): bool
    {
        foreach ($schema as $key => $rules) {
            if (!isset($params[$key])) {
                if (!empty($rules['required'])) {
                    return false;
                }
                continue;
            }
            $value = $params[$key];

            switch ($rules['type'] ?? null) {
                case 'integer':
                    if (filter_var($value, FILTER_VALIDATE_INT) === false) { return false; }
                    $int = (int)$value;
                    if (isset($rules['min']) && $int < $rules['min']) { return false; }
                    if (isset($rules['max']) && $int > $rules['max']) { return false; }
                    break;

                case 'string':
                    if (!is_string($value)) { return false; }
                    if (isset($rules['pattern']) && !preg_match($rules['pattern'], $value)) { return false; }
                    $len = mb_strlen($value, 'UTF‑8');
                    if (isset($rules['min_length']) && $len < $rules['min_length']) { return false; }
                    if (isset($rules['max_length']) && $len > $rules['max_length']) { return false; }
                    break;

                case 'boolean':
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) { return false; }
                    break;

                case 'date':
                    $fmt  = $rules['format'] ?? 'Y-m-d';
                    $date = \DateTime::createFromFormat($fmt, $value);
                    if (!$date || $date->format($fmt) !== $value) { return false; }
                    break;

                case 'float':
                    if (filter_var($value, FILTER_VALIDATE_FLOAT) === false) { return false; }
                    break;

                case 'email':
                    if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) { return false; }
                    break;

                case 'url':
                    if (filter_var($value, FILTER_VALIDATE_URL) === false) { return false; }
                    break;
            }

            if (isset($rules['enum']) && !in_array($value, $rules['enum'], true)) { return false; }
            if (isset($rules['callback']) && is_callable($rules['callback']) && !$rules['callback']($value)) { return false; }
        }
        return true;
    }

    /**
     * Loads route definitions from the database.
     */
    private function loadRoutesFromConfig(): void
    {
        if (!$this->persistRoutes) {
            return;
        }

        try {
            $table       = $this->dbHandler->table(self::URL_MAPPING_TABLE);
            $urlMappings = $table->find();

            if ($urlMappings === []) {
                $this->addDefaultFallbackRoutes();
                return;
            }

            foreach ($urlMappings as $config) {
                if (!is_array($config) || empty($config['pattern'])) {
                    $this->logError('Invalid route entry: ' . json_encode($config));
                    continue;
                }

                $method  = strtoupper($config['method'] ?? self::METHOD_GET);
                $pattern = $config['pattern'];
                $handler = $config['handler'] ?? '';
                $options = $this->parseOptionsJson($config['options'] ?? '{}');
                $regex   = $config['regex'] ?? '';
                if ($regex === '') {
                    $regex = $this->compilePattern($pattern, $options);
                }

                $route = [
                    'method'  => $method,
                    'pattern' => $pattern,
                    'regex'   => $regex,
                    'handler' => $handler,
                    'options' => $options,
                ];

                $this->routes[] = $route;
                if (isset($options['name'])) {
                    $this->namedRoutes[$options['name']] = $route;
                }
            }

            $this->ensureEssentialRoutes();
        } catch (\Exception $e) {
            $this->logError('Failed loading routes: ' . $e->getMessage());
            $this->routes = $this->namedRoutes = [];
            $this->addDefaultFallbackRoutes();
        }
    }

    /**
     * Parses JSON options safely.
     */
    private function parseOptionsJson(string $json): array
    {
        $options = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError('JSON decode error: ' . json_last_error_msg());
            return [];
        }
        return $options;
    }

    /**
     * Ensures core fallback routes exist.
     */
    private function ensureEssentialRoutes(): void
    {
        $hasRoot     = false;
        $hasCatchAll = false;

        foreach ($this->routes as $route) {
            if ($route['method'] === self::METHOD_GET && $route['pattern'] === '/') {
                $hasRoot = true;
            }
            if ($route['method'] === self::METHOD_GET && str_contains($route['pattern'], '{path:')) {
                $hasCatchAll = true;
            }
        }

        if (!$hasRoot) {
            $this->addRoute(self::METHOD_GET, '/', '', ['name' => 'home.default']);
        }
        if (!$hasCatchAll) {
            $this->addRoute(self::METHOD_GET, '/{path:.+}', '', ['name' => 'page.any.fallback']);
        }
    }

    /**
     * Adds minimal fallback routes when DB‑routes are unavailable.
     */
    private function addDefaultFallbackRoutes(): void
    {
        $this->logWarning('Adding fallback routes');
        $this->addRoute(self::METHOD_GET, '/', '', ['name' => 'home.fallback']);
        $this->addRoute(self::METHOD_GET, '/{path:.+}', '', ['name' => 'page.any.fallback']);
    }

    /**
     * Writes an error to the application logger or error_log().
     */
    private function logError(string $message, array $context = []): void
    {
        if ($this->container && $this->container->has(Logger::class)) {
            try {
                $this->container->get(Logger::class)->error($message, $context);
                return;
            } catch (\Exception) {
            }
        }
        error_log(self::LOG_PREFIX['error'] . ': ' . $message);
    }

    /**
     * Writes a warning to the application logger or error_log().
     */
    private function logWarning(string $message, array $context = []): void
    {
        if ($this->container && $this->container->has(Logger::class)) {
            try {
                $this->container->get(Logger::class)->warning($message, $context);
                return;
            } catch (\Exception) {
            }
        }
        error_log(self::LOG_PREFIX['warning'] . ': ' . $message);
    }
}
