<?php

namespace Utopia\Orchestration\Adapter;

use Exception;
use Utopia\CLI\Console;
use Utopia\Orchestration\Adapter;

class DockerCLI extends Adapter
{
    public function __construct(string $username = null, string $password = null)
    {
        if($username && $password) {
            $stdout = '';
            $stderr = '';

            if (!Console::execute('docker login --username '.$username.' --password-stdin', $password, $stdout, $stderr)) {
                throw new Exception("Docker Error: {$stderr}");
            };
        }
    }

    public function pull(string $image): bool
    {
        $stdout = '';
        $stderr = '';

        $result = Console::execute('docker pull '.$image, '', $stdout, $stderr);

        if ($result !== 0) {
            throw new Exception("Docker Error: {$stderr}");
        }

        return !$result;
    }

    public function list(): array
    {
        $stdout = '';
        $stderr = '';

        $result = Console::execute('docker ps --all --format "name={{.Names}}&status={{.Status}}&labels={{.Labels}}" --filter label='.$this->namespace.'-type=runtime', '', $stdout, $stderr);

        if ($result !== 0) {
            throw new Exception("Docker Error: {$stderr}");
        }

        $list = [];
        $stdoutArray = \explode("\n", $stdout);

        \array_map(function($value) use (&$list) {
            $container = [];
        
            \parse_str($value, $container);
        
            if(isset($container['name'])) {
                $container = [
                    'name' => $container['name'],
                    'online' => (\substr($container['status'], 0, 2) === 'Up'),
                    'status' => $container['status'],
                    'labels' => $container['labels'],
                ];
            
                \array_map(function($value) use (&$container) {
                    $value = \explode('=', $value);

                    if(isset($value[0]) && isset($value[1])) {
                        $container[$value[0]] = $value[1];
                    }
                }, \explode(',', $container['labels']));
            
                $list[$container['name']] = $container;
            }
        }, $stdoutArray);

        return ($list);
    }

    public function run(string $image, string $name, string $entrypoint = '', string $command = '', string $workdir = '/', array $volumes = [], array $vars = [], string $mountFolder = ''): bool
    {
        $stdout = '';
        $stderr = '';

        $time = time();
        $result = Console::execute("docker run ".
            " -d".
            " --entrypoint=\"{$entrypoint}\"".
            (empty($this->cpus) ? "" : (" --cpus=".$this->cpus)).
            (empty($this->memory) ? "" : (" --memory=".$this->memory."m")).
            (empty($this->swap) ? "" : (" --memory-swap=".$this->swap."m")).
            " --name={$name}".
            " --label {$this->namespace}-type=runtime".
            " --label {$this->namespace}-created={$time}".
            " --volume {$mountFolder}:/tmp:rw".
            " --workdir {$workdir}".
            " ".\implode(" ", $vars).
            " {$image}".
            " {$command}"
            , '', $stdout, $stderr, 30);

        if (!empty($stderr)) {
            throw new Exception("Docker Error: {$stderr}");
        }

        return !$result;
    }

    public function execute(string $name, string $command, array $vars = []): bool
    {
        $stdout = '';
        $stderr = '';

        $result = Console::execute("docker exec ".\implode(" ", $vars)." {$name} {$command}"
            , '', $stdout, $stderr, 30);
            
        if ($result !== 0) {
            throw new Exception("Docker Error: {$stderr}");
        }

        return !$result;
    }

    public function executeWithStdout(string $name, string $command, array $vars = []): string
    {
        $stdout = '';
        $stderr = '';

        $result = Console::execute("docker exec ".\implode(" ", $vars)." {$name} {$command}"
            , '', $stdout, $stderr, 30);
            
        if ($result !== 0) {
            throw new Exception("Docker Error: {$stderr}");
        }

        return $stdout;
    }

    public function remove($name, $force = false): bool
    {
        $stdout = '';
        $stderr = '';

        $result = Console::execute("docker rm " . ($force ? '--force': '') . " {$name}", '', $stdout, $stderr);

        if ($result !== 0) {
            throw new Exception("Docker Error: {$stderr}");
        }

        return !$result;
    }
}