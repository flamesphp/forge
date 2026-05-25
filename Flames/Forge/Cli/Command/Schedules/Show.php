<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command\Schedules;

use Flames\Forge\Cli\Output;
use Flames\Kernel\Config;
use Flames\Server\Process;

/**
 * @internal
 */
final class Show
{
    public function __construct(mixed $data) {}

    public function run(bool $debug = false): bool
    {
        $config    = Config::get();
        $schedules = $config['schedules'] ?? [];

        Output::section('Schedule Status');

        if (empty($schedules)) {
            Output::warning('No schedules defined in config.yml.');
            Output::blank();
            return true;
        }

        $cacheDir = ROOT_PATH . '.cache/.flames/schedules/';
        $now      = time();
        $col      = [18, 14, 14, 8, 11];

        self::row(['NAME', 'LAST RUN', 'NEXT RUN', 'PID', 'STATUS'], $col, true);
        self::divider($col);

        foreach ($schedules as $name => $schedule) {
            $every    = $schedule['run']['every'] ?? [];
            $interval = Run::calculateInterval($every);

            $cacheFile = $cacheDir . $name . '.json';
            $lastRun   = null;

            if (file_exists($cacheFile)) {
                $cached  = json_decode((string)file_get_contents($cacheFile), true);
                $lastRun = isset($cached['last_run']) ? (int)$cached['last_run'] : null;
            }

            $elapsed      = $lastRun !== null ? $now - $lastRun : null;
            $lastRunLabel = $elapsed !== null ? self::formatElapsed($elapsed) : 'never';
            $nextRunLabel = ($lastRun !== null && $interval > 0)
                ? self::formatNext(($lastRun + $interval) - $now)
                : ($interval > 0 ? 'now' : '—');

            [$pids, $recentlyDone] = self::getLockStatus($cacheDir, $name, $now);
            $pidStr = empty($pids) ? '—' : implode(',', $pids);

            $status = match (true) {
                !empty($pids)  => Output::GREEN . Output::BOLD . 'running' . Output::RESET,
                $recentlyDone  => Output::CYAN . 'completed' . Output::RESET,
                default        => Output::GRAY . 'idle' . Output::RESET,
            };

            self::row([$name, $lastRunLabel, $nextRunLabel, $pidStr, $status], $col);
        }

        Output::blank();
        return true;
    }

    /**
     * Returns [$runningPids, $recentlyCompleted].
     */
    private static function getLockStatus(string $cacheDir, string $name, int $now): array
    {
        $running      = [];
        $recentlyDone = false;

        foreach (glob($cacheDir . $name . '.*.lock') ?: [] as $lockFile) {
            $data      = json_decode((string)file_get_contents($lockFile), true);
            $pid       = (int)($data['pid']        ?? 0);
            $startedAt = (int)($data['started_at'] ?? 0);

            if ($pid > 0 && Process::isRunning($pid)) {
                $running[] = $pid;
            } elseif (($now - $startedAt) < 30) {
                $recentlyDone = true;
            } else {
                @unlink($lockFile);
            }
        }

        return [$running, $recentlyDone];
    }

    private static function formatElapsed(int $seconds): string
    {
        return match (true) {
            $seconds < 0    => 'just now',
            $seconds < 60   => $seconds . 's ago',
            $seconds < 3600 => floor($seconds / 60) . 'm ago',
            default         => floor($seconds / 3600) . 'h ago',
        };
    }

    private static function formatNext(int $seconds): string
    {
        return match (true) {
            $seconds <= 0   => 'now',
            $seconds < 60   => 'in ' . $seconds . 's',
            $seconds < 3600 => 'in ' . floor($seconds / 60) . 'm',
            default         => 'in ' . floor($seconds / 3600) . 'h',
        };
    }

    private static function row(array $cells, array $widths, bool $header = false): void
    {
        $color = $header ? Output::YELLOW . Output::BOLD : Output::WHITE;
        $line  = '  ';

        foreach ($cells as $i => $cell) {
            $w     = $widths[$i] ?? 14;
            $plain = preg_replace('/\033\[[0-9;]*m/', '', (string)$cell);
            $pad   = max(0, $w - strlen($plain));
            $line .= $color . $cell . Output::RESET . str_repeat(' ', $pad + 2);
        }

        echo $line . "\n";
    }

    private static function divider(array $widths): void
    {
        $line = '  ';
        foreach ($widths as $w) {
            $line .= Output::GRAY . str_repeat('─', $w) . Output::RESET . '  ';
        }
        echo $line . "\n";
    }
}
