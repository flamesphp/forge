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
use Flames\Surface\Build\Assets;
use Flames\Forge\Cli\Command\Build\App\StaticEx;
use Flames\Forge\Cli\Command\Build\App\Native;
use Flames\Forge\Cli\Command\Build\App\Mobile;
use Flames\Forge\Cli\Command\Container;
use Flames\Forge\Cli\Command\MicroserviceList;
use Flames\Ready\Kernel\Boot as ReadyBoot;
use Flames\Cron\Kernel\Boot as CronBoot;
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
use Flames\Forge\Cli\Command\Upgrade;
use Flames\Collection\Arr;
use Flames\Framework\Event;
use Flames\Router;
use Flames\Interfaces\Event\Route as RouteContract;

/**
 * @internal
 */
final class System
{
    /** @var array<string, class-string> */
    private const array COMMANDS = [
        'install'            => Install::class,
        'inject'             => Inject::class,
        'key generate'       => KeyGenerate::class,
        'shell'              => Shell::class,
        'serve'              => Server::class,
        'surface build'      => Assets::class,
        'snapshot'           => StaticEx::class,
        'bundle'             => Native::class,
        'container'          => Container::class,
        'composer'           => Package::class,
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
        'code upgrade'       => Upgrade::class,
        'internal:coroutine' => Coroutine::class,
    ];

    /** Passthrough commands flush ob and skip the Flames header (hash map for O(1) lookup). */
    private const array PASSTHROUGH = [
        'container' => true,
        'composer'  => true,
        'db'        => true,
        'shell'     => true,
        'cache'     => true,
        'code'      => true,
    ];

    // ── Help sections ─────────────────────────────────────────────────────────

    private const array FRAMEWORK_HELP = [
        ['install',               'Install the project'],
        ['inject',                'Inject the global forge launcher'],
        ['key generate',          'Create or update the project unique key'],
        ['key generate --crypto', 'Create or update the cryptography key'],
        ['shell',                 'Open an interactive PHP REPL'],
    ];

    private const array CODE_HELP = [
        ['code upgrade {php}',                      'Upgrade App/ code to a PHP version (UP_TO_PHP_X)'],
        ['code upgrade {php} {path}',               'Upgrade a specific path (e.g. vendor/flamesphp/composer)'],
        ['code upgrade {php} [{path}] --preview',   'Preview code upgrades without saving'],
        ['code upgrade {php} [{path}] --clear-cache', 'Clear upgrade cache before running'],
    ];

    private const array SCHEDULE_HELP = [
        ['schedule install',           'Register schedule runner in crontab'],
        ['schedule remove',            'Remove schedule runner from crontab'],
        ['schedule run',               'Run all due schedules'],
        ['schedule list',              'List all schedules defined in config.yml'],
        ['schedule show',              'Show currently running schedule processes'],
        ['schedule stop {name|pid}',   'Stop a running schedule by name or PID'],
    ];

    private const array WEBSERVER_HELP = [
        ['serve',               'Run a development server (0.0.0.0:80)'],
        ['serve {host}:{port}', 'Run at a specific host and port'],
        ['serve -host={host}',  'Run at a specific host'],
        ['serve -port={port}',  'Run at a specific port'],
    ];

    private const array SURFACE_HELP = [
        ['surface build', 'Build client-side assets'],
    ];

    private const array SNAPSHOT_HELP = [
        ['snapshot',              'Build the app as static HTML pages'],
        ['snapshot --cloudflare', 'Build for Cloudflare Pages'],
    ];

    private const array BUNDLE_HELP = [
        ['bundle',                       'Build app webview for Linux or Windows'],
        ['bundle --linux',               'Build for Linux'],
        ['bundle --windows',             'Build for Windows'],
        ['bundle --windows --installer', 'Build Windows installer'],
        ['bundle --android',             'Build Android APK'],
    ];

    private const array CONTAINER_HELP = [
        ['container',                                    'Show running container status'],
        ['container run',                                'Start containers in the background'],
        ['container run --foreground',                   'Start containers in the foreground'],
        ['container reload',                             'Rebuild and restart all containers'],
        ['container build',                              'Build / rebuild container images'],
        ['container stop',                               'Stop and remove containers'],
        ['container compose {args}',                     'Run any docker compose command'],
        ['container app set apache_modphp',              'Apache with PHP mod'],
        ['container app set apache_phpfpm',              'Apache with PHP-FPM'],
        ['container app set nginx_phpfpm',               'NGINX with PHP-FPM'],
        ['container app set nginx_phpfpm_flames',        'NGINX with PHP-FPM and Flames C extensions (recommended for develop)'],
        ['container app set nginx_flames',               'NGINX with Flames Ready & C extensions (recommended for production)'],
        ['container db set {database}',                  'Set the default database'],
        ['container db add mariadb',                     'Add MariaDB service'],
        ['container db add mysql',                       'Add MySQL service'],
        ['container db add postgresql',                  'Add PostgreSQL service'],
        ['container db add mongodb',                     'Add MongoDB service'],
        ['container db remove {database}',               'Remove a database service (fallback to sqlite if none left)'],
        ['container search set {engine}',                'Set the default search engine'],
        ['container search add opensearch',              'Add OpenSearch service'],
        ['container search add elasticsearch',           'Add Elasticsearch service'],
        ['container search add meilisearch',             'Add Meilisearch service'],
        ['container search remove {engine}',             'Remove a search engine service'],
        ['container memory set {engine}',               'Set the default memory engine'],
        ['container memory add kvrocks',                'Add Kvrocks service'],
        ['container memory add keydb',                  'Add KeyDB service'],
        ['container memory add redis',                  'Add Redis service'],
        ['container memory add dragonfly',              'Add Dragonfly service'],
        ['container memory add valkey',                 'Add Valkey service'],
        ['container memory remove {engine}',            'Remove a memory engine service'],
        ['container service add stealth',               'Add Stealth (chromium) browser service'],
        ['container service remove stealth',            'Remove Stealth (chromium) browser service'],
        ['container {service}',                          'Open a bash shell in a container'],
        ['container {service} bash|sh',                  'Open bash or sh in a container'],
        ['container {service} {command}',                'Run "php forge {command}" inside a container'],
        ['container {service} php {args}',               'Run an explicit php command inside a container'],
    ];

