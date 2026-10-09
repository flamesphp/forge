<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

use Flames\Env\Env;
use Flames\Forge\Cli\Output;

/**
 * SonarQube API bootstrap — project + scan token (no scanner).
 *
 * @internal
 */
final class Sonarqube
{
    public const string DEFAULT_SCAN_PATH = 'App';

    /** Quality profile with vulnerability rules only (no bugs / code smells). */
    public const string VULNERABILITIES_ONLY_PROFILE = 'Flames Vulnerabilities Only';

    public const string PROFILE_LANGUAGE = 'php';

    /**
     * After `container service add sonarqube`: wait for server, create App/ project, store token in .env.
     */
    public static function warnIfHostSysctlLow(): void
    {
        $path = '/proc/sys/vm/max_map_count';
        if (!is_readable($path)) {
            return;
        }

        $value = (int) trim((string) file_get_contents($path));
        if ($value >= 524288) {
            return;
        }

        Output::warning('Host vm.max_map_count is ' . $value . ' (SonarQube requires at least 524288).');
        Output::info('Run on WSL/Linux host: sudo sysctl -w vm.max_map_count=524288');
        Output::info('To persist: echo "vm.max_map_count=524288" | sudo tee -a /etc/sysctl.conf && sudo sysctl -p');
    }

    public static function bootstrapAfterServiceAdd(): bool
    {
        Env::reload();
        self::warnIfHostSysctlLow();

        $port      = self::port();
        $adminUser = self::adminUser();
        $adminPass = self::adminPassword();
        $projectKey = self::projectKey(self::DEFAULT_SCAN_PATH);
        $projectName = 'Flames — ' . self::DEFAULT_SCAN_PATH;

        Output::info('Waiting for SonarQube to finish starting...');

        if (!self::waitUntilReady($port, $adminUser, $adminPass)) {
            return false;
        }

        if (!self::ensureProject($port, $adminUser, $adminPass, $projectKey, $projectName)) {
            return false;
        }

        $token = self::generateScanToken($port, $adminUser, $adminPass, 'forge-sast');
        if ($token === null) {
            return false;
        }

        self::writeEnvValue('SONARQUBE_PROJECT_KEY', $projectKey);
        self::writeEnvValue('SONARQUBE_TOKEN', $token);
        self::writeEnvValue('SONARQUBE_DEFAULT_PATH', self::DEFAULT_SCAN_PATH);
        Env::reload();

        Output::success('SonarQube project and scan token configured.');
        Output::info('Project: ' . $projectKey);
        Output::info('Dashboard: ' . self::vulnerabilitiesDashboardUrl($port, $projectKey));
        Output::info('Run a scan with: php forge code sast');

        return true;
    }

