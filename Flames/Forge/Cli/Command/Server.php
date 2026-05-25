<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

use Flames\Command;
use Flames\Environment;

/**
 * @internal
 */
final class Server
{
    protected string $host = '0.0.0.0';
    protected int    $port = 80;

    public function __construct(mixed $data)
    {
        if ($data->argument->count > 0) {
            $uri = explode(':', (string)$data->argument[0]);
            if (count($uri) === 2) {
                $this->host = $uri[0];
                $this->port = (int)$uri[1];
            }
        }

        if (isset($data->parameter->host)) {
            $this->host = (string)$data->parameter->host;
        }
        if (isset($data->parameter->port)) {
            $this->port = (int)$data->parameter->port;
        }

        if ($this->host === '') {
            $this->host = '0.0.0.0';
        }
        if ($this->port <= 0) {
            $this->port = 80;
        }
    }

    public function run(bool $debug = false): bool
    {
        return \Flames\PHP\Server::run($this->host, $this->port);
    }
}