    private const array DATABASE_HELP = [
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

    private const array CACHE_HELP = [
        ['cache purge',        'Clear everything in app cache'],
        ['cache purge kernel', 'Clear kernel cache'],
        ['cache purge all',    'Clear everything'],
    ];

    private const array PACKAGE_HELP = [
        ['composer',                     'List available composer commands'],
        ['composer require {package}',   'Add a new package to the project'],
        ['composer remove {package}',    'Remove a package from the project'],
        ['composer update',              'Update all project packages'],
        ['composer update {package}',    'Update a specific package'],
        ['composer show',                'Show installed packages'],
        ['composer audit',               'Check for security vulnerabilities'],
        ['composer validate',            'Validate composer.json'],
        ['composer {command} {args}',    'Run any composer command'],
    ];

    private const array ROUTE_HELP = [
        ['route server list',                  'List all server-side routes'],
        ['route client list',                  'List all client-side routes'],
        ['route server list {microservice}',   'List server-side routes for a microservice'],
        ['route client list {microservice}',   'List client-side routes for a microservice'],
    ];

    private const array MICROSERVICE_HELP = [
        ['microservice list', 'List all configured microservices'],
    ];

    private readonly Arr $data;

    public function __construct(?Arr $data = null, private bool $debug = true)
    {
        $this->data = $data ?? Data::getData();
    }

    public function run(): bool
    {
        $command = (string)($this->data->command ?? '');
        $args    = (array)$this->data->argument;
        $options = (array)$this->data->option;

        if ($command === '--ready') {
            ReadyBoot::boot();
            return true;
        }

        if ($command === '--cron') {
            CronBoot::boot();
            return true;
        }

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

        $this->printEnvironmentInfo();

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
            'Code'                        => self::CODE_HELP,
            'Schedules'                   => self::SCHEDULE_HELP,
            'Database'                    => self::DATABASE_HELP,
            'Routes'                      => self::ROUTE_HELP,
            'Microservices'               => self::MICROSERVICE_HELP,
            'Surface (PHP Frontend WASM)' => self::SURFACE_HELP,
            'Snapshot (Build Static App)' => self::SNAPSHOT_HELP,
            'Bundle (Build Native App)'   => self::BUNDLE_HELP,
            'Webserver (Development)'     => self::WEBSERVER_HELP,
            'Container (Docker)'          => self::CONTAINER_HELP,
            'Composer'                    => self::PACKAGE_HELP,
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
            Router::clear();
            Event::dispatch(RouteContract::class, 'Route', 'onRoute');
        } catch (\Throwable) {
            return [];
        }

        if (Router::hasRoutes() === false) {
            return [];
        }

        $names = [];
        foreach (Router::getMetadata() as $route) {
            if ($route->methods === 'CLI') {
                $names[] = $route->routeFormatted;
            }
        }

        return $names;
    }

    private function readEnvValues(): array
    {
        $envPath = ROOT_PATH . '.env';
        if (!file_exists($envPath)) {
            return [];
        }

        $result = [];
        foreach (explode("\n", (string)file_get_contents($envPath)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $result[trim($parts[0])] = trim($parts[1], '"\'');
            }
        }

        return $result;
    }

