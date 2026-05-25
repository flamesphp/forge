<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command\Schedules;

use Flames\Forge\Cli\Output;
use Flames\Server\Process;

/**
 * @internal
 */
final class Stop
{
    private readonly string|null $target;

    public function __construct(mixed $data)
    {
        $this->target = $data->argument[0] ?? null;
    }

    public function run(bool $debug = false): bool
    {
        if (empty($this->target)) {
            Output::error('Usage: schedule stop {name|pid}');
            return false;
        }

        $cacheDir = ROOT_PATH . '.cache/.flames/schedules/';
        $locks    = glob($cacheDir . '*.*.lock') ?: [];

        if (empty($locks)) {
            Output::warning('No running schedules found.');
            return true;
        }

        $running = [];
        $seq     = 1;

        foreach ($locks as $lockFile) {
            $data = json_decode((string)file_get_contents($lockFile), true);
            if ($data === null) {
                continue;
            }

            $pid = (int)($data['pid'] ?? 0);
            if ($pid <= 0 || !Process::isRunning($pid)) {
                @unlink($lockFile);
                continue;
            }

            $running[] = [
                'id'       => $seq++,
                'name'     => $data['name']    ?? '',
                'pid'      => $pid,
                'lockFile' => $lockFile,
            ];
        }

        if (empty($running)) {
            Output::warning('No running schedules found.');
            return true;
        }

        $killed = 0;
        foreach ($running as $entry) {
            $match = (string)$entry['id']  === $this->target
                  || (string)$entry['pid'] === $this->target
                  || $entry['name']        === $this->target;

            if (!$match) {
                continue;
            }

            self::killPid($entry['pid']);
            @unlink($entry['lockFile']);

            Output::success("Stopped schedule '{$entry['name']}' (pid: {$entry['pid']}).");
            $killed++;
        }

        if ($killed === 0) {
            Output::warning("No running schedule matched '{$this->target}'.");
            return false;
        }

        Output::blank();
        return true;
    }

    private static function killPid(int $pid): void
    {
        if (\Flames\Server\Os::isUnix()) {
            exec('kill -9 ' . $pid);
            return;
        }
        exec('taskkill /pid ' . $pid . ' /F');
    }
}
