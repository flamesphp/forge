<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

use Flames\Library\AutoLoad;
use Flames\Library\Composer\Console\Application;
use Flames\Library\Symfony\Component\Console\Input\StringInput;
use Flames\Library\Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Runs Composer operations programmatically via the scoped Flames\Library\Composer
 * classes — no CLI binary required.
 *
 * Usage examples:
 *   forge library require vendor/package
 *   forge library remove vendor/package
 *   forge library update
 *   forge library show
 *   forge library audit
 *   forge library validate
 *   forge library {any-composer-command} [args...]
 *
 * @internal
 */
final class Package
{
    /** @var list<string> */
    private readonly array $args;

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
            : implode(' ', array_map('escapeshellarg', $this->args)) . ' --ansi';

        $app = new Application();
        $app->setAutoExit(false);
        $app->setCatchExceptions(true);

        return $app->run(new StringInput($inputStr), new ConsoleOutput()) === 0;
    }
}
