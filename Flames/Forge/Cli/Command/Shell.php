<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command;

use Flames\Forge\Cli\Output;
use Flames\Dumpper\Dump;

/**
 * @internal
 *
 * Interactive PHP REPL for the Flames framework — analogous to Laravel Tinker.
 * All application classes are available because the Flames autoloader has already run.
 *
 * Features:
 *   - readline with history (~/.flames_shell_history)
 *   - tab-completion for variables, classes, namespaces, and methods
 *   - token-based multi-line buffering (only buffers when { [ ( are unclosed)
 *   - variable persistence across evaluations
 *   - \q, exit, quit to exit
 */
final readonly class Shell
{
    // \x01 / \x02 = RL_PROMPT_START_IGNORE / RL_PROMPT_END_IGNORE
    // These tell readline not to count ANSI bytes when computing cursor position.
    protected const string PROMPT      = "\x01\033[38;5;208m\033[1m\x02flames\x01\033[0m\033[90m\x02>>>\x01\033[0m\x02 ";
    protected const string PROMPT_CONT = "\x01\033[90m\x02...\x01\033[0m\x02 ";
    protected const string HISTORY_FILE = '~/.flames_shell_history';
    private const array   SUPER_GLOBALS = ['_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', '_REQUEST', '_SESSION', 'GLOBALS', 'argv', 'argc'];

    public function __construct(mixed $data) {}

    public function run(bool $debug = false): bool
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $this->printBanner();
        self::configureReadline();

        $historyFile = str_replace('~', $_SERVER['HOME'] ?? '/tmp', self::HISTORY_FILE);

        if (function_exists('readline_read_history') && file_exists($historyFile)) {
            readline_read_history($historyFile);
        }

        $vars   = [];
        $buffer = '';

        // Convert PHP warnings / notices to ErrorException so they are caught by
        // the try-catch inside evaluate() and formatted consistently.
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });

        if (function_exists('readline_completion_function')) {
            readline_completion_function(function (string $input) use (&$vars): array {
                // Completion must never throw — readline would show nothing.
                // Also swallow warnings (PHP 8.5 deprecations, etc.) during completion.
                $previous = set_error_handler(static fn(): bool => true);

                try {
                    $line = function_exists('readline_info')
                        ? (string)readline_info('line_buffer')
                        : $input;

                    return self::complete($input, $line, $vars);
                } catch (\Throwable) {
                    return [];
                } finally {
                    if ($previous !== null) {
                        set_error_handler($previous);
                    }
                }
            });
        }

        try {
            while (true) {
                $prompt = $buffer === '' ? self::PROMPT : self::PROMPT_CONT;
                $line   = $this->readLine($prompt);

                if ($line === false) {
                    echo "\n";
                    break;
                }

                $trimmed = trim($line);

                if (in_array($trimmed, ['\q', 'exit', 'quit', 'exit;', 'quit;'], true)) {
                    break;
                }

                if ($trimmed === '') {
                    if ($buffer !== '') {
                        $this->evaluate($buffer, $vars);
                        $buffer = '';
                    }
                    continue;
                }

                if (function_exists('readline_add_history')) {
                    readline_add_history($line);
                }

                $buffer .= $line . "\n";

                if ($this->isComplete($buffer)) {
                    $this->evaluate($buffer, $vars);
                    $buffer = '';
                }
            }
        } finally {
            restore_error_handler();
        }

        if (function_exists('readline_write_history')) {
            readline_write_history($historyFile);
        }

        return true;
    }

    /** Never append a trailing space after tab-completion (namespaces, methods, …). */
    protected static function configureReadline(): void
    {
        // PHP libedit builds ignore readline_info('completion_append_character').
        if (defined('READLINE_LIB') && READLINE_LIB === 'libedit') {
            return;
        }

        if (!function_exists('readline_info')) {
            return;
        }

        readline_info('completion_append_character', '');
        @readline_info('completion_suppress_append', true);
    }

    /**
     * Prefer the full line_buffer; libedit often passes only "::" or "get" as $input.
     */
    protected static function completionContext(string $input, string $line): string
    {
        if (str_contains($line, '::') || str_contains($line, '->')) {
            return $line;
        }

        if (str_contains($input, '::') || str_contains($input, '->')) {
            return $input;
        }

        return $line !== '' ? $line : $input;
    }

    /**
     * Tab-completion candidates for readline.
     *
     * readline passes only the current word fragment ($input), not the full line.
     * After "$" the fragment is "te" (no dollar sign), so variable context must
     * be detected from the full line buffer.
     *
     * @param array<string, mixed> $vars
     * @return list<string>
     */
    protected static function complete(string $input, string $line, array $vars): array
    {
        $ctx = self::completionContext($input, $line);

        $static = self::resolveStaticCall($input, $line);
        if ($static !== null) {
            $methods = self::completeMethods($static['target'], $static['partial'], $vars, staticOnly: true);

            return self::alignCompletionsToInput($input, $static['prefix'], $methods);
        }

        // Instance member completion: $var->method / $var?->method
        if (preg_match('/\$(?<var>\w+)\s*(?<op>(?:->|\?->))\s*(?<partial>\w*)$/', $ctx, $m)) {
            $partial = self::callMemberPartial($input, $m['partial'], $m['op']);
            $methods = self::completeMethods('$' . $m['var'], $partial, $vars, staticOnly: false);
            $prefix  = '$' . $m['var'] . $m['op'];

            return self::alignCompletionsToInput($input, $prefix, $methods);
        }

        // Variable completion: $partial (readline fragment may omit the "$")
        if (preg_match('/\$(\w*)$/', $ctx, $m)) {
            $partial    = $m[1];
            $withDollar = str_starts_with($input, '$');
            $completions = [];

            foreach (array_keys($vars) as $name) {
                if ($partial === '' || str_starts_with($name, $partial)) {
                    $completions[] = $withDollar ? '$' . $name : $name;
                }
            }

            return $completions;
        }

        // FQCN / namespace completion: \Flames\Env\ partial (not after ::)
        if (
            !str_contains($ctx, '::')
            && preg_match('/(?:^|[(\s,;=+\-*\/&|^!~.?[\{])(?<fqcn>\\\?[\w\\\\]*)$/', $ctx, $m)
            && (str_starts_with($m['fqcn'], '\\') || str_contains($m['fqcn'], '\\'))
        ) {
            return self::completeFqcn($input, $m['fqcn']);
        }

        // PHP built-in function completion (only outside $variable context)
        if ($input === '') {
            return [];
        }

        $lower       = strtolower($input);
        $completions = [];

        foreach (get_defined_functions()['internal'] as $fn) {
            if (str_starts_with($fn, $lower)) {
                $completions[] = $fn;
                if (count($completions) >= 50) {
                    break;
                }
            }
        }

        return $completions;
    }

    /**
     * @return array{target: string, partial: string, prefix: string}|null
     */
    protected static function resolveStaticCall(string $input, string $line): ?array
    {
        foreach ([$line, $input] as $text) {
            if ($text === '') {
                continue;
            }

            $parsed = self::tryParseStaticCall($text);
            if ($parsed === null) {
                continue;
            }

            $parsed['partial'] = self::resolveMethodPartial($input, $line, $parsed);

            return $parsed;
        }

        return null;
    }

    /**
     * @param array{target: string, partial: string, prefix: string} $parsed
     */
    protected static function resolveMethodPartial(string $input, string $line, array $parsed): string
    {
        if ($parsed['partial'] !== '') {
            return $parsed['partial'];
        }

        // libedit word-break at "::" — $input holds the method fragment, $line ends with ::
        if (
            str_contains($line, '::')
            && $input !== ''
            && !str_contains($input, '::')
            && !str_contains($input, '\\')
            && !str_starts_with($input, '$')
        ) {
            return $input;
        }

        if (str_contains($input, '::')) {
            return substr($input, strrpos($input, '::') + 2);
        }

        return '';
    }

    /**
     * @return array{target: string, partial: string, prefix: string}|null
     */
    protected static function tryParseStaticCall(string $text): ?array
    {
        if (!str_contains($text, '::')) {
            return null;
        }

        $pos     = strrpos($text, '::');
        $target  = trim(substr($text, 0, $pos));
        $partial = substr($text, $pos + 2);

        if ($target === '' || !self::isNamespacedStaticTarget($target)) {
            return null;
        }

        return [
            'target'  => $target,
            'partial' => $partial,
            'prefix'  => $target . '::',
        ];
    }

    protected static function isNamespacedStaticTarget(string $target): bool
    {
        if (str_starts_with($target, '$')) {
            return true;
        }

        return str_contains(ltrim($target, '\\'), '\\');
    }

    /** Method/property fragment after :: or -> (readline may pass the whole call as $input). */
    protected static function callMemberPartial(string $input, string $linePartial, string $operator): string
    {
        if (str_contains($input, $operator)) {
            return substr($input, strrpos($input, $operator) + strlen($operator));
        }

        if ($linePartial !== '') {
            return $linePartial;
        }

        if ($input !== '' && !str_contains($input, '\\') && !str_starts_with($input, '$')) {
            return $input;
        }

        return '';
    }

    /**
     * readline/libedit only keeps completions that start with the word being completed
     * ($input). That word varies: "g", "::", "\Flames\Env\Env::get", etc.
     *
     * @param list<string> $members
     * @return list<string>
     */
    protected static function alignCompletionsToInput(string $input, string $callPrefix, array $members): array
    {
        if ($members === []) {
            return [];
        }

        $methodPartial = self::methodPartialFromInput($input, $callPrefix);
        $members       = array_values(array_filter(
            $members,
            static fn(string $member): bool => $methodPartial === '' || str_starts_with($member, $methodPartial)
        ));

        if ($members === []) {
            return [];
        }

        $result = [];

        foreach ($members as $member) {
            $candidate = self::buildCompletionCandidate($input, $member);

            if ($candidate === null) {
                continue;
            }

            if ($input !== '' && !str_starts_with($candidate, $input)) {
                continue;
            }

            $result[$candidate] = true;
        }

        $result = array_keys($result);
        sort($result);

        return array_slice($result, 0, 50);
    }

    /**
     * libedit filters completions with strncmp(text) — every candidate must start with $input.
     * After "\\" word-breaks the word is often "Env::g", not the full FQCN.
     */
    protected static function buildCompletionCandidate(string $input, string $member): ?string
    {
        if ($input === '' || $input === '::') {
            return ($input === '::' ? '::' : '') . $member;
        }

        if (str_contains($input, '::')) {
            return substr($input, 0, strrpos($input, '::') + 2) . $member;
        }

        if (str_starts_with($member, $input)) {
            return $member;
        }

        return null;
    }

    protected static function methodPartialFromInput(string $input, string $callPrefix): string
    {
        if ($input === '' || $input === '::') {
            return '';
        }

        if (str_starts_with($input, $callPrefix)) {
            return substr($input, strlen($callPrefix));
        }

        if (str_contains($input, '::')) {
            return substr($input, strrpos($input, '::') + 2);
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $vars
     * @return list<string>
     */
    protected static function completeMethods(
        string $target,
        string $partial,
        array $vars,
        bool $staticOnly,
    ): array {
        $class = self::resolveClassForCompletion($target, $vars);
        if ($class === null) {
            return [];
        }

        try {
            $ref = new \ReflectionClass($class);
        } catch (\ReflectionException) {
            return [];
        }

        $members = [];

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($staticOnly && !$method->isStatic()) {
                continue;
            }

            $name = $method->getName();

            if (str_starts_with($name, '__') && ($partial === '' || !str_starts_with($name, $partial))) {
                continue;
            }

            if ($partial === '' || str_starts_with($name, $partial)) {
                $members[$name] = true;
            }
        }

        if (!$staticOnly) {
            foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $name = $property->getName();

                if ($partial === '' || str_starts_with($name, $partial)) {
                    $members[$name] = true;
                }
            }
        }

        $result = array_keys($members);
        sort($result);

        return array_slice($result, 0, 50);
    }

    /**
     * @param array<string, mixed> $vars
     */
    protected static function resolveClassForCompletion(string $target, array $vars): ?string
    {
        if (str_starts_with($target, '$')) {
            $name = substr($target, 1);

            if (!isset($vars[$name])) {
                return null;
            }

            $value = $vars[$name];

            if (is_object($value)) {
                return $value::class;
            }

            if (is_string($value) && class_exists($value)) {
                return $value;
            }

            return null;
        }

        $fqcn = ltrim($target, '\\');

        foreach ([$target, '\\' . $fqcn] as $candidate) {
            if (class_exists($candidate)) {
                return ltrim($candidate, '\\');
            }
        }

        // discoverClasses() finds FQCNs on disk; the class may not be autoloaded yet.
        if (in_array($fqcn, self::discoverClasses(), true)) {
            $file = self::classFileForFqcn($fqcn);
            if ($file !== null) {
                require_once $file;
            }

            if (class_exists('\\' . $fqcn, false)) {
                return $fqcn;
            }
        }

        return null;
    }

    protected static function classFileForFqcn(string $fqcn): ?string
    {
        if (str_starts_with($fqcn, 'App\\') && defined('APP_PATH')) {
            $path = APP_PATH . str_replace('\\', DIRECTORY_SEPARATOR, substr($fqcn, 4)) . '.php';

            return is_file($path) ? $path : null;
        }

        if (str_starts_with($fqcn, 'Flames\\') && defined('ROOT_PATH')) {
            $rel = str_replace('\\', DIRECTORY_SEPARATOR, substr($fqcn, 7)) . '.php';

            foreach (glob(ROOT_PATH . 'vendor/flamesphp/*/Flames/' . $rel) ?: [] as $path) {
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected static function completeFqcn(string $input, string $fqcnFragment): array
    {
        $path         = ltrim($fqcnFragment, '\\');
        $leadingSlash = str_starts_with($fqcnFragment, '\\');

        if (str_ends_with($fqcnFragment, '\\') || str_ends_with($path, '\\')) {
            $nsPrefix = rtrim($path, '\\');
            $partial  = self::fqcnPartialFromInput($input, '');
        } elseif (str_contains($path, '\\')) {
            $pos      = strrpos($path, '\\');
            $nsPrefix = substr($path, 0, $pos);
            $partial  = self::fqcnPartialFromInput($input, substr($path, $pos + 1));
        } else {
            $nsPrefix = '';
            $partial  = self::fqcnPartialFromInput($input, $path);
        }

        $prefix  = $nsPrefix === '' ? '' : $nsPrefix . '\\';
        $matches = [];

        foreach (self::discoverClasses() as $class) {
            if ($prefix !== '' && !str_starts_with($class, $prefix)) {
                continue;
            }

            $rest    = $prefix === '' ? $class : substr($class, strlen($prefix));
            $segment = explode('\\', $rest)[0];

            if ($segment === '' || ($partial !== '' && !str_starts_with($segment, $partial))) {
                continue;
            }

            $matches[$segment] = true;
        }

        $segments = array_keys($matches);
        sort($segments);

        return self::alignFqcnCompletions($input, $leadingSlash, $nsPrefix, $segments);
    }

    protected static function fqcnPartialFromInput(string $input, string $fragmentTail): string
    {
        if ($input !== '' && !str_contains($input, '\\')) {
            return $input;
        }

        if ($input !== '' && str_contains($input, '\\')) {
            $tail = substr($input, strrpos($input, '\\') + 1);

            if ($tail !== '') {
                return $tail;
            }
        }

        return $fragmentTail;
    }

    /**
     * libedit filters with strncmp(text) — candidates must start with $input.
     *
     * @param list<string> $segments
     * @return list<string>
     */
    protected static function alignFqcnCompletions(
        string $input,
        bool $leadingSlash,
        string $nsPrefix,
        array $segments,
    ): array {
        if ($segments === []) {
            return [];
        }

        $stem   = ($leadingSlash ? '\\' : '') . ($nsPrefix !== '' ? $nsPrefix . '\\' : '');
        $result = [];

        foreach ($segments as $segment) {
            $full = $stem . $segment;

            if ($input === '' || str_starts_with($segment, $input)) {
                $result[$segment] = true;
            }

            if ($input !== '' && str_starts_with($full, $input)) {
                $result[$full] = true;
            }
        }

        $list = array_keys($result);
        sort($list);

        return array_slice($list, 0, 50);
    }

    /**
     * @param list<string> $classes
     */
    protected static function scanClassPaths(string $dir, string $prefix, array &$classes): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $base = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $rel = substr($file->getPathname(), strlen($base));
            $rel = str_replace(DIRECTORY_SEPARATOR, '\\', $rel);

            if (!str_ends_with($rel, '.php')) {
                continue;
            }

            $classes[] = $prefix . substr($rel, 0, -4);
        }
    }

    /** @return list<string> */
    protected static function discoverClasses(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $classes = [];

        if (defined('APP_PATH') && is_dir(APP_PATH)) {
            self::scanClassPaths(APP_PATH, 'App\\', $classes);
        }

        if (defined('ROOT_PATH')) {
            foreach (glob(ROOT_PATH . 'vendor/flamesphp/*/Flames', GLOB_ONLYDIR) ?: [] as $flamesDir) {
                self::scanClassPaths($flamesDir . DIRECTORY_SEPARATOR, 'Flames\\', $classes);
            }
        }

        foreach (get_declared_classes() as $class) {
            if (str_starts_with($class, 'Flames\\') || str_starts_with($class, 'App\\')) {
                $classes[] = $class;
            }
        }

        $cache = array_values(array_unique($classes));
        sort($cache);

        return $cache;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Evaluation
    // ─────────────────────────────────────────────────────────────────────────

    protected function evaluate(string $__code, array &$vars): void
    {
        $__code = preg_replace('/^\s*<\?(php)?\s*/i', '', trim($__code));

        if ($__code === '') {
            return;
        }

        if (!preg_match('/[;{}]\s*$/', $__code)) {
            $__code .= ';';
        }

        extract($vars, EXTR_OVERWRITE);

        $__savedCalledFrom = Dump::$displayCalledFrom ?? true;
        Dump::$displayCalledFrom = false;

        // Double-buffered capture:
        //   dump() in Register.php calls @ob_flush() after each echo, which
        //   normally flushes the active buffer straight to stdout.
        //   By opening two levels, the flush goes to the outer level (N-1)
        //   instead of escaping to stdout — so we can capture and clean it.
        //   N-1: receives content flushed via ob_flush()
        //   N:   receives regular echo / print output
        ob_start();  // N-1
        ob_start();  // N

        $__result    = null;
        $__error     = null;
        $__hasResult = false;

        $__expr = rtrim(trim($__code), ';');
        try {
            // Try as expression first so we can display the return value
            $__result    = eval('return (' . $__expr . ');');
            $__hasResult = true;
        } catch (\ParseError) {
            // ParseError fires at compile-time — nothing was output yet;
            // discard the inner buffer and re-try as a full statement block.
            ob_clean();  // clean N (N-1 still empty)
            try {
                eval($__code);
            } catch (\Throwable $__e2) {
                $__error = $__e2;
            }
        } catch (\Throwable $__e) {
            $__error = $__e;
        }

        // Collect output: N holds un-flushed echoes, N-1 holds ob_flush'd content.
        $__fromN   = ob_get_clean();   // get + close N
        $__fromN_1 = ob_get_clean();   // get + close N-1 (accumulated flushes)
        // N-1 was written in the same chronological order as the flushes,
        // and whatever remained in N at the end is appended.
        $__output  = $__fromN_1 . $__fromN;

        if ($__output !== '') {
            echo self::cleanDumpOutput($__output);
        }

        if ($__error !== null) {
            self::printError($__error);
        } elseif ($__hasResult && $__output === '' && !self::isAssignmentExpression($__expr)) {
            // Only show the return value when nothing was already echoed;
            // avoids duplicating output for echo / dump calls.
            // Assignments ($x = 1) persist silently like in Tinker.
            self::dumpValue($__result);
        }

        // Restore after all output (including dumpValue) so displayCalledFrom=false
        // covers the entire display phase, not just the eval phase.
        Dump::$displayCalledFrom = $__savedCalledFrom;

        // Persist all user-defined variables.
        // Internal vars use __ prefix to avoid collisions with user vars.
        $__snap = get_defined_vars();
        $vars   = array_filter(
            $__snap,
            fn($key) => !str_starts_with($key, '__') && $key !== 'vars' && !in_array($key, self::SUPER_GLOBALS, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Output helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Display a value using the Flames dump() function, but without the
     * decorative header box and footer separator.
     */
    protected static function dumpValue(mixed $value): void
    {
        // Return output directly — avoids the global dump() wrapper and source
        // parsing that breaks for eval'd REPL code (e.g. $x = 1 + 2).
        $prevReturn = Dump::$returnOutput;
        Dump::$returnOutput = true;

        try {
            $raw = Dump::dump($value);
            if (is_string($raw) && $raw !== '') {
                echo self::cleanDumpOutput($raw);
            }
        } finally {
            Dump::$returnOutput = $prevReturn;
        }
    }

    /**
     * Strips the decorative header box (┌ │ └) and separator footer (════)
     * that the Flames dump decorator wraps around each value.
     * Only the actual variable-body lines are kept.
     *
     * Works for output that contains multiple concatenated dump() calls.
     */
    protected static function cleanDumpOutput(string $raw): string
    {
        $lines  = explode("\n", $raw);
        $result = [];

        foreach ($lines as $line) {
            $clean   = preg_replace('/\x1b\[[0-9;]*m/', '', $line);
            $trimmed = ltrim($clean);

            // Header box lines: top border (┌), label row (│), bottom border (└)
            if (str_starts_with($trimmed, '┌') ||
                str_starts_with($trimmed, '│') ||
                str_starts_with($trimmed, '└')) {
                continue;
            }

            // Footer: separator line (════) and call-stack lines
            if (str_starts_with($trimmed, '════')) {
                continue;
            }
            if (str_starts_with($trimmed, 'Call stack')) {
                continue;
            }
            // Numbered trace lines: "        N. File:line"
            if (preg_match('/^\d+\.\s/', $trimmed)) {
                continue;
            }

            $result[] = $line;
        }

        // Trim leading/trailing blank lines
        while ($result && trim(preg_replace('/\x1b\[[0-9;]*m/', '', $result[0])) === '') {
            array_shift($result);
        }
        while ($result && trim(preg_replace('/\x1b\[[0-9;]*m/', '', end($result))) === '') {
            array_pop($result);
        }

        return $result ? implode("\n", $result) . "\n" : '';
    }

    /**
     * Formats a caught Throwable for clean REPL display.
     * ErrorException (from converted PHP warnings) shows the severity label.
     */
    protected static function printError(\Throwable $error): void
    {
        if ($error instanceof \ErrorException) {
            $label = match ($error->getSeverity()) {
                E_WARNING, E_USER_WARNING       => 'Warning',
                E_NOTICE,  E_USER_NOTICE        => 'Notice',
                E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
                E_STRICT                        => 'Strict',
                default                         => 'Error',
            };
        } else {
            $label = $error::class;
        }

        $msg = $error->getMessage();
        // Strip the PHP-appended "in eval()'d code on line N" suffix
        $msg = preg_replace('/\s*in\s+\S*eval\S*.*$/i', '', $msg);

        echo "\033[31m{$label}\033[0m: {$msg}\n";
    }

    /**
     * True when the input is a top-level assignment ($x = 1, $x += 1, …).
     * Those should persist without dumping the assigned value.
     */
    protected static function isAssignmentExpression(string $code): bool
    {
        $code = rtrim(trim($code), ';');
        if ($code === '') {
            return false;
        }

        $tokens = @token_get_all('<?php ' . $code);
        if ($tokens === false) {
            return false;
        }

        $compoundAssign = [
            T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL,
            T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL,
            T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
        ];

        $depth = 0;

        foreach ($tokens as $token) {
            if (is_string($token)) {
                match ($token) {
                    '(', '[', '{' => $depth++,
                    ')', ']', '}' => $depth = max(0, $depth - 1),
                    default         => null,
                };

                if ($token === '=' && $depth === 0) {
                    return true;
                }

                continue;
            }

            if ($depth === 0 && in_array($token[0], $compoundAssign, true)) {
                return true;
            }
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Completeness detection
    // ─────────────────────────────────────────────────────────────────────────

    protected function isComplete(string $code): bool
    {
        $tokens = @token_get_all('<?php ' . $code);
        $depth  = 0;
        $arr    = 0;
        $par    = 0;

        foreach ($tokens as $token) {
            if (!is_string($token)) {
                continue;
            }
            match ($token) {
                '{'     => $depth++,
                '}'     => $depth--,
                '['     => $arr++,
                ']'     => $arr--,
                '('     => $par++,
                ')'     => $par--,
                default => null,
            };
        }

        return $depth <= 0 && $arr <= 0 && $par <= 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // I/O
    // ─────────────────────────────────────────────────────────────────────────

    protected function readLine(string $prompt): string|false
    {
        if (function_exists('readline')) {
            $line = readline($prompt);

            if ($line === false) {
                return false;
            }

            return self::stripLibeditCompletionSpace($line);
        }

        echo $prompt;
        $line = fgets(STDIN);
        return $line !== false ? rtrim($line, "\n") : false;
    }

    /**
     * libedit appends a space after tab-completion; PHP cannot always disable it.
     * Strip one trailing space when it looks like an identifier / namespace completion.
     */
    protected static function stripLibeditCompletionSpace(string $line): string
    {
        if (
            !defined('READLINE_LIB')
            || READLINE_LIB !== 'libedit'
            || !str_ends_with($line, ' ')
            || strlen($line) < 2
        ) {
            return $line;
        }

        $before = substr($line, 0, -1);

        if (preg_match('/(?:[\w\\\\]|::)$/', $before) === 1) {
            return $before;
        }

        return $line;
    }

    protected function printBanner(): void
    {
        echo "\n";
        echo Output::ORANGE . Output::BOLD . "  Flames Shell" . Output::RESET;
        echo Output::GRAY   . "  — type \\q or Ctrl+D to exit" . Output::RESET . "\n\n";
    }
}
