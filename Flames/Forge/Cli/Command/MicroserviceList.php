<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

use Flames\Forge\Cli\Output;
use Flames\Kernel\Config;

/**
 * Lists all microservices defined in config.yml.
 *
 * @internal
 */
final class MicroserviceList
{
    public function __construct(mixed $data) {}

    public function run(bool $debug = false): bool
    {
        $config        = Config::get();
        $microservices = $config['microservices'] ?? [];

        echo "\n" . Output::YELLOW . Output::BOLD . "  MICROSERVICES" . Output::RESET . "\n\n";

        echo '  '
            . Output::GREEN . Output::BOLD . str_pad('App', 20) . Output::RESET
            . Output::GRAY  . '(default)'                        . Output::RESET
            . "\n";

        if (empty($microservices)) {
            Output::blank();
            Output::info('No additional microservices configured in config.yml.');
            Output::blank();
            return true;
        }

        foreach ($microservices as $key => $entry) {
            $namespace = $entry['namespace'] ?? $key;
            $hosts     = $entry['hosts']     ?? [];

            $hostStr = empty($hosts)
                ? Output::GRAY . '(no hosts)' . Output::RESET
                : Output::CYAN . implode(Output::RESET . ', ' . Output::CYAN, $hosts) . Output::RESET;

            echo '  '
                . Output::GREEN . Output::BOLD . str_pad((string)$namespace, 20) . Output::RESET
                . $hostStr
                . "\n";
        }

        echo "\n";
        return true;
    }
}
