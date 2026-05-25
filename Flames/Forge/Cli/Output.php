<?php

declare(strict_types=1);

namespace Flames\Forge\Cli;

/**
 * @internal
 */
final class Output
{
    private static ?bool $isCli = null;

    // ANSI escape sequences
    public const RESET  = "\033[0m";
    public const BOLD   = "\033[1m";
    public const DIM    = "\033[2m";
    public const GREEN  = "\033[32m";
    public const YELLOW = "\033[33m";
    public const BLUE   = "\033[34m";
    public const CYAN   = "\033[36m";
    public const WHITE  = "\033[97m";
    public const GRAY   = "\033[90m";
    public const RED    = "\033[31m";
    public const ORANGE = "\033[38;5;208m";

    public static function line(string $text = ''): void
    {
        self::echo($text . "\n");
    }

    public static function blank(): void
    {
        self::echo("\n");
    }

    public static function success(string $text): void
    {
        self::echo(self::GREEN . self::BOLD . '  ✔  ' . self::RESET . self::GREEN . $text . self::RESET . "\n");
    }

    public static function error(string $text): void
    {
        self::echo(self::RED . self::BOLD . '  ✘  ' . self::RESET . self::RED . $text . self::RESET . "\n");
    }

    public static function info(string $text): void
    {
        self::echo(self::CYAN . '  ℹ  ' . self::RESET . $text . "\n");
    }

    public static function warning(string $text): void
    {
        self::echo(self::YELLOW . self::BOLD . '  ⚠  ' . self::RESET . self::YELLOW . $text . self::RESET . "\n");
    }

    public static function section(string $title): void
    {
        self::echo("\n" . self::YELLOW . self::BOLD . '  ' . strtoupper($title) . self::RESET . "\n");
    }

    /**
     * Prints a command row with aligned columns.
     * $command is rendered in green, $description in gray.
     */
    public static function command(string $command, string $description = '', int $width = 42): void
    {
        $pad = max(1, $width - strlen($command));
        echo '    ' . self::GREEN . $command . self::RESET
            . str_repeat(' ', $pad)
            . self::GRAY . $description . self::RESET . "\n";
    }

    public static function logo(): void
    {
        self::echo(self::ORANGE . self::BOLD . '
    ███████╗██╗      █████╗ ███╗   ███╗███████╗███████╗
    ██╔════╝██║     ██╔══██╗████╗ ████║██╔════╝██╔════╝
    █████╗  ██║     ███████║██╔████╔██║█████╗  ███████╗
    ██╔══╝  ██║     ██╔══██║██║╚██╔╝██║██╔══╝  ╚════██║
    ██║     ███████╗██║  ██║██║ ╚═╝ ██║███████╗███████║
    ╚═╝     ╚══════╝╚═╝  ╚═╝╚═╝     ╚═╝╚══════╝╚══════╝
' . self::RESET);

        self::echo(self::GRAY
            . "    Created by Gabriel 'Kazz' Morgado\n"
            . "    https://github.com/flamesphp/flames\n"
            . self::RESET);

        self::echo(self::GRAY . '    ' . str_repeat('─', 60) . self::RESET . "\n");
    }

    private static function echo(string $message): void
    {
        if (self::$isCli === null) {
            self::$isCli = \Flames\Forge\Cli::isCli();
        }

        if (!self::$isCli) {
            return;
        }

        echo $message;
        @flush();
        @ob_flush();
    }
}