    private function printEnvironmentInfo(): void
    {
        $env = $this->readEnvValues();
        $composeEntries = [];
        if (!empty($env['COMPOSE_FILE'])) {
            $composeEntries = array_filter(array_map(trim(...), explode(':', $env['COMPOSE_FILE'])));
        }

        $appYmlMap = [
            'vendor/flamesphp/docker/resources/app/nginx_flames/nginx_flames.yml'                           => 'nginx_flames',
            'vendor/flamesphp/docker/resources/app/nginx_phpfpm_flames/nginx_phpfpm_flames.yml'             => 'nginx_phpfpm_flames',
            'vendor/flamesphp/docker/resources/app/nginx_phpfpm/nginx_phpfpm.yml'                           => 'nginx_phpfpm',
            'vendor/flamesphp/docker/resources/app/apache_phpfpm/apache_phpfpm.yml'                         => 'apache_phpfpm',
            'vendor/flamesphp/docker/resources/app/apache_modphp/apache_modphp.yml'                         => 'apache_modphp',
        ];
        $dbYmlMap = [
            'vendor/flamesphp/docker/resources/database/mariadb/mariadb.yml'           => 'mariadb',
            'vendor/flamesphp/docker/resources/database/mysql/mysql.yml'               => 'mysql',
            'vendor/flamesphp/docker/resources/database/postgresql/postgresql.yml'     => 'postgresql',
            'vendor/flamesphp/docker/resources/database/mongodb/mongodb.yml'           => 'mongodb',
        ];
        $memYmlMap = [
            'vendor/flamesphp/docker/resources/memory/kvrocks/kvrocks.yml'             => 'kvrocks',
            'vendor/flamesphp/docker/resources/memory/keydb/keydb.yml'                 => 'keydb',
            'vendor/flamesphp/docker/resources/memory/redis/redis.yml'                 => 'redis',
            'vendor/flamesphp/docker/resources/memory/dragonfly/dragonfly.yml'         => 'dragonfly',
            'vendor/flamesphp/docker/resources/memory/valkey/valkey.yml'               => 'valkey',
        ];
        $searchYmlMap = [
            'vendor/flamesphp/docker/resources/search/opensearch/opensearch.yml'       => 'opensearch',
            'vendor/flamesphp/docker/resources/search/elasticsearch/elasticsearch.yml' => 'elasticsearch',
            'vendor/flamesphp/docker/resources/search/meilisearch/meilisearch.yml'     => 'meilisearch',
        ];
        $serviceYmlMap = [
            'vendor/flamesphp/docker/resources/service/stealth/stealth.yml' => 'stealth',
        ];

        $activeApp      = null;
        $activeDbs      = [];
        $activeMem      = [];
        $activeSearch   = [];
        $activeServices = [];

        foreach ($composeEntries as $entry) {
            $normalized = ltrim(str_replace('\\', '/', $entry), './');
            foreach ($appYmlMap as $yml => $name) {
                if ($normalized === $yml) {
                    $activeApp = $name;
                }
            }
            foreach ($dbYmlMap as $yml => $name) {
                if ($normalized === $yml) {
                    $activeDbs[] = $name;
                }
            }
            foreach ($memYmlMap as $yml => $name) {
                if ($normalized === $yml) {
                    $activeMem[] = $name;
                }
            }
            foreach ($searchYmlMap as $yml => $name) {
                if ($normalized === $yml) {
                    $activeSearch[] = $name;
                }
            }
            foreach ($serviceYmlMap as $yml => $name) {
                if ($normalized === $yml) {
                    $activeServices[] = $name;
                }
            }
        }

        $dbDefault     = $env['DATABASE_DEFAULT']        ?? 'sqlite';
        $memDefault    = $env['DATABASE_MEMORY_DEFAULT'] ?? 'file';
        $searchDefault = $env['DATABASE_SEARCH_DEFAULT'] ?? '';

        $labelWidth = 12;
        $indent     = '  ';

        echo "\n";

        if ($activeApp !== null) {
            echo $indent . Output::GRAY . str_pad('App', $labelWidth) . Output::RESET
                . Output::WHITE . Output::BOLD . $activeApp . Output::RESET . "\n";
        }

        $renderList = function (string $label, array $items, string $default) use ($indent, $labelWidth): void {
            if (empty($items)) {
                return;
            }
            $parts = [];
            foreach ($items as $item) {
                if ($item === $default) {
                    $parts[] = Output::CYAN . Output::BOLD . $item . Output::RESET
                        . Output::GRAY . ' (default)' . Output::RESET;
                } else {
                    $parts[] = Output::WHITE . $item . Output::RESET;
                }
            }
            echo $indent . Output::GRAY . str_pad($label, $labelWidth) . Output::RESET
                . implode(Output::GRAY . '  ·  ' . Output::RESET, $parts) . "\n";
        };

        if (!empty($activeDbs)) {
            $renderList('Database', $activeDbs, $dbDefault);
        } else {
            echo $indent . Output::GRAY . str_pad('Database', $labelWidth) . Output::RESET
                . Output::GRAY . 'sqlite' . Output::RESET
                . Output::GRAY . ' (default)' . Output::RESET . "\n";
        }

        if (!empty($activeMem)) {
            $renderList('Memory', $activeMem, $memDefault);
        }

        if (!empty($activeSearch)) {
            $renderList('Search', $activeSearch, $searchDefault);
        }

        if (!empty($activeServices)) {
            echo $indent . Output::GRAY . str_pad('Services', $labelWidth) . Output::RESET
                . implode(Output::GRAY . '  ·  ' . Output::RESET, array_map(
                    fn($item) => Output::WHITE . $item . Output::RESET,
                    $activeServices
                )) . "\n";
        }
    }
}
