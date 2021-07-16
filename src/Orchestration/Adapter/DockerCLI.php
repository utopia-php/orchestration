<?php

namespace Utopia\Orchestration\Adapter;

use Utopia\CLI\Console;
use Utopia\Orchestration\Adapter;
use Utopia\Orchestration\StandardContainer;
use Utopia\Orchestration\Exceptions\DockerCLIException;

class DockerCLI extends Adapter
{
    /**
     * Constructor
     * 
     * @param string $usernam
     * @param string $password
     */
    public function __construct(string $username = null, string $password = null)
    {
        if($username && $password) {
            $stdout = '';
            $stderr = '';

            if (!Console::execute('docker login --username '.$username.' --password-stdin', $password, $stdout, $stderr)) {
                throw new DockerCLIException("Docker Error: {$stderr}");
            };
        }
    }

    public function pull(string $image): bool
    {
        $stdout = '';
        $stderr = '';

        $result = Console::execute('docker pull '.$image, '', $stdout, $stderr);

        if ($result !== 0) {
            throw new DockerCLIException("Docker Error: {$stderr}");
        }

        return !$result;
    }

    public function list(): array
    {
        $stdout = '';
        $stderr = '';

        $result = Console::execute('docker ps --all --format "id={{.ID}}&name={{.Names}}&status={{.Status}}&labels={{.Labels}}"', '', $stdout, $stderr);

        if ($result !== 0) {
            throw new DockerCLIException("Docker Error: {$stderr}");
        }

        $list = [];
        $stdoutArray = \explode("\n", $stdout);

        \array_map(function($value) use (&$list) {
            $container = [];
        
            \parse_str($value, $container);
        
            if(isset($container['name'])) {
                $parsedContainer = new StandardContainer();
                $parsedContainer->name = $container['name'];
                $parsedContainer->id = $container['id'];
                $parsedContainer->status = $container['status'];
            
                \array_map(function($value) use (&$parsedContainer) {
                    $value = \explode('=', $value);

                    if(isset($value[0]) && isset($value[1])) {
                        $parsedContainer->labels[$value[0]] = $value[1];
                    }
                }, \explode(',', $container['labels']));
            
                $list[$container['name']] = $parsedContainer;
            }
        }, $stdoutArray);

        return ($list);
    }

    public function run(string $image, string $name, string $entrypoint = '', array $command = [], string $workdir = '/', array $volumes = [], array $vars = [], string $mountFolder = '', array $labels = []): bool
    {
        $stdout = '';
        $stderr = '';

        $command = \array_map(function($value) {
            if (str_contains($value, " ")) {
                $value = "'".$value."'";
            }

            return $value;
        }, $command);

        $labelString = ' ';

        \array_walk($labels, function (string $key, string $value) use ($labelString) {
            $labelString = $labelString . "--label {$key}={$value}";
        });

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
            $labelString .
            " --workdir {$workdir}".
            " ".\implode(" ", $vars).
            " {$image}".
            " ".implode(" ", $command)
            , '', $stdout, $stderr, 30);

        if (!empty($stderr)) {
            throw new DockerCLIException("Docker Error: {$stderr}");
        }

        return !$result;
    }

    public function execute(string $name, array $command, string &$stdout = '', string &$stderr = '', array $vars = [], int $timeout = 0): bool
    {
        $result = Console::execute("docker exec ".\implode(" ", $vars)." {$name} ".implode(" ", $command)
            , '', $stdout, $stderr, 30);
            
        if ($result !== 0) {
            throw new DockerCLIException("Docker Error: {$stderr}");
        }

        return !$result;
    }

    public function executeWithStdout(string $name, array $command, array $vars = []): string
    {
        $stdout = '';
        $stderr = '';

        $result = Console::execute("docker exec ".\implode(" ", $vars)." {$name} ".implode(" ", $command)
            , '', $stdout, $stderr, 30);
            
        if ($result !== 0) {
            throw new DockerCLIException("Docker Error: {$stderr}");
        }

        return $stdout;
    }

    public function remove($name, $force = false): bool
    {
        $stdout = '';
        $stderr = '';

        $result = Console::execute("docker rm " . ($force ? '--force': '') . " {$name}", '', $stdout, $stderr);

        if (!str_contains($stdout, $name)) {
            throw new DockerCLIException("Docker Error: {$stderr}");
        }

        return !$result;
    }
}