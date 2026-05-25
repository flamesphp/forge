<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command\Build\App;

use Flames\Collection\Arr;
use Flames\Collection\Strings;
use Flames\Environment;
use Flames\Kernel;
use Flames\Kernel\Tools\WinIco;
use Flames\Process;
use Flames\Server\Shell;
use ZipArchive;

class Native
{
    protected const APP_NATIVE_KEY_SALT = 'e196f36370deafd5377d377458185484a18d9cc7';
    protected const ICON_INSTALLER_MAX_SIZE = 256;

    protected bool $debug = false;
    protected string $buildPath;
    protected string $assetsPath;
    protected Arr|null $inputs;

    protected static bool $isRunningBuild = false;

    protected ?string $platform = null;

    protected bool $installer = false;
    protected bool $run = false;

    public function __construct(mixed $data)
    {
        if ($data->option->contains('windows')) { $this->platform = 'windows'; }
        if ($data->option->contains('linux'))   { $this->platform = 'linux'; }
        $this->installer = (bool)($data->option->contains('installer') ?? false);
        $this->run       = (bool)($data->option->contains('run')       ?? false);
    }

    public function run(bool $debug = false): bool
    {
        if (self::$isRunningBuild) {
            return false;
        }

        self::$isRunningBuild = true;

        $this->debug = $debug;

        $this->buildPath  = ROOT_PATH . '.cache/build-native/';
        $this->assetsPath = FLAMES_PATH . 'Cli/Command/Build/App/Native/Desktop/';

        $this->checkBuildPath();
        $this->cleanBuild();

        if ($this->verifyDependencies() === false) { return false; }

        if ($this->mountNodeApp()      === false) { return false; }
        if ($this->installNodeModules() === false) { return false; }
        if ($this->installElectron()   === false) { return false; }
        if ($this->prepareApp()        === false) { return false; }
        if ($this->buildIcon()         === false) { return false; }
        if ($this->buildApp()          === false) { return false; }
        if ($this->packBuild()         === false) { return false; }

        self::$isRunningBuild = false;
        return true;
    }

    protected function checkBuildPath(): void
    {
        if (!is_dir($this->buildPath)) {
            $mask = umask(0);
            mkdir($this->buildPath, 0777, true);
            umask($mask);
        }
    }

    protected function verifyDependencies(): bool
    {
        $process    = new Shell('npm -v');
        $npmVersion = $process->getOutput();
        $output     = (int)$npmVersion;

        if ($process->getCode() !== Shell\Code::CODE_SUCCESS || $output === 0) {
            $this->log("NPM not found.\n");
            $this->reportNodeMissing();
            return false;
        }

        $process    = new Shell('npx -v');
        $npxVersion = $process->getOutput();
        $output     = (int)$npxVersion;

        if ($process->getCode() !== Shell\Code::CODE_SUCCESS || $output === 0) {
            $this->log("NPX not found.\n");
            $this->reportNodeMissing();
            return false;
        }

        if (!\Flames\Server\Os::isWindows()) {
            $process    = new Shell('rpmbuild --version');
            $rpmVersion = Strings::split($process->getOutput(), ' ');
            $rpmVersion = $rpmVersion->last;
            $output     = (int)$rpmVersion;

            if ($process->getCode() !== Shell\Code::CODE_SUCCESS || $output === 0) {
                $this->log("RPM not found.\n");
                $this->log("Install RPM on Ubuntu using command: 'apt install rpm -y'.\n");
                $this->log("Install RPM on others Unix based OS: 'apt install rpmbuild -y'.\n");
                return false;
            }
        }

        $this->log("Dependencies checks: NPM version {$npmVersion} and NPX version {$npxVersion}.\n");
        return true;
    }

    protected function reportNodeMissing(): void
    {
        if (\Flames\Server\Os::isWindows()) {
            $this->log("Please install Node.JS using command: 'choco install nodejs -y'.\n");
            $this->log("Alternatively, you can download the installer from 'https://nodejs.org/en/download'.\n");
        } else {
            $this->log("Please install NodeJS using command: 'apt install nodejs -y'.\n");
        }
    }

