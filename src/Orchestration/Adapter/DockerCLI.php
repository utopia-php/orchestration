<?php

namespace Utopia\Orchestration\Adapter;

use Utopia\CLI\Console;
use Utopia\Orchestration\Adapter;
use Utopia\Orchestration\Container;
use Utopia\Orchestration\Container\Stats;
use Utopia\Orchestration\Exception\Orchestration;
use Utopia\Orchestration\Exception\Timeout;
use Utopia\Orchestration\Network;

class DockerCLI extends Adapter
{
    /**
     * Constructor
     *
     * @return void
     */
    public function __construct(?string $username = null, ?string $password = null)
    {
        if ($username && $password) {
            $output = '';

            if (! Console::execute('docker login --username '.$username.' --password-stdin', $password, $output)) {
                throw new Orchestration("Docker Error: {$output}");
            }
        }
    }

    /**
     * Create Network
     */
    public function createNetwork(string $name, bool $internal = false): bool
    {
        $output = '';

        $result = Console::execute('docker network create '.$name.($internal ? '--internal' : ''), '', $output);

        return $result === 0;
    }

    /**
     * Remove Network
     */
    public function removeNetwork(string $name): bool
    {
        $output = '';

        $result = Console::execute('docker network rm '.$name, '', $output);

        return $result === 0;
    }

    /**
     * Connect a container to a network
     */
    public function networkConnect(string $container, string $network): bool
    {
        $output = '';

        $result = Console::execute('docker network connect '.$network.' '.$container, '', $output);

        return $result === 0;
    }

    /**
     * Disconnect a container from a network
     */
    public function networkDisconnect(string $container, string $network, bool $force = false): bool
    {
        $output = '';

        $result = Console::execute('docker network disconnect '.$network.' '.$container.($force ? ' --force' : ''), '', $output);

        return $result === 0;
    }

    /**
     * Get usage stats of containers
     *
     * @param  array<string, string>  $filters
     * @return array<Stats>
     */
    public function getStats(?string $container = null, array $filters = []): array
    {
        // List ahead of time, since docker stats does not allow filtering
        $containerIds = [];

        if ($container === null) {
            $containers = $this->list($filters);
            $containerIds = \array_map(fn ($c) => $c->getId(), $containers);
        } else {
            $containerIds[] = $container;
        }

        $output = '';

        if (\count($containerIds) <= 0 && \count($filters) > 0) {
            return []; // No containers found
        }

        $stats = [];

        $containersString = '';

        foreach ($containerIds as $containerId) {
            $containersString .= ' '.$containerId;
        }

        $result = Console::execute('docker stats --no-trunc --format "id={{.ID}}&name={{.Name}}&cpu={{.CPUPerc}}&memory={{.MemPerc}}&diskIO={{.BlockIO}}&memoryIO={{.MemUsage}}&networkIO={{.NetIO}}" --no-stream'.$containersString, '', $output);

        if ($result !== 0) {
            throw new Orchestration("Docker Error: {$output}");
        }

        $lines = \explode("\n", $output);

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            $stat = [];
            \parse_str($line, $stat);

            $stats[] = new Stats(
                containerId: $stat['id'],
                containerName: $stat['name'],
                cpuUsage: \floatval(\rtrim($stat['cpu'], '%')) / 100, // Remove percentage symbol, parse to number, convert to percentage
                memoryUsage: empty($stat['memory']) ? 0 : \floatval(\rtrim($stat['memory'], '%')), // Remove percentage symbol and parse to number. Value is empty on Windows
                diskIO: $this->parseIOStats($stat['diskIO']),
                memoryIO: $this->parseIOStats($stat['memoryIO']),
                networkIO: $this->parseIOStats($stat['networkIO']),
            );
        }

