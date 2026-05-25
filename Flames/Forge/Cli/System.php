<?php

declare(strict_types=1);

namespace Flames\Forge\Cli;

use Flames\Forge\Cli\Command\Cache;
use Flames\Forge\Cli\Command\Coroutine;
use Flames\Forge\Cli\Command\Db;
use Flames\Forge\Cli\Command\Inject;
use Flames\Forge\Cli\Command\Install;
use Flames\Forge\Cli\Command\Key\Generate as KeyGenerate;
use Flames\Forge\Cli\Command\Crypto\Key\Generate as CryptoKeyGenerate;
use Flames\Forge\Cli\Command\Build\Assets;
use Flames\Forge\Cli\Command\Build\App\StaticEx;
use Flames\Forge\Cli\Command\Build\App\Native;
use Flames\Forge\Cli\Command\Build\App\Mobile;
use Flames\Forge\Cli\Command\Container;
use Flames\Forge\Cli\Command\MicroserviceList;
use Flames\Forge\Cli\Command\Package;
use Flames\Forge\Cli\Command\Route;
use Flames\Forge\Cli\Command\Schedules\Install       as SchedulesInstall;
use Flames\Forge\Cli\Command\Schedules\Remove        as SchedulesRemove;
use Flames\Forge\Cli\Command\Schedules\Run           as SchedulesRun;
use Flames\Forge\Cli\Command\Schedules\ListSchedules as SchedulesList;
use Flames\Forge\Cli\Command\Schedules\Show          as SchedulesShow;
use Flames\Forge\Cli\Command\Schedules\Stop          as SchedulesStop;
use Flames\Forge\Cli\Command\Server;
use Flames\Forge\Cli\Command\Shell;
use Flames\Collection\Arr;
use Flames\Event;
use Flames\Router;

/**
 * @internal
 */
final class System
{
    /** @var array<string, class-string> */
    private const COMMANDS = [
        'install'            => Install::class,
        'inject'             => Inject::class,
        'key generate'       => KeyGenerate::class,
        'shell'              => Shell::class,
        'serve'              => Server::class,
        'surface build'      => Assets::class,
        'snapshot'           => StaticEx::class,
        'bundle'             => Native::class,
        'container'          => Container::class,
        'library'            => Package::class,
        'db'                 => Db::class,
        'schedule install'   => SchedulesInstall::class,
        'schedule remove'    => SchedulesRemove::class,
        'schedule run'       => SchedulesRun::class,
        'schedule list'      => SchedulesList::class,
        'schedule show'      => SchedulesShow::class,
        'schedule stop'      => SchedulesStop::class,
        'route server list'  => Route::class,
        'route client list'  => Route::class,
        'microservice list'  => MicroserviceList::class,
        'db wipe'            => Db::class,
        'db truncate'        => Db::class,
        'db migrate'         => Db::class,
        'cache purge'        => Cache::class,
        'cache purge kernel' => Cache::class,
        'cache purge all'    => Cache::class,
        'internal:coroutine' => Coroutine::class,
    ];

    /** Passthrough commands flush ob and skip the Flames header (hash map for O(1) lookup). */
    private const PASSTHROUGH = [
        'container' => true,
        'library'   => true,
        'db'        => true,
        'shell'     => true,
        'cache'     => true,
    ];

    // ── Help sections ─────────────────────────────────────────────────────────

    private const FRAMEWORK_HELP = [
        ['install',               'Install the project'],
        ['inject',                'Inject the global forge launcher'],
        ['key generate',          'Create or update the project unique key'],
        ['key generate --crypto', 'Create or update the cryptography key'],
        ['shell',                 'Open an interactive PHP REPL'],
    ];

    private const SCHEDULE_HELP = [
        ['schedule install',           'Register schedule runner in crontab'],
        ['schedule remove',            'Remove schedule runner from crontab'],
        ['schedule run',               'Run all due schedules'],
        ['schedule list',              'List all schedules defined in config.yml'],
        ['schedule show',              'Show currently running schedule processes'],
        ['schedule stop {name|pid}',   'Stop a running schedule by name or PID'],
    ];

    private const WEBSERVER_HELP = [
        ['serve',               'Run a development server (0.0.0.0:80)'],
        ['serve {host}:{port}', 'Run at a specific host and port'],
        ['serve -host={host}',  'Run at a specific host'],
        ['serve -port={port}',  'Run at a specific port'],
    ];

    private const SURFACE_HELP = [
        ['surface build', 'Build client-side assets'],
    ];