    protected function mountNodeApp(): bool
    {
        $packageData = json_decode(file_get_contents($this->assetsPath . 'package.template.json'));

        $appTitle = Environment::get('APP_TITLE');
        if (!empty($appTitle)) { $packageData->name = $appTitle; }
        else { $this->log("Please set APP_TITLE environment variable in .env. Using default value.\n"); sleep(1); }

        $appVersion = Environment::get('APP_VERSION');
        if (!empty($appVersion)) { $packageData->version = $appVersion; }
        else { $this->log("Please set APP_VERSION environment variable in .env. Using default value.\n"); sleep(1); }

        $appAuthor = Environment::get('APP_AUTHOR');
        if (!empty($appAuthor)) { $packageData->author = $appAuthor; }
        else { $this->log("Please set APP_AUTHOR environment variable in .env. Using default value.\n"); sleep(1); }

        $appDescription = Environment::get('APP_DESCRIPTION');
        if (!empty($appDescription)) { $packageData->description = $appDescription; }
        else { $this->log("Please set APP_DESCRIPTION environment variable in .env. Using default value.\n"); sleep(1); }

        file_put_contents($this->buildPath . 'package.json', json_encode($packageData, JSON_PRETTY_PRINT));

        return true;
    }

    protected function installNodeModules(): bool
    {
        $this->log("Installing node modules. It could take up to several minutes...\n");

        $currentPath = getcwd();
        $this->checkBuildPath();
        chdir($this->buildPath);

        $process = new Shell('npm install --force');
        chdir($currentPath);

        if ($process->getCode() !== Shell\Code::CODE_SUCCESS) {
            $this->log("Error installing node modules.\n");
            return false;
        }

        return true;
    }

    protected function installElectron(): bool
    {
        $this->log("Installing Electron. It could take up to several minutes...\n");

        $currentPath = getcwd();
        chdir($this->buildPath);
        $process = new Shell('npm install --save-dev @electron-forge/cli');

        if ($process->getCode() !== Shell\Code::CODE_SUCCESS) {
            chdir($currentPath);
            $this->log("Error installing Electron.\n");
            return false;
        }

        $process = new Shell('npx electron-forge import');
        chdir($currentPath);

        if ($process->getCode() !== Shell\Code::CODE_SUCCESS) {
            $this->log("Error importing Electron.\n");
            return false;
        }

        return true;
    }

    protected function prepareApp(): bool
    {
        file_put_contents($this->buildPath . 'main.js',        file_get_contents($this->assetsPath . 'main.js'));
        file_put_contents($this->buildPath . 'forge.config.js', file_get_contents($this->assetsPath . 'forge.config.js'));

        $assetsAppPath = $this->buildPath . 'Kernel/';
        if (!is_dir($assetsAppPath)) {
            $mask = umask(0);
            mkdir($assetsAppPath, 0777, true);
            umask($mask);
        }

        foreach (['BrowserView.js', 'BrowserWindow.js', 'Register.js', 'Flames.js', 'Initialize.js', 'Setup.js'] as $jsFile) {
            file_put_contents($assetsAppPath . $jsFile, file_get_contents($this->assetsPath . 'Kernel/' . $jsFile));
        }

        $appDomain = Environment::get('APP_DOMAIN');
        if (empty($appDomain)) {
            $this->log("Please set APP_DOMAIN environment variable in .env. Using default value.\n");
            sleep(1);
            $appDomain = 'flamesphp.com';
        }

        $appProtocol = Environment::get('APP_PROTOCOL');
        if (empty($appProtocol)) {
            $this->log("Please set APP_PROTOCOL environment variable in .env. Using default value.\n");
            sleep(1);
            $appProtocol = 'https';
        }

        $appNativeKey = self::getAppNativeKey();
        $domains      = (string)Environment::get('APP_DOMAIN') . ',';
        $appNativeDomains = Environment::get('APP_NATIVE_DOMAINS');
        if (!empty($appNativeDomains)) {
            $domains .= $appNativeDomains . ',';
        }

        if (str_ends_with($domains, ',')) {
            $domains = substr($domains, 0, -1);
        }

        $appEnv = str_replace(
            ['{{ url }}', '{{ appNativeKey }}', '{{ domains }}'],
            [$appProtocol . '://' . $appDomain, $appNativeKey, $domains],
            (string)file_get_contents($this->assetsPath . 'env.template.js')
        );
        file_put_contents($this->buildPath . 'env.js', $appEnv);

        return true;
    }

