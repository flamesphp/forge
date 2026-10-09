<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

use Flames\Composer\AutoLoad;
use Flames\Composer\Composer\Console\Application;
use Flames\Composer\Symfony\Component\Console\Input\StringInput;
use Flames\Composer\Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Runs Composer operations programmatically via the scoped Flames\Composer\Composer
 * classes — no CLI binary required.
 *
 * Usage examples:
 *   forge composer require vendor/package
 *   forge composer remove vendor/package
 *   forge composer update
 *   forge composer show
 *   forge composer audit
 *   forge composer validate
 *   forge composer {any-composer-command} [args...]
 *
 * @internal
 */
final readonly class Package
{
    /** @var list<string> */
    private array $args;

    public function __construct(mixed $data)
    {
        $this->args = array_values(array_slice($_SERVER['argv'], 2));
    }

    public function run(bool $debug = false): bool
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        AutoLoad::register();
        chdir(ROOT_PATH);

        $inputStr = empty($this->args)
            ? 'list --ansi'
            : implode(' ', array_map(escapeshellarg(...), $this->args)) . ' --ansi';

        $app = new Application();
        $app->setAutoExit(false);
        $app->setCatchExceptions(true);

        return $app->run(new StringInput($inputStr), new ConsoleOutput()) === 0;
    }
}
