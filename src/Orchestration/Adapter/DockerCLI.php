<?php

namespace Utopia\Orchestration\Adapter;

use Utopia\CLI\Console;
use Utopia\Orchestration\Adapter;
use Utopia\Orchestration\Container;
use Utopia\Orchestration\Exceptions\DockerCLIException;
use Utopia\Orchestration\Exceptions\TimeoutException;

class DockerCLI extends Adapter
{
    /**
     * Constructor
     * 
     * @param string $username
     * @param string $password
     * @return void
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


    /**
     * Pull Image
     * 
     * @param string $image
     * 
     * @return bool
     */
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

    /**
     * List Containers
     * @param array<string, string> $filters
     *
     * @return Container[]
     */
    public function list(array $filters = []): array
    {
        $stdout = '';
        $stderr = '';

        $filterString = '';
        array_walk($filters,
            function(string $value, string $key) use (&$filterString){
                $filterString = $filterString . ' --filter "'.$key.'='.$value.'"';
            });

        $result = Console::execute('docker ps --all --no-trunc --format "id={{.ID}}&name={{.Names}}&status={{.Status}}&labels={{.Labels}}"'.$filterString, '', $stdout, $stderr);

        if ($result !== 0) {
            throw new DockerCLIException("Docker Error: {$stderr}");
        }

        $list = [];
        $stdoutArray = \explode("\n", $stdout);

        \array_map(function($value) use (&$list) {
            $container = [];
        
            \parse_str($value, $container);
        
            if(isset($container['name'])) {
                $labelsParsed = [];

                \array_map(function($value) use (&$labelsParsed) {
                    $value = \explode('=', $value);

                    if(isset($value[0]) && isset($value[1])) {
                        $labelsParsed[$value[0]] = $value[1];
                    }
                }, \explode(',', $container['labels']));

                $parsedContainer = new Container($container['name'], $container['id'], $container['status'], $labelsParsed);
            
                array_push($list, $parsedContainer);
            }
        }, $stdoutArray);

        return ($list);
    }

    /**
     * Run Container
     * 
     * Creates and runs a new container, On success it will return a string containing the container ID.
     * On fail it will throw an exception.
     * 
     * @param string $image
     * @param string $name
     * @param string $entrypoint
     * @param string[] $command
     * @param string $workdir
     * @param string[] $volumes
     * @param array<string, string> $vars
     * @param string $mountFolder
     * 
     * @return string
     */
    public function run(string $image, string $name, string $entrypoint = '', array $command = [], string $workdir = '/', array $volumes = [], array $vars = [], string $mountFolder = '', array $labels = []): string
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

        \array_walk($vars, function (string &$value, string $key) {
            $key = $this->filterEnvKey($key);

            $value = \escapeshellarg((empty($value)) ? '' : $value);
            $value = "--env {$key}={$value}";
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

        if (!empty($stderr) || $result !== 0) {
            throw new DockerCLIException("Docker Error: {$stderr}");
        }

        return rtrim($stdout);
    }

    /**
     * Execute Container
     *
     * @param string $name
     * @param string[] $command
     * @param string &$stdout
     * @param string &$stderr
     * @param array<string, string> $vars
     * @param int $timeout
     * @return bool
     */
    public function execute(string $name, array $command, string &$stdout = '', string &$stderr = '', array $vars = [], int $timeout = 0): bool
    {
        \array_walk($vars, function (string &$value, string $key) {
            $key = $this->filterEnvKey($key);

            $value = \escapeshellarg((empty($value)) ? '' : $value);
            $value = "--env {$key}={$value}";
        });

        $result = Console::execute("docker exec ".\implode(" ", $vars)." {$name} ".implode(" ", $command)
            , '', $stdout, $stderr, $timeout);
            
        if ($result !== 0) {
            if ($result == 1) {
                throw new TimeoutException("Command timed out");
            } else {
                throw new DockerCLIException("Docker Error: {$stderr}");
            }
        }

        return !$result;
    }

    /**
     * Remove Container
     *
     * @param string $name
     * @param bool $force
     * @return bool
     */
    public function remove(string $name, bool $force = false): bool
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