    protected function buildIcon(): bool
    {
        $buildResourcePath = $this->buildPath . 'Resource/';
        if (!is_dir($buildResourcePath)) {
            $mask = umask(0);
            mkdir($buildResourcePath, 0777, true);
            umask($mask);
        }

        $iconPath = APP_PATH . 'Client/Resource/icon.png';
        if (!file_exists($iconPath)) {
            $iconPath = FLAMES_PATH . 'Kernel/Client/Engine/Flames.png';
            $this->log("App Icon not found, please put at 'App/Client/Resource/icon.png'. Using default value.\n");
            sleep(1);
        }
        copy($iconPath, $buildResourcePath . 'icon.png');

        $winIco = new WinIco($buildResourcePath . 'icon.png');
        $winIco->save($buildResourcePath . 'icon.ico');

        return true;
    }

    protected function buildApp(): bool
    {
        $this->log("Building native app... It could take up to several minutes...\n");

        $currentPath = getcwd();
        chdir($this->buildPath);

        $process = new Shell('npm run make');
        chdir($currentPath);

        if ($process->getCode() !== Shell\Code::CODE_SUCCESS) {
            $this->log("Error building app.\n");
            return false;
        }

        return true;
    }

    protected function packBuild(): bool
    {
        $buildZipPath = APP_PATH . 'Client/Build/';
        if (!is_dir($buildZipPath)) {
            $mask = umask(0);
            mkdir($buildZipPath, 0777, true);
            umask($mask);
        }

        $outputPath = $this->buildPath . 'out/';

        if (\Flames\Server\Os::isWindows()) {
            $squirrelPath = $outputPath . 'make/squirrel.windows/x64/';

            if (is_dir($squirrelPath)) {
                $outputFile = $this->findFileByExtension($squirrelPath, '.nupkg');

                if ($outputFile !== null) {
                    $fileName = 'build_' . $this->getBuildFilePrefix() . '.nupkg';
                    copy($squirrelPath . $outputFile, APP_PATH . 'Client/Build/' . $fileName);
                } else {
                    $this->log("No nupkg build file found in output directory.\n");
                }
            }

            $this->packZip($outputPath);

            if ($this->installer) {
                if ($this->buildInstaller($outputPath) === false) {
                    return false;
                }
            } elseif ($this->run) {
                $this->runBuild($outputPath);
            }
            return true;
        }

        $this->packBuildBundleUnix($outputPath, 'deb');
        $this->packBuildBundleUnix($outputPath, 'rpm');
        if ($this->packZip($outputPath) === false) { return false; }

        if ($this->run) {
            $this->runBuild($outputPath);
        }

        return true;
    }

    protected function packBuildBundleUnix(string $outputPath, string $type): void
    {
        $typeDir = $outputPath . 'make/' . $type . '/x64/';

        if (!is_dir($typeDir)) {
            return;
        }

        $outputFile = $this->findFileByExtension($typeDir, '.' . $type);

        if ($outputFile !== null) {
            $fileName = 'build_' . $this->getBuildFilePrefix() . '.' . $type;
            copy($typeDir . $outputFile, APP_PATH . 'Client/Build/' . $fileName);
        } else {
            $this->log('No ' . $type . " build file found in output directory.\n");
        }
    }

    protected function packZip(string $outputPath): bool
    {
        $outputDir = $this->getPackPath($outputPath);

        if ($outputDir === null) {
            $this->log("No output directory found.\n");
            return false;
        }

        @unlink($outputDir . '/LICENSES.chromium.html');
        @unlink($outputDir . '/Squirrel.exe');

        $this->buildZip($outputDir);

        return true;
    }

    public function getPackPath(string $outputPath): ?string
    {
        $outputDir = null;
        foreach (scandir($outputPath) as $outDir) {
            if ($outDir !== '.' && $outDir !== '..' && $outDir !== 'make') {
                $outputDir = $outputPath . $outDir . '/';
            }
        }

        return $outputDir;
    }

