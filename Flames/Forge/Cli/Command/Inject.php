<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

use Flames\Forge\Cli\Output;
use Flames\Server\Os;

/**
 * Sets up the global "forge" launcher so the user can run "forge" from any
 * directory without typing "./forge".
 *
 * On Unix: installs a shell wrapper in ~/.local/bin/forge and ensures
 *          ~/.local/bin is in PATH via ~/.bashrc.
 * On Windows: displays manual instructions (PATH setup is environment-specific).
 *
 * @internal
 */
final class Inject
{
    private const WRAPPER_CONTENT = <<<'BASH'
#!/bin/bash
# Global forge launcher — walks up the directory tree to find a project's forge file.
dir="$PWD"
while [ "$dir" != "/" ]; do
    if [ -f "$dir/forge" ] && [ -x "$dir/forge" ]; then
        exec "$dir/forge" "$@"
    fi
    dir="$(dirname "$dir")"
done
echo "forge: No forge file found in the current or any parent directory." >&2
exit 1
BASH;

    private const PATH_SNIPPET = 'if [ -d "$HOME/.local/bin" ]; then export PATH="$HOME/.local/bin:$PATH"; fi';

    public function __construct(mixed $data) {}

    public function run(bool $debug = false, bool $silent = false): bool
    {
        if (!Os::isUnix()) {
            if (!$silent) {
                Output::warning('Automatic setup is only supported on Unix/Linux/macOS.');
                Output::info('Add the project directory to your PATH manually, or run forge via: php forge {command}');
            }
            return false;
        }

        $home = $_SERVER['HOME'] ?? posix_getpwuid(posix_getuid())['dir'] ?? null;
        if ($home === null) {
            Output::error('Could not determine home directory.');
            return false;
        }

        $localBin    = $home . '/.local/bin';
        $wrapperPath = $localBin . '/forge';

        // ── 1. Create ~/.local/bin if needed ──────────────────────────────────
        if (!is_dir($localBin)) {
            mkdir($localBin, 0755, true);
            if (!$silent) {
                Output::success("Created {$localBin}");
            }
        }

        // ── 2. Write the global wrapper (skip if already identical) ──────────
        $alreadyInstalled = file_exists($wrapperPath)
            && file_get_contents($wrapperPath) === self::WRAPPER_CONTENT;

        if ($alreadyInstalled) {
            if (!$silent) {
                Output::info("Global wrapper already installed → {$wrapperPath}");
            }
        } else {
            file_put_contents($wrapperPath, self::WRAPPER_CONTENT);
            chmod($wrapperPath, 0755);
            if (!$silent) {
                Output::success("Installed global wrapper → {$wrapperPath}");
            }
        }

        // ── 3. Add ~/.local/bin to PATH in ~/.bashrc ──────────────────────────
        $bashrc       = $home . '/.bashrc';
        $bashrcExists = file_exists($bashrc);
        $bashrcAlready = $bashrcExists && str_contains((string)file_get_contents($bashrc), '.local/bin');

        if ($bashrcAlready) {
            if (!$silent) {
                Output::info('~/.local/bin already present in ~/.bashrc — skipping.');
            }
        } else {
            file_put_contents($bashrc, "\n# forge global launcher\n" . self::PATH_SNIPPET . "\n", FILE_APPEND);
            if (!$silent) {
                Output::success('Added ~/.local/bin to PATH in ~/.bashrc');
            }
        }

        if ($alreadyInstalled && $bashrcAlready) {
            if (!$silent) {
                Output::blank();
                Output::info('forge is already fully injected. Nothing was changed.');
            }
            return true;
        }

        if (!$silent) {
            Output::blank();
            Output::info('Run the following to activate forge in the current session:');
            echo '    ' . Output::CYAN . 'source ~/.bashrc' . Output::RESET . "\n";
        }

        return true;
    }
}
