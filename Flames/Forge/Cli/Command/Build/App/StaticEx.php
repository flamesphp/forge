<?php

declare(strict_types=1);

namespace Flames\Forge\Cli\Command\Build\App;

use Flames\Collection\Arr;
use Flames\Command;
use Flames\Controller\Response;
use Flames\Environment;
use Flames\Event;
use Flames\Header;
use Flames\Kernel;
use Flames\Kernel\Route;
use ZipArchive;

class StaticEx
{
    protected bool $debug = false;
    protected string $buildPath;
    protected Arr|null $inputs;

    protected static bool $isRunningBuild = false;

    protected bool $cloudflare = false;

    public function __construct(mixed $data)
    {
        $this->cloudflare = (bool)($data->option->contains('cloudflare') ?? false);
    }

    public function run(bool $debug = false): bool
    {
        if (self::$isRunningBuild) {
            return false;
        }
        self::$isRunningBuild = true;

        $this->debug     = $debug;
        $this->buildPath = ROOT_PATH . '.cache/build/';

        if (!is_dir($this->buildPath)) {
            $mask = umask(0);
            mkdir($this->buildPath, 0777, true);
            umask($mask);
        }

        $this->cleanBuild();
        $this->copyPublic();
        $this->saveInputs();

        $router = Kernel::getDefaultRouter();
        $metadatas = $router->getMetadata();

        foreach ($metadatas as $metadata) {
            if (str_contains($metadata->methods, 'GET') !== true) {
                continue;
            }

            $_SERVER['REQUEST_URI'] = $metadata->routeFormatted;
            $match = $router->getMatch();
            $responseData = $this->getResponse($match);

            $this->saveResponse($metadata, $responseData);
        }

        Command::run('build:assets');

        $this->buildFlames();
        $this->buildZip();
        $this->restoreInputs();

        self::$isRunningBuild = false;
        return true;
    }

    protected function buildZip(): void
    {
        $buildZipPath = APP_PATH . 'Client/Build/';
        if (!is_dir($buildZipPath)) {
            $mask = umask(0);
            mkdir($buildZipPath, 0777, true);
            umask($mask);
        }

        $appName = (string)(Environment::get('APP_NAME') ?? '');
        $zipName = ($appName !== '' ? strtolower($appName) . '_' : 'build_')
                 . (new \DateTimeImmutable())->format('Y_m_d_His');
        $zipPath = $buildZipPath . $zipName . '.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $buildPathLen = strlen($this->buildPath);
        foreach ($this->getDirContents($this->buildPath) as $buildFile) {
            if (!is_dir($buildFile)) {
                $zip->addFile($buildFile, substr($buildFile, $buildPathLen));
            }
        }
        $zip->close();
    }

    protected function buildFlames(): void
    {
        $buildStream = fopen($this->buildPath . 'flames.js', 'w+');

        $clientPath = APP_PATH . 'Client/Resource/client.js';
        if (file_exists($clientPath)) {
            $fileStream = fopen($clientPath, 'r');
            stream_copy_to_stream($fileStream, $buildStream);
            fclose($fileStream);
        }

        $fileStream = fopen(APP_PATH . 'Client/Resource/Build/Flames.js', 'r');
        stream_copy_to_stream($fileStream, $buildStream);
        fclose($fileStream);
        fclose($buildStream);

        $flamesDir = $this->buildPath . '.flames';
        if (!is_dir($flamesDir)) {
            $mask = umask(0);
            mkdir($flamesDir, 0777, true);
            umask($mask);
        }
    }

    protected function cleanBuild(): void
    {
        $buildFiles = $this->getDirContents($this->buildPath);
        foreach ($buildFiles as $buildFile) {
            if (is_file($buildFile)) {
                unlink($buildFile);
            }
        }
        foreach ($buildFiles as $buildFile) {
            if (is_dir($buildFile)) {
                rmdir($buildFile);
            }
        }
    }

