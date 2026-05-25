<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

/**
 * Executes a serialised coroutine in an isolated PHP process.
 *
 * @internal
 */
final class Coroutine
{
    private static bool $isCoroutineRunning = false;
    private static ?self $currentCoroutine  = null;

    private const BASE_FOLDER = '.cache/coroutine/';

    private ?array $coroutine = null;

    public function __construct(mixed $data)
    {
        $path = ROOT_PATH . self::BASE_FOLDER . $data->argument[0];
        if (!file_exists($path)) {
            return;
        }

        $unserialized = unserialize((string)file_get_contents($path));
        if ($unserialized !== false) {
            $this->coroutine = $unserialized;
        }
    }

    public function run(bool $debug = false): bool
    {
        if ($this->coroutine === null) {
            return false;
        }

        self::$isCoroutineRunning = true;
        self::$currentCoroutine   = $this;

        $class  = new $this->coroutine['caller']();
        $method = $this->coroutine['method'];

        // Deserialize args and pad to 16 positional parameters
        $args = array_map('unserialize', $this->coroutine['args']);
        if (count($args) < 16) {
            $args = array_pad($args, 16, null);
        }

        $response = $class->$method(...$args);
        $buffer   = ob_get_contents();
        @ob_end_clean();

        $hashPath = ROOT_PATH . self::BASE_FOLDER . $this->coroutine['hash'];
        if (file_exists($hashPath)) {
            file_put_contents(
                ROOT_PATH . self::BASE_FOLDER . sha1($this->coroutine['hash']),
                serialize([
                    'buffer'   => $buffer,
                    'response' => serialize($response),
                    'error'    => null,
                ])
            );
        }

        self::$isCoroutineRunning = false;
        return true;
    }

    public static function isCoroutineRunning(): bool
    {
        return self::$isCoroutineRunning;
    }

    public static function errorHandler(): bool
    {
        $buffer = ob_get_contents();
        ob_end_clean();

        $coroutine = self::$currentCoroutine;
        if ($coroutine === null) {
            return true;
        }

        $hashPath = ROOT_PATH . self::BASE_FOLDER . $coroutine->coroutine['hash'];
        if (file_exists($hashPath)) {
            file_put_contents(
                ROOT_PATH . self::BASE_FOLDER . sha1($coroutine->coroutine['hash']),
                serialize([
                    'buffer'   => $buffer,
                    'response' => serialize(null),
                    'error'    => json_encode(error_get_last()),
                ])
            );
        }

        return true;
    }
}
