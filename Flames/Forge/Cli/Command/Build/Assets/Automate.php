<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command\Build\Assets;

use Flames\Environment;

/**
 * Computes a hash of all client-side files to detect changes for auto-build.
 *
 * @internal
 */
final class Automate
{
    private bool $debug = false;

    /** @var list<string>|null */
    private ?array $ignorePaths = null;

    /** @var list<array{path:string,changed:int,type:string}>|null */
    private ?array $files = null;

    public function run(bool $debug = false): bool
    {
        $this->debug = $debug;

        if ($this->debug) {
            echo 'Current modified hash: ' . $this->getCurrentHash();
        }

        return true;
    }

    public function getCurrentHash(): string
    {
        $ignorePath = Environment::get('AUTO_BUILD_IGNORE_PATHS');
        if ($ignorePath !== null) {
            $parts = explode(',', (string)$ignorePath);
            if (!empty($parts)) {
                $this->ignorePaths = $parts;
            }
        }

        $this->buildFileTimes();
        return sha1(serialize($this->files));
    }

    private function buildFileTimes(): void
    {
        $this->files = [];

        $envFile = ROOT_PATH . '.env';
        if (file_exists($envFile)) {
            $this->files[] = [
                'path'    => $envFile,
                'changed' => filemtime($envFile),
                'type'    => 'config',
            ];
        }

        $this->collectFiles(APP_PATH . 'Client/View/',   'view',   ['.twig']);
        $this->collectFiles(APP_PATH . 'Client/Public/', 'public', ['.css', '.scss', '.js', '.sass']);
        $this->collectFiles(APP_PATH . 'Client/',        'client',  ['.php'], [
            APP_PATH . 'Client/Public/',
            APP_PATH . 'Client/View/',
            APP_PATH . 'Client/Resource/',
        ]);
    }

    /**
     * Adds matching files from a directory to $this->files using the iterator's
     * built-in getMTime() — avoids an extra filemtime() syscall per file.
     *
     * @param list<string> $extensions  Lowercase extensions to accept (with dot).
     * @param list<string> $excludeDirs Absolute path prefixes to skip.
     */
    private function collectFiles(string $dir, string $type, array $extensions, array $excludeDirs = []): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                continue;
            }

            $path = $item->getPathname();

            foreach ($excludeDirs as $excl) {
                if (str_starts_with($path, $excl)) {
                    continue 2;
                }
            }

            if ($this->ignorePaths !== null && $this->isIgnored($path)) {
                continue;
            }

            $ext = strtolower('.' . $item->getExtension());
            if (!in_array($ext, $extensions, true)) {
                continue;
            }

            $this->files[] = [
                'path'    => $path,
                'changed' => $item->getMTime(),
                'type'    => $type,
            ];
        }
    }

    private function isIgnored(string $path): bool
    {
        foreach ($this->ignorePaths as $ignored) {
            if (str_starts_with($path, ROOT_PATH . $ignored)) {
                return true;
            }
        }
        return false;
    }
}