        return $stats;
    }

    /**
     * Use this method to parse string format into numeric in&out stats.
     * CLI IO stats in verbose format: "2.133MiB / 62.8GiB"
     * Output after parsing: [ "in" => 2133000, "out" => 62800000000 ]
     *
     * @return array<string,float>
     */
    private function parseIOStats(string $stats)
    {
        $stats = \strtolower($stats);
        $units = [
            'b' => 1,
            'kb' => 1000,
            'mb' => 1000000,
            'gb' => 1000000000,
            'tb' => 1000000000000,
            'kib' => 1024,
            'mib' => 1048576,
            'gib' => 1073741824,
            'tib' => 1099511627776,
        ];

        [$inStr, $outStr] = \explode(' / ', $stats);

        $inUnit = null;
        $outUnit = null;

        foreach ($units as $unit => $value) {
            if (\str_ends_with($inStr, $unit)) {
                $inUnit = $unit;
            }
            if (\str_ends_with($outStr, $unit)) {
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
     * @return Network[]
     */
    public function listNetworks(): array
    {
        $output = '';

        $result = Console::execute('docker network ls --format "id={{.ID}}&name={{.Name}}&driver={{.Driver}}&scope={{.Scope}}"', '', $output);

        if ($result !== 0) {
            throw new Orchestration("Docker Error: {$output}");
        }

        $list = [];
        $stdoutArray = \explode("\n", $output);

        foreach ($stdoutArray as $value) {
            $network = [];

            \parse_str($value, $network);

            if (isset($network['name'])) {
                $parsedNetwork = new Network($network['name'], $network['id'], $network['driver'], $network['scope']);

                array_push($list, $parsedNetwork);
            }
        }

        return $list;
    }

    /**
     * Pull Image
     */
    public function pull(string $image): bool
    {
        $output = '';

        $result = Console::execute('docker pull '.$image, '', $output);

        return $result === 0;
    }

    /**
     * List Containers
     *
     * @param  array<string, string>  $filters
     * @return Container[]
     */
    public function list(array $filters = []): array
    {
        $output = '';

        $filterString = '';

        foreach ($filters as $key => $value) {
            $filterString = $filterString.' --filter "'.$key.'='.$value.'"';
        }

        $result = Console::execute('docker ps --all --no-trunc --format "id={{.ID}}&name={{.Names}}&status={{.Status}}&labels={{.Labels}}"'.$filterString, '', $output);

        if ($result !== 0 && $result !== -1) {
            throw new Orchestration("Docker Error: {$output}");
        }

        $list = [];
        $stdoutArray = \explode("\n", $output);

        foreach ($stdoutArray as $value) {
            $container = [];

            \parse_str($value, $container);

            if (isset($container['name'])) {
                $labelsParsed = [];

                foreach (\explode(',', $container['labels']) as $value) {
                    $value = \explode('=', $value);

                    if (isset($value[0]) && isset($value[1])) {
                        $labelsParsed[$value[0]] = $value[1];
                    }
                }

                $parsedContainer = new Container($container['name'], $container['id'], $container['status'], $labelsParsed);

                array_push($list, $parsedContainer);
            }
        }

        return $list;
    }

    /**
     * Run Container
     *
     * Creates and runs a new container, On success it will return a string containing the container ID.
     * On fail it will throw an exception.
     *
     * @param  string[]  $command
     * @param  string[]  $volumes
     * @param  array<string, string>  $vars
     * @param  array<string, string>  $labels
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
        bool $remove = false,
        string $network = '',
        string $restart = self::RESTART_NO
    ): string {
        $output = '';

        foreach ($command as $key => $value) {
            if (str_contains($value, ' ')) {
                $command[$key] = "'".$value."'";
            }
        }

        $labelString = '';

        foreach ($labels as $labelKey => $label) {
            // sanitize label
            $label = str_replace("'", '', $label);

            if (str_contains($label, ' ')) {
                $label = "'".$label."'";
            }

            $labelString = $labelString.' --label '.$labelKey.'='.$label;
        }

        $parsedVariables = [];

        foreach ($vars as $key => $value) {
            $key = $this->filterEnvKey($key);

            $value = \escapeshellarg((empty($value)) ? '' : $value);
            $parsedVariables[$key] = "--env {$key}={$value}";
        }

        $volumeString = '';
        foreach ($volumes as $volume) {
            $volumeString = $volumeString.'--volume '.$volume.' ';
        }

        $vars = $parsedVariables;

        $time = time();

        $result = Console::execute('docker run'.
        ' -d'.
        ($remove ? ' --rm' : '').
        (empty($network) ? '' : " --network=\"{$network}\"").
        (empty($entrypoint) ? '' : " --entrypoint=\"{$entrypoint}\"").
        (empty($this->cpus) ? '' : (' --cpus='.$this->cpus)).
        (empty($this->memory) ? '' : (' --memory='.$this->memory.'m')).
        (empty($this->swap) ? '' : (' --memory-swap='.$this->swap.'m')).
        " --restart={$restart}".
        " --name={$name}".
        " --label {$this->namespace}-type=runtime".
        " --label {$this->namespace}-created={$time}".
        (empty($mountFolder) ? '' : " --volume {$mountFolder}:/tmp:rw").
        (empty($volumeString) ? '' : ' '.$volumeString).
        (empty($labelString) ? '' : ' '.$labelString).
        (empty($workdir) ? '' : " --workdir {$workdir}").
        (empty($hostname) ? '' : " --hostname {$hostname}").
        (empty($vars) ? '' : ' '.\implode(' ', $vars)).
        " {$image}".
        (empty($command) ? '' : ' '.implode(' ', $command)), '', $output, 30);

        if ($result !== 0) {
            throw new Orchestration("Docker Error: {$output}");
        }

        // Use first line only, CLI can add warnings or other messages
        $output = \explode("\n", $output)[0];

        return rtrim($output);
    }

    /**
     * Execute Container
     *
     * @param  string[]  $command
     * @param  array<string, string>  $vars
     */
    public function execute(
        string $name,
        array $command,
        string &$output = '',
        array $vars = [],
        int $timeout = -1
    ): bool {
        foreach ($command as $key => $value) {
            if (str_contains($value, ' ')) {
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

        $result = Console::execute('docker exec '.\implode(' ', $vars)." {$name} ".implode(' ', $command), '', $output, $timeout);

        if ($result !== 0) {
            if ($result == 124) {
                throw new Timeout('Command timed out');
            } else {
                throw new Orchestration("Docker Error: {$output}");
            }
        }

        return true;
    }

    /**
     * Remove Container
     */
    public function remove(string $name, bool $force = false): bool
    {
        $output = '';

        $result = Console::execute('docker rm '.($force ? '--force' : '')." {$name}", '', $output);

        if (! \str_starts_with($output, $name) || \str_contains($output, 'No such container')) {
            throw new Orchestration("Docker Error: {$output}");
        }

        return ! $result;
    }
}
