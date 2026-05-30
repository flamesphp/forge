<?php

declare(strict_types=1);

namespace Flames\Forge;

use Flames\Framework\StaticData;

/**
 * Detects whether the current execution context is a CLI forge invocation.
 */
final class Cli
{
    /**
     * Returns true when running as the forge CLI tool (not as a web request).
     */
    public static function isCli(): bool
    {
        return once(function() {
            $base = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
            return ($base === 'forge');
        });
    }
}

