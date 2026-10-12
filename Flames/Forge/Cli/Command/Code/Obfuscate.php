<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command\Code;

use Flames\Forge\Cli\Output;
use Flames\Js\Security\Obfuscate as JsObfuscate;

/**
 * Obfuscate JavaScript source files in place.
 *
 * Usage:
 *   forge code obfuscate public/assets/app.js
 *   forge code obfuscate public/&flames/app/get
 *   forge code obfuscate path/to/file.js --preview
 *
 * @internal
 */
final readonly class Obfuscate
{
    private ?string $targetPath;
    private bool $preview;

    public function __construct(mixed $data)
    {
        $args    = array_values((array) ($data->argument ?? []));
        $options = array_values((array) ($data->option ?? []));

        $positional = array_values(array_filter(
            $args,
            static fn (string $arg): bool => !str_starts_with($arg, '-'),
        ));

        $this->targetPath = isset($positional[0]) ? (string) $positional[0] : null;
        $this->preview    = in_array('preview', $options, true);
    }

    public function run(bool $debug = false): bool
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        chdir(ROOT_PATH);

        if ($this->targetPath === null) {
            $this->printUsage();

            return false;
        }

        $resolved = $this->resolvePath($this->targetPath);
        if ($resolved === null) {
            return false;
        }

        try {
            $files = $this->collectJsFiles($resolved);
        } catch (\InvalidArgumentException $e) {
            Output::error($e->getMessage());

            return false;
        }

        if ($files === []) {
            Output::error('No .js files found at: ' . $this->relativePath($resolved));

            return false;
        }

        if (!$this->preview) {
            echo Output::CYAN . Output::BOLD . '  Code Obfuscate' . Output::RESET
                . Output::GRAY . '  →  ' . Output::RESET
                . Output::WHITE . $this->relativePath($resolved) . Output::RESET
                . Output::GRAY . '  (' . count($files) . ' file' . (count($files) === 1 ? '' : 's') . ')' . Output::RESET
                . "\n\n";
        }

        $failures = 0;

        foreach ($files as $index => $file) {
            $label = $this->relativePath($file);

            try {
                $source     = (string) file_get_contents($file);
                $obfuscated = JsObfuscate::obfuscate($source);

                if ($this->preview) {
                    if ($index > 0) {
                        echo "\n";
                    }

                    echo Output::CYAN . Output::BOLD . '  # ' . $label . Output::RESET
                        . Output::GRAY . '  (' . strlen($source) . ' → ' . strlen($obfuscated) . ' bytes)' . Output::RESET
                        . "\n\n";
                    echo $obfuscated;
                    if (!str_ends_with($obfuscated, "\n")) {
                        echo "\n";
                    }

                    continue;
                }

                if (file_put_contents($file, $obfuscated) === false) {
                    throw new \RuntimeException('Unable to write file.');
                }

                echo Output::GREEN . '  ✔  ' . Output::RESET . $label . "\n";
            } catch (\Throwable $e) {
                ++$failures;
                echo Output::RED . '  ✘  ' . Output::RESET . $label . Output::GRAY . '  ' . $e->getMessage() . Output::RESET . "\n";
            }
        }

        if ($failures > 0) {
            Output::blank();
            Output::error($failures . ' file(s) failed.');

            return false;
        }

        if (!$this->preview) {
            Output::blank();
            Output::success('Obfuscated ' . count($files) . ' file(s).');
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function collectJsFiles(string $path): array
    {
        if (is_file($path)) {
            if (!str_ends_with(strtolower($path), '.js')) {
                throw new \InvalidArgumentException('Not a JavaScript file: ' . $this->relativePath($path));
            }

            return [$path];
        }

        if (!is_dir($path)) {
            throw new \InvalidArgumentException('Path is not a file or directory: ' . $this->relativePath($path));
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $file = $item->getPathname();
            if (!str_ends_with(strtolower($file), '.js')) {
                continue;
            }

            $files[] = $file;
        }

        sort($files);

        return $files;
    }

    private function resolvePath(string $path): ?string
    {
        $absolute = $path !== '' && $path[0] === '/'
            ? $path
            : ROOT_PATH . ltrim(str_replace('\\', '/', $path), '/');

        $real = realpath($absolute);
        if ($real === false) {
            Output::error('Path not found: ' . $path);

            return null;
        }

        return $real;
    }

    private function relativePath(string $absolute): string
    {
        $root = rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/';
        $path = str_replace('\\', '/', $absolute);

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function printUsage(): void
    {
        Output::error('Missing path argument.');
        echo Output::GRAY . '  Usage: forge code obfuscate {path} [--preview]' . Output::RESET . "\n";
        echo Output::GRAY . '  path: .js file or directory (recursive)' . Output::RESET . "\n";
    }
}
