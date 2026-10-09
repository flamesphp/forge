<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

use Flames\Forge\Cli\Output;
use Flames\Framework\Cache;

/**
 * Run Flames code upgrades on application or vendor paths.
 *
 * Usage:
 *   forge code upgrade 8.5              — upgrade everything under App/
 *   forge code upgrade 8.2 App/Server   — upgrade a subdirectory of App/
 *   forge code upgrade 8.4 vendor/foo/bar
 *   forge code upgrade 8.5 --preview    — preview changes without saving
 *
 * @internal
 */
final readonly class Upgrade
{
    /** @var array<string, string> normalized suffix (e.g. "85") => LevelSetList constant name */
    private const array LEVEL_SETS = [
        '53' => 'UP_TO_PHP_53',
        '54' => 'UP_TO_PHP_54',
        '55' => 'UP_TO_PHP_55',
        '56' => 'UP_TO_PHP_56',
        '70' => 'UP_TO_PHP_70',
        '71' => 'UP_TO_PHP_71',
        '72' => 'UP_TO_PHP_72',
        '73' => 'UP_TO_PHP_73',
        '74' => 'UP_TO_PHP_74',
        '80' => 'UP_TO_PHP_80',
        '81' => 'UP_TO_PHP_81',
        '82' => 'UP_TO_PHP_82',
        '83' => 'UP_TO_PHP_83',
        '84' => 'UP_TO_PHP_84',
        '85' => 'UP_TO_PHP_85',
        '86' => 'UP_TO_PHP_86',
    ];

    private ?string $phpVersion;
    private ?string $targetPath;
    private bool $dryRun;
    private bool $clearCache;

    public function __construct(mixed $data)
    {
        $args    = array_values((array) ($data->argument ?? []));
        $options = array_values((array) ($data->option ?? []));

        $this->dryRun = in_array('preview', $options, true);
        $this->clearCache = in_array('clear-cache', $options, true);

        $positional = array_values(array_filter(
            $args,
            static fn (string $arg): bool => !str_starts_with($arg, '-'),
        ));

        $this->phpVersion  = isset($positional[0]) ? (string) $positional[0] : null;
        $this->targetPath  = isset($positional[1]) ? (string) $positional[1] : null;
    }

    public function run(bool $debug = false): bool
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        chdir(ROOT_PATH);

        if ($this->phpVersion === null) {
            $this->printUsage();
            return false;
        }

        $upgradeBin = $this->resolveUpgradeBin();
        if ($upgradeBin === null) {
            echo Output::RED . '  flamesphp/code is not installed.' . Output::RESET . "\n";
            echo Output::GRAY . '  From the project root run: php scripts/install-flames-code.php' . Output::RESET . "\n";
            return false;
        }

        if (!is_executable($upgradeBin)) {
            @chmod($upgradeBin, 0755);
        }

        try {
            $setConstant = $this->resolveLevelSet($this->phpVersion);
        } catch (\InvalidArgumentException $e) {
            echo Output::RED . '  ' . $e->getMessage() . Output::RESET . "\n";
            return false;
        }

        $path = $this->resolveTargetPath($this->targetPath);
        if ($path === null) {
            return false;
        }

        $configFile = $this->writeConfig($path, $setConstant);
        if ($configFile === null) {
            return false;
        }

        $command = [
            PHP_BINARY,
            $upgradeBin,
            'process',
            $path,
            '--config=' . $configFile,
            '--ansi',
        ];

        if ($this->dryRun) {
            $command[] = '--dry-run';
        }

        if ($this->clearCache) {
            $command[] = '--clear-cache';
        }

        echo Output::CYAN . Output::BOLD . '  Code Upgrade' . Output::RESET
            . Output::GRAY . '  ' . $setConstant . Output::RESET
            . Output::GRAY . '  →  ' . Output::RESET
            . Output::WHITE . $this->relativePath($path) . Output::RESET . "\n\n";

        $shell = implode(' ', array_map(escapeshellarg(...), $command));
        passthru($shell, $exitCode);

        return ($exitCode ?? 1) === 0;
    }

    private function resolveLevelSet(string $version): string
    {
        $suffix = $this->normalizePhpVersionSuffix($version);

        if (!isset(self::LEVEL_SETS[$suffix])) {
            $available = array_map(
                fn (string $key): string => $this->formatPhpVersionLabel('UP_TO_PHP_' . $key),
                array_keys(self::LEVEL_SETS),
            );

            throw new \InvalidArgumentException(sprintf(
                'Unsupported PHP version "%s". Supported: %s',
                $version,
                implode(', ', $available),
            ));
        }

        return self::LEVEL_SETS[$suffix];
    }

    private function normalizePhpVersionSuffix(string $version): string
    {
        $version = trim($version);

        if (preg_match('/^(\d+)\.(\d+)$/', $version, $matches) === 1) {
            return $matches[1] . $matches[2];
        }

        if (preg_match('/^(\d{2,3})$/', $version, $matches) === 1) {
            return $matches[1];
        }

        throw new \InvalidArgumentException(sprintf(
            'Invalid PHP version "%s". Use formats like 8.5, 8.0, 7.4 or 85.',
            $version,
        ));
    }

    private function formatPhpVersionLabel(string $constant): string
    {
        $suffix = substr($constant, strlen('UP_TO_PHP_'));

        return strlen($suffix) === 2
            ? $suffix[0] . '.' . $suffix[1]
            : $suffix;
    }

    private function resolveTargetPath(?string $path): ?string
    {
        $path ??= 'App';

        if ($path !== '' && $path[0] === '/') {
            $absolute = $path;
        } else {
            $absolute = ROOT_PATH . ltrim(str_replace('\\', '/', $path), '/');
        }

        $real = realpath($absolute);
        if ($real === false || !is_dir($real)) {
            echo Output::RED . '  Path not found: ' . $path . Output::RESET . "\n";
            return null;
        }

        return rtrim($real, '/') . '/';
    }

    private function writeConfig(string $path, string $setConstant): ?string
    {
        $cacheDir = rtrim(Cache::getPath(), '/') . '/flames/code-upgrade/';

        if (!$this->ensureWritableDirectory($cacheDir)) {
            return null;
        }

        $configFile = $cacheDir . 'code-upgrade.php';
        $upgradeCacheDir = $cacheDir . 'code-upgrade-cache';
        $containerCacheDir = $cacheDir . 'code-upgrade-container';

        foreach ([$upgradeCacheDir, $containerCacheDir] as $directory) {
            if (!$this->ensureWritableDirectory($directory)) {
                return null;
            }
        }

        $bootstrapFile = ROOT_PATH . 'vendor/flamesphp/code/resources/upgrade/bootstrap-flames-project.php';
        $vendorFlamesPath = ROOT_PATH . 'vendor/flamesphp';
        $appPath = ROOT_PATH . 'App';

        $content = <<<PHP
<?php

declare(strict_types=1);

use Flames\Code\Upgrade\Config\UpgradeConfig;
use Flames\Code\Upgrade\Set\ValueObject\LevelSetList;

return UpgradeConfig::configure()
    ->withPaths([
        {$this->exportPath($path)},
    ])
    ->withSets([
        LevelSetList::{$setConstant},
    ])
    ->withAutoloadPaths([
        {$this->exportPath($vendorFlamesPath)},
        {$this->exportPath($appPath)},
    ])
    ->withBootstrapFiles([
        {$this->exportPath($bootstrapFile)},
    ])
    ->withSkip([
        '**/installed.php',
        '**/autoload_classmap.php',
        '**/autoload_static.php',
        '**/autoload_files.php',
        '**/autoload_psr4.php',
        '**/autoload_namespaces.php',
        '**/autoload_real.php',
    ])
    ->withCache({$this->exportPath($upgradeCacheDir)}, null, {$this->exportPath($containerCacheDir)});

PHP;

        if (@file_put_contents($configFile, $content) === false) {
            echo Output::RED . '  Cannot write config: ' . $this->relativePath($configFile) . Output::RESET . "\n";
            return null;
        }

        @chmod($configFile, 0666 & ~umask());

        return $configFile;
    }

    private function ensureWritableDirectory(string $directory): bool
    {
        if (!is_dir($directory)) {
            $mask = umask(0);
            $created = @mkdir($directory, 0777, true);
            umask($mask);
            if (!$created) {
                clearstatcache(true, $directory);
                if (!is_dir($directory)) {
                    echo Output::RED . '  Cannot create directory: ' . $this->relativePath($directory) . Output::RESET . "\n";
                    return false;
                }
            }
        } elseif (!is_writable($directory)) {
            echo Output::RED . '  Cannot write in directory: ' . $this->relativePath($directory) . Output::RESET . "\n";
            return false;
        }

        return true;
    }

    private function resolveUpgradeBin(): ?string
    {
        foreach ([
            ROOT_PATH . 'vendor/flamesphp/code/resources/upgrade/bin/code-upgrade',
            ROOT_PATH . 'vendor/bin/code-upgrade',
            ROOT_PATH . 'vendor/flamesphp/code/resources/upgrade/bin/code-upgrade.php',
        ] as $bin) {
            if (is_file($bin)) {
                return $bin;
            }
        }

        return null;
    }

    private function exportPath(string $path): string
    {
        return var_export($path, true);
    }

    private function relativePath(string $absolute): string
    {
        $root = rtrim(ROOT_PATH, '/') . '/';
        return str_starts_with($absolute, $root)
            ? substr($absolute, strlen($root))
            : $absolute;
    }

    private function printUsage(): void
    {
        echo Output::YELLOW . '  Usage:' . Output::RESET . "\n";
        echo Output::GRAY . '    forge code upgrade {php}' . Output::RESET . "\n";
        echo Output::GRAY . '    forge code upgrade {php} {path}' . Output::RESET . "\n";
        echo Output::GRAY . '    forge code upgrade {php} [{path}] --preview' . Output::RESET . "\n";
        echo Output::GRAY . '    forge code upgrade {php} [{path}] --clear-cache' . Output::RESET . "\n\n";
        echo Output::GRAY . '  Examples:' . Output::RESET . "\n";
        echo Output::GRAY . '    forge code upgrade 8.5' . Output::RESET . "\n";
        echo Output::GRAY . '    forge code upgrade 8.2 App/Server' . Output::RESET . "\n";
        echo Output::GRAY . '    forge code upgrade 8.4 vendor/flamesphp/composer' . Output::RESET . "\n";
    }
}
