<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command\Build;

use Flames\Forge\Cli;
use Flames\Forge\Cli\Command\Build\Assets\Automate;
use Flames\Forge\Cli\Command\Build\Assets\Data;
use Flames\Client\View;
use Flames\Environment;
use Flames;
use Flames\Kernel;

/**
 * Builds the client-side JavaScript bundle (Flames.js).
 *
 * @internal
 */
final class Assets
{
    public const BASE_PATH = APP_PATH . 'Client/Resource/Build/';

    /** @var list<class-string> */
    private const DEFAULT_FILES = [
        Flames\Kernel\Client\Error::class,
        Flames\Kernel\Client::class,
        Flames\Connection\Client::class,
        Flames\Collection\Strings::class,
        Flames\Collection\Bools::class,
        Flames\Collection\Ints::class,
        Flames\Collection\Floats::class,
        Flames\Collection\Arr::class,
        Flames\Php::class,
        Flames\Js::class,
        Flames\Forge\Cli::class,
        Flames\RequestData::class,
        Flames\Kernel\Route::class,
        Flames\Browser\Page::class,
        Flames\Router::class,
        Flames\Json::class,
        Flames\Event\Route::class,
        Flames\Event\Ready::class,
        Flames\Event\Page::class,
        Flames\Event\Native::class,
        Flames\Router\Parser::class,
        Flames\Header\Client::class,
        Flames\Coroutine\Timeout::class,
        Flames\Element::class,
        Flames\Element\Event::class,
        Flames\Money\Client::class,
        Flames\Http\Client\Client::class,
        Flames\Http\Async\Request\Client::class,
        Flames\Http\Async\Response\Client::class,
        Flames\Http\Code::class,
        Flames\Event\Element\Click::class,
        Flames\Event\Element\Change::class,
        Flames\Event\Element\Input::class,
        Flames\Event\Element\KeyDown::class,
        Flames\Event\Element\KeyUp::class,
        Flames\Event\Element\Focus::class,
        Flames\Kernel\Client\Dispatch::class,
        Flames\Js\Module::class,
        Flames\Client\Os::class,
        Flames\Client\Platform::class,
        Flames\Client\Browser::class,
        Flames\Client\UserAgentParser::class,
        Flames\Kernel\Client\Dispatch\Tag::class,
        Flames\Client\Tag::class,
        Flames\Element\Shadow::class,
        Flames\Kernel\Client\Service\Keyboard::class,
        Flames\Kernel\Client\Service\Clipboard::class,
        Flames\Client\Keyboard::class,
        Flames\Client\Keyboard\Event::class,
        Flames\Client\Clipboard::class,
        Flames\Client\Clipboard\Event::class,
        Flames\Event\Clipboard\Paste::class,
        Flames\FunctionEx::class,
        Flames\Cache\Memory\Client::class,
        Flames\Cookie\Client::class,
        Flames\Date\DateTime::class,
        Flames\Date\TimeZone\Client::class,
        Flames\Kernel\Client\Dispatch\Native::class,
        Flames\Client\Native::class,
        Flames\Client\Browser\DevTools::class,
        Flames\Client\Shell::class,
        Flames\Event\Native\Shell::class,
    ];

    /** @var list<class-string> */
    private const CLIENT_MOCKS = [
        Flames\Kernel\Client::class,
        Flames\Http\Client\Client::class,
        Flames\Http\Async\Request\Client::class,
        Flames\Http\Async\Response\Client::class,
        Flames\Connection\Client::class,
        Flames\Header\Client::class,
        Flames\Money\Client::class,
        Flames\Cache\Memory\Client::class,
        Flames\Cookie\Client::class,
        Flames\Date\TimeZone\Client::class,
    ];

    private bool $debug = false;
    private bool $auto  = false;
    private bool $swfExtension = false;

    /** Cached exploded CLIENT_EXTENSIONS list (null = not yet resolved). */
    private ?array $clientExtensions = null;

    public function __construct(mixed $data)
    {
        $this->auto = (bool)($data->option->contains('auto') ?? false);
    }