    private const SNAPSHOT_HELP = [
        ['snapshot',              'Build the app as static HTML pages'],
        ['snapshot --cloudflare', 'Build for Cloudflare Pages'],
    ];

    private const BUNDLE_HELP = [
        ['bundle',                       'Build app webview for Linux or Windows'],
        ['bundle --linux',               'Build for Linux'],
        ['bundle --windows',             'Build for Windows'],
        ['bundle --windows --installer', 'Build Windows installer'],
        ['bundle --android',             'Build Android APK'],
    ];

    private const CONTAINER_HELP = [
        ['container',                      'Show running container status'],
        ['container run',                  'Start containers in the background'],
        ['container run --foreground',     'Start containers in the foreground'],
        ['container build',                'Build / rebuild container images'],
        ['container stop',                 'Stop and remove containers'],
        ['container compose {args}',       'Run any docker compose command'],
        ['container {service}',            'Open a bash shell in a container'],
        ['container {service} bash|sh',    'Open bash or sh in a container'],
        ['container {service} {command}',  'Run "php forge {command}" inside a container'],
        ['container {service} php {args}', 'Run an explicit php command inside a container'],
    ];

    private const DATABASE_HELP = [
        ['db',                           'Open a shell for the default database'],
        ['db {connection}',              'Open a shell for a named connection'],
        ['db sql {sql}',                 'Run SQL on the default database'],
        ['db sql {connection} {sql}',    'Run SQL on a named connection'],
        ['db model list',                'List all models across all connections'],
        ['db model list {connection}',   'List models for a specific connection'],
        ['db migrate',                   'Force-migrate all models'],
        ['db migrate {connection}',      'Force-migrate models for a specific connection'],
        ['db truncate',                  'Empty all tables, reset auto-increment'],
        ['db truncate {connection}',     'Truncate tables for a specific connection'],
        ['db wipe',                      'Drop all tables in the default database'],
        ['db wipe {connection}',         'Drop all tables in a specific connection'],
    ];

    private const CACHE_HELP = [
        ['cache purge',        'Clear everything in app cache'],
        ['cache purge kernel', 'Clear kernel cache'],
        ['cache purge all',    'Clear everything'],
    ];

    private const PACKAGE_HELP = [
        ['library',                     'List available composer commands'],
        ['library require {package}',   'Add a new package to the project'],
        ['library remove {package}',    'Remove a package from the project'],
        ['library update',              'Update all project packages'],
        ['library update {package}',    'Update a specific package'],
        ['library show',                'Show installed packages'],
        ['library audit',               'Check for security vulnerabilities'],
        ['library validate',            'Validate composer.json'],
        ['library {command} {args}',    'Run any composer command'],
    ];

    private const ROUTE_HELP = [
        ['route server list',                  'List all server-side routes'],
        ['route client list',                  'List all client-side routes'],
        ['route server list {microservice}',   'List server-side routes for a microservice'],
        ['route client list {microservice}',   'List client-side routes for a microservice'],
    ];

    private const MICROSERVICE_HELP = [
        ['microservice list', 'List all configured microservices'],
    ];

    private Arr $data;

    public function __construct(?Arr $data = null, private bool $debug = true)
    {
        $this->data = $data ?? Data::getData();
    }