    protected function buildInstaller(string $outputPath): bool
    {
        $this->log("Building windows installer... It could take up to several minutes...\n");

        $outputDir = $this->getPackPath($outputPath);
        $exeFile   = $this->getWindowExecutable($outputDir);

        $issrcPath = $this->verifyIssrc();

        $installerPath = $this->buildPath . 'Installer/';
        if (!is_dir($installerPath)) {
            $mask = umask(0);
            mkdir($installerPath, 0777, true);
            umask($mask);
        }

        $this->buildIconInstaller();
        $appInstallerUuid = $this->getInstallerUuid();

        $appTitle    = Environment::get('APP_TITLE');    if (empty($appTitle))    { $appTitle    = 'Flames'; }
        $appVersion  = Environment::get('APP_VERSION');  if (empty($appTitle))    { $appVersion  = '1.0.0'; }
        $appAuthor   = Environment::get('APP_AUTHOR');   if (empty($appTitle))    { $appAuthor   = 'Flames'; }
        $appDomain   = Environment::get('APP_DOMAIN');   if (empty($appDomain))   { $appDomain   = 'localhost'; }
        $appProtocol = Environment::get('APP_PROTOCOL'); if (empty($appDomain))   { $appDomain   = 'https'; }

        $issData = str_replace(
            ['{{ APP_TITLE }}', '{{ APP_VERSION }}', '{{ APP_AUTHOR }}', '{{ APP_URL }}',
             '{{ APP_UUID }}', '{{ FILE_EXECUTABLE }}', '{{ PATH_INTALLER }}', '{{ PATH_BUILD }}'],
            [$appTitle, $appVersion, $appAuthor, $appProtocol . '://' . $appDomain,
             $appInstallerUuid, $exeFile, $installerPath, $outputDir],
            (string)file_get_contents($this->assetsPath . 'WinInstaller/setup.template.iss')
        );
        file_put_contents($installerPath . 'setup.iss', $issData);

        $process = new Shell($issrcPath . 'iscc.exe "' . $installerPath . 'setup.iss"');
        if ($process->getCode() !== Shell\Code::CODE_SUCCESS) {
            $this->log("Error building installer.\n");
            return false;
        }

        $fileName = 'build_' . $this->getBuildFilePrefix() . '.exe';
        copy($installerPath . 'setup.exe', APP_PATH . 'Client/Build/' . $fileName);

        return true;
    }

