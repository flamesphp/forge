<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command\Schedules;

use Flames\Forge\Cli\Output;
use Flames\Kernel\Config;
use Flames\Server\Os;
use Flames\Server\Process;

/**
 * @internal
 */
final class Run
{
    /** How long (seconds) the command loops so cron-triggered sub-minute schedules are handled. */
    private const LOOP_DURATION = 59;

    public function __construct(mixed $data) {}

    public function run(bool $debug = false): bool
    {
        $config    = Config::get();
        $schedules = $config['schedules'] ?? [];

        if (empty($schedules)) {
            if ($debug) {
                Output::info('No schedules configured.');
            }
            return true;
        }

        $cacheDir = ROOT_PATH . '.cache/.flames/schedules/';
        if (!is_dir($cacheDir)) {
            $mask = umask(0);
            mkdir($cacheDir, 0777, true);
            umask($mask);
        }

        if (self::hasSubMinuteSchedules($schedules)) {
            $start = time();
            while ((time() - $start) < self::LOOP_DURATION) {
                $tickStart = microtime(true);
                $this->tick($schedules, $cacheDir, $debug);
                $elapsed = microtime(true) - $tickStart;
                $sleep   = max(0, 1_000_000 - (int)($elapsed * 1_000_000));
                if ($sleep > 0) {
                    usleep($sleep);
                }
            }
        } else {
            $this->tick($schedules, $cacheDir, $debug);
        }

        return true;
    }

    private function tick(array $schedules, string $cacheDir, bool $debug): void
    {
        $now    = time();
        $phpBin = PHP_BINARY;

        foreach ($schedules as $name => $schedule) {
            $command     = $schedule['command']            ?? null;
            $every       = $schedule['run']['every']       ?? [];
            $overlapping = $schedule['overlapping']        ?? true;
            $timeout     = $schedule['timeout']['seconds'] ?? null;

            if (empty($command) || empty($every)) {
                continue;
            }

            $interval = self::calculateInterval($every);
            if ($interval <= 0) {
                continue;
            }

            $cacheFile = $cacheDir . $name . '.json';
            $lastRun   = 0;

            if (file_exists($cacheFile)) {
                $cached  = json_decode((string)file_get_contents($cacheFile), true, flags: JSON_THROW_ON_ERROR);
                $lastRun = (int)($cached['last_run'] ?? 0);
            }

            if (($now - $lastRun) < $interval) {
                continue;
            }

            if ($overlapping === false && self::hasRunningLocks($cacheDir, $name)) {
                if ($debug) {
                    Output::warning("Schedule '{$name}' skipped — overlapping=false and still running.");
                }
                continue;
            }

            file_put_contents($cacheFile, json_encode([
                'name'     => $name,
                'command'  => $command,
                'last_run' => $now,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            if ($debug) {
                Output::info("Launching schedule '{$name}' → {$command}");
            }

            $pid = self::launch($name, $command, $phpBin, $timeout, $cacheDir);

            if ($pid > 0) {
                file_put_contents(
                    $cacheDir . $name . '.' . $pid . '.lock',
                    json_encode([
                        'name'       => $name,
                        'command'    => $command,
                        'pid'        => $pid,
                        'started_at' => $now,
                        'timeout'    => $timeout,
                    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
                );

                if ($debug) {
                    Output::success("Schedule '{$name}' running (pid: {$pid}).");
                }
            } elseif ($debug) {
                Output::error("Schedule '{$name}' failed to launch (could not obtain PID).");
            }
        }
    }

    private static function launch(
        string $name,
        string $command,
        string $phpBin,
        int|null $timeout,
        string $cacheDir
    ): int {
        $logFile = $cacheDir . $name . '.log';
        $phpCmd  = escapeshellcmd($phpBin) . ' forge ' . escapeshellarg($command);

        if ($timeout !== null && Os::isUnix()) {
            $phpCmd = 'timeout ' . $timeout . ' ' . $phpCmd;
        }

        $cmd = 'cd ' . escapeshellarg(ROOT_PATH) . ' && ' . $phpCmd;

        if (Os::isUnix()) {
            $fullCmd = $cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
            exec($fullCmd, $output);
            $pid = (int)($output[0] ?? 0);

            if ($pid > 0) {
                usleep(50_000);
            }

            if ($pid > 0 && Os::isLinux() && !file_exists('/proc/' . $pid)) {
                return 0;
            }

            return $pid;
        }

        // Windows fallback
        if ($procSocket = proc_open('start /b ' . $cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes)) {
            $status = proc_get_status($procSocket);
            return (int)($status['pid'] ?? 0);
        }

        return 0;
    }

    private static function hasRunningLocks(string $cacheDir, string $name): bool
    {
        $now = time();
        foreach (glob($cacheDir . $name . '.*.lock') ?: [] as $lockFile) {
            $data      = json_decode((string)file_get_contents($lockFile), true);
            $pid       = (int)($data['pid']        ?? 0);
            $startedAt = (int)($data['started_at'] ?? 0);

            if ($pid > 0 && Process::isRunning($pid)) {
                return true;
            }

            if (($now - $startedAt) >= 30) {
                @unlink($lockFile);
            }
        }
        return false;
    }

    private static function hasSubMinuteSchedules(array $schedules): bool
    {
        foreach ($schedules as $schedule) {
            if (!empty($schedule['run']['every']['second'])) {
                return true;
            }
        }
        return false;
    }

    public static function calculateInterval(array $every): int
    {
        return (int)($every['second'] ?? 0)
             + (int)($every['minute'] ?? 0) * 60
             + (int)($every['hour']   ?? 0) * 3_600
             + (int)($every['day']    ?? 0) * 86_400
             + (int)($every['month']  ?? 0) * 2_592_000;
    }
}