    public static function projectKey(string $relativePath): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $relativePath) ?? 'app');

        return 'flames-' . trim($slug, '-') . '-' . substr(sha1($relativePath), 0, 8);
    }

    public static function resolveProjectKey(string $relativePath): string
    {
        $defaultPath = (string) (Env::get('SONARQUBE_DEFAULT_PATH') ?? self::DEFAULT_SCAN_PATH);
        $storedKey   = Env::get('SONARQUBE_PROJECT_KEY');

        if ($storedKey !== null && $storedKey !== '' && $relativePath === $defaultPath) {
            return (string) $storedKey;
        }

        return self::projectKey($relativePath);
    }

    public static function resolveScanToken(): ?string
    {
        $token = Env::get('SONARQUBE_TOKEN');
        if ($token !== null && $token !== '') {
            return (string) $token;
        }

        return self::generateScanToken(self::port(), self::adminUser(), self::adminPassword(), 'forge-sast-' . date('YmdHis'));
    }

    public static function waitUntilReady(?int $port = null, ?string $user = null, ?string $pass = null): bool
    {
        $port ??= self::port();
        $user ??= self::adminUser();
        $pass ??= self::adminPassword();
        $deadline = time() + 300;
        $announced = false;

        while (time() < $deadline) {
            $status = self::apiGet($port, $user, $pass, '/api/system/status');
            $value  = is_array($status) ? ($status['status'] ?? null) : null;

            if ($value === 'UP') {
                Output::success('SonarQube is ready.');
                return true;
            }

            if (!$announced) {
                Output::info('Waiting for SonarQube to finish starting (first boot can take 2–3 minutes)...');
                $announced = true;
            }

            if ($value === 'DB_MIGRATION_NEEDED' || $value === 'MIGRATION_RUNNING') {
                Output::info('SonarQube database migration in progress...');
            }

            sleep(3);
        }

        Output::error('Timed out waiting for SonarQube to become ready.');
        return false;
    }

    public static function ensureProject(int $port, string $user, string $pass, string $projectKey, string $projectName): bool
    {
        $search = self::apiGet($port, $user, $pass, '/api/projects/search', [
            'projects' => $projectKey,
        ]);

        if (is_array($search) && !empty($search['components'])) {
            return self::assignVulnerabilitiesOnlyProfile($port, $user, $pass, $projectKey);
        }

        $created = self::apiPost($port, $user, $pass, '/api/projects/create', [
            'project' => $projectKey,
            'name'    => $projectName,
        ]);

        if ($created === null) {
            Output::error('Failed to create SonarQube project. Check SONARQUBE_ADMIN_USER / SONARQUBE_ADMIN_PASSWORD in .env.');
            return false;
        }

        Output::success('Created SonarQube project: ' . $projectKey);

        return self::assignVulnerabilitiesOnlyProfile($port, $user, $pass, $projectKey);
    }

    public static function assignVulnerabilitiesOnlyProfile(int $port, string $user, string $pass, string $projectKey): bool
    {
        if (!self::ensureVulnerabilitiesOnlyProfile($port, $user, $pass)) {
            return false;
        }

        $result = self::apiPost($port, $user, $pass, '/api/qualityprofiles/set_project', [
            'project'        => $projectKey,
            'qualityProfile' => self::VULNERABILITIES_ONLY_PROFILE,
            'language'       => self::PROFILE_LANGUAGE,
        ]);

        if ($result === null) {
            Output::error('Failed to assign vulnerabilities-only quality profile to project.');
            return false;
        }

        return true;
    }

    public static function ensureVulnerabilitiesOnlyProfile(int $port, string $user, string $pass): bool
    {
        $search = self::apiGet($port, $user, $pass, '/api/qualityprofiles/search', [
            'qualityProfile' => self::VULNERABILITIES_ONLY_PROFILE,
            'language'       => self::PROFILE_LANGUAGE,
        ]);

        if (is_array($search) && !empty($search['profiles'][0]['key'])) {
            return true;
        }

        $created = self::apiPost($port, $user, $pass, '/api/qualityprofiles/create', [
            'name'     => self::VULNERABILITIES_ONLY_PROFILE,
            'language' => self::PROFILE_LANGUAGE,
        ]);

        if (! is_array($created) || empty($created['profile']['key'])) {
            Output::error('Failed to create vulnerabilities-only quality profile.');
            return false;
        }

        $profileKey = (string) $created['profile']['key'];

        $activated = self::apiPost($port, $user, $pass, '/api/qualityprofiles/activate_rules', [
            'targetKey' => $profileKey,
            'types'     => 'VULNERABILITY',
            'languages' => self::PROFILE_LANGUAGE,
        ]);

        if ($activated === null) {
            Output::warning('Could not bulk-activate vulnerability rules; profile may be empty.');
        }

        Output::success('Created quality profile: ' . self::VULNERABILITIES_ONLY_PROFILE);

        return true;
    }

    public static function vulnerabilitiesDashboardUrl(int $port, string $projectKey): string
    {
        return 'http://localhost:' . $port
            . '/project/issues?id=' . rawurlencode($projectKey)
            . '&types=VULNERABILITY';
    }

    public static function generateScanToken(int $port, string $user, string $pass, string $name): ?string
    {
        $result = self::apiPost($port, $user, $pass, '/api/user_tokens/generate', [
            'name' => $name,
        ]);

        if (!is_array($result) || empty($result['token'])) {
            Output::error('Failed to generate SonarQube scan token.');
            return null;
        }

        return (string) $result['token'];
    }

    public static function port(): int
    {
        $port = Env::get('SONARQUBE_PORT') ?? Env::get('SONARQUBE_PORT_FORWARDED');

        return is_numeric($port) ? (int) $port : 9000;
    }

    public static function adminUser(): string
    {
        $user = Env::get('SONARQUBE_ADMIN_USER');

        return ($user !== null && $user !== '') ? (string) $user : 'admin';
    }

    public static function adminPassword(): string
    {
        $pass = Env::get('SONARQUBE_ADMIN_PASSWORD');

        return ($pass !== null && $pass !== '') ? (string) $pass : 'admin';
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>|null
     */
    public static function apiGet(int $port, string $user, string $pass, string $path, array $query = []): ?array
    {
        return self::apiRequest('GET', $port, $user, $pass, $path, $query);
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>|null
     */
    public static function apiPost(int $port, string $user, string $pass, string $path, array $query = []): ?array
    {
        return self::apiRequest('POST', $port, $user, $pass, $path, $query);
    }

    public static function writeEnvValue(string $key, string $value): void
    {
        $path = ROOT_PATH . '.env';
        if (!is_file($path)) {
            return;
        }

        $content = (string) file_get_contents($path);
        $line    = $key . '=' . $value;

        if (preg_match('/^' . preg_quote($key, '/') . '=/m', $content) === 1) {
            $content = preg_replace('/^' . preg_quote($key, '/') . '=.*/m', $line, $content);
        } else {
            $content = rtrim($content) . "\n" . $line . "\n";
        }

        file_put_contents($path, $content);
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>|null
     */
    private static function apiRequest(string $method, int $port, string $user, string $pass, string $path, array $query = []): ?array
    {
        $url = 'http://127.0.0.1:' . $port . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => 'Authorization: Basic ' . base64_encode($user . ':' . $pass) . "\r\n",
                'ignore_errors' => true,
                'timeout'       => 30,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
