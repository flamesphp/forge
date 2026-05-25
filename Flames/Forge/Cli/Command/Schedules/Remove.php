<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command\Schedules;

use Flames\Forge\Cli\Output;
use Flames\Server\Os;

/**
 * Removes the "forge schedule run" crontab entry.
 *
 * @internal
 */
final class Remove
{
    public function __construct(mixed $data) {}

    public function run(bool $debug = false): bool
    {
        if (!Os::isUnix()) {
            Output::warning('Crontab management is only supported on Unix/Linux/macOS.');
            return false;
        }

        $marker      = 'forge schedule run';
        $currentCron = $this->readCrontab();

        if (!str_contains($currentCron, $marker)) {
            Output::info('forge schedule run is not registered in crontab. Nothing to remove.');
            return true;
        }

        $lines   = explode("\n", $currentCron);
        $kept    = [];
        $removed = 0;

        foreach ($lines as $line) {
            if (str_contains($line, $marker)) {
                $removed++;
            } else {
                $kept[] = $line;
            }
        }

        $newCron = rtrim(implode("\n", $kept)) . "\n";

        if (!$this->writeCrontab($newCron)) {
            Output::error('Failed to write crontab. Try running: crontab -e');
            return false;
        }

        $word = $removed === 1 ? 'entry' : 'entries';
        Output::success("Removed {$removed} crontab {$word} for forge schedule run.");
        return true;
    }

    private function readCrontab(): string
    {
        return (string)shell_exec('crontab -l 2>/dev/null');
    }

    private function writeCrontab(string $content): bool
    {
        if (trim($content) === '') {
            shell_exec('crontab -r 2>/dev/null');
            return true;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'forge_cron_');
        if ($tmp === false) {
            return false;
        }

        file_put_contents($tmp, $content);
        $result = shell_exec('crontab ' . escapeshellarg($tmp) . ' 2>&1');
        unlink($tmp);

        return $result === null || trim((string)$result) === '';
    }
}
