<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command\Crypto\Key;

use Flames\Crypto\Hash;
use Flames\Environment;

/**
 * Generates (or regenerates) the cryptography key in .env.
 *
 * @internal
 */
final class Generate
{
    public function __construct(mixed $data) {}

    public function run(bool $debug = false): bool
    {
        $environment = Environment::default();
        if (!$environment->isValid()) {
            return false;
        }

        $environment->CRYPTO_KEY = Hash::getRandom();
        $environment->save();

        return true;
    }
}
