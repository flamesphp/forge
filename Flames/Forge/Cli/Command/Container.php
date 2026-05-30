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
    private const COMPOSE = 'CLICOLOR_FORCE=1 TERM=xterm-256color docker compose';

    /** @var array<string, bool>|null Cached service list (flipped for O(1) lookup). */
    private static ?array $serviceCache = null;

    /** Cached result of the docker binary check. */
    private static ?bool $dockerInstalled = null;

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
            'build'    => $this->runCompose(['build']),
            'stop'     => $this->runCompose(['down']),
            'app'      => $this->handleApp($options),
            default    => $this->runExec($first, $options),
        };
    }

    private function runCompose(array $args): bool
    {
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
            return implode(' ', array_map('escapeshellarg', $args));
        }

        return 'php forge ' . implode(' ', array_map('escapeshellarg', $args));
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

    private function setApp(string $image): bool
    {
        $images = [
            'apache_modphp'       => 'vendor/flamesphp/docker/resource/image/apache_modphp/apache_modphp.yml',
            'apache_phpfpm'       => 'vendor/flamesphp/docker/resource/image/apache_phpfpm/apache_phpfpm.yml',
            'nginx_phpfpm'        => 'vendor/flamesphp/docker/resource/image/nginx_phpfpm/nginx_phpfpm.yml',
            'nginx_phpfpm_flames' => 'vendor/flamesphp/docker/resource/image/nginx_phpfpm_flames/nginx_phpfpm_flames.yml',
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
            ? array_filter(array_map('trim', explode(':', $matches[1])))
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
}
