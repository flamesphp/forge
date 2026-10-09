<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

use Flames\Forge\Cli\Output;

/**
 * @internal
 */
final class Container
{
    // CLICOLOR_FORCE=1 + TERM tell docker compose to emit ANSI colours even
    // when it can't detect a TTY itself (e.g. when called via PHP passthru).
    private const string COMPOSE = 'CLICOLOR_FORCE=1 TERM=xterm-256color docker compose';

    /** @var array<string, bool>|null Cached service list (flipped for O(1) lookup). */
    private static ?array $serviceCache = null;

    /** Cached result of the docker binary check. */
    private static ?bool $dockerInstalled = null;

    private const array DATABASES = [
        'mariadb'     => 'vendor/flamesphp/docker/resources/database/mariadb/mariadb.yml',
        'mysql'       => 'vendor/flamesphp/docker/resources/database/mysql/mysql.yml',
        'postgresql'  => 'vendor/flamesphp/docker/resources/database/postgresql/postgresql.yml',
        'mongodb'     => 'vendor/flamesphp/docker/resources/database/mongodb/mongodb.yml',
    ];

    private const array MEMORY_ENGINES = [
        'kvrocks'   => 'vendor/flamesphp/docker/resources/memory/kvrocks/kvrocks.yml',
        'keydb'     => 'vendor/flamesphp/docker/resources/memory/keydb/keydb.yml',
        'redis'     => 'vendor/flamesphp/docker/resources/memory/redis/redis.yml',
        'dragonfly' => 'vendor/flamesphp/docker/resources/memory/dragonfly/dragonfly.yml',
        'valkey'    => 'vendor/flamesphp/docker/resources/memory/valkey/valkey.yml',
    ];

    private const array SEARCH_ENGINES = [
        'opensearch'    => 'vendor/flamesphp/docker/resources/search/opensearch/opensearch.yml',
        'elasticsearch' => 'vendor/flamesphp/docker/resources/search/elasticsearch/elasticsearch.yml',
        'meilisearch'   => 'vendor/flamesphp/docker/resources/search/meilisearch/meilisearch.yml',
    ];

    private const array CONTAINER_SERVICES = [
        'stealth'    => 'vendor/flamesphp/docker/resources/service/stealth/stealth.yml',
        'sonarqube'  => 'vendor/flamesphp/docker/resources/service/sonarqube/sonarqube.yml',
    ];

    /** Default .env lines appended on `container service add` when keys are missing. */
    private const array SERVICE_ENV_DEFAULTS = [
        'sonarqube' => <<<'ENV'

# SonarQube Community Build (forge container service add sonarqube)
SONARQUBE_HOST=sonarqube
SONARQUBE_PORT=9000
SONARQUBE_PORT_FORWARDED=19000
SONARQUBE_DB_USER=sonar
SONARQUBE_DB_PASSWORD=sonar
SONARQUBE_DB_NAME=sonar
SONARQUBE_ADMIN_USER=admin
SONARQUBE_ADMIN_PASSWORD=admin
ENV,
    ];

    /** @var list<string> Raw args after "container". */
    private readonly array $args;

    public function __construct(mixed $data)
    {
        $this->args = array_values(array_slice($_SERVER['argv'], 2));
    }

    public function run(bool $debug = false): bool
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        chdir(ROOT_PATH);

        if (!self::isInstalled()) {
            Output::error('Docker is not installed or not found in PATH.');
            return false;
        }

        if (empty($this->args)) {
            self::showStatus();
            return true;
        }

        $first   = $this->args[0];
        $options = array_slice($this->args, 1);

