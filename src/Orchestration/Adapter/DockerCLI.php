<?php

namespace Utopia\Orchestration\Adapter;

use Exception;
use Utopia\CLI\Console;
use Utopia\Orchestration\Adapter;
use Utopia\Orchestration\Container;
use Utopia\Orchestration\Exception\Orchestration;
use Utopia\Orchestration\Exception\Timeout;
use Utopia\Orchestration\Network;

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
                throw new Orchestration("Docker Error: {$stderr}");
            };
        }
    }

    /**
     * Create Network
     * 
     * @param string $name
     * @param bool $internal
     * 
     * @return bool
     */
    public function createNetwork(string $name, bool $internal = false): bool
    {
        $stdout = '';
        $stderr = '';

        $result = Console::execute('docker network create '.$name . ($internal ? '--internal' : ''), '', $stdout, $stderr);
        
        return $result === 0;
    }

    /**
     * Remove Network
     * 
     * @param string $name
     * 
     * @return bool
     */
    public function removeNetwork(string $name): bool
    {
        $stdout = '';
        $stderr = '';
        
        $result = Console::execute('docker network rm '.$name, '', $stdout, $stderr);

        return $result === 0;
    }

    /**
     * Connect a container to a network
     * 
     * @param string $container
     * @param string $network
     * 
     * @return bool
     */
    public function networkConnect(string $container, string $network): bool 
    {
        $stdout = '';
        $stderr = '';
        
        $result = Console::execute('docker network connect '.$network . ' ' . $container, '', $stdout, $stderr);

        return $result === 0;
    }

    /**
     * Disconnect a container from a network
     * 
     * @param string $container
     * @param string $network
     * @param bool $force
     * 
     * @return bool
    */
    public function networkDisconnect(string $container, string $network, bool $force = false): bool 
    {
        $stdout = '';
        $stderr = '';

        $result = Console::execute('docker network disconnect '.$network . ' ' . $container . ($force ? ' --force' : ''), '', $stdout, $stderr);

        return $result === 0;
    }

     /**
     * Get usage stats of containers
     * 
     * @param string $container
     * 
     * @return array
     */
    public function getStats(string $container = null): array
    {
        $stats = [];

        $stdout = '';
        $stderr = '';

        $result = Console::execute('docker stats --no-trunc --format "id={{.ID}}&name={{.Name}}&cpu={{.CPUPerc}}&memory={{.MemPerc}}&diskIO={{.BlockIO}}&memoryIO={{.MemUsage}}&networkIO={{.NetIO}}" --no-stream ' . $container, '', $stdout, $stderr);
        
        if($result !== 0) {
            throw new Orchestration("Docker Error: {$stderr}");
        }

        $lines = \explode("\n", $stdout);

        foreach($lines as $line) {
            if(empty($line)) {
                continue;
            }

            $stat = [];
            \parse_str($line, $stat);

            $stats[] = [
                'id' => $stat['id'],
                'name' => $stat['name'],
                'cpu' => \floatval(\rtrim($stat['cpu'], '%')), // Remove percentage symbol and parse to number
                'memory' => empty($stat['memory']) ? 0 : \floatval(\rtrim($stat['memory'], '%')), // Remove percentage symbol and parse to number. Value is empty on Windows
                'diskIO' => $this->parseIOStats($stat['diskIO']),
                'memoryIO' => $this->parseIOStats($stat['memoryIO']),
                'networkIO' => $this->parseIOStats($stat['networkIO']),
            ];
        }

        return $stats;
    }

    private function parseIOStats(string $stats) {
        $units = [
            'B' => 1,
            'KB' => 1000,
            'MB' => 1000000,
            'GB' => 1000000000,
            'TB' => 1000000000000,

            'KiB' => 1000,
            'MiB' => 1000000,
            'GiB' => 1000000000,
            'TiB' => 1000000000000,
        ];

        [ $inStr, $outStr ] = \explode(' / ', $stats);

        $inUnit = null;
        $outUnit = null;

        foreach ($units as $unit) {
            if(\str_ends_with($inStr, $unit)) {
                $inUnit = $unit;
            } else if(\str_ends_with($outStr, $unit)) {
                $outUnit = $unit;
            }
        }

        $inMultiply = $inUnit === null ? 1 : $units[$inUnit];
        $outMultiply = $outUnit === null ? 1 : $units[$outUnit];

        $inValue = \floatval(\rtrim($inStr, $inUnit));
        $outValue = \floatval(\rtrim($outStr, $outUnit));

        $response = [
            'in' => $inValue * $inMultiply,
            'out' => $outValue * $outMultiply,
        ];

        return $response;
    }

    /**
     * List Networks
     * 
     * @return array
     */
    public function listNetworks(): array
    {
        $stdout = '';
        $stderr = '';
        
        $result = Console::execute('docker network ls --format "id={{.ID}}&name={{.Name}}&driver={{.Driver}}&scope={{.Scope}}"', '', $stdout, $stderr);

        if($result !== 0) {
            throw new Orchestration("Docker Error: {$stderr}");
        }

        $list = [];
        $stdoutArray = \explode("\n", $stdout);

        foreach($stdoutArray as $value) {
            $network = [];
        
            \parse_str($value, $network);
        
            if(isset($network['name'])) {
                $parsedNetwork = new Network($network['name'], $network['id'], $network['driver'], $network['scope']);
            
                array_push($list, $parsedNetwork);
            }
        }

        return $list;
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

        return $result === 0;
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

        foreach($filters as $key => $value) {
            $filterString = $filterString . ' --filter "'.$key.'='.$value.'"';
        }

        $result = Console::execute('docker ps --all --no-trunc --format "id={{.ID}}&name={{.Names}}&status={{.Status}}&labels={{.Labels}}"'.$filterString, '', $stdout, $stderr);

        if ($result !== 0 && $result !== -1) {
            throw new Orchestration("Docker Error: {$stderr}");
        }

        $list = [];
        $stdoutArray = \explode("\n", $stdout);

        foreach($stdoutArray as $value) {
            $container = [];
        
            \parse_str($value, $container);
        
            if(isset($container['name'])) {
                $labelsParsed = [];

                foreach (\explode(',', $container['labels']) as $value) {
                    if (is_array($value)) {
                        $value = implode('', $value);
                    }
                    $value = \explode('=', $value);

                    if(isset($value[0]) && isset($value[1])) {
                        $labelsParsed[$value[0]] = $value[1];
                    }
                }

                $parsedContainer = new Container($container['name'], $container['id'], $container['status'], $labelsParsed);
            
                array_push($list, $parsedContainer);
            }
        }

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
     * @param string[] $command
     * @param string $entrypoint
     * @param string $workdir
     * @param string[] $volumes
     * @param array<string, string> $vars
     * @param string $mountFolder
     * 
     * @return string
     */
    public function run(string $image, 
        string $name,
        array $command = [],
        string $entrypoint = '',
        string $workdir = '',
        array $volumes = [],
        array $vars = [],
        string $mountFolder = '',
        array $labels = [],
        string $hostname = '',
        bool $remove = false
    ): string {
        $stdout = '';
        $stderr = '';

        foreach ($command as $key => $value) {
            if (str_contains($value, " ")) {
                $command[$key] = "'".$value."'";
            }
        }

        $labelString = '';

        foreach ($labels as $labelKey => $label) {
            // sanitize label
            $label = str_replace("'", '', $label);

            if (str_contains($label, " ")) {
                $label = "'".$label."'";
            }

            $labelString = $labelString . ' --label '.$labelKey.'='.$label;
        }

        $parsedVariables = [];

        foreach ($vars as $key => $value) {
            $key = $this->filterEnvKey($key);

            $value = \escapeshellarg((empty($value)) ? '' : $value);
            $parsedVariables[$key] = "--env {$key}={$value}";
        }

        $volumeString = '';
        foreach ($volumes as $volume) {
            $volumeString = $volumeString . '--volume ' . $volume . " ";
        }

        $vars = $parsedVariables;

        $time = time();

        $result = Console::execute("docker run".
        " -d".
        ($remove ? " --rm" : "").
        (empty($entrypoint) ? "" : " --entrypoint=\"{$entrypoint}\"").
        (empty($this->cpus) ? "" : (" --cpus=".$this->cpus)).
        (empty($this->memory) ? "" : (" --memory=".$this->memory."m")).
        (empty($this->swap) ? "" : (" --memory-swap=".$this->swap."m")).
        " --name={$name}".
        " --label {$this->namespace}-type=runtime".
        " --label {$this->namespace}-created={$time}".
        (empty($mountFolder) ? "" : " --volume {$mountFolder}:/tmp:rw").
        (empty($volumeString) ? "" : " ".$volumeString).
        (empty($labelString) ? "" : " ".$labelString) .
        (empty($workdir) ? "" : " --workdir {$workdir}").
        (empty($hostname) ? "" : " --hostname {$hostname}").
        (empty($vars) ? "" : " ".\implode(" ", $vars)).
        " {$image}".
        (empty($command) ? "" : " ".implode(" ", $command))
            , '', $stdout, $stderr, 30);

        if ($result !== 0) {
            throw new Orchestration("Docker Error: {$stderr}");
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
    public function execute(
        string $name,
        array $command,
        string &$stdout = '',
        string &$stderr = '',
        array $vars = [],
        int $timeout = -1
    ): bool {
        foreach ($command as $key => $value) {
            if (str_contains($value, " ")) {
                $command[$key] = "'".$value."'";
            }
        }

        $parsedVariables = [];

        foreach ($vars as $key => $value) {
            $key = $this->filterEnvKey($key);

            $value = \escapeshellarg((empty($value)) ? '' : $value);
            $parsedVariables[$key] = "--env {$key}={$value}";
        }

        $vars = $parsedVariables;

        $result = Console::execute("docker exec ".\implode(" ", $vars)." {$name} ".implode(" ", $command)
            , '', $stdout, $stderr, $timeout);

        if ($result !== 0) {
            if ($result == 124) {
                throw new Timeout("Command timed out");
            } else {
                throw new Orchestration("Docker Error: {$stderr}");
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
            throw new Orchestration("Docker Error: {$stderr}");
        }

        return !$result;
    }
}