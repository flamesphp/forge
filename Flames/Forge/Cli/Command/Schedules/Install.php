<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command\Schedules;

use Flames\Forge\Cli\Output;
use Flames\Server\Os;

/**
 * Registers "forge schedule run" in the system crontab.
 *
 * @internal
 */
final class Install
{
    public function __construct(mixed $data) {}

    public function run(bool $debug = false): bool
    {
        if (!Os::isUnix()) {
            Output::warning('Crontab setup is only supported on Unix/Linux/macOS.');
            Output::info('Add the following to Windows Task Scheduler:');
            echo '    ' . Output::CYAN . 'php ' . ROOT_PATH . 'forge schedule run' . Output::RESET . "\n";
            return false;
        }

        $php      = $this->findPhpBinary();
        $rootPath = rtrim(ROOT_PATH, '/');
        $logPath  = ROOT_PATH . '.cache/.flames/schedules/cron.log';
        $cronCmd  = "* * * * * cd {$rootPath} && {$php} forge schedule run >> {$logPath} 2>&1";
        $marker   = 'forge schedule run';

        $currentCron = $this->readCrontab();

        if (str_contains($currentCron, $marker)) {
            Output::info('forge schedule run is already registered in crontab.');
            Output::blank();
            $this->printCurrentEntry($currentCron, $marker);
            return true;
        }

        $newCron = rtrim($currentCron) . "\n" . $cronCmd . "\n";
        if (!$this->writeCrontab($newCron)) {
            Output::error('Failed to write crontab. Try running: crontab -e');
            return false;
        }

        Output::success('Crontab entry added successfully.');
        Output::blank();
        echo '    ' . Output::CYAN . $cronCmd . Output::RESET . "\n";
        Output::blank();
        Output::info('Logs will be written to: ' . $logPath);

        return true;
    }

    private function findPhpBinary(): string
    {
        $which = trim((string)shell_exec('which php 2>/dev/null'));
        return $which !== '' ? $which : 'php';
    }

    private function readCrontab(): string
    {
        return (string)shell_exec('crontab -l 2>/dev/null');
    }

    private function writeCrontab(string $content): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'forge_cron_');
        if ($tmp === false) {
            return false;
        }

        file_put_contents($tmp, $content);
        $result = shell_exec('crontab ' . escapeshellarg($tmp) . ' 2>&1');
        unlink($tmp);

        return $result === null || trim((string)$result) === '';
    }

    private function printCurrentEntry(string $cron, string $marker): void
    {
        foreach (explode("\n", $cron) as $line) {
            if (str_contains($line, $marker)) {
                echo '    ' . Output::CYAN . trim($line) . Output::RESET . "\n";
            }
        }
        Output::blank();
    }
}
