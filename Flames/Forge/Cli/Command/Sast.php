<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

use Flames\Forge\Cli\Output;

/**
 * Run SonarQube SAST against application source paths.
 *
 * Usage:
 *   forge code sast              — scan App/
 *   forge code sast App/Server   — scan a subdirectory
 *
 * @internal
 */
final readonly class Sast
{
    private const string SONARQUBE_YML = 'vendor/flamesphp/docker/resources/service/sonarqube/sonarqube.yml';

    private const string SCANNER_IMAGE = 'sonarsource/sonar-scanner-cli:latest';

    private ?string $targetPath;

    public function __construct(mixed $data)
    {
        $args = array_values((array) ($data->argument ?? []));

        $positional = array_values(array_filter(
            $args,
            static fn (string $arg): bool => !str_starts_with($arg, '-'),
        ));

        $this->targetPath = isset($positional[0]) ? (string) $positional[0] : null;
    }

    public function run(bool $debug = false): bool
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        chdir(ROOT_PATH);

        if (!self::dockerInstalled()) {
            Output::error('Docker is not installed or not found in PATH.');
            return false;
        }

        $path = $this->resolveTargetPath($this->targetPath);
        if ($path === null) {
            return false;
        }

        $permanent    = Container::isSonarqubePermanent();
        $composeFile  = $this->resolveComposeFile($permanent);
        $port         = Sonarqube::port();
        $adminUser    = Sonarqube::adminUser();
        $adminPass    = Sonarqube::adminPassword();
        $relativePath = $this->relativePath($path);
        $projectKey   = Sonarqube::resolveProjectKey($relativePath);
        $projectName  = 'Flames SAST — ' . $relativePath;

        echo Output::CYAN . Output::BOLD . '  Code SAST' . Output::RESET
            . Output::GRAY . '  SonarQube Community' . Output::RESET
            . Output::GRAY . '  ·  vulnerabilities only' . Output::RESET
            . Output::GRAY . '  →  ' . Output::RESET
            . Output::WHITE . $relativePath . Output::RESET . "\n\n";

        if ($permanent) {
            Output::info('SonarQube service is registered in COMPOSE_FILE (persistent mode).');
        } else {
            Output::info('SonarQube is not registered — using ephemeral containers (will stop after scan).');
        }

        try {
            if (!Container::isServiceRunning('sonarqube', $composeFile)) {
                Sonarqube::warnIfHostSysctlLow();
                Output::info('Starting SonarQube containers...');
                if ($this->compose($composeFile, ['up', '-d', 'sonarqube-db', 'sonarqube']) !== 0) {
                    Output::error('Failed to start SonarQube containers.');
                    Output::info('On WSL/Linux host, run: sudo sysctl -w vm.max_map_count=524288');
                    return false;
                }
            }

            if (!Sonarqube::waitUntilReady($port, $adminUser, $adminPass)) {
                return false;
            }

            if (!Sonarqube::ensureProject($port, $adminUser, $adminPass, $projectKey, $projectName)) {
                return false;
            }

            $token = Sonarqube::resolveScanToken();
            if ($token === null) {
                return false;
            }

            Output::info('Running scanner and uploading analysis bundle to SonarQube...');

            if (!$this->runScanner($composeFile, $path, $projectKey, $token)) {
                return false;
            }

            if (!$this->waitForAnalysis($port, $adminUser, $adminPass, $projectKey)) {
                Output::warning('Analysis is still processing; showing available results.');
            }

            $this->printReport($port, $adminUser, $adminPass, $projectKey);

            Output::success('SAST scan completed.');
            Output::info('Dashboard (vulnerabilities only): ' . Sonarqube::vulnerabilitiesDashboardUrl($port, $projectKey));

            return true;
        } finally {
            if (!$permanent) {
                Output::info('Stopping ephemeral SonarQube containers...');
                $this->compose($composeFile, ['stop', 'sonarqube', 'sonarqube-db']);
                $this->compose($composeFile, ['rm', '-f', 'sonarqube', 'sonarqube-db']);
            }
        }
    }

    private function resolveTargetPath(?string $path): ?string
    {
        $path ??= Sonarqube::DEFAULT_SCAN_PATH;

        if ($path !== '' && $path[0] === '/') {
            $absolute = $path;
        } else {
            $absolute = ROOT_PATH . ltrim(str_replace('\\', '/', $path), '/');
        }

        $real = realpath($absolute);
        if ($real === false || !is_dir($real)) {
            echo Output::RED . '  Path not found: ' . $path . Output::RESET . "\n";
            return null;
        }

        return rtrim($real, '/') . '/';
    }

    private function resolveComposeFile(bool $permanent): string
    {
        $entries = Container::readComposeFileEntries();

        if (!$permanent && !in_array(self::SONARQUBE_YML, $entries, true)) {
            $entries[] = self::SONARQUBE_YML;
        }

        return implode(':', $entries);
    }

    /**
     * @param list<string> $args
     */
    private function compose(string $composeFile, array $args): int
    {
        $command = 'COMPOSE_FILE=' . escapeshellarg($composeFile)
            . ' ' . Container::composeCommand()
            . ' ' . implode(' ', array_map(static fn (string $arg): string => escapeshellarg($arg), $args));

        passthru($command, $exitCode);

        return $exitCode ?? 1;
    }

    private function runScanner(string $composeFile, string $path, string $projectKey, string $token): bool
    {
        $network = $this->resolveSonarqubeNetwork($composeFile);
        if ($network === null) {
            Output::error('Could not detect Docker network for SonarQube.');
            return false;
        }

        $command = [
            'docker', 'run', '--rm',
            '--network', $network,
            '-v', $path . ':/usr/src',
            '-e', 'SONAR_HOST_URL=http://sonarqube:9000',
            '-e', 'SONAR_TOKEN=' . $token,
            self::SCANNER_IMAGE,
            '-Dsonar.projectKey=' . $projectKey,
            '-Dsonar.projectBaseDir=/usr/src',
            '-Dsonar.sources=.',
            '-Dsonar.sourceEncoding=UTF-8',
            '-Dsonar.exclusions=**/vendor/**,**/node_modules/**,**/cache/**,**/.git/**,**/Resource/Build/**',
            '-Dsonar.php.file.suffixes=php',
            '-Dsonar.scanner.analysisCacheEnabled=false',
            '-Dsonar.profile=' . Sonarqube::VULNERABILITIES_ONLY_PROFILE,
        ];

        passthru(implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $command)), $exitCode);

        return ($exitCode ?? 1) === 0;
    }

    private function resolveSonarqubeNetwork(string $composeFile): ?string
    {
        $command = 'COMPOSE_FILE=' . escapeshellarg($composeFile)
            . ' ' . Container::composeCommand()
            . ' ps -q sonarqube 2>/dev/null';

        exec($command, $ids, $code);
        if ($code !== 0 || empty($ids[0])) {
            return null;
        }

        $containerId = trim((string) $ids[0]);
        $format      = '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}';
        exec(
            'docker inspect -f ' . escapeshellarg($format) . ' ' . escapeshellarg($containerId),
            $networks,
            $inspectCode,
        );

        if ($inspectCode !== 0 || $networks === []) {
            return null;
        }

        foreach (explode(' ', trim((string) $networks[0])) as $network) {
            if ($network !== '' && str_contains($network, 'flames')) {
                return $network;
            }
        }

        $parts = explode(' ', trim((string) $networks[0]));

        return $parts[0] !== '' ? $parts[0] : null;
    }

    private function waitForAnalysis(int $port, string $user, string $pass, string $projectKey): bool
    {
        $deadline = time() + 180;

        while (time() < $deadline) {
            $activity = Sonarqube::apiGet($port, $user, $pass, '/api/ce/activity', [
                'component' => $projectKey,
                'ps'        => 1,
            ]);

            $status = is_array($activity) ? ($activity['tasks'][0]['status'] ?? null) : null;

            if ($status === 'SUCCESS') {
                return true;
            }

            if ($status === 'FAILED' || $status === 'CANCELED') {
                Output::error('SonarQube analysis task failed.');
                return false;
            }

            sleep(2);
        }

        return false;
    }

    private function printReport(int $port, string $user, string $pass, string $projectKey): void
    {
        $measures = Sonarqube::apiGet($port, $user, $pass, '/api/measures/component', [
            'component'  => $projectKey,
            'metricKeys' => 'vulnerabilities',
        ]);

        if (is_array($measures) && isset($measures['component']['measures'])) {
            echo "\n" . Output::WHITE . Output::BOLD . '  Summary' . Output::RESET
                . Output::GRAY . '  (vulnerabilities only)' . Output::RESET . "\n";
            foreach ($measures['component']['measures'] as $measure) {
                $metric = (string) ($measure['metric'] ?? '');
                $value  = (string) ($measure['value'] ?? '0');
                echo '    ' . Output::GRAY . str_pad($metric, 24) . Output::RESET . $value . "\n";
            }
        }

        $issues = Sonarqube::apiGet($port, $user, $pass, '/api/issues/search', [
            'componentKeys' => $projectKey,
            'types'         => 'VULNERABILITY',
            'ps'            => 100,
            's'             => 'SEVERITY',
            'asc'           => 'false',
        ]);

        if (!is_array($issues) || empty($issues['issues'])) {
            echo "\n" . Output::GREEN . '  No vulnerabilities reported.' . Output::RESET . "\n";
            return;
        }

        $total = (int) ($issues['total'] ?? count($issues['issues']));
        echo "\n" . Output::WHITE . Output::BOLD . '  Vulnerabilities' . Output::RESET
            . Output::GRAY . '  (' . $total . ' total, showing up to 100)' . Output::RESET . "\n\n";

        foreach ($issues['issues'] as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $severity  = strtoupper((string) ($issue['severity'] ?? 'INFO'));
            $type      = (string) ($issue['type'] ?? 'ISSUE');
            $message   = (string) ($issue['message'] ?? '');
            $component = (string) ($issue['component'] ?? '');
            $line      = (string) ($issue['line'] ?? '');

            $severityColor = match ($severity) {
                'BLOCKER', 'CRITICAL' => Output::RED,
                'MAJOR'               => Output::YELLOW,
                'MINOR'               => Output::CYAN,
                default               => Output::GRAY,
            };

            $location = $component;
            if ($line !== '') {
                $location .= ':' . $line;
            }

            echo $severityColor . str_pad($severity, 10) . Output::RESET
                . Output::GRAY . str_pad($type, 16) . Output::RESET
                . Output::WHITE . $location . Output::RESET . "\n"
                . '           ' . $message . "\n\n";
        }
    }

    private function relativePath(string $absolute): string
    {
        $root = rtrim(ROOT_PATH, '/') . '/';

        return str_starts_with($absolute, $root)
            ? rtrim(substr($absolute, strlen($root)), '/')
            : rtrim($absolute, '/');
    }

    private static function dockerInstalled(): bool
    {
        exec('docker --version 2>/dev/null', $out, $code);

        return $code === 0;
    }
}