    public function run(): bool
    {
        $command = (string)($this->data->command ?? '');
        $args    = (array)$this->data->argument;
        $options = (array)$this->data->option;

        // ── Multi-word command resolution ─────────────────────────────────────
        // Try longest match first: command + 2 args, then + 1 arg, then alone.
        $resolved = null;
        $consumed = 0;

        foreach ([3, 2, 1, 0] as $n) {
            if ($n > 0 && !isset($args[$n - 1])) {
                continue;
            }
            $candidate = $n > 0
                ? $command . ' ' . implode(' ', array_slice($args, 0, $n))
                : $command;

            if (isset(self::COMMANDS[$candidate])) {
                $resolved = $candidate;
                $consumed = $n;
                break;
            }
        }

        if ($resolved === null) {
            if ($command !== '' && Container::serviceExists($command)) {
                return $this->runContainerService($command);
            }
            $this->dispatchHelper();
            return false;
        }

        $this->data->command  = $resolved;
        $this->data->argument = Arr(array_slice($args, $consumed));

        if ($resolved === 'internal:coroutine') {
            $this->debug = false;
        }

        // ── Special routing ───────────────────────────────────────────────────

        if ($resolved === 'bundle' && in_array('android', $options, true)) {
            return $this->dispatchSpecial(new Mobile($this->data), 'bundle --android');
        }

        if ($resolved === 'key generate' && in_array('crypto', $options, true)) {
            return $this->dispatchSpecial(new CryptoKeyGenerate($this->data), 'key generate --crypto');
        }

        // ── Standard dispatch ─────────────────────────────────────────────────
        $isPassthrough = isset(self::PASSTHROUGH[$command]);

        if ($this->debug && !$isPassthrough) {
            Output::logo();
            Output::blank();
            echo Output::CYAN . Output::BOLD
                . '  Running ' . Output::RESET
                . Output::GREEN . Output::BOLD . $resolved . Output::RESET
                . "\n\n";
        }

        $class    = self::COMMANDS[$resolved];
        $instance = new $class($this->data);
        $return   = $instance->run($this->debug);

        if ($this->debug && !$isPassthrough) {
            Output::blank();
        }

        return $return;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function dispatchSpecial(object $instance, string $label): bool
    {
        if ($this->debug) {
            Output::logo();
            Output::blank();
            echo Output::CYAN . Output::BOLD . '  Running ' . Output::RESET
                . Output::GREEN . Output::BOLD . $label . Output::RESET . "\n\n";
        }
        $return = $instance->run($this->debug);
        if ($this->debug) {
            Output::blank();
        }
        return $return;
    }

    private function runContainerService(string $service): bool
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $args     = array_slice($_SERVER['argv'], 2);
        $instance = new Container($this->data);
        return $instance->runExec($service, array_values($args));
    }

    private function dispatchHelper(): void
    {
        Output::logo();
        Output::blank();

        if (file_exists('/.dockerenv')) {
            $hostname = trim((string)shell_exec('hostname 2>/dev/null')) ?: 'container';
            echo '  ' . Output::GRAY . 'Running in ' . Output::RESET
                . Output::CYAN . Output::BOLD . 'container' . Output::RESET
                . Output::GRAY . '  (' . $hostname . ')' . Output::RESET . "\n";
        } else {
            echo '  ' . Output::GRAY . 'Running in ' . Output::RESET
                . Output::GREEN . Output::BOLD . 'native' . Output::RESET . "\n";
        }

        Output::blank();
        echo '  ' . Output::WHITE . Output::BOLD . 'USAGE' . Output::RESET . "\n";
        echo '    ' . Output::GRAY . 'forge ' . Output::RESET
            . Output::CYAN . '<command>' . Output::RESET
            . Output::GRAY . ' [--native] [--container] [options]' . Output::RESET . "\n";

        Output::blank();
        echo '  ' . Output::WHITE . Output::BOLD . 'GLOBAL FLAGS' . Output::RESET . "\n";
        Output::command('--native',    'Force execution on the local machine (skip Docker routing)');
        Output::command('--container', 'Force execution inside the Docker container');

        $cliRoutes = $this->getApplicationCliRoutes();
        if (!empty($cliRoutes)) {
            Output::section('Application Commands');
            foreach ($cliRoutes as $route) {
                Output::command($route, 'CLI route');
            }
        }

        $sections = [
            'Framework Commands'          => self::FRAMEWORK_HELP,
            'Schedules'                   => self::SCHEDULE_HELP,
            'Database'                    => self::DATABASE_HELP,
            'Routes'                      => self::ROUTE_HELP,
            'Microservices'               => self::MICROSERVICE_HELP,
            'Surface (PHP Frontend WASM)' => self::SURFACE_HELP,
            'Snapshot (Build Static App)' => self::SNAPSHOT_HELP,
            'Bundle (Build Native App)'   => self::BUNDLE_HELP,
            'Webserver (Development)'     => self::WEBSERVER_HELP,
            'Container (Docker)'          => self::CONTAINER_HELP,
            'Libraries (Composer)'        => self::PACKAGE_HELP,
            'Cache'                       => self::CACHE_HELP,
        ];

        foreach ($sections as $title => $items) {
            Output::section($title);
            foreach ($items as [$cmd, $desc]) {
                Output::command($cmd, $desc);
            }
        }

        Output::blank();
    }

    private function getApplicationCliRoutes(): array
    {
        try {
            $router = Event::dispatch('Route', 'onRoute', new Router());
        } catch (\Throwable) {
            return [];
        }

        if (!($router instanceof Router)) {
            return [];
        }

        $names = [];
        foreach ($router->getMetadata() as $route) {
            if ($route->methods === 'CLI') {
                $names[] = $route->routeFormatted;
            }
        }

        return $names;
    }
}
