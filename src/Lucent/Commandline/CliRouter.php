<?php

namespace Lucent\Commandline;

use Lucent\Facades\FileSystem;
use Lucent\Router;

class CliRouter extends Router
{
    /**
     * Register a new route with optional controller and middleware
     */
    public function registerRoute(string $uri, string $type, string $method, ?string $controller = null, array $middleware = []): void
    {
        $this->routes[$type][$uri] = [
            "controller" => $controller,
            "method" => $method,
            "middleware" => array_merge($this->middleware, $middleware)
        ];
    }

    /**
     * Load routes from a file
     */
    public function loadRoutes(string $file, ?string $prefix = null): void
    {
        require_once FileSystem::rootPath().$file;
    }
}