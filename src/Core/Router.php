<?php

declare(strict_types=1);

namespace AlphaForge\Core;

/**
 * HTTP router with path-parameter extraction, middleware pipelines, and
 * route groups.
 *
 * Routes are matched in registration order. The first match wins. Path
 * parameters use curly-brace syntax, e.g. /api/v1/assets/{symbol}.
 *
 * Middleware classes must implement the `handle(Request $req, callable $next): void`
 * interface convention. They are executed in the order they were registered
 * (outermost first).
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, params: list<string>, controller: string, action: string, middleware: list<string>}> */
    private array $routes = [];

    /** @var list<string> Current middleware stack during group() calls */
    private array $groupMiddleware = [];

    /** Current path prefix during group() calls */
    private string $groupPrefix = '';

    /**
     * Register a GET route.
     *
     * @param string        $path        URI path, e.g. '/api/v1/users/{id}'
     * @param string        $controller  Fully-qualified controller class name
     * @param string        $method      Public method name on the controller
     * @param list<string>  $middleware  Middleware class names to apply
     */
    public function get(string $path, string $controller, string $method, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $controller, $method, $middleware);
    }

    /**
     * Register a POST route.
     *
     * @param string        $path
     * @param string        $controller
     * @param string        $method
     * @param list<string>  $middleware
     */
    public function post(string $path, string $controller, string $method, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $controller, $method, $middleware);
    }

    /**
     * Register a PUT route.
     *
     * @param string        $path
     * @param string        $controller
     * @param string        $method
     * @param list<string>  $middleware
     */
    public function put(string $path, string $controller, string $method, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $controller, $method, $middleware);
    }

    /**
     * Register a PATCH route.
     *
     * @param string        $path
     * @param string        $controller
     * @param string        $method
     * @param list<string>  $middleware
     */
    public function patch(string $path, string $controller, string $method, array $middleware = []): void
    {
        $this->addRoute('PATCH', $path, $controller, $method, $middleware);
    }

    /**
     * Register a DELETE route.
     *
     * @param string        $path
     * @param string        $controller
     * @param string        $method
     * @param list<string>  $middleware
     */
    public function delete(string $path, string $controller, string $method, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $controller, $method, $middleware);
    }

    /**
     * Create a route group with a shared prefix and/or shared middleware.
     *
     * Groups may be nested; each nesting level appends to the current prefix
     * and middleware stack.
     *
     * @param string        $prefix     URI prefix applied to all routes inside the callback
     * @param callable      $callback   Receives $this (the Router) to register child routes
     * @param list<string>  $middleware Middleware appended to the active stack
     */
    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $previousPrefix     = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix     = $previousPrefix . $prefix;
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix     = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /**
     * Dispatch the current request to the matching route.
     *
     * Iterates registered routes, matches by HTTP method and path, extracts
     * path parameters, builds the middleware pipeline, and invokes the
     * controller action.
     *
     * @param Request $request The current HTTP request
     * @return never Execution ends inside the controller/response
     */
    public function dispatch(Request $request): never
    {
        $method = $request->method();
        $path   = $request->path();

        // Collect all routes that match the path (regardless of HTTP method)
        // so we can emit a proper 405 when appropriate.
        $pathMatches = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            // Extract named path parameters.
            $params = [];
            foreach ($route['params'] as $paramName) {
                if (isset($matches[$paramName])) {
                    $params[$paramName] = urldecode($matches[$paramName]);
                }
            }

            $pathMatches[] = ['route' => $route, 'params' => $params];

            if ($route['method'] === $method) {
                // Found matching route — set path params and run middleware pipeline.
                $request->setPathParams($params);

                $this->runPipeline($route['middleware'], $request, function () use ($route, $request): void {
                    $controllerClass = $route['controller'];

                    if (!class_exists($controllerClass)) {
                        throw new \RuntimeException("Controller class '{$controllerClass}' not found.");
                    }

                    $controller = new $controllerClass();
                    $action     = $route['action'];

                    if (!method_exists($controller, $action)) {
                        throw new \RuntimeException(
                            "Method '{$action}' does not exist on '{$controllerClass}'."
                        );
                    }

                    $controller->{$action}($request);
                });

                // Controller is expected to call Response::* which exits.
                // This line is reached only if a controller returns without responding.
                Response::serverError('Controller did not send a response.');
            }
        }

        if (!empty($pathMatches)) {
            // Path matched but not with this HTTP method.
            $allowed = array_map(fn(array $m) => $m['route']['method'], $pathMatches);
            Response::methodNotAllowed($allowed);
        }

        Response::notFound("No route found for {$method} {$path}");
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * @param list<string> $middleware
     */
    private function addRoute(
        string $httpMethod,
        string $path,
        string $controller,
        string $action,
        array  $middleware,
    ): void {
        $fullPath   = $this->groupPrefix . $path;
        $allMiddleware = array_merge($this->groupMiddleware, $middleware);

        [$regex, $params] = $this->compilePath($fullPath);

        $this->routes[] = [
            'method'     => strtoupper($httpMethod),
            'pattern'    => $fullPath,
            'regex'      => $regex,
            'params'     => $params,
            'controller' => $controller,
            'action'     => $action,
            'middleware' => $allMiddleware,
        ];
    }

    /**
     * Convert a path template like /api/v1/assets/{symbol} into a PCRE regex
     * and return the list of parameter names.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function compilePath(string $path): array
    {
        $params = [];

        // Split path on {param} placeholders first, then quote the literal segments
        // and re-join with named capture groups. This avoids the ordering problem
        // of calling preg_quote() before the placeholder pattern can be matched.
        $parts  = preg_split('/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/', $path, flags: PREG_SPLIT_DELIM_CAPTURE);
        $parts  = $parts ?: [$path];

        $regex = '';

        foreach ($parts as $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $part, $m)) {
                // This segment is a named path parameter placeholder.
                $params[] = $m[1];
                $regex   .= '(?P<' . $m[1] . '>[^/]+)';
            } else {
                // Literal path segment — escape for use inside a regex.
                $regex .= preg_quote($part, '#');
            }
        }

        return ['#^' . $regex . '$#', $params];
    }

    /**
     * Build and execute a middleware pipeline as a recursive chain of closures.
     *
     * @param list<string> $middlewareClasses Ordered list of middleware FQCNs
     * @param Request      $request
     * @param callable     $finalHandler      The controller invocation
     */
    private function runPipeline(array $middlewareClasses, Request $request, callable $finalHandler): void
    {
        $pipeline = $finalHandler;

        // Wrap in reverse order so the first middleware in the list is outermost.
        foreach (array_reverse($middlewareClasses) as $middlewareClass) {
            $next     = $pipeline;
            $pipeline = static function () use ($middlewareClass, $request, $next): void {
                if (!class_exists($middlewareClass)) {
                    throw new \RuntimeException("Middleware class '{$middlewareClass}' not found.");
                }

                $middleware = new $middlewareClass();
                $middleware->handle($request, $next);
            };
        }

        $pipeline();
    }
}
