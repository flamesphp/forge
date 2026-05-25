<?php

declare(strict_types=1);

namespace Flames\Forge\Cli;

use Flames\Collection\Arr;

/**
 * Parses command-line arguments into a structured Arr object.
 *
 * @internal
 */
final class Data
{
    /**
     * Retrieves data from the given array of arguments or from $_SERVER['argv'].
     */
    public static function getData(?array $args = null): Arr
    {
        $args ??= $_SERVER['argv'];

        $data = Arr([
            'command'   => null,
            'argument'  => Arr(),
            'option'    => Arr(),
            'parameter' => Arr(),
        ]);

        // argv[0] = script path; argv[1] = command
        $command = $args[1] ?? null;
        if ($command !== null) {
            $data->command = $command;
        }

        $count = count($args);
        for ($i = 2; $i < $count; $i++) {
            $arg = $args[$i];

            if (!str_starts_with($arg, '-')) {
                $data->argument[] = $arg;
                continue;
            }

            if (str_starts_with($arg, '--')) {
                $data->option[] = substr($arg, 2);
                continue;
            }

            // Single-dash parameter: -key or -key=value
            $inner = substr($arg, 1);
            $eqPos = strpos($inner, '=');
            if ($eqPos === false) {
                $data->parameter[$inner] = null;
            } else {
                $data->parameter[substr($inner, 0, $eqPos)] = substr($inner, $eqPos + 1);
            }
        }

        return $data;
    }
}
