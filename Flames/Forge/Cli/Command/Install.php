<?php

namespace Flames\Forge\Cli\Command;

use Flames\Crypto\Hash;
use Flames\Environment;
use Flames\Server\Os;

/**
 * @internal
 */
final class Install
{
    public function __construct($data) {}

    public function run(bool $debug = false): bool
    {
        $this->copyEnv();
        $this->copyIndex();
        $this->copyHtaccess();
        $this->copyForge();
        $this->generateKeys();

        if (Os::isUnix()) {
            $inject = new Inject(null);
            $inject->run(false, true);
        }

        return true;
    }

    private function copyEnv(): void
    {
        $envPath = ROOT_PATH . '.env';
        if (file_exists($envPath) === true) {
            return;
        }

        $examplePath = ROOT_PATH . 'vendor/flamesphp/example/.env.example';
        if (file_exists($examplePath) === true) {
            copy($examplePath, $envPath);
        }
    }

    private function copyIndex(): void
    {
        $indexPath = ROOT_PATH . 'index.php';
        if (file_exists($indexPath) === true) {
            return;
        }

        $examplePath = ROOT_PATH . 'vendor/flamesphp/example/index.php';
        if (file_exists($examplePath) === true) {
            copy($examplePath, $indexPath);
        }
    }

    private function copyHtaccess(): void
    {
        $htaccessPath = ROOT_PATH . '.htaccess';
        if (file_exists($htaccessPath) === true) {
            return;
        }

        $examplePath = ROOT_PATH . 'vendor/flamesphp/example/.htaccess';
        if (file_exists($examplePath) === true) {
            copy($examplePath, $htaccessPath);
        }
    }

    private function copyForge(): void
    {
        $forgePath        = ROOT_PATH . 'forge';
        $forgeResourcePath = ROOT_PATH . 'vendor/flamesphp/forge/resource/forge';

        if (file_exists($forgeResourcePath) === false) {
            return;
        }

        $shouldCopy = (file_exists($forgePath) === false);
        if ($shouldCopy === false) {
            $shouldCopy = (file_get_contents($forgePath) !== file_get_contents($forgeResourcePath));
        }

        if ($shouldCopy === true) {
            copy($forgeResourcePath, $forgePath);
            @chmod($forgePath, 0755);
        }
    }

    private function generateKeys(): void
    {
        $environment = Environment::default();
        if ($environment->isValid() === false) {
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

        if ($changed === true) {
            $environment->save();
        }
    }
}
