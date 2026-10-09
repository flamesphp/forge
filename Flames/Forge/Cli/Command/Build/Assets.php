<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command\Build;

use Flames\Surface\Build\Assets as SurfaceAssets;

/**
 * Backward-compatible entry point. Client bundle logic lives in flamesphp/surface.
 *
 * @internal
 */
final readonly class Assets
{
    public const BASE_PATH = SurfaceAssets::BASE_PATH;

    private SurfaceAssets $delegate;

    public function __construct(mixed $data)
    {
        $this->delegate = new SurfaceAssets($data);
    }

    public function run(bool $debug = false): bool
    {
        return $this->delegate->run($debug);
    }
}
