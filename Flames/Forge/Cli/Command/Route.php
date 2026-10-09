<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

use Flames\Forge\Cli\Output;
use Flames\Framework\Event;
use Flames\Router;
use Flames\Interfaces\Event\Route as RouteContract;

/**
 * Lists server-side or client-side routes.
 *
 * Usage:
 *   forge route server list                   — all HTTP routes
 *   forge route server list {microservice}    — filter by microservice
 *   forge route client list                   — all client-side routes
 *   forge route client list {microservice}    — filter by microservice
 *
 * @internal
 */
final readonly class Route
{
    private string $side;
    private ?string $microservice;

    public function __construct(mixed $data)
    {
        $command           = (string)($data->command ?? '');
        $this->side        = str_contains($command, 'server') ? 'server' : 'client';
        $args              = (array)($data->argument ?? []);
        $this->microservice = !empty($args[0]) ? (string)$args[0] : null;
    }

    public function run(bool $debug = false): bool
    {
        if ($this->loadRoutes() === false) {
            Output::error('Could not load routes.');
            return false;
        }

        $serverMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
        $filtered      = [];

        foreach (Router::getMetadata() as $route) {
            $method = strtoupper((string)($route->methods ?? ''));

            if ($this->side === 'server' && !in_array($method, $serverMethods, true)) {
                continue;
            }

            if ($this->side === 'client' && in_array($method, $serverMethods, true)) {
                continue;
            }

            if ($this->microservice !== null) {
                $controller = (string)($route->controller ?? '');
                if (!str_contains($controller, '\\Microservice\\' . $this->microservice . '\\')) {
                    continue;
                }
            }

            $filtered[] = $route;
        }

        if (empty($filtered)) {
            $label = $this->side === 'server' ? 'server-side' : 'client-side';
            $msg   = $this->microservice
                ? "No {$label} routes found for microservice '{$this->microservice}'."
                : "No {$label} routes found.";
            Output::warning($msg);
            return true;
        }

        $title = $this->side === 'server' ? 'SERVER ROUTES' : 'CLIENT ROUTES';
        if ($this->microservice !== null) {
            $title .= ' — ' . strtoupper($this->microservice);
        }

        $methodW = 8;
        $pathW   = 40;

        echo "\n" . Output::YELLOW . Output::BOLD . "  {$title}" . Output::RESET . "\n\n";
        echo '  '
            . Output::YELLOW . Output::BOLD . str_pad('METHOD', $methodW)  . Output::RESET . '  '
            . Output::YELLOW . Output::BOLD . str_pad('PATH', $pathW)       . Output::RESET . '  '
            . Output::YELLOW . Output::BOLD . 'CONTROLLER'                  . Output::RESET . "\n";
        echo '  '
            . Output::GRAY . str_repeat('─', $methodW) . Output::RESET . '  '
            . Output::GRAY . str_repeat('─', $pathW)   . Output::RESET . '  '
            . Output::GRAY . str_repeat('─', 50)        . Output::RESET . "\n";

        foreach ($filtered as $route) {
            $method     = strtoupper((string)($route->methods ?? ''));
            $path       = (string)($route->routeFormatted ?? '');
            $controller = (string)($route->controller     ?? '');

            $methodColor = match ($method) {
                'GET'          => Output::GREEN,
                'POST'         => Output::CYAN,
                'PUT', 'PATCH' => Output::YELLOW,
                'DELETE'       => Output::RED,
                default        => Output::WHITE,
            };

            echo '  '
                . $methodColor . str_pad($method, $methodW) . Output::RESET . '  '
                . Output::WHITE . str_pad($path, $pathW)    . Output::RESET . '  '
                . Output::GRAY  . $controller               . Output::RESET . "\n";
        }

        echo "\n";
        return true;
    }

    private function loadRoutes(): bool
    {
        Router::clear();

        try {
            if ($this->side === 'server') {
                Event::dispatch(RouteContract::class, 'Route', 'onRoute');
                $this->loadMicroserviceServerRoutes();
            } else {
                $this->loadClientEventFiles();
            }
        } catch (\Throwable) {
            return false;
        }

        return Router::hasRoutes();
    }

    private function loadClientEventFiles(): void
    {
        $this->callClientEventFile(
            ROOT_PATH . 'App/Client/Event/Route.php',
            '\\App\\Client\\Event\\Route'
        );

        $microDir = ROOT_PATH . 'Microservice/';
        if (!is_dir($microDir)) {
            return;
        }

        foreach (glob($microDir . '*/Client/Event/Route.php') ?: [] as $file) {
            $rel   = str_replace(['\\', ROOT_PATH], ['/', ''], $file);
            $class = '\\' . str_replace('/', '\\', rtrim($rel, '.php'));
            $this->callClientEventFile($file, $class);
        }
    }

    private function callClientEventFile(string $file, string $class): void
    {
        if (!file_exists($file)) {
            return;
        }
        require_once $file;
        if (!class_exists($class, false)) {
            return;
        }
        new $class()->onRoute();
    }

    private function loadMicroserviceServerRoutes(): void
    {
        $microDir = ROOT_PATH . 'Microservice/';
        if (!is_dir($microDir)) {
            return;
        }

        foreach (glob($microDir . '*/Server/Event/Route.php') ?: [] as $file) {
            $rel   = str_replace(['\\', ROOT_PATH], ['/', ''], $file);
            $class = '\\' . str_replace('/', '\\', rtrim($rel, '.php'));
            if (!file_exists($file)) {
                continue;
            }
            require_once $file;
            if (!class_exists($class, false)) {
                continue;
            }
            new $class()->onRoute();
        }
    }
}