    public function run(bool $debug = false): bool
    {
        if ($this->auto && !Cli::isCli() && (($_GET['timeout'] ?? '') === 'true')) {
            $this->verifyAuto();
            return false;
        }

        $this->debug = $debug;
        $this->ensureFolder();

        $stream = $this->openStream();

        $this->injectStructure($stream);
        $this->injectExtensions($stream);
        $this->injectDefaultFiles($stream);
        $this->finish($stream);
        $this->verifyAuto();

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return resource */
    private function openStream(): mixed
    {
        $path   = self::BASE_PATH . 'Flames.js';
        $stream = @fopen($path, 'w');

        if ($stream === false) {
            $mask = umask(0);
            mkdir(self::BASE_PATH, 0777, true);
            umask($mask);
            $stream = fopen($path, 'w');
        }

        return $stream;
    }

    private function ensureFolder(): void
    {
        if ($this->debug) {
            echo 'Verifying base resource folder ' . substr(self::BASE_PATH, strlen(ROOT_PATH)) . "\n";
        }

        if (!is_dir(self::BASE_PATH)) {
            if ($this->debug) {
                echo 'Creating base resource folder ' . self::BASE_PATH . "\n";
            }
            $mask = umask(0);
            mkdir(self::BASE_PATH, 0777, true);
            umask($mask);
        }
    }

    /** @param resource $stream */
    private function injectStructure(mixed $stream): void
    {
        if ($this->debug) {
            echo "Inject structure javascript system\n";
        }

        $this->swfExtension    = false;
        $this->clientExtensions = null;
        $raw                   = Environment::get('CLIENT_EXTENSIONS');

        if ($raw !== null) {
            $this->clientExtensions = explode(',', strtolower((string)$raw));
            $this->swfExtension     = in_array('swf', $this->clientExtensions, true);
        }

        $dateTimezone = trim((string)(Environment::get('DATE_TIMEZONE') ?? ''));
        if ($dateTimezone === '') {
            $dateTimezone = 'UTC';
        }

        $appNativeKey = (string)\Flames\Forge\Cli\Command\Build\App\Native::getAppNativeKey();
        $unsupported  = (string)@file_get_contents(ROOT_PATH . 'App/Client/Resource/Event/Unsupported.js');

        $engine = str_replace(
            [
                '{{ environment }}',
                '{{ dumpLocalPath }}',
                '{{ dateTimeZone }}',
                '\'{{ asyncRedirect }}\'',
                '\'{{ swfExtension }}\'',
                '\'{{ composer }}\'',
                '\'{{ unsupported }}\';',
                '{{ appNativeKey }}',
            ],
            [
                rawurlencode((string)Environment::get('ENVIRONMENT')),
                rawurlencode((string)Environment::get('DUMP_LOCAL_PATH')),
                rawurlencode($dateTimezone),
                Environment::get('CLIENT_ASYNC_REDIRECT') === true ? 'true' : 'false',
                $this->swfExtension ? 'true' : 'false',
                FLAMES_COMPOSER === true ? 'true' : 'false',
                '(function(){' . $unsupported . '})();',
                $appNativeKey,
            ],
            (string)file_get_contents(FLAMES_PATH . 'Kernel/Client/Engine/Flames.js')
        );

        fwrite($stream, $engine);
        fwrite($stream, 'window.Flames.onReady=function(){');
    }

    /** @param resource $stream */
    private function injectExtensions(mixed $stream): void
    {
        if ($this->debug) {
            echo "Inject default loaded extensions\n";
        }

        $extensions = $this->clientExtensions;
        if ($extensions === null) {
            return;
        }

        $eval = '';

        foreach ($extensions as $extension) {
            if ($extension === 'swf') {
                continue;
            }
            if ($eval !== '') {
                $eval .= 'usleep(1);';
            }
            $eval .= "dl('{$extension}.so');";
        }

        if ($eval !== '') {
            fwrite($stream, "Flames.Internal.evalBase64('" . base64_encode($eval) . "');");
        }

        if ($this->swfExtension) {
            fwrite($stream, "
                var xmlhttp = new XMLHttpRequest();
                xmlhttp.open('GET', 'https://cdn.jsdelivr.net/gh/flamesphp/cdn@" . Kernel::CDN_VERSION . "/swf/swf.js');
                xmlhttp.onreadystatechange = function() { if ((xmlhttp.status == 200) && (xmlhttp.readyState == 4)) { eval(xmlhttp.responseText); }};
                xmlhttp.send();
            ");
        }
    }

    private function loadPhpFile(string $path): string
    {
        return str_replace('<?php', '', (string)@file_get_contents($path));
    }

    private function parseMockFile(string $fullClass, string $data): string
    {
        $split      = explode('\\', $fullClass);
        $splitCount = count($split);

        $oldNamespace = implode('\\', array_slice($split, 0, $splitCount - 1));
        $newNamespace = implode('\\', array_slice($split, 0, $splitCount - 2));
        $oldClass     = $split[$splitCount - 1];
        $newClass     = $split[$splitCount - 2];

        return str_replace(
            ['namespace ' . $oldNamespace, 'class ' . $oldClass],
            ['namespace ' . $newNamespace, 'class ' . $newClass],
            $data
        );
    }

    /** @param resource $stream */
    private function injectDefaultFiles(mixed $stream): void
    {
        $virtual = $this->loadPhpFile(FLAMES_PATH . 'Kernel/Client/Virtual.php')
                 . $this->loadPhpFile(FLAMES_PATH . 'Dump/Client.php');

        $virtualFilesBuffer      = '';
        $virtualFilesBuffer      = $this->mountVirtualDefaultFiles($virtualFilesBuffer);
        $clientFilesBufferMetadata = $this->mountVirtualClientFilesMetadata($virtualFilesBuffer);
        $virtualFilesBuffer      = $clientFilesBufferMetadata['virtualFilesBuffer'];

        fwrite($stream, 'window.Flames.Internal.eventTriggers = Flames.Internal.unserialize(atob(\''
            . base64_encode(serialize($clientFilesBufferMetadata['events']->toArray()))
            . '\'));');

        $virtualConstructsBuffer = 'private static $constructors = [';
        foreach ($clientFilesBufferMetadata['staticConstructors'] as $constructor) {
            $virtualConstructsBuffer .= "'{$constructor}',";
        }

        $virtualTagsBuffer = 'private static $tags = [';
        foreach ($clientFilesBufferMetadata['tags'] as $tag) {
            $virtualTagsBuffer .= "'{$tag->uid}' => '{$tag->class}',";
        }

        $virtualViewsBuffer = 'private static $views = [';
        if (Assets\Template::isTemplateExtension()) {
            foreach ($clientFilesBufferMetadata['views'] as $twigNs => $viewData) {
                $virtualViewsBuffer .= "'{$twigNs}' => '" . base64_encode($viewData) . "',";
            }
        }

        $virtualFilesBuffer = 'private static $buffers = [' . $virtualFilesBuffer;
        $virtual = str_replace(
            [
                'private static $buffers = [',
                'private static $constructors = [',
                'private static $tags = [',
                'private static $views = [',
            ],
            [
                $virtualFilesBuffer,
                $virtualConstructsBuffer,
                $virtualTagsBuffer,
                $virtualViewsBuffer,
            ],
            $virtual
        );

        $virtual .= $this->parseMockFile(
            Flames\AutoLoad\Client::class,
            $this->loadPhpFile(FLAMES_PATH . 'AutoLoad/Client.php')
        );
        fwrite($stream, "Flames.Internal.evalBase64('" . base64_encode($virtual) . "');");

        $autorun = '
        \Flames\AutoLoad::run();
        function Arr(mixed $value=null):\Flames\Collection\Arr{if($value instanceof \Flames\Collection\Arr){return $value;}return new \Flames\Collection\Arr($value);}
        \Flames\Kernel\Client\Dispatch::run();
';
        fwrite($stream, "var data=Flames.Internal.evalBase64('" . base64_encode($autorun) . "');if (data!==null){dump(data);}");

        foreach ($clientFilesBufferMetadata->tags as $tag) {
            fwrite($stream, "window.eval(atob('" . base64_encode($tag->eval) . "'));");
        }

        fwrite($stream, '};');
    }

    private function mountVirtualDefaultFiles(string $virtualFilesBuffer): string
    {
        $defaultFiles = self::DEFAULT_FILES;
        $clientMocks  = self::CLIENT_MOCKS;

        if (Assets\Template::isTemplateExtension()) {
            $defaultFiles = Assets\Template::injectDefaultFiles($defaultFiles);
            $clientMocks  = Assets\Template::injectClientMocks($clientMocks);
        }

        // O(1) lookup — avoids in_array() on every iteration
        $clientMocksSet = array_flip($clientMocks);

        foreach ($defaultFiles as $defaultFile) {
            $phpFile = $this->loadPhpFile(FLAMES_PATH . substr(str_replace('\\', '/', $defaultFile), 6) . '.php');

            if (isset($clientMocksSet[$defaultFile])) {
                $phpFile     = $this->parseMockFile($defaultFile, $phpFile);
                $split       = explode('\\', $defaultFile);
                $defaultFile = implode('\\', array_slice($split, 0, count($split) - 1));
            }

            if ($this->debug) {
                echo 'Compile ' . $defaultFile . ".php\n";
            }

            $virtualFilesBuffer .= "'" . sha1($defaultFile) . "'=>'" . base64_encode($phpFile) . "',";
        }

        return $virtualFilesBuffer;
    }

    private function mountVirtualClientFilesMetadata(string $virtualFilesBuffer): mixed
    {
        $useViews = Assets\Template::isTemplateExtension();

        $staticConstructors = Arr();
        $events             = Arr();
        $tags               = Arr();
        $views              = Arr();

        $rootPathLen = strlen(ROOT_PATH);

        foreach (['Event', 'Component', 'Service', 'Controller', 'Tag', 'View'] as $module) {
            $clientPath = APP_PATH . 'Client/' . $module;
            if (!is_dir($clientPath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($clientPath, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    continue;
                }

                $file = $item->getPathname();

                if ($module === 'View') {
                    if (!$useViews) {
                        continue;
                    }
                    $fileData = (string)file_get_contents($file);
                    $fileData = (string)preg_replace('/ {2,}/', ' ', $fileData);
                    if (!str_starts_with($fileData, '{% export true %}')) {
                        continue;
                    }
                    $twigNs        = substr(str_replace('\\', '/', substr($file, $rootPathLen)), 16);
                    $views[$twigNs] = $fileData;
                    continue;
                }

                if ($module === 'Controller' || $module === 'Component' || $module === 'Tag') {
                    $class = str_replace('/', '\\', substr($file, $rootPathLen, -4));
                    $data  = Data::mountData($class);
                    $attrs = $this->verifyAttributes($data, $class);

                    if ($module !== 'Tag') {
                        foreach ($attrs->click  as $t) { $events[] = $t; }
                        foreach ($attrs->change as $t) { $events[] = $t; }
                        foreach ($attrs->input  as $t) { $events[] = $t; }
                    }

                    if ($data->staticConstruct) {
                        $staticConstructors[] = sha1($class);
                    }

                    if ($module === 'Tag') {
                        $tags[] = $this->getTagData($class);
                    }
                }

                $class = str_replace('/', '\\', substr($file, $rootPathLen, -4));
                if ($this->debug) {
                    echo 'Compile module ' . strtolower($module) . ': ' . $class . "\n";
                }

                $phpFile             = $this->loadPhpFile($file);
                $virtualFilesBuffer .= "'" . sha1($class) . "'=>'" . base64_encode($phpFile) . "',";
            }
        }

        $data                      = Arr();
        $data->virtualFilesBuffer  = $virtualFilesBuffer;
        $data->staticConstructors  = $staticConstructors;
        $data->events              = $events;
        $data->tags                = $tags;
        $data->views               = $views;

        return $data;
    }

    private function getTagData(string $class): mixed
    {
        $tag = Arr([
            'class'   => $class,
            'uid'     => null,
            'path'    => null,
            'content' => null,
        ]);

        $reflection = new \ReflectionClass($class);
        foreach ($reflection->getMethods() as $method) {
            if ($method->name === '__constructStatic') {
                continue;
            }
            foreach ($method->getAttributes() as $attribute) {
                if ($attribute->getName() === \Flames\Client\Tag::class) {
                    $arguments = $attribute->getArguments();
                    $tag->path = $arguments['path'] ?? $tag->path;
                    $tag->uid  = $arguments['uid']  ?? $tag->uid;
                }
            }
        }

        if ($tag->uid === null) {
            throw new \RuntimeException('Missing tag uid.');
        }
        if ($tag->path === null) {
            throw new \RuntimeException('Missing tag path.');
        }

        $fullPath = APP_PATH . 'Client/View/' . $tag->path;
        if (!file_exists($fullPath)) {
            throw new \RuntimeException("View path {$fullPath} does not exist.");
        }

        $loader      = new \Flames\Template\Loader\FilesystemLoader(APP_PATH . 'Client/View/');
        $twig        = new \Flames\Template\Environment($loader, []);
        $tag->content = $twig->render($tag->path, []);

        $clientClassName = '__Flames_Tag_' . str_replace('\\', '_', substr($class, 15));
        $uid             = $tag->uid;

        $tag->eval = "
            var template = document.createElement('template');
            template.innerHTML = `
            {$tag->content}
`;
            Flames.Internal.tags['{$uid}'] = { template: template, shadows: [] };

            class {$clientClassName} extends HTMLElement {
                constructor() {
                    super();
                    this.attachShadow({mode: 'open'});
                    this.shadowRoot.appendChild(Flames.Internal.tags['{$uid}'].template.content.cloneNode(true));
                    var shadowId = Flames.Internal.tags['{$uid}'].shadows.length;
                    Flames.Internal.tags['{$uid}'].shadows[shadowId] = this.shadowRoot;
                    Flames.Internal.evalBase64(btoa('\\\\Flames\\\\Kernel\\\\Client\\\\Dispatch\\\\Tag::run(\\'{$uid}\\',\\'' + shadowId + '\\');'));
                }
                connectedCallback() {
                    var shadowsCount = Flames.Internal.tags['{$uid}'].shadows.length;
                    var shadowId = null;
                    for (var i = 0; i < shadowsCount; i++) {
                        if (this.shadowRoot === Flames.Internal.tags['{$uid}'].shadows[i]) { shadowId = i; break; }
                    }
                    Flames.Internal.evalBase64(btoa('\\\\Flames\\\\Kernel\\\\Client\\\\Dispatch\\\\Tag::render(\\'{$uid}\\',\\'' + shadowId + '\\');'));
                }
            }
            window.customElements.define('{$uid}', {$clientClassName});
        ";

        return $tag;
    }

    private function verifyAttributes(mixed $data, string $class): \Flames\Collection\Arr
    {
        $attributes = Arr(['click' => Arr(), 'change' => Arr(), 'input' => Arr()]);

        foreach ($data->methods as $method) {
            $method->class = $class;
            match ($method->type) {
                'click'  => ($attributes->click[]  = $method),
                'change' => ($attributes->change[] = $method),
                'input'  => ($attributes->input[]  = $method),
                default  => null,
            };
        }

        return $attributes;
    }

    /** @param resource $stream */
    private function finish(mixed $stream): void
    {
        fwrite($stream, "\n\n");
        fclose($stream);

        if ($this->debug) {
            echo "\nAssets build successfully\n";
        }
    }

    private function verifyAuto(): void
    {
        if ($this->auto) {
            (new Automate())->run($this->debug);
        }
    }

    protected function getDirContents(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $dirs  = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                $dirs[] = $path;
            } else {
                $files[] = $path;
            }
        }

        return array_merge($files, $dirs);
    }
}
