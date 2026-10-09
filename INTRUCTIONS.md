# Flames Forge — AI / Vibecode Instructions

This document describes every class in the `flamesphp/forge` package, its methods, and how they work. Use it as context when building or modifying Flames CLI tooling with AI-assisted development.

---

## Overview

**Flames Forge** is the CLI layer for the [Flames PHP framework](https://github.com/flamesphp/flames). It is invoked via the `forge` executable and provides commands for installation, development servers, Docker management, database tools, asset builds, scheduling, and more.

### Entry points

| File | Purpose |
|------|---------|
| `forge` | Composer-installed entry: boots from vendor path |
| `resource/forge` | Template copied to project root on `forge install` |
| `Flames/Forge/Kernel.php` | Shared boot logic (Docker routing, argv parsing) |

### Boot flow

```
forge [args]
  → Kernel::boot($projectRoot, $kernelFile)
    → Parse --native / --container flags
    → Optionally route to Docker container (docker exec)
    → Load Flames\Kernel and boot framework
    → Kernel::run() → Cli\System::run()
```

### Command pattern

Almost every command class follows this contract:

```php
public function __construct(mixed $data);  // Parsed CLI data (Flames\Collection\Arr)
public function run(bool $debug = false): bool;
```

- `$data` comes from `Flames\Forge\Cli\Data::getData()` and contains `command`, `argument`, `option`, `parameter`.
- `$debug` controls whether the Flames logo and command banner are shown (handled by `System`, not individual commands).

### Help screen (`forge` with no arguments)

Running `forge` without a command invokes `System::dispatchHelper()`. It prints:

1. **Flames logo** and attribution
2. **Execution context** — `Running in native` or `Running in container ({hostname})`
3. **Environment panel** — parsed from `.env` → `COMPOSE_FILE`:
   - **App** — active app stack (e.g. `nginx_phpfpm_flames`)
   - **Database** — active DB services; `(default)` marks `DATABASE_DEFAULT`
   - **Memory** — active memory engines; `(default)` marks `DATABASE_MEMORY_DEFAULT`
   - **Search** — active search engines; `(default)` marks `DATABASE_SEARCH_DEFAULT`
   - **Services** — optional services (e.g. `stealth`)
4. **Usage** — `forge <command> [--native] [--container] [options]`
5. **Command sections** — see [Command Catalog](#command-catalog) below
6. **Application CLI routes** — dynamically loaded from the project's registered CLI routes (if any)

---

## Command Catalog

Authoritative list of commands as shown by `forge` help. Source of truth: `System.php` `*_HELP` constants.

### Global flags

| Flag | Description |
|------|-------------|
| `--native` | Force execution on the local machine (skip Docker routing) |
| `--container` | Force execution inside the Docker container |

### Framework Commands

| Command | Description |
|---------|-------------|
| `install` | Install the project |
| `inject` | Inject the global forge launcher |
| `key generate` | Create or update the project unique key |
| `key generate --crypto` | Create or update the cryptography key |
| `shell` | Open an interactive PHP REPL |

### Schedules

| Command | Description |
|---------|-------------|
| `schedule install` | Register schedule runner in crontab |
| `schedule remove` | Remove schedule runner from crontab |
| `schedule run` | Run all due schedules |
| `schedule list` | List all schedules defined in config.yml |
| `schedule show` | Show currently running schedule processes |
| `schedule stop {name\|pid}` | Stop a running schedule by name or PID |

### Database

| Command | Description |
|---------|-------------|
| `db` | Open a shell for the default database |
| `db {connection}` | Open a shell for a named connection |
| `db sql {sql}` | Run SQL on the default database |
| `db sql {connection} {sql}` | Run SQL on a named connection |
| `db model list` | List all models across all connections |
| `db model list {connection}` | List models for a specific connection |
| `db migrate` | Force-migrate all models |
| `db migrate {connection}` | Force-migrate models for a specific connection |
| `db truncate` | Empty all tables, reset auto-increment |
| `db truncate {connection}` | Truncate tables for a specific connection |
| `db wipe` | Drop all tables in the default database |
| `db wipe {connection}` | Drop all tables in a specific connection |

### Routes

| Command | Description |
|---------|-------------|
| `route server list` | List all server-side routes |
| `route client list` | List all client-side routes |
| `route server list {microservice}` | List server-side routes for a microservice |
| `route client list {microservice}` | List client-side routes for a microservice |

### Microservices

| Command | Description |
|---------|-------------|
| `microservice list` | List all configured microservices |

### Surface (PHP Frontend WASM)

| Command | Description |
|---------|-------------|
| `surface build` | Build client-side assets |

### Snapshot (Build Static App)

| Command | Description |
|---------|-------------|
| `snapshot` | Build the app as static HTML pages |
| `snapshot --cloudflare` | Build for Cloudflare Pages |

### Bundle (Build Native App)

| Command | Description |
|---------|-------------|
| `bundle` | Build app webview for Linux or Windows |
| `bundle --linux` | Build for Linux |
| `bundle --windows` | Build for Windows |
| `bundle --windows --installer` | Build Windows installer |
| `bundle --android` | Build Android APK |

### Webserver (Development)

| Command | Description |
|---------|-------------|
| `serve` | Run a development server (0.0.0.0:80) |
| `serve {host}:{port}` | Run at a specific host and port |
| `serve -host={host}` | Run at a specific host |
| `serve -port={port}` | Run at a specific port |

### Container (Docker)

| Command | Description |
|---------|-------------|
| `container` | Show running container status |
| `container run` | Start containers in the background |
| `container run --foreground` | Start containers in the foreground |
| `container reload` | Rebuild and restart all containers |
| `container build` | Build / rebuild container images |
| `container stop` | Stop and remove containers |
| `container compose {args}` | Run any docker compose command |
| `container app set apache_modphp` | Apache with PHP mod |
| `container app set apache_phpfpm` | Apache with PHP-FPM |
| `container app set nginx_phpfpm` | NGINX with PHP-FPM |
| `container app set nginx_phpfpm_flames` | NGINX with PHP-FPM and Flames C extensions (recommended for develop) |
| `container app set nginx_flames` | NGINX with Flames Ready & C extensions (recommended for production) |
| `container db set {database}` | Set the default database |
| `container db add mariadb` | Add MariaDB service |
| `container db add mysql` | Add MySQL service |
| `container db add postgresql` | Add PostgreSQL service |
| `container db add mongodb` | Add MongoDB service |
| `container db remove {database}` | Remove a database service (fallback to sqlite if none left) |
| `container search set {engine}` | Set the default search engine |
| `container search add opensearch` | Add OpenSearch service |
| `container search add elasticsearch` | Add Elasticsearch service |
| `container search add meilisearch` | Add Meilisearch service |
| `container search remove {engine}` | Remove a search engine service |
| `container memory set {engine}` | Set the default memory engine |
| `container memory add kvrocks` | Add Kvrocks service |
| `container memory add keydb` | Add KeyDB service |
| `container memory add redis` | Add Redis service |
| `container memory add dragonfly` | Add Dragonfly service |
| `container memory add valkey` | Add Valkey service |
| `container memory remove {engine}` | Remove a memory engine service |
| `container service add stealth` | Add Stealth (chromium) browser service |
| `container service remove stealth` | Remove Stealth (chromium) browser service |
| `container service add sonarqube` | Add SonarQube Community Build (code quality) |
| `container service remove sonarqube` | Remove SonarQube Community Build |
| `container {service}` | Open a bash shell in a container |
| `container {service} bash\|sh` | Open bash or sh in a container |
| `container {service} {command}` | Run `php forge {command}` inside a container |
| `container {service} php {args}` | Run an explicit php command inside a container |

### Composer

| Command | Description |
|---------|-------------|
| `composer` | List available composer commands |
| `composer require {package}` | Add a new package to the project |
| `composer remove {package}` | Remove a package from the project |
| `composer update` | Update all project packages |
| `composer update {package}` | Update a specific package |
| `composer show` | Show installed packages |
| `composer audit` | Check for security vulnerabilities |
| `composer validate` | Validate composer.json |
| `composer {command} {args}` | Run any composer command |

### Cache

| Command | Description |
|---------|-------------|
| `cache purge` | Clear everything in app cache |
| `cache purge kernel` | Clear kernel cache |
| `cache purge all` | Clear everything |

### Hidden / internal commands (not shown in help)

| Command | Description |
|---------|-------------|
| `--ready` | Boots Flames Ready worker (`Flames\Ready\Kernel\Boot`) |
| `--cron` | Boots Flames Cron worker (`Flames\Cron\Kernel\Boot`) |
| `internal:coroutine` | Executes a serialized coroutine in an isolated process |
| `surface build --auto` | Auto-rebuild trigger with change-detection hash (not listed in help) |

---

## Core Classes

### `Flames\Forge\Kernel`

Shared forge entry-point logic. Used by every `forge` launcher file.

| Method | Visibility | Description |
|--------|------------|-------------|
| `boot(string $projectRoot, string $kernelFile, bool $run = false): void` | public static | Main entry. Parses `--native` and `--container` from argv, optionally forwards execution into a Docker container, restores filtered argv, `chdir`s to project root, requires the Flames kernel, and calls `\Flames\Kernel::boot()`. If `$run === true`, also calls `run()`. |
| `run(): void` | public static | Instantiates `Cli\System` and calls `run()`, then exits with code 0. |
| `dockerIsRunning(): bool` | private static | Returns true if a Docker Unix socket exists and the process is **not** already inside a container (`/.dockerenv`). Uses socket file check instead of `docker info` for speed. |
| `getAppContainer(string $projectRoot): ?string` | private static | Finds the best matching running Docker container for the project by slug-matching `docker ps` output against project basename and keywords (`apache`, `-app`, `php`). |

**Local-only commands** (never auto-routed to Docker): `container`, `db`, `shell`, `code` (`code sast` needs host Docker).

---

### `Flames\Forge\Cli`

Detects whether the current process is a forge CLI invocation.

| Method | Description |
|--------|-------------|
| `isCli(): bool` | Returns `false` if `FLAMES_READY_WORKER` is defined. Otherwise returns true when `SCRIPT_FILENAME` basename is `forge`. Result is memoized via `once()`. Used by `Output` to suppress web output. |

---

### `Flames\Forge\Boot`

Placeholder boot stub (`echo 'boot forge kernel'`). Not part of the active CLI pipeline.

---

## CLI Infrastructure

### `Flames\Forge\Cli\Data`

Parses `$_SERVER['argv']` into a structured `Flames\Collection\Arr`.

| Method | Description |
|--------|-------------|
| `getData(?array $args = null): Arr` | Builds data object with keys: `command` (argv[1]), `argument` (positional args), `option` (double-dash flags without `--`), `parameter` (single-dash `-key` or `-key=value`). |

**Parsing rules:**
- `argv[0]` = script path (ignored)
- `argv[1]` = command name
- Non-dash tokens → `argument`
- `--flag` → `option[] = 'flag'`
- `-key` or `-key=value` → `parameter[key]`

---

### `Flames\Forge\Cli\System`

Central command dispatcher. Maps multi-word commands to handler classes.

| Method | Description |
|--------|-------------|
| `__construct(?Arr $data = null, bool $debug = true)` | Uses provided data or `Data::getData()`. |
| `run(): bool` | Resolves command (longest match up to 3 args), handles special routes, dispatches to handler, or shows help. |
| `dispatchSpecial(object $instance, string $label): bool` | Runs a command with logo/banner (used for `bundle --android` and `key generate --crypto`). |
| `runContainerService(string $service): bool` | When first argv token matches a Docker service name, runs `Container::runExec()`. |
| `dispatchHelper(): void` | Prints full help: logo, native/container mode, environment info, all command sections. |
| `getApplicationCliRoutes(): array` | Loads app CLI routes via `Router` + `Event::dispatch` and returns route names. |
| `readEnvValues(): array` | Simple `.env` KEY=VALUE parser. |
| `printEnvironmentInfo(): void` | Displays active Docker app stack, databases, memory engines, search engines, and services from `COMPOSE_FILE`. |

**Registered commands (`COMMANDS` map):**

| Command | Handler |
|---------|---------|
| `install` | `Command\Install` |
| `inject` | `Command\Inject` |
| `key generate` | `Command\Key\Generate` |
| `shell` | `Command\Shell` |
| `serve` | `Command\Server` |
| `surface build` | `Command\Build\Assets` |
| `snapshot` | `Command\Build\App\StaticEx` |
| `bundle` | `Command\Build\App\Native` |
| `container` | `Command\Container` |
| `composer` | `Command\Package` |
| `db`, `db wipe`, `db truncate`, `db migrate` | `Command\Db` |
| `schedule install/remove/run/list/show/stop` | `Command\Schedules\*` |
| `route server list`, `route client list` | `Command\Route` |
| `microservice list` | `Command\MicroserviceList` |
| `cache purge`, `cache purge kernel`, `cache purge all` | `Command\Cache` |
| `internal:coroutine` | `Command\Coroutine` |

**Special routing:**
- `bundle --android` → `Mobile` instead of `Native`
- `key generate --crypto` → `Crypto\Key\Generate` instead of `Key\Generate`
- Unknown first token matching a Docker service → `Container::runExec()`

**Passthrough commands** (no logo, flush output buffers): `container`, `composer`, `db`, `shell`, `cache`.

**Hidden boot flags:**
- `--ready` → `Flames\Ready\Kernel\Boot::boot()`
- `--cron` → `Flames\Cron\Kernel\Boot::boot()`

---

### `Flames\Forge\Cli\Output`

Terminal output helpers with ANSI colors. Only prints when `Cli::isCli()` is true.

| Constant | Value |
|----------|-------|
| `RESET`, `BOLD`, `DIM`, `GREEN`, `YELLOW`, `BLUE`, `CYAN`, `WHITE`, `GRAY`, `RED`, `ORANGE` | ANSI escape sequences |

| Method | Description |
|--------|-------------|
| `line(string $text = ''): void` | Print a line. |
| `blank(): void` | Print blank line. |
| `success(string $text): void` | Green checkmark message. |
| `error(string $text): void` | Red X message. |
| `info(string $text): void` | Cyan info icon message. |
| `warning(string $text): void` | Yellow warning icon message. |
| `section(string $title): void` | Uppercase yellow section header. |
| `command(string $command, string $description = '', int $width = 42): void` | Help row: green command + gray description. |
| `logo(): void` | Prints ASCII Flames logo and separator. |
| `echo(string $message): void` | Private. Writes to stdout with flush when CLI. |

---

## Framework Commands

### `Flames\Forge\Cli\Command\Install`

Bootstraps a new Flames project from example templates.

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Runs full install pipeline. |
| `copyEnv(): void` | Copies `vendor/flamesphp/example/.env.example` → `.env` if missing. |
| `copyIndex(): void` | Creates `public/index.php` from example. |
| `copyHtaccess(): void` | Copies `.htaccess` from example if missing. |
| `copyForge(): void` | Copies `resource/forge` → project `forge` (executable). |
| `copyDockerCompose(): void` | Copies default `docker-compose.yml` if missing. |
| `generateKeys(): void` | Generates `APP_KEY` and `CRYPTO_KEY` in `.env` if empty. |

On Unix, silently runs `Inject` to set up global forge launcher.

---

### `Flames\Forge\Cli\Command\Inject`

Installs a global `forge` shell wrapper so `forge` works from any directory.

| Method | Description |
|--------|-------------|
| `run(bool $debug = false, bool $silent = false): bool` | Creates `~/.local/bin/forge` wrapper that walks up directories to find a project `forge` file. Adds `~/.local/bin` to PATH in `~/.bashrc`. Unix only. |
| `WRAPPER_CONTENT` | Bash script constant: walks `$PWD` up to `/` looking for executable `forge`. |
| `PATH_SNIPPET` | Bash snippet to export `~/.local/bin` in PATH. |

---

### `Flames\Forge\Cli\Command\Key\Generate`

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Regenerates `APP_KEY` in `.env` using `Hash::getRandom()` and saves via `Environment::default()`. |

---

### `Flames\Forge\Cli\Command\Crypto\Key\Generate`

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Regenerates `CRYPTO_KEY` in `.env`. Dispatched when `forge key generate --crypto` is used. |

---

### `Flames\Forge\Cli\Command\Shell`

Interactive PHP REPL (similar to Laravel Tinker).

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Main REPL loop with readline, history (`~/.flames_shell_history`), tab completion, multi-line buffering. Exit with `\q`, `exit`, or `quit`. |
| `evaluate(string $__code, array &$vars): void` | Evaluates PHP code. Tries expression first (`return (...)`), falls back to statement block. Captures output via double-buffered `ob_start`. Persists user variables between evaluations. |
| `dumpValue(mixed $value): void` | Displays return value using Flames `dump()` with decorative headers stripped. |
| `cleanDumpOutput(string $raw): string` | Removes dump box borders, separators, and call stack from dump output. |
| `printError(\Throwable $error): void` | Formats errors/warnings for REPL display. |
| `isComplete(string $code): bool` | Token-based brace/bracket/paren balance check for multi-line input. |
| `readLine(string $prompt): string\|false` | readline or fallback `fgets(STDIN)`. |
| `printBanner(): void` | Shows "Flames Shell" banner. |

**Tab completion:** variables (`$foo`), object properties (`$obj->prop`), PHP internal functions.

---

### `Flames\Forge\Cli\Command\Server`

Development HTTP server.

| Method | Description |
|--------|-------------|
| `__construct(mixed $data)` | Parses host/port from `{host}:{port}` argument or `-host=` / `-port=` parameters. Defaults: `0.0.0.0:80`. |
| `run(bool $debug = false): bool` | Delegates to `\Flames\PHP\Server::run($host, $port)`. |

---

## Database Commands

### `Flames\Forge\Cli\Command\Db`

Database shell and management (similar to Laravel `artisan db`).

**Constructor** parses raw argv (not `$data`) to determine mode:
- `db model list [connection]`
- `db wipe [connection]`
- `db truncate [connection]`
- `db migrate [connection]`
- `db sql [connection] {sql}` or `db sql {sql}`
- `db {connection}` (interactive shell)

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Dispatches to mode handler or opens DB shell. |
| `runMysql(...)` | Runs `mysql` CLI client interactively or with `-e` for SQL. |
| `runPgsql(...)` | Runs `psql` with `PGPASSWORD`. |
| `runSqlite(string $path)` | Runs `sqlite3` CLI. |
| `runWipe(): bool` | Drops all tables via PDO (`SHOW TABLES` + `DROP TABLE`). |
| `runTruncate(): bool` | Truncates all tables except `flames_migration`, resets auto-increment. |
| `runMigrate(): bool` | Discovers ORM models and force-migrates via driver (`MariaDb`, `MySql`, `Postgresql`). |
| `buildPdo(string $connName, array $env): ?\PDO` | Creates MySQL PDO from `.env` connection config. |
| `runModelList(): bool` | Lists discovered models with table, connection, driver columns. |
| `discoverModels(array $env, string $defaultConn): array` | Scans `App/Server/Model` and `Microservice/*/Server/Model`. |
| `scanModelsDir(...)` | Recursive scan; reads `#[Database]` and `#[Table]` attributes via reflection. |
| `commandExists(string $cmd): bool` | Checks if CLI tool exists via `which`. |
| `readEnv(): array` | Parses `.env` file. |

**Connection config pattern in `.env`:**
```
DATABASE_DEFAULT=mariadb
DATABASE_MARIADB_DRIVER=mariadb
DATABASE_MARIADB_HOST=127.0.0.1
DATABASE_MARIADB_PORT=3306
DATABASE_MARIADB_NAME=mydb
DATABASE_MARIADB_USER=root
DATABASE_MARIADB_PASSWORD=secret
```

---

## Docker / Container Commands

### `Flames\Forge\Cli\Command\Container`

Manages Docker Compose stacks and `.env` service configuration.

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Main dispatcher. No args → `showStatus()`. Subcommands: `compose`, `run`, `reload`, `build`, `stop`, `app`, `db`, `search`, `memory`, `service`, or service name for exec. |
| `runCompose(array $args): bool` | Runs `docker compose {args}`. Calls `ExposePort::resolveAll()` before `up`. |
| `runExec(string $service, array $args): bool` | `docker compose exec {service} {inner}`. Default inner command: `bash`. |
| `buildInnerCommand(array $args): string` | Empty → `bash`. `bash`/`sh`/`php` → passthrough. Otherwise → `php forge {args}`. |
| `serviceExists(string $service): bool` | **Static.** Checks if service is defined in compose (cached via `docker compose config --services`). |
| `handleApp(array $options): bool` | `container app set {image}` — switches app stack (nginx_flames, apache_modphp, etc.). |
| `handleDb(array $options): bool` | `set`, `add`, `remove` database services. |
| `handleMemory(array $options): bool` | `set`, `add`, `remove` memory engines (redis, keydb, etc.). |
| `handleSearch(array $options): bool` | `set`, `add`, `remove` search engines. |
| `handleService(array $options): bool` | `add`, `remove` optional services (stealth browser). |
| `setApp(string $image): bool` | Updates `COMPOSE_FILE` to swap app yml. |
| `setDatabase/addDatabase/removeDatabase(...)` | Manages DB yml entries in `COMPOSE_FILE` and `DATABASE_DEFAULT`. |
| `setMemory/addMemory/removeMemory(...)` | Manages memory engine services and `DATABASE_MEMORY_DEFAULT`. |
| `setSearch/addSearch/removeSearch(...)` | Manages search services and `DATABASE_SEARCH_DEFAULT`. |
| `addService/removeService(...)` | Manages optional container services. |
| `isInstalled(): bool` | Checks `docker --version`. |
| `showStatus(): void` | Runs `docker compose ps`. |
| `isServiceRunning(string $service): bool` | Checks if compose service is running. |

**Supported stacks:**
- **App:** `apache_modphp`, `apache_phpfpm`, `nginx_phpfpm`, `nginx_phpfpm_flames`, `nginx_flames`
- **Database:** `mariadb`, `mysql`, `postgresql`, `mongodb`
- **Memory:** `kvrocks`, `keydb`, `redis`, `dragonfly`, `valkey`, `filedb` (on-disk `${STORAGE_PATH}/flames.filedb/`)
- **Search:** `opensearch`, `elasticsearch`, `meilisearch`
- **Services:** `stealth`, `sonarqube`

---

## Package / Composer Commands

### `Flames\Forge\Cli\Command\Package`

Runs Composer programmatically (no binary required).

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Registers autoload, runs scoped `Flames\Composer\Composer\Console\Application` with argv args (default: `list --ansi`). |

Examples: `forge composer require vendor/pkg`, `forge composer update`, `forge composer audit`.

---

## Cache Commands

### `Flames\Forge\Cli\Command\Cache`

| Method | Description |
|--------|-------------|
| `__construct(mixed $data)` | Sets mode from command: `purge`, `purge kernel`, or `purge all`. |
| `run(bool $debug = false): bool` | Clears cache directories under `.cache/`. |
| `clearDir(string $dir, array $skip): int` | Recursively deletes entries, skipping names in `$skip`. |
| `removeDir(string $dir): void` | Recursive directory removal. |

| Mode | Clears |
|------|--------|
| `cache purge` | `.cache/` except `.flames/` |
| `cache purge kernel` | `.cache/.flames/` except `environment` and `schedules` |
| `cache purge all` | Everything including `.cache/.flames/` |

---

## Route Commands

### `Flames\Forge\Cli\Command\Route`

| Method | Description |
|--------|-------------|
| `__construct(mixed $data)` | Sets `$side` (`server` or `client`) from command string; optional microservice filter from first argument. |
| `run(bool $debug = false): bool` | Loads routes, filters by HTTP method side, prints formatted table. |
| `loadRoutes(): bool` | Clears router, dispatches server events or loads client event files. |
| `loadClientEventFiles(): void` | Requires `App/Client/Event/Route.php` and microservice client route files. |
| `callClientEventFile(string $file, string $class): void` | Instantiates route class and calls `onRoute()`. |
| `loadMicroserviceServerRoutes(): void` | Loads `Microservice/*/Server/Event/Route.php` files. |

---

### `Flames\Forge\Cli\Command\MicroserviceList`

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Lists microservices from `config.yml` with namespace and hosts. Always shows default `App`. |

---

## Schedule Commands

All schedules are defined in `config.yml` under `schedules:`.

### `Flames\Forge\Cli\Command\Schedules\Install`

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Adds `* * * * * cd {root} && {php} forge schedule run` to crontab. Unix only. |
| `findPhpBinary(): string` | Resolves PHP binary via `which php`. |
| `readCrontab()/writeCrontab(string $content): bool` | Crontab I/O via temp file. |
| `printCurrentEntry(...)` | Shows existing crontab line. |

### `Flames\Forge\Cli\Command\Schedules\Remove`

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Removes crontab lines containing `forge schedule run`. |

### `Flames\Forge\Cli\Command\Schedules\Run`

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Executes due schedules. Loops for 59s if any schedule has `every.second` (sub-minute). |
| `tick(array $schedules, string $cacheDir, bool $debug): void` | Per-second check: compares `last_run` cache vs interval, respects `overlapping`, launches background processes. |
| `launch(...): int` | Spawns `php forge {command}` in background; returns PID. Uses `timeout` on Unix if configured. |
| `hasRunningLocks(string $cacheDir, string $name): bool` | Checks lock files for live PIDs. |
| `hasSubMinuteSchedules(array $schedules): bool` | True if any schedule defines `every.second`. |
| `calculateInterval(array $every): int` | **Static.** Converts `{second, minute, hour, day, month}` to total seconds. |

**Cache location:** `.cache/.flames/schedules/` — `{name}.json` (last run), `{name}.{pid}.lock` (running), `{name}.log` (output).

### `Flames\Forge\Cli\Command\Schedules\ListSchedules`

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Table of all configured schedules from `config.yml`. |
| `formatEvery(array $every): string` | Human-readable interval (e.g. `every 5m 1h`). |

### `Flames\Forge\Cli\Command\Schedules\Show`

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Live status table: last run, next run, PID, status (running/idle/completed). |
| `getLockStatus(...)` | Returns running PIDs and recently-completed flag from lock files. |
| `formatElapsed/formatNext(int $seconds): string` | Human-readable time labels. |

### `Flames\Forge\Cli\Command\Schedules\Stop`

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Stops schedule by name, PID, or list index. |
| `killPid(int $pid): void` | `kill -9` on Unix, `taskkill /F` on Windows. |

---

## Build Commands

### `Flames\Forge\Cli\Command\Build\Assets`

Builds the client-side JavaScript bundle (`App/Client/Resource/Build/Flames.js`). Compiles PHP client code to run in the browser via Flames WASM engine.

| Constant | Description |
|----------|-------------|
| `BASE_PATH` | `App/Client/Resource/Build/` |
| `DEFAULT_FILES` | Core Flames classes compiled into the bundle |
| `CLIENT_MOCKS` | Server-side classes replaced with client mock namespaces |

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Full build pipeline: open stream → inject structure → extensions → default files → finish → auto-verify. |
| `openStream(): mixed` | Opens/creates `Flames.js` write stream. |
| `ensureFolder(): void` | Creates build directory if missing. |
| `injectStructure(mixed $stream): void` | Writes Flames engine JS with env placeholders replaced; opens `window.Flames.onReady`. |
| `injectExtensions(mixed $stream): void` | Injects `dl('*.so')` calls from `CLIENT_EXTENSIONS` env; optionally loads SWF from CDN. |
| `injectDefaultFiles(mixed $stream): void` | Compiles virtual PHP files, event triggers, tags, views into base64-eval'd JS. |
| `mountVirtualDefaultFiles(string $buffer): string` | Adds framework default + mock PHP files to virtual buffer. |
| `mountVirtualClientFilesMetadata(string $buffer): mixed` | Scans `App/Client/{Event,Component,Service,Controller,Tag,View}` and compiles each PHP/Twig file. |
| `getTagData(string $class): mixed` | Builds custom element registration JS for `#[Tag]` classes with Twig-rendered shadow DOM. |
| `verifyAttributes(mixed $data, string $class): Arr` | Groups event methods by type (click/change/input). |
| `finish(mixed $stream): void` | Closes stream, prints success in debug mode. |
| `verifyAuto(): void` | If `--auto` flag, runs `Automate` hash check. |
| `loadPhpFile/parseMockFile(...)` | Strips `<?php`, renames mock class namespaces for client-side execution. |

Triggered by: `forge surface build` or internally by `Command::run('build:assets')`.

---

### `Flames\Forge\Cli\Command\Build\Assets\Automate`

Change detection for auto-rebuild (`--auto` flag).

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Prints current hash in debug mode. |
| `getCurrentHash(): string` | SHA1 of serialized file list with mtimes. |
| `buildFileTimes(): void` | Collects `.env`, views (`.twig`), public assets, and client PHP files. |
| `collectFiles(...)` | Recursive directory scan with extension filter and exclude dirs. |
| `isIgnored(string $path): bool` | Checks paths against `AUTO_BUILD_IGNORE_PATHS` env. |

---

### `Flames\Forge\Cli\Command\Build\Assets\Data`

Reflection cache for client-side controller classes.

| Method | Description |
|--------|-------------|
| `mountData(string $class): Arr` | Returns cached reflection data for a client class. Cache at `.cache/.flames/client-controller/{sha1(class)}`. |
| `buildReflection(string $class): Arr` | Scans methods for `#[Click]`, `#[Change]`, `#[Input]` attributes with `uid` argument. |

**Cached data shape:** `{version, class, methods: {methodName: {name, uid, type}}, staticConstruct: bool}`

---

### `Flames\Forge\Cli\Command\Build\Assets\Template`

Twig template extension support for asset builds.

| Method | Description |
|--------|-------------|
| `isTemplateExtension(): bool` | True when `CLIENT_TEMPLATE_ENABLED === true` in environment. |
| `injectDefaultFiles(array $defaultFiles): array` | Merges Twig-related framework classes into compile list. |
| `injectClientMocks(array $clientMocks): array` | Merges `View\Client` mock into client mocks list. |

Contains large `$defaultFiles` array of all `Flames\Template\*` classes required for client-side Twig rendering.

---

### `Flames\Forge\Cli\Command\Build\App\StaticEx`

Static site generator (`forge snapshot`). Pre-renders all GET routes to HTML files.

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Cleans build dir, copies public assets, iterates GET routes, saves HTML, builds JS bundle, creates zip. |
| `getResponse(RouteMatch $match): bool\|Arr` | Simulates HTTP request: dispatches Initialize + Route events, calls controller, captures output + headers. |
| `saveResponse(...)` | Writes HTML to `.cache/build/{route}/index.html` and `{route}.html`. |
| `saveHeader(...)` | Hook for Cloudflare header export (empty by default; override for `--cloudflare`). |
| `buildFlames(): void` | Concatenates `client.js` + compiled `Flames.js` → `flames.js`. |
| `buildZip(): void` | Zips build output to `App/Client/Build/{app_name}_{timestamp}.zip`. |
| `cleanBuild/copyPublic/getDirContents(...)` | File system helpers. |
| `saveInputs()/restoreInputs(): void` | Saves/restores superglobals and headers during static render loop. |

**Build output:** `.cache/build/` → zipped to `App/Client/Build/`.

---

### `Flames\Forge\Cli\Command\Build\App\Native`

Electron desktop app builder (`forge bundle`).

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Full pipeline: deps → node app → electron → prepare → icon → build → pack. |
| `verifyDependencies(): bool` | Requires npm, npx; rpmbuild on Linux. |
| `mountNodeApp(): bool` | Creates `package.json` from template using `APP_TITLE`, `APP_VERSION`, etc. |
| `installNodeModules()/installElectron(): bool` | npm install + electron-forge setup. |
| `prepareApp(): bool` | Copies Electron assets, generates `env.js` with domain/key config. |
| `buildIcon(): bool` | Converts `App/Client/Resource/icon.png` to PNG + ICO. |
| `buildApp(): bool` | Runs `npm run make`. |
| `packBuild(): bool` | Copies `.deb`, `.rpm`, `.nupkg`, or zip to `App/Client/Build/`. |
| `packBuildBundleUnix/packZip/buildZip(...)` | Platform-specific packaging. |
| `buildInstaller(string $outputPath): bool` | Windows Inno Setup installer (requires `--installer`). |
| `getAppNativeKey(): string` | **Static.** Derives/stores native app key from APP_KEY + CRYPTO_KEY salt. |
| `getPackPath/getWindowExecutable/getBuildFilePrefix(...)` | Output path helpers. |
| `cleanBuild/getDirContents/log(...)` | Build directory management. |

**Options:** `--linux`, `--windows`, `--installer`, `--run`

**Assets path:** `FLAMES_PATH/Cli/Command/Build/App/Native/Desktop/`

---

### `Flames\Forge\Cli\Command\Build\App\Mobile`

Android APK builder (`forge bundle --android`).

| Method | Description |
|--------|-------------|
| `run(bool $debug = false): bool` | Syncs Android tools from CDN, cleans build dir, extracts project template. |
| `syncProject(): void` | Downloads android tools zip if version changed (via `Sync::getData('mobile.android')`). |
| `downloadProject(...)` | Fetches from `cdn.jsdelivr.net/gh/flamesphp/cdn@{CDN_VERSION}/tools/android.zip.dat`. |
| `setupProject(): void` | Extracts `install.zip` to `.cache/build-mobile/`. |
| `cleanBuild/checkBuildPath/getDirContents(...)` | Directory helpers. |

---

## Internal Commands

### `Flames\Forge\Cli\Command\Coroutine`

Executes serialized coroutines in isolated PHP processes. Not intended for direct user use.

| Method | Description |
|--------|-------------|
| `__construct(mixed $data)` | Loads serialized coroutine from `.cache/coroutine/{hash}`. |
| `run(bool $debug = false): bool` | Deserializes args, invokes caller method, writes result to `.cache/coroutine/{sha1(hash)}`. |
| `isCoroutineRunning(): bool` | **Static.** Whether a coroutine is currently executing. |
| `errorHandler(): bool` | **Static.** Shutdown handler: writes error + buffer to result file. |

---

## Conventions for AI-Assisted Development

### Adding a new command

1. Create class in `Flames/Forge/Cli/Command/` implementing `__construct(mixed $data)` + `run(bool $debug): bool`.
2. Register in `System::COMMANDS` (use longest-match key for multi-word commands).
3. Add help entries to the appropriate `*_HELP` constant in `System.php`.
4. If the command needs raw passthrough output, add to `System::PASSTHROUGH`.
5. If it must run locally (not in Docker), add to `Kernel::LOCAL_ONLY`.

### CLI data access

```php
$args    = (array)$data->argument;   // positional
$options = (array)$data->option;     // --flag values (without --)
$params  = (array)$data->parameter;  // -key or -key=value
$command = (string)$data->command;   // resolved command string
```

### Global constants available at runtime

- `ROOT_PATH` — project root
- `APP_PATH` — `App/` directory
- `FLAMES_PATH` — framework vendor path
- `FLAMES_COMPOSER` — whether running via Composer install

### Dependencies

Requires PHP >= 8.5 with extensions: `pdo`, `zip`, `gd`, `posix`, `readline`, `tokenizer`.

Most commands depend on the main Flames framework (`flamesphp/framework`) being booted before execution.

---

## Keeping this document in sync

When adding or renaming commands, update **both**:

1. `Flames/Forge/Cli/System.php` — `COMMANDS` map and the matching `*_HELP` constant
2. This file — [Command Catalog](#command-catalog) section

Run `forge` with no arguments to verify the help output matches.
