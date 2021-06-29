<?php

namespace Utopia\Orchestration\Adapter;

use Utopia\CLI\Console;
use Utopia\Orchestration\Adapter;

class DockerCLI extends Adapter
{
    public function __construct($username, $password)
    {
        if($username) {
            Console::execute('docker login --username '.$username.' --password-stdin', $password, '', '');
        }
    }

    public function pull(string $image): bool
    {
        return (Console::execute('docker pull '.$image, '', '', '') === 0);
    }

    public function list(): bool
    {
        return (Console::execute('docker ps --all --format "name={{.Names}}&status={{.Status}}&labels={{.Labels}}" --filter label='.$this->namespace.'-type=runtime', '', '', '') === 0);
    }

    public function run(string $image, string $name, string $entrypoint = '', string $command = '', string $workdir = '/', array $volumes = [], array $vars = []): bool
    {
        $time = time();
        $code = Console::execute("docker run ".
            " -d".
            " --entrypoint=\"{$entrypoint}\"".
            (empty($this->cpus) ? "" : (" --cpus=".$this->cpus)).
            (empty($this->memory) ? "" : (" --memory=".$this->memory."m")).
            (empty($this->swap) ? "" : (" --memory-swap=".$this->swap."m")).
            " --name={$name}".
            " --label {$this->namespace}-type=runtime".
            " --label {$this->namespace}-created={$time}".
            " --volume /xxx/xxx:/tmp:rw".
            " --workdir {$workdir}".
            " ".\implode(" ", $vars).
            " {$image}".
            " {$command}"
            , '', $stdout, $stderr, 30);

        return ($code === 0);
    }

    public function execute(string $name, string $command, array $vars = []): bool
    {
        $code = Console::execute("docker exec ".\implode(" ", $vars)." {$name} {$command}"
            , '', $stdout, $stderr, 30);

        return ($code === 0);
    }

    public function remove($name): bool
    {
        return (Console::execute("docker rm {$name}", '', '', '') === 0);
    }
}