<?php

declare(strict_types=1);

namespace Flames\Forge;

/**
 * Shared forge entry-point logic.
 *
 * Handles --native / --container flag parsing, Docker detection & fast routing,
 * then delegates to the Flames Kernel.  Used by every "forge" entry-point file
 * so the same code path runs for both composer and no-composer installs.
 *
 * @internal
 */
final class Kernel
{
    /** Commands that must always run locally (interactive TTY or local-only). */
    private const LOCAL_ONLY = [
        'container' => true,
        'package'   => true,
        'db'        => true,
        'shell'     => true,
    ];

    /** Docker Unix socket paths checked in order. */
    private const DOCKER_SOCKETS = ['/var/run/docker.sock', '/run/docker.sock'];

    /**
     * Boot the forge entry point.
     *
     * @param string $projectRoot Absolute path to the project / framework root.
     * @param string $kernelFile  Path to Kernel.php relative to $projectRoot.
     */
    public static function boot(string $projectRoot, string $kernelFile): never
    {
        // ── 1. Parse routing flags ────────────────────────────────────────────
        $forceNative    = false;
        $forceContainer = false;
        $filteredArgs   = [];

        foreach (array_slice($_SERVER['argv'], 1) as $arg) {
            match ($arg) {
                '--native'    => ($forceNative    = true),
                '--container' => ($forceContainer = true),
                default       => ($filteredArgs[] = $arg),
            };
        }

        $command = $filteredArgs[0] ?? null;

        // ── 2. Docker routing ─────────────────────────────────────────────────
        if (!$forceNative && !isset(self::LOCAL_ONLY[$command])) {
            if ($forceContainer || self::dockerIsRunning()) {
                $container = self::getAppContainer($projectRoot);
                if ($container !== null) {
                    $innerArgs = implode(' ', array_map('escapeshellarg', $filteredArgs));
                    passthru(
                        'docker exec -it ' . escapeshellarg($container) . ' php forge ' . $innerArgs,
                        $exitCode
                    );
                    exit($exitCode ?? 0);
                }
            }
        }

        // ── 3. Restore filtered argv (flags consumed) ─────────────────────────
        $_SERVER['argv'] = [$_SERVER['argv'][0], ...$filteredArgs];
        $_SERVER['argc'] = count($_SERVER['argv']);

        // ── 4. Load kernel ────────────────────────────────────────────────────
        chdir($projectRoot);
        require $projectRoot . DIRECTORY_SEPARATOR . $kernelFile;
        \Flames\Kernel::run();

        exit(0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Docker helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns true when the Docker daemon is reachable and we are NOT already
     * inside a container ourselves.
     *
     * Uses a socket-file check instead of spawning "docker info", which can
     * take ~1 s even when Docker is healthy.  The Unix domain socket is
     * created by dockerd on start and removed on stop, so its presence is a
     * reliable, near-instant proxy for "daemon is running".
     */
    private static function dockerIsRunning(): bool
    {
        if (file_exists('/.dockerenv')) {
            return false;
        }

        foreach (self::DOCKER_SOCKETS as $sock) {
            if (file_exists($sock)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the name of the best-matching running container for this project,
     * or null if none is found.
     */
    private static function getAppContainer(string $projectRoot): ?string
    {
        if (!file_exists($projectRoot . DIRECTORY_SEPARATOR . 'docker-compose.yml')) {
            return null;
        }

        $projectSlug = preg_replace('/[^a-z0-9]/', '', strtolower(basename($projectRoot)));

        exec(
            'docker ps --format "{{.Names}}"'
            . ' --filter "status=running"'
            . ' --filter ' . escapeshellarg('name=' . $projectSlug)
            . ' 2>/dev/null',
            $names,
            $code
        );

        if ($code !== 0) {
            return null;
        }

        $names = array_values(array_filter($names, static fn(string $n): bool => trim($n) !== ''));
        if (empty($names)) {
            return null;
        }

        // Pre-lowercase once — avoids repeated strtolower() inside loops
        $namesLower = array_map('strtolower', $names);

        // 1st pass: project slug + known app-service keywords
        foreach ($namesLower as $i => $n) {
            if (str_contains($n, $projectSlug) &&
                (str_contains($n, 'apache') || str_contains($n, '-app') || str_contains($n, 'php'))) {
                return $names[$i];
            }
        }

        // 2nd pass: any container belonging to this project
        foreach ($namesLower as $i => $n) {
            if (str_contains($n, $projectSlug)) {
                return $names[$i];
            }
        }

        return null;
    }
}