    protected function getInstallerUuid(): string
    {
        $installerUuid = Environment::get('APP_INSTALLER_UUID');
        if (empty($installerUuid)) {
            $data    = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            $uuid    = Strings::toUpper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)));

            $env                    = Environment::default();
            $env->APP_INSTALLER_UUID = $uuid;
            $env->save();

            return $env->APP_INSTALLER_UUID;
        }

        return $installerUuid;
    }

    protected function buildIconInstaller(): void
    {
        $iconInstallerPath = $this->buildPath . 'Installer/icon.png';
        copy($this->buildPath . 'Resource/icon.png', $iconInstallerPath);

        [$iconWidth, $iconHeight] = getimagesize($iconInstallerPath);
        $iconWidth  = (int)$iconWidth;
        $iconHeight = (int)$iconHeight;
        $origWidth  = $iconWidth;
        $origHeight = $iconHeight;

        if ($iconWidth > self::ICON_INSTALLER_MAX_SIZE) {
            $iconHeight = (int)((self::ICON_INSTALLER_MAX_SIZE / $iconWidth) * $iconHeight);
            $iconWidth  = self::ICON_INSTALLER_MAX_SIZE;
        }
        if ($iconHeight > self::ICON_INSTALLER_MAX_SIZE) {
            $iconWidth  = (int)((self::ICON_INSTALLER_MAX_SIZE / $iconHeight) * $iconWidth);
            $iconHeight = self::ICON_INSTALLER_MAX_SIZE;
        }

        if ($iconWidth !== $origWidth || $iconHeight !== $origHeight) {
            $iconImageResized = imagecreate($iconWidth, $iconHeight);
            $iconImage        = imagecreatefrompng($iconInstallerPath);
            imagecopyresampled($iconImageResized, $iconImage, 0, 0, 0, 0,
                $iconWidth, $iconHeight, $origWidth, $origHeight);
            imagepng($iconImageResized, $iconInstallerPath);
        }

        $winIco = new WinIco($iconInstallerPath);
        $winIco->save($this->buildPath . 'Installer/icon.ico');
    }

    protected function verifyIssrc(): string
    {
        $issrcPath = ROOT_PATH . '.cache/tools/issrc/';
        if (!is_dir($issrcPath)) {
            $mask = umask(0);
            mkdir($issrcPath, 0777, true);
            umask($mask);
        }

        if (!file_exists($issrcPath . 'ok')) {
            $installPath = $issrcPath . 'install.zip';
            file_put_contents(
                $installPath,
                file_get_contents('https://cdn.jsdelivr.net/gh/flamesphp/cdn@' . Kernel::CDN_VERSION . '/tools/issrc.zip.dat')
            );

            $zip = new ZipArchive();
            $zip->open($installPath);
            $zip->extractTo($issrcPath);
            $zip->close();

            @unlink($installPath);
            file_put_contents($issrcPath . 'ok', '');
        }

        return $issrcPath;
    }

    protected function runBuild(string $outputPath): void
    {
        $outputDir = null;
        foreach (scandir($outputPath) as $outDir) {
            if ($outDir !== '.' && $outDir !== '..' && $outDir !== 'make') {
                $outputDir = $outputPath . $outDir . '/';
            }
        }

        if ($outputDir === null) {
            $this->log("No output directory found. Can't run build.\n");
            return;
        }

        $exeFile = $this->getWindowExecutable($outputDir);
        if ($exeFile === null) {
            $this->log("No executable file found. Can't run build.\n");
            return;
        }

        proc_open('start /b ' . escapeshellarg($outputDir . $exeFile), [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
        ], $pipes);
    }

    protected function getWindowExecutable(string $outputDir): ?string
    {
        return $this->findFileByExtension($outputDir, '.exe');
    }

    protected function getBuildFilePrefix(): string
    {
        $appName  = (string)(Environment::get('APP_NAME') ?? '');
        $pathName = $appName !== '' ? strtolower($appName) . '_' : '';
        return $pathName . (new \DateTimeImmutable())->format('Y_m_d_His');
    }

    protected function buildZip(string $buildPath): void
    {
        $this->log("Building zip file... It could take up to several minutes...\n");

        $buildZipPath = APP_PATH . 'Client/Build/';
        if (!is_dir($buildZipPath)) {
            $mask = umask(0);
            mkdir($buildZipPath, 0777, true);
            umask($mask);
        }

        $appName = (string)(Environment::get('APP_NAME') ?? '');
        $zipName = 'build_' . ($appName !== '' ? strtolower($appName) . '_' : '')
                 . (new \DateTimeImmutable())->format('Y_m_d_His');
        $zipPath = $buildZipPath . $zipName . '.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $buildPathLen = strlen($buildPath);
        foreach ($this->getDirContents($buildPath) as $buildFile) {
            if (!is_dir($buildFile)) {
                $zip->addFile($buildFile, substr($buildFile, $buildPathLen));
            }
        }
        $zip->close();
    }

    protected function cleanBuild(): void
    {
        $currentPath = getcwd();

        if (\Flames\Server\Os::isWindows()) {
            @exec('del /s /q "' . $this->buildPath . '"');
            sleep(1);
            $this->checkBuildPath();
        } else {
            chdir($this->buildPath);
            @exec('rm -rf *');
            chdir($currentPath);
        }

        if (!is_dir($this->buildPath)) {
            return;
        }

        $buildFiles = $this->getDirContents($this->buildPath);
        foreach ($buildFiles as $buildFile) {
            if (is_file($buildFile)) {
                @unlink($buildFile);
            }
        }
        foreach ($buildFiles as $buildFile) {
            if (is_dir($buildFile)) {
                @rmdir($buildFile);
            }
        }

        $this->checkBuildPath();
    }

    protected function getDirContents(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $dirs  = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                $dirs[] = $path;
            } else {
                $files[] = $path;
            }
        }

        return array_merge($files, $dirs);
    }

    protected function log(string $message): void
    {
        echo $message;
        @flush();
        @ob_flush();
    }

    public static function getAppNativeKey(): string
    {
        $appNativeKey = Environment::get('APP_NATIVE_KEY');
        if (empty($appNativeKey)) {
            $appNativeKey = sha1(
                self::APP_NATIVE_KEY_SALT . '.' .
                Environment::get('APP_KEY') . '.' .
                Environment::get('CRYPTO_KEY')
            );

            $env                  = Environment::default();
            $env->APP_NATIVE_KEY  = $appNativeKey;
            $env->save();
        }

        return $appNativeKey;
    }

    /**
     * Scans a directory and returns the first filename ending with $ext, or null.
     */
    private function findFileByExtension(string $dir, string $ext): ?string
    {
        foreach (scandir($dir) ?: [] as $file) {
            if (str_ends_with($file, $ext)) {
                return $file;
            }
        }
        return null;
    }
}