        return match ($first) {
            'compose'  => $this->runCompose($options),
            'run'      => $this->runCompose(in_array('--foreground', $options, true) ? ['up'] : ['up', '-d']),
            'reload'   => $this->runCompose(['up', '-d', '--build', '--force-recreate']),
            'build'    => $this->runCompose(['build']),
            'stop'     => $this->runCompose(['down']),
            'app'      => $this->handleApp($options),
            'db'       => $this->handleDb($options),
            'search'   => $this->handleSearch($options),
            'memory'   => $this->handleMemory($options),
            'service'  => $this->handleService($options),
            default    => $this->runExec($first, $options),
        };
    }

    private function runCompose(array $args): bool
    {
        if (in_array('up', $args, true)) {
            \Flames\Docker\ExposePort::resolveAll();
        }

        passthru(self::COMPOSE . ' ' . implode(' ', $args), $code);
        return $code === 0;
    }

    public function runExec(string $service, array $args): bool
    {
        if (!self::serviceExists($service)) {
            Output::error("Service '{$service}' not found in docker-compose.yml.");
            return false;
        }

        $inner = $this->buildInnerCommand($args);
        passthru(self::COMPOSE . ' exec ' . escapeshellarg($service) . ' ' . $inner, $code);
        return $code === 0;
    }

    private function buildInnerCommand(array $args): string
    {
        if (empty($args)) {
            return 'bash';
        }

        $first = $args[0];

        if ($first === 'bash' || $first === 'sh' || $first === 'php') {
            return implode(' ', array_map(escapeshellarg(...), $args));
        }

        return 'php forge ' . implode(' ', array_map(escapeshellarg(...), $args));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function handleApp(array $options): bool
    {
        if (($options[0] ?? '') !== 'set' || !isset($options[1])) {
            Output::error('Usage: container app set {image}');
            return false;
        }

        return $this->setApp($options[1]);
    }

    private function handleDb(array $options): bool
    {
        $subcommand = $options[0] ?? '';
        $arg        = $options[1] ?? null;

        if ($subcommand === 'set' && $arg !== null) {
            return $this->setDatabase($arg);
        }

        if ($subcommand === 'add' && $arg !== null) {
            return $this->addDatabase($arg);
        }

        if ($subcommand === 'remove' && $arg !== null) {
            return $this->removeDatabase($arg);
        }

        Output::error('Usage:');
        Output::error('  container db set {database}      Set the default database');
        Output::error('  container db add {database}      Add a database service');
        Output::error('  container db remove {database}   Remove a database service');
        return false;
    }

    private function setDatabase(string $driver): bool
    {
        $envPath = ROOT_PATH . '.env';
        if (!file_exists($envPath)) {
            Output::error('.env file not found.');
            return false;
        }

        if ($driver !== 'sqlite' && !self::serviceExists($driver)) {
            if (isset(self::DATABASES[$driver])) {
                Output::info("Service '{$driver}' not active. Adding it first...");
                if (!$this->addDatabase($driver)) {
                    return false;
                }
            } else {
                Output::error("Database service '{$driver}' not found in docker-compose. Use 'container db add {database}' first.");
                return false;
            }
        }

        $prefix  = strtoupper($driver);
        $content = (string) file_get_contents($envPath);

        if ($driver === 'sqlite') {
            $host = 'sqlite';
        } else {
            preg_match('/^DATABASE_' . $prefix . '_HOST=(.*)$/m', $content, $matches);
            $host = trim($matches[1] ?? $driver);
        }

        $content = preg_replace('/^DATABASE_DEFAULT=.*$/m', 'DATABASE_DEFAULT=' . $host, $content);
        file_put_contents($envPath, $content);

        Output::success("Default database set to '{$host}'.");
        return true;
    }

    private function addDatabase(string $driver): bool
    {
        if (!isset(self::DATABASES[$driver])) {
            Output::error("Unknown database '{$driver}'. Available: " . implode(', ', array_keys(self::DATABASES)));
            return false;
        }

        if (self::serviceExists($driver)) {
            Output::error("Database '{$driver}' is already configured.");
            return false;
        }

        $envPath = ROOT_PATH . '.env';
        if (!file_exists($envPath)) {
            Output::error('.env file not found.');
            return false;
        }

        $content = (string) file_get_contents($envPath);

        preg_match('/^COMPOSE_FILE=(.*)$/m', $content, $matches);
        $entries = isset($matches[1])
            ? array_filter(array_map(trim(...), explode(':', $matches[1])))
            : ['docker-compose.yml'];

        if (!in_array('docker-compose.yml', $entries, true)) {
            array_unshift($entries, 'docker-compose.yml');
        }

        $entries[] = self::DATABASES[$driver];

        $composeFile = implode(':', array_unique($entries));
        $content     = preg_replace('/^COMPOSE_FILE=.*$/m', 'COMPOSE_FILE=' . $composeFile, $content);

        preg_match('/^DATABASE_DEFAULT=(.*)$/m', $content, $defaultMatches);
        if (trim($defaultMatches[1] ?? '') === 'sqlite') {
            $prefix = strtoupper($driver);
            preg_match('/^DATABASE_' . $prefix . '_HOST=(.*)$/m', $content, $hostMatches);
            $host    = trim($hostMatches[1] ?? $driver);
            $content = preg_replace('/^DATABASE_DEFAULT=.*$/m', 'DATABASE_DEFAULT=' . $host, $content);
            Output::info("DATABASE_DEFAULT set to '{$host}'.");
        }

        file_put_contents($envPath, $content);

        Output::success("Added {$driver} to docker-compose.");
        Output::info('Building and starting containers...');

        passthru(self::COMPOSE . ' up -d --build', $code);
        return $code === 0;
    }

    private function removeDatabase(string $driver): bool
    {
        if (!isset(self::DATABASES[$driver])) {
            Output::error("Unknown database '{$driver}'. Available: " . implode(', ', array_keys(self::DATABASES)));
            return false;
        }

        $envPath = ROOT_PATH . '.env';
        if (!file_exists($envPath)) {
            Output::error('.env file not found.');
            return false;
        }

        $content = (string) file_get_contents($envPath);

        preg_match('/^COMPOSE_FILE=(.*)$/m', $content, $matches);
        $entries = isset($matches[1])
            ? array_filter(array_map(trim(...), explode(':', $matches[1])))
            : ['docker-compose.yml'];

        $ymlToRemove = self::DATABASES[$driver];

        if (!in_array($ymlToRemove, $entries, true)) {
            Output::error("Database '{$driver}' is not currently active.");
            return false;
        }

        $entries = array_values(array_filter($entries, fn($e) => $e !== $ymlToRemove));

        $composeFile = implode(':', $entries ?: ['docker-compose.yml']);
        $content     = preg_replace('/^COMPOSE_FILE=.*$/m', 'COMPOSE_FILE=' . $composeFile, $content);

        $dbYmlMap  = array_flip(self::DATABASES);
        $remaining = array_filter($entries, fn($e) => isset($dbYmlMap[$e]));

        if (!empty($remaining)) {
            $nextDriver = $dbYmlMap[array_first($remaining)];
            $prefix     = strtoupper($nextDriver);
            preg_match('/^DATABASE_' . $prefix . '_HOST=(.*)$/m', $content, $hostMatches);
            $nextHost = trim($hostMatches[1] ?? $nextDriver);
            $content  = preg_replace('/^DATABASE_DEFAULT=.*$/m', 'DATABASE_DEFAULT=' . $nextHost, $content);
            Output::info("DATABASE_DEFAULT set to '{$nextHost}'.");
        } else {
            $content = preg_replace('/^DATABASE_DEFAULT=.*$/m', 'DATABASE_DEFAULT=sqlite', $content);
            Output::info('No active databases remaining. DATABASE_DEFAULT set to sqlite.');
        }

        file_put_contents($envPath, $content);

        Output::success("Removed '{$driver}' from docker-compose.");
        Output::info('Restarting containers...');

        passthru(self::COMPOSE . ' up -d --build --force-recreate --remove-orphans', $code);
        return $code === 0;
    }

    private function handleMemory(array $options): bool
    {
        $subcommand = $options[0] ?? '';
        $arg        = $options[1] ?? null;

        if ($subcommand === 'set' && $arg !== null) {
            return $this->setMemory($arg);
        }

        if ($subcommand === 'add' && $arg !== null) {
            return $this->addMemory($arg);
        }

        if ($subcommand === 'remove' && $arg !== null) {
            return $this->removeMemory($arg);
        }

        Output::error('Usage:');
        Output::error('  container memory set {engine}      Set the default memory engine');
        Output::error('  container memory add {engine}      Add a memory engine service');
        Output::error('  container memory remove {engine}   Remove a memory engine service');
        return false;
    }

    private function setMemory(string $engine): bool
    {
        $envPath = ROOT_PATH . '.env';
        if (!file_exists($envPath)) {
            Output::error('.env file not found.');
            return false;
        }

        if (!self::serviceExists($engine)) {
            if (isset(self::MEMORY_ENGINES[$engine])) {
                Output::info("Service '{$engine}' not active. Adding it first...");
                if (!$this->addMemory($engine)) {
                    return false;
                }
            } else {
                Output::error("Memory engine '{$engine}' not found in docker-compose. Use 'container memory add {engine}' first.");
                return false;
            }
        }

        $prefix  = strtoupper($engine);
        $content = (string) file_get_contents($envPath);

        preg_match('/^DATABASE_MEMORY_' . $prefix . '_HOST=(.*)$/m', $content, $matches);
        $host = trim($matches[1] ?? $engine);

        if (preg_match('/^DATABASE_MEMORY_DEFAULT=/m', $content)) {
            $content = preg_replace('/^DATABASE_MEMORY_DEFAULT=.*$/m', 'DATABASE_MEMORY_DEFAULT=' . $host, $content);
        } else {
            $content .= "\nDATABASE_MEMORY_DEFAULT=" . $host;
        }

        file_put_contents($envPath, $content);

        Output::success("Default memory engine set to '{$host}'.");
        return true;
    }

    private function addMemory(string $engine): bool
    {
        if (!isset(self::MEMORY_ENGINES[$engine])) {
            Output::error("Unknown memory engine '{$engine}'. Available: " . implode(', ', array_keys(self::MEMORY_ENGINES)));
            return false;
        }

        if (self::serviceExists($engine)) {
            Output::error("Memory engine '{$engine}' is already configured.");
            return false;
        }

        $envPath = ROOT_PATH . '.env';
        if (!file_exists($envPath)) {
            Output::error('.env file not found.');
            return false;
        }

        $content = (string) file_get_contents($envPath);

        preg_match('/^COMPOSE_FILE=(.*)$/m', $content, $matches);
        $entries = isset($matches[1])
            ? array_filter(array_map(trim(...), explode(':', $matches[1])))
            : ['docker-compose.yml'];

        if (!in_array('docker-compose.yml', $entries, true)) {
            array_unshift($entries, 'docker-compose.yml');
        }

        $entries[]   = self::MEMORY_ENGINES[$engine];
        $composeFile = implode(':', array_unique($entries));
        $content     = preg_replace('/^COMPOSE_FILE=.*$/m', 'COMPOSE_FILE=' . $composeFile, $content);

        preg_match('/^DATABASE_MEMORY_DEFAULT=(.*)$/m', $content, $defaultMatches);
        $currentDefault = trim($defaultMatches[1] ?? '');
        if (empty($currentDefault) || $currentDefault === 'file') {
            $prefix = strtoupper($engine);
            preg_match('/^DATABASE_MEMORY_' . $prefix . '_HOST=(.*)$/m', $content, $hostMatches);
            $host    = trim($hostMatches[1] ?? $engine);
            $content = preg_replace('/^DATABASE_MEMORY_DEFAULT=.*$/m', 'DATABASE_MEMORY_DEFAULT=' . $host, $content);
            Output::info("DATABASE_MEMORY_DEFAULT set to '{$host}'.");
        }

        file_put_contents($envPath, $content);

        Output::success("Added {$engine} to docker-compose.");
        Output::info('Building and starting containers...');

        passthru(self::COMPOSE . ' up -d --build', $code);
        return $code === 0;
    }

    private function removeMemory(string $engine): bool
    {
        if (!isset(self::MEMORY_ENGINES[$engine])) {
            Output::error("Unknown memory engine '{$engine}'. Available: " . implode(', ', array_keys(self::MEMORY_ENGINES)));
            return false;
        }

        $envPath = ROOT_PATH . '.env';
        if (!file_exists($envPath)) {
            Output::error('.env file not found.');
            return false;
        }

        $content = (string) file_get_contents($envPath);

        preg_match('/^COMPOSE_FILE=(.*)$/m', $content, $matches);
        $entries = isset($matches[1])
            ? array_filter(array_map(trim(...), explode(':', $matches[1])))
            : ['docker-compose.yml'];

        $ymlToRemove = self::MEMORY_ENGINES[$engine];

        if (!in_array($ymlToRemove, $entries, true)) {
            Output::error("Memory engine '{$engine}' is not currently active.");
            return false;
        }

        $entries     = array_values(array_filter($entries, fn($e) => $e !== $ymlToRemove));
        $composeFile = implode(':', $entries ?: ['docker-compose.yml']);
        $content     = preg_replace('/^COMPOSE_FILE=.*$/m', 'COMPOSE_FILE=' . $composeFile, $content);

        $memYmlMap = array_flip(self::MEMORY_ENGINES);
        $remaining = array_filter($entries, fn($e) => isset($memYmlMap[$e]));

        if (!empty($remaining)) {
            $nextEngine = $memYmlMap[array_first($remaining)];
            $prefix     = strtoupper($nextEngine);
            preg_match('/^DATABASE_MEMORY_' . $prefix . '_HOST=(.*)$/m', $content, $hostMatches);
            $nextHost = trim($hostMatches[1] ?? $nextEngine);
            $content  = preg_replace('/^DATABASE_MEMORY_DEFAULT=.*$/m', 'DATABASE_MEMORY_DEFAULT=' . $nextHost, $content);
            Output::info("DATABASE_MEMORY_DEFAULT set to '{$nextHost}'.");
        } else {
            $content = preg_replace('/^DATABASE_MEMORY_DEFAULT=.*$/m', 'DATABASE_MEMORY_DEFAULT=file', $content);
            Output::info('No active memory engines remaining. DATABASE_MEMORY_DEFAULT set to file.');
        }

        file_put_contents($envPath, $content);

        Output::success("Removed '{$engine}' from docker-compose.");
        Output::info('Restarting containers...');

        passthru(self::COMPOSE . ' up -d --build --force-recreate --remove-orphans', $code);
        return $code === 0;
    }

    private function handleSearch(array $options): bool
    {
        $subcommand = $options[0] ?? '';
        $arg        = $options[1] ?? null;

        if ($subcommand === 'set' && $arg !== null) {
            return $this->setSearch($arg);
        }

        if ($subcommand === 'add' && $arg !== null) {
            return $this->addSearch($arg);
        }

        if ($subcommand === 'remove' && $arg !== null) {
            return $this->removeSearch($arg);
        }

        Output::error('Usage:');
        Output::error('  container search set {engine}      Set the default search engine');
        Output::error('  container search add {engine}      Add a search engine service');
        Output::error('  container search remove {engine}   Remove a search engine service');
        return false;
    }

    private function setSearch(string $engine): bool
    {
        $envPath = ROOT_PATH . '.env';
        if (!file_exists($envPath)) {
            Output::error('.env file not found.');
            return false;
        }

        if (!self::serviceExists($engine)) {
            if (isset(self::SEARCH_ENGINES[$engine])) {
                Output::info("Service '{$engine}' not active. Adding it first...");
                if (!$this->addSearch($engine)) {
                    return false;
                }
            } else {
                Output::error("Search engine '{$engine}' not found in docker-compose. Use 'container search add {engine}' first.");
                return false;
            }
        }

        $prefix  = strtoupper($engine);
        $content = (string) file_get_contents($envPath);

        preg_match('/^DATABASE_SEARCH_' . $prefix . '_HOST=(.*)$/m', $content, $matches);
        $host = trim($matches[1] ?? $engine);

        if (preg_match('/^DATABASE_SEARCH_DEFAULT=/m', $content)) {
            $content = preg_replace('/^DATABASE_SEARCH_DEFAULT=.*$/m', 'DATABASE_SEARCH_DEFAULT=' . $host, $content);
        } else {
            $content .= "\nDATABASE_SEARCH_DEFAULT=" . $host;
        }

        file_put_contents($envPath, $content);

        Output::success("Default search engine set to '{$host}'.");
        return true;
    }

    private function addSearch(string $engine): bool
    {
        if (!isset(self::SEARCH_ENGINES[$engine])) {
            Output::error("Unknown search engine '{$engine}'. Available: " . implode(', ', array_keys(self::SEARCH_ENGINES)));
            return false;
        }

        if (self::serviceExists($engine)) {
            if (self::isServiceRunning($engine)) {
                Output::error("Search engine '{$engine}' is already configured and running.");
                return false;
            }

            Output::info("Search engine '{$engine}' is configured but not running. Starting it...");
            passthru(self::COMPOSE . ' up -d ' . escapeshellarg($engine), $code);
            return $code === 0;
        }

        $envPath = ROOT_PATH . '.env';
        if (!file_exists($envPath)) {
            Output::error('.env file not found.');
            return false;
        }

        $content = (string) file_get_contents($envPath);

        preg_match('/^COMPOSE_FILE=(.*)$/m', $content, $matches);
        $entries = isset($matches[1])
            ? array_filter(array_map(trim(...), explode(':', $matches[1])))
            : ['docker-compose.yml'];

        if (!in_array('docker-compose.yml', $entries, true)) {
            array_unshift($entries, 'docker-compose.yml');
        }

        $entries[] = self::SEARCH_ENGINES[$engine];

        $composeFile = implode(':', array_unique($entries));
        $content     = preg_replace('/^COMPOSE_FILE=.*$/m', 'COMPOSE_FILE=' . $composeFile, $content);

        preg_match('/^DATABASE_SEARCH_DEFAULT=(.*)$/m', $content, $defaultMatches);
        if (empty(trim($defaultMatches[1] ?? ''))) {
            $prefix = strtoupper($engine);
            preg_match('/^DATABASE_SEARCH_' . $prefix . '_HOST=(.*)$/m', $content, $hostMatches);
            $host = trim($hostMatches[1] ?? $engine);

            if (preg_match('/^DATABASE_SEARCH_DEFAULT=/m', $content)) {
                $content = preg_replace('/^DATABASE_SEARCH_DEFAULT=.*$/m', 'DATABASE_SEARCH_DEFAULT=' . $host, $content);
            } else {
                $content .= "\nDATABASE_SEARCH_DEFAULT=" . $host;
            }

            Output::info("DATABASE_SEARCH_DEFAULT set to '{$host}'.");
        }

        file_put_contents($envPath, $content);

        Output::success("Added {$engine} to docker-compose.");
        Output::info('Building and starting containers...');

        passthru(self::COMPOSE . ' up -d --build', $code);
        return $code === 0;
    }

    private function removeSearch(string $engine): bool
    {
        if (!isset(self::SEARCH_ENGINES[$engine])) {
            Output::error("Unknown search engine '{$engine}'. Available: " . implode(', ', array_keys(self::SEARCH_ENGINES)));
            return false;
        }

        $envPath = ROOT_PATH . '.env';
        if (!file_exists($envPath)) {
            Output::error('.env file not found.');
            return false;
        }

        $content = (string) file_get_contents($envPath);

        preg_match('/^COMPOSE_FILE=(.*)$/m', $content, $matches);
        $entries = isset($matches[1])
            ? array_filter(array_map(trim(...), explode(':', $matches[1])))
            : ['docker-compose.yml'];

        $ymlToRemove = self::SEARCH_ENGINES[$engine];

        if (!in_array($ymlToRemove, $entries, true)) {
            Output::error("Search engine '{$engine}' is not currently active.");
            return false;
        }

        $entries = array_values(array_filter($entries, fn($e) => $e !== $ymlToRemove));

        $composeFile = implode(':', $entries ?: ['docker-compose.yml']);
        $content     = preg_replace('/^COMPOSE_FILE=.*$/m', 'COMPOSE_FILE=' . $composeFile, $content);

        $searchYmlMap = array_flip(self::SEARCH_ENGINES);
        $remaining    = array_filter($entries, fn($e) => isset($searchYmlMap[$e]));

        if (!empty($remaining)) {
            $nextEngine = $searchYmlMap[array_first($remaining)];
            $prefix     = strtoupper($nextEngine);
            preg_match('/^DATABASE_SEARCH_' . $prefix . '_HOST=(.*)$/m', $content, $hostMatches);
            $nextHost = trim($hostMatches[1] ?? $nextEngine);
            $content  = preg_replace('/^DATABASE_SEARCH_DEFAULT=.*$/m', 'DATABASE_SEARCH_DEFAULT=' . $nextHost, $content);
            Output::info("DATABASE_SEARCH_DEFAULT set to '{$nextHost}'.");
        } else {
            $content = preg_replace('/^DATABASE_SEARCH_DEFAULT=.*$/m', 'DATABASE_SEARCH_DEFAULT=', $content);
            Output::info('No active search engines remaining. DATABASE_SEARCH_DEFAULT cleared.');
        }

        file_put_contents($envPath, $content);

        Output::success("Removed '{$engine}' from docker-compose.");
        Output::info('Restarting containers...');

        passthru(self::COMPOSE . ' up -d --build --force-recreate --remove-orphans', $code);
        return $code === 0;
    }

    private function handleService(array $options): bool
    {
        $subcommand = $options[0] ?? '';
        $arg        = $options[1] ?? null;

        if ($subcommand === 'add' && $arg !== null) {
            return $this->addService($arg);
        }

        if ($subcommand === 'remove' && $arg !== null) {
            return $this->removeService($arg);
        }

        Output::error('Usage:');
        Output::error('  container service add {service}      Add an optional service');
        Output::error('  container service remove {service}   Remove an optional service');
        return false;
    }

    private function addService(string $service): bool
    {
        if (!isset(self::CONTAINER_SERVICES[$service])) {
            Output::error("Unknown service '{$service}'. Available: " . implode(', ', array_keys(self::CONTAINER_SERVICES)));
            return false;
        }

        if (self::serviceExists($service)) {
            Output::error("Service '{$service}' is already configured.");
            return false;
        }

        $envPath = ROOT_PATH . '.env';
        if (!file_exists($envPath)) {
            Output::error('.env file not found.');
            return false;
        }

        $content = (string) file_get_contents($envPath);

        preg_match('/^COMPOSE_FILE=(.*)$/m', $content, $matches);
        $entries = isset($matches[1])
            ? array_filter(array_map(trim(...), explode(':', $matches[1])))
            : ['docker-compose.yml'];

        if (!in_array('docker-compose.yml', $entries, true)) {
            array_unshift($entries, 'docker-compose.yml');
        }

        $entries[]   = self::CONTAINER_SERVICES[$service];
        $composeFile = implode(':', array_unique($entries));
        $content     = preg_replace('/^COMPOSE_FILE=.*$/m', 'COMPOSE_FILE=' . $composeFile, $content);
        $content     = self::appendServiceEnvDefaults($service, $content);

        file_put_contents($envPath, $content);

        Output::success("Added {$service} to docker-compose.");
        if ($service === 'sonarqube') {
            Sonarqube::warnIfHostSysctlLow();
        }
        Output::info('Building and starting containers...');

        passthru(self::COMPOSE . ' up -d --build', $code);
        if ($code !== 0) {
            return false;
        }

        if ($service === 'sonarqube') {
            return Sonarqube::bootstrapAfterServiceAdd();
        }

        return true;
    }

    private function removeService(string $service): bool
    {
        if (!isset(self::CONTAINER_SERVICES[$service])) {
            Output::error("Unknown service '{$service}'. Available: " . implode(', ', array_keys(self::CONTAINER_SERVICES)));
            return false;
        }

        $envPath = ROOT_PATH . '.env';
        if (!file_exists($envPath)) {
            Output::error('.env file not found.');
            return false;
        }

        $content = (string) file_get_contents($envPath);

        preg_match('/^COMPOSE_FILE=(.*)$/m', $content, $matches);
        $entries = isset($matches[1])
            ? array_filter(array_map(trim(...), explode(':', $matches[1])))
            : ['docker-compose.yml'];

        $ymlToRemove = self::CONTAINER_SERVICES[$service];

        if (!in_array($ymlToRemove, $entries, true)) {
            Output::error("Service '{$service}' is not currently active.");
            return false;
        }

        $entries     = array_values(array_filter($entries, fn($e) => $e !== $ymlToRemove));
        $composeFile = implode(':', $entries ?: ['docker-compose.yml']);
        $content     = preg_replace('/^COMPOSE_FILE=.*$/m', 'COMPOSE_FILE=' . $composeFile, $content);

        file_put_contents($envPath, $content);

        Output::success("Removed '{$service}' from docker-compose.");
        Output::info('Restarting containers...');

        passthru(self::COMPOSE . ' up -d --build --force-recreate --remove-orphans', $code);
        return $code === 0;
    }

    private static function appendServiceEnvDefaults(string $service, string $content): string
    {
        $block = self::SERVICE_ENV_DEFAULTS[$service] ?? null;
        if ($block === null || trim($block) === '') {
            return $content;
        }

        foreach (explode("\n", trim($block)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $key = explode('=', $line, 2)[0] ?? '';
            if ($key !== '' && preg_match('/^' . preg_quote($key, '/') . '=/m', $content) === 1) {
                return $content;
            }

            break;
        }

        return rtrim($content) . "\n" . trim($block) . "\n";
    }

    private function setApp(string $image): bool
    {
        $images = [
            'apache_modphp'       => 'vendor/flamesphp/docker/resources/app/apache_modphp/apache_modphp.yml',
            'apache_phpfpm'       => 'vendor/flamesphp/docker/resources/app/apache_phpfpm/apache_phpfpm.yml',
            'nginx_phpfpm'        => 'vendor/flamesphp/docker/resources/app/nginx_phpfpm/nginx_phpfpm.yml',
            'nginx_phpfpm_flames' => 'vendor/flamesphp/docker/resources/app/nginx_phpfpm_flames/nginx_phpfpm_flames.yml',
            'nginx_flames'        => 'vendor/flamesphp/docker/resources/app/nginx_flames/nginx_flames.yml',
        ];

        if (!isset($images[$image])) {
            Output::error("Unknown image '{$image}'. Available: " . implode(', ', array_keys($images)));
            return false;
        }

        $envPath = ROOT_PATH . '.env';
        if (!file_exists($envPath)) {
            Output::error('.env file not found.');
            return false;
        }

        $content = (string) file_get_contents($envPath);

        // Parse existing COMPOSE_FILE entries
        preg_match('/^COMPOSE_FILE=(.*)$/m', $content, $matches);
        $entries = isset($matches[1])
            ? array_filter(array_map(trim(...), explode(':', $matches[1])))
            : ['docker-compose.yml'];

        // Remove any existing app image yml, keep all other services (mariadb, mongodb, etc.)
        $appYmls  = array_values($images);
        $filtered = array_values(array_filter($entries, fn($e) => !in_array($e, $appYmls, true)));

        // Ensure docker-compose.yml is always first
        if (!in_array('docker-compose.yml', $filtered, true)) {
            array_unshift($filtered, 'docker-compose.yml');
        }

        // Append the new app yml right after docker-compose.yml
        array_splice($filtered, 1, 0, [$images[$image]]);

        $composeFile = implode(':', $filtered);
        $content     = preg_replace('/^COMPOSE_FILE=.*$/m', 'COMPOSE_FILE=' . $composeFile, $content);

        file_put_contents($envPath, $content);

        Output::success("Switched to {$image}.");
        Output::info('Building and starting containers...');

        passthru(self::COMPOSE . ' up -d --build', $code);
        return $code === 0;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function isInstalled(): bool
    {
        if (self::$dockerInstalled === null) {
            exec('docker --version 2>/dev/null', $out, $code);
            self::$dockerInstalled = ($code === 0);
        }
        return self::$dockerInstalled;
    }

    private static function showStatus(): void
    {
        passthru(self::COMPOSE . ' ps');
    }

    public static function serviceExists(string $service): bool
    {
        $composePath = ROOT_PATH . 'docker-compose.yml';
        if (!file_exists($composePath)) {
            return false;
        }

        if (self::$serviceCache === null) {
            exec(self::COMPOSE . ' config --services 2>/dev/null', $services, $code);

            if ($code !== 0) {
                // Fallback: parse YAML without spawning a subprocess
                self::$serviceCache = [];
                $content = (string)file_get_contents($composePath);
                if (preg_match_all('/^\s{2,4}(\S+)\s*:/m', $content, $m)) {
                    self::$serviceCache = array_flip($m[1]);
                }
            } else {
                self::$serviceCache = array_flip(array_filter($services));
            }
        }

        return isset(self::$serviceCache[$service]);
    }

    public static function isServiceRunning(string $service, ?string $composeFile = null): bool
    {
        $prefix = $composeFile !== null
            ? 'COMPOSE_FILE=' . escapeshellarg($composeFile) . ' '
            : '';

        exec(
            $prefix . self::COMPOSE . ' ps --status running --services ' . escapeshellarg($service) . ' 2>/dev/null',
            $running,
            $code,
        );

        $running = array_values(array_filter(
            array_map(trim(...), $running),
            static fn (string $line): bool => $line !== '',
        ));

        return $code === 0 && $running !== [];
    }

    public static function composeCommand(): string
    {
        return self::COMPOSE;
    }

    public static function isSonarqubePermanent(): bool
    {
        $yml = 'vendor/flamesphp/docker/resources/service/sonarqube/sonarqube.yml';

        $envPath = ROOT_PATH . '.env';
        if (!is_file($envPath)) {
            return false;
        }

        $content = (string) file_get_contents($envPath);
        if (preg_match('/^COMPOSE_FILE=(.*)$/m', $content, $matches) !== 1) {
            return false;
        }

        $entries = array_filter(array_map(trim(...), explode(':', $matches[1])));

        return in_array($yml, $entries, true);
    }

    /**
     * @return list<string>
     */
    public static function readComposeFileEntries(): array
    {
        $envPath = ROOT_PATH . '.env';
        if (!is_file($envPath)) {
            return ['docker-compose.yml'];
        }

        $content = (string) file_get_contents($envPath);
        if (preg_match('/^COMPOSE_FILE=(.*)$/m', $content, $matches) !== 1) {
            return ['docker-compose.yml'];
        }

        $entries = array_filter(array_map(trim(...), explode(':', $matches[1])));

        if (!in_array('docker-compose.yml', $entries, true)) {
            array_unshift($entries, 'docker-compose.yml');
        }

        return array_values($entries);
    }
}
