<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

use Flames\Crypto\Hash;
use Flames\Environment;
use Flames\Server\Os;

/**
 * @internal
 */
final class Install
{
    public function __construct(mixed $data) {}

    public function run(bool $debug = false): bool
    {
        $this->copyEnv();
        $this->copyIndex();
        $this->copyHtaccess();
        $this->copyForge();
        $this->copyDockerCompose();
        $this->generateKeys();

        if (Os::isUnix()) {
            (new Inject(null))->run(false, true);
        }

        return true;
    }

    private function copyEnv(): void
    {
        $envPath = ROOT_PATH . '.env';
        if (file_exists($envPath)) {
            return;
        }

        $examplePath = ROOT_PATH . 'vendor/flamesphp/example/.env.example';
        if (file_exists($examplePath)) {
            copy($examplePath, $envPath);
        }
    }

    private function copyIndex(): void
    {
        $examplePath = ROOT_PATH . 'vendor/flamesphp/example/index.php';
        if (file_exists($examplePath)) {
            copy($examplePath, ROOT_PATH . 'index.php');
        }

        $publicDir = ROOT_PATH . 'public';
        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }

        $examplePublicPath = ROOT_PATH . 'vendor/flamesphp/example/public/index.php';
        if (file_exists($examplePublicPath)) {
            copy($examplePublicPath, $publicDir . '/index.php');
        }
    }

    private function copyHtaccess(): void
    {
        $htaccessPath = ROOT_PATH . '.htaccess';
        if (file_exists($htaccessPath)) {
            return;
        }

        $examplePath = ROOT_PATH . 'vendor/flamesphp/example/.htaccess';
        if (file_exists($examplePath)) {
            copy($examplePath, $htaccessPath);
        }
    }

    private function copyForge(): void
    {
        $forgePath         = ROOT_PATH . 'forge';
        $forgeResourcePath = ROOT_PATH . 'vendor/flamesphp/forge/resource/forge';

        if (!file_exists($forgeResourcePath)) {
            return;
        }

        $shouldCopy = !file_exists($forgePath)
            || file_get_contents($forgePath) !== file_get_contents($forgeResourcePath);

        if ($shouldCopy) {
            copy($forgeResourcePath, $forgePath);
            chmod($forgePath, 0755);
        }
    }

    private function copyDockerCompose(): void
    {
        $dockerComposePath = ROOT_PATH . 'docker-compose.yml';
        if (file_exists($dockerComposePath)) {
            return;
        }

        $sourcePath = ROOT_PATH . 'vendor/flamesphp/docker/resources/docker-compose.yml';
        if (file_exists($sourcePath)) {
            copy($sourcePath, $dockerComposePath);
        }
    }

    private function generateKeys(): void
    {
        $environment = Environment::default();
        if (!$environment->isValid()) {
            return;
        }

        $changed = false;

        if (empty((string)$environment->APP_KEY)) {
            $environment->APP_KEY = Hash::getRandom();
            $changed = true;
        }

        if (empty((string)$environment->CRYPTO_KEY)) {
            $environment->CRYPTO_KEY = Hash::getRandom();
            $changed = true;
        }

        if ($changed) {
            $environment->save();
        }
    }
}