    protected function copyPublic(): void
    {
        $publicPath = APP_PATH . 'Client/Public/';
        if (!is_dir($publicPath)) {
            return;
        }

        $publicPathLen = strlen($publicPath);
        $publicFiles = $this->getDirContents($publicPath);
        foreach ($publicFiles as $publicFile) {
            if (is_dir($publicFile)) {
                continue;
            }

            $buildFile = ($this->buildPath . substr($publicFile, $publicPathLen));
            $buildDir  = dirname($buildFile);

            if (!is_dir($buildDir)) {
                $mask = umask(0);
                mkdir($buildDir, 0777, true);
                umask($mask);
            }

            copy($publicFile, $buildFile);
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


    protected function saveResponse(mixed $metadata, mixed $responseData): void
    {
        $url = $metadata->routeFormatted;
        if ($url === '404') {
            $url = '/404';
        }

        if (!str_ends_with($url, '/')) {
            $url .= '/';
        }

        $urls = $url !== '/'
            ? [$url . 'index.html', substr($url, 0, -1) . '.html']
            : ['/index.html'];

        $isUnix = str_contains(ROOT_PATH, '/');
        foreach ($urls as &$u) {
            $u = $isUnix
                ? str_replace('\\', '/', substr($u, 1))
                : str_replace('/', '\\', substr($u, 1));
        }
        unset($u);

        $output = $responseData->output ?? '';

        foreach ($urls as $url) {
            $path    = $this->buildPath . $url;
            $dirPath = dirname($path);
            if (!is_dir($dirPath)) {
                $mask = umask(0);
                mkdir($dirPath, 0777, true);
                umask($mask);
            }
            file_put_contents($path, $output);
        }

        $this->saveHeader($metadata, $responseData, $urls);
    }

    protected function saveHeader(mixed $metadata, mixed $responseData, array $urls): void {}

    public function getResponse(mixed $routeData): bool|Arr
    {
        Header::set('X-Powered-By', 'Flames');
        if (Event::dispatch('Initialize', 'onInitialize') === false) {
            return false;
        }

        $requestData      = Route::mountRequestData($routeData);
        $requestDataAllow = Event::dispatch('Route', 'onMatch', $requestData);
        if ($requestDataAllow === false) {
            return false;
        }

        $controller = new $routeData->controller();
        $response   = $controller->{$routeData->delegate}($requestData);

        if (!($response instanceof Response)) {
            $response = new Response($response);
        }

        $output = $response->output;

        $_output = Event::dispatch('Output', 'onOutput', $requestData, $output);
        if ($_output !== null) {
            $output = (string)$_output;
        }

        Header::set('Code', $response->code);
        if ($response->headers !== null) {
            foreach ($response->headers as $key => $value) {
                Header::set($key, $value);
            }
        }

        $data = Arr([
            'header' => Header::getAll(),
            'output' => $output
        ]);

        Header::clear();
        return $data;
    }


    protected function saveInputs(): void
    {
        $this->inputs = Arr([
            'get'       => $_GET,
            'post'      => $_POST,
            'request'   => $_REQUEST,
            'cookie'    => $_COOKIE,
            'uri'       => @$_SERVER['REQUEST_URI'],
            'method'    => @$_SERVER['REQUEST_METHOD'],
            'header'    => Header::getAll(),
            'client_ip' => @$_SERVER['HTTP_CLIENT_IP'],
            'forwarded' => @$_SERVER['HTTP_X_FORWARDED_FOR'],
            'addr'      => @$_SERVER['REMOTE_ADDR'],
            'script'    => @$_SERVER['SCRIPT_FILENAME'],
            'svname'    => @$_SERVER['SERVER_NAME'],
            'svport'    => @$_SERVER['SERVER_PORT']

        ]);

        $_GET     = [];
        $_POST    = [];
        $_REQUEST = [];
        $_COOKIE  = [];

        $_SERVER['REQUEST_URI']          = '/';
        $_SERVER['REQUEST_METHOD']       = 'GET';
        $_SERVER['REMOTE_ADDR']          = null;
        $_SERVER['HTTP_CLIENT_IP']       = null;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = null;
        $_SERVER['SCRIPT_FILENAME']      = null;
        $_SERVER['SERVER_NAME']          = 'localhost';
        $_SERVER['SERVER_PORT']          = '80';

        Header::clear();
    }

    protected function restoreInputs(): void
    {
        $_GET     = $this->inputs->get;
        $_POST    = $this->inputs->post;
        $_REQUEST = $this->inputs->request;
        $_COOKIE  = $this->inputs->cookie;

        $_SERVER['REQUEST_URI']          = $this->inputs->uri;
        $_SERVER['REQUEST_METHOD']       = $this->inputs->method;
        $_SERVER['HTTP_CLIENT_IP']       = $this->inputs->client_ip;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $this->inputs->forwarded;
        $_SERVER['REMOTE_ADDR']          = $this->inputs->addr;
        $_SERVER['SCRIPT_FILENAME']      = $this->inputs->script;
        $_SERVER['SERVER_NAME']          = $this->inputs->svname;
        $_SERVER['SERVER_PORT']          = $this->inputs->svport;

        Header::clear();
        foreach ($this->inputs->header as $key => $value) {
            Header::set($key, $value);
        }
    }
}
