<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

use Flames\Forge\Cli\Output;

/**
 * Cache management commands.
 *
 * Usage:
 *   forge cache purge        — clear .cache (everything except .cache/.flames)
 *   forge cache purge kernel — clear .cache/.flames (except environment and schedules)
 *   forge cache purge all    — clear everything including .cache/.flames
 *
 * @internal
 */
final class Cache
{
    private const MODE_PURGE  = 'purge';
    private const MODE_KERNEL = 'kernel';
    private const MODE_ALL    = 'all';

    private readonly string $mode;

    public function __construct(mixed $data)
    {
        $this->mode = match ((string)($data->command ?? '')) {
            'cache purge kernel' => self::MODE_KERNEL,
            'cache purge all'    => self::MODE_ALL,
            default              => self::MODE_PURGE,
        };
    }

    public function run(bool $debug = false): bool
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $cacheDir  = ROOT_PATH . '.cache/';
        $flamesDir = $cacheDir . '.flames/';

        match ($this->mode) {
            self::MODE_PURGE => (function () use ($cacheDir): void {
                $count = $this->clearDir($cacheDir, ['.flames']);
                echo Output::GREEN . "  Cache purged ($count items cleared)." . Output::RESET . "\n";
            })(),

            self::MODE_KERNEL => (function () use ($flamesDir): void {
                $count = $this->clearDir($flamesDir, ['environment', 'schedules']);
                echo Output::GREEN . "  Kernel cache purged ($count items cleared)." . Output::RESET . "\n";
            })(),

            self::MODE_ALL => (function () use ($cacheDir, $flamesDir): void {
                $total = $this->clearDir($flamesDir, []) + $this->clearDir($cacheDir, ['.flames']);
                echo Output::GREEN . "  All cache purged ($total items cleared)." . Output::RESET . "\n";
            })(),
        };

        return true;
    }

    /**
     * Recursively deletes all entries inside $dir, skipping names in $skip.
     * Returns the count of top-level items removed.
     */
    private function clearDir(string $dir, array $skip): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $skipSet = array_flip($skip);
        $count   = 0;

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || isset($skipSet[$entry])) {
                continue;
            }

            $path = $dir . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
            $count++;
        }

        return $count;
    }

    private function removeDir(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
