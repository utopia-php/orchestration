<?php

namespace Utopia\Containers\Adapter;

use Utopia\Console;
use Utopia\Containers\Adapter;
use Utopia\Containers\Container;
use Utopia\Containers\Usage;
use Utopia\Containers\Exception\ContainersAuthException;
use Utopia\Containers\Exception\ContainersException;
use Utopia\Containers\Exception\ContainersTimeoutException;
use Utopia\Containers\Mount;
use Utopia\Containers\Mount\Bind;
use Utopia\Containers\Mount\Tmpfs;
use Utopia\Containers\Mount\TmpfsMount;
use Utopia\Containers\Mount\Volume;
use Utopia\Containers\Mount\VolumeMount;
use Utopia\Containers\Network;
use Utopia\Containers\RestartPolicy;

class DockerCLI extends Adapter
{
    /**
     * Constructor
     *
     * @return void
     */
    public function __construct(?string $username = null, ?string $password = null)
    {
        if (!$username || !$password) {
            return;
        }

        $this->login($username, $password);
    }

    private function login(string $username, string $password): void
    {
        $output = '';

        $result = Console::execute([
            'docker', 'login', '--username', $username, '--password-stdin'
        ], $password, $output);

        if ($result !== 0) {
            throw new ContainersAuthException("Failed to login to Docker registry, command return $result, output: $output");
        }
    }

    public function createNetwork(string $id, bool $internal): void
    {
        $output = '';

        $result = Console::execute([
            'docker', 'network', 'create', $id, ($internal ? '--internal' : '')
        ], '', $output);

        if ($result !== 0) {
            throw new ContainersException("Failed to create Docker network, command return $result, output: $output");
        }
    }

    public function removeNetwork(string $name): void
    {
        $output = '';

        $result = Console::execute([
            'docker', 'network', 'rm', $name
        ], '', $output);

        if ($result !== 0) {
            throw new ContainersException("Failed to remove Docker network, command return $result, output: $output");
        }
    }

    public function connect(string $container, string $network): void
    {
        $output = '';

        $result = Console::execute([
            'docker', 'network', 'connect', $network, $container
        ], '', $output);

        if ($result !== 0) {
            throw new ContainersException("Failed to connect Docker container to network, command return $result, output: $output");
        }
    }

    public function disconnect(string $container, string $network, bool $force)
    {
        $output = '';

        $command = ['docker', 'network', 'disconnect', $network, $container];
        if ($force) {
            $command[] = '--force';
        }

        $result = Console::execute($command, '', $output);

        if ($result !== 0) {
            throw new ContainersException("Failed to disconnect Docker container from network, command return $result, output: $output");
        }
    }

    /**
     * Check if a network exists
     */
    public function networkExists(string $name): bool
    {
        $output = '';

        $result = Console::execute([
            'docker', 'network', 'inspect', $name, '--format', '{{.Name}}'
        ], '', $output);

        return $result === 0 && trim($output) === $name;
    }

    public function getUsage(string $container): array
    {
        $output = '';

        $result = Console::execute([
            'docker', 'container', 'stats', $container, '--no-stream', '--format', '{{.CPUPerc}}&{{.MemPerc}}&{{.BlockIO}}&{{.MemUsage}}&{{.NetIO}}'
        ], '', $output);

        if ($result !== 0) {
            throw new ContainersException("Failed to get usage stats for Docker container, command return $result, output: $output");
        }

        $stats = explode('&', $output);

        throw new \Exception('getUsage');
    }

    public function listUsage(array $filters): array
    {
        // List ahead of time, since docker stats does not allow filtering
        $containerIds = [];


        $output = '';

        if (\count($containerIds) <= 0 && \count($filters) > 0) {
            return []; // No containers found
        }

        $stats = [];

        $containersString = '';

        foreach ($containerIds as $containerId) {
            $containersString .= ' '.$containerId;
        }

        $result = Console::execute([
            'docker', 'stats', '--no-trunc',
            '--format', 'id={{.ID}}&name={{.Name}}&cpu={{.CPUPerc}}&memory={{.MemPerc}}&diskIO={{.BlockIO}}&memoryIO={{.MemUsage}}&networkIO={{.NetIO}}',
            '--no-stream'
        ], '', $output);

        if ($result !== 0) {
            return [];
        }

        $lines = \explode("\n", $output);

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            $stat = [];
            \parse_str($line, $stat);

            $stats[$stat['id']] = new Usage(
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

        $result = Console::execute([
            'docker', 'network', 'ls', '--format', '"id={{.ID}}&name={{.Name}}&driver={{.Driver}}&scope={{.Scope}}"'
        ], '', $output);

        if ($result !== 0) {
            throw new ContainersException("Failed to list networks, command returned $result, output: $output");
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

    public function pull(string $image): void
    {
        $output = '';

        $result = Console::execute([
            'docker', 'pull', $image
        ], '', $output);

        if ($result !== 0) {
            throw new ContainersException("Failed to pull image $image, command returned $result, output: $output");
        }
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

        $result = Console::execute([
            'docker', 'ps', '--all', '--no-trunc', '--format', '"id={{.ID}}&name={{.Names}}&status={{.Status}}&labels={{.Labels}}"'.$filterString
        ], '', $output);

        if ($result !== 0 && $result !== -1) {
            throw new ContainersException("Failed to list containers, command returned $result, output: $output");
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
     * Run container
     *
     * Creates and runs a new container. On success it will return a string containing the container ID.
     * On fail it will throw an exception.
     *
     * @param  string[]  $command
     * @param  string[]  $entrypoint
     * @param  array<string, string>  $environment
     * @param  Mount[]  $mounts
     * @param  array<string, string>  $labels
     */
    public function run(
        string $name,
        string $image,
        string $hostname,
        array $command,
        array $entrypoint,
        ?string $workdir,
        array $environment,
        array $mounts,
        array $labels,
        ?string $network,
        ?float $cpus,
        ?int $memory,
        ?int $swap,
        RestartPolicy $restart,
        bool $remove,
    ): string {
        $output = '';

        $cmd = ['docker', 'run', '-d'];

        if ($remove) {
            $cmd[] = '--rm';
        }

        if ($network !== null) {
            $cmd[] = "--network={$network}";
        }

        if (!empty($entrypoint)) {
            foreach ($entrypoint as $entry) {
                $cmd[] = '--entrypoint';
                $cmd[] = $entry;
            }
        }

        if ($cpus !== null) {
            $cmd[] = "--cpus={$cpus}";
        }

        if ($memory !== null) {
            $cmd[] = "--memory={$memory}m";
        }

        if ($swap !== null) {
            $cmd[] = "--memory-swap={$swap}m";
        }

        $cmd[] = "--restart={$restart->value}";
        $cmd[] = "--name={$name}";

        foreach ($mounts as $mount) {
            if ($mount instanceof Bind) {
                $permission = $mount->isReadOnly() ? 'ro' : 'rw';
                $cmd[] = '--volume';
                $cmd[] = "{$mount->hostPath}:{$mount->containerPath}:{$permission}";
            } elseif ($mount instanceof Volume) {
                $permission = $mount->isReadOnly() ? 'ro' : 'rw';
                $cmd[] = '--volume';
                $cmd[] = "{$mount->volumeName}:{$mount->containerPath}:{$permission}";
            } elseif ($mount instanceof Tmpfs) {
                $tmpfsSpec = $mount->containerPath;
                if ($mount->sizeBytes !== null) {
                    $tmpfsSpec = "{$tmpfsSpec}:size={$mount->sizeBytes}";
                }
                $cmd[] = '--tmpfs';
                $cmd[] = $tmpfsSpec;
            }
        }

        foreach ($labels as $key => $value) {
            $cmd[] = '--label';
            $cmd[] = "{$key}={$value}";
        }

        if ($workdir !== null) {
            $cmd[] = '--workdir';
            $cmd[] = $workdir;
        }

        if (!empty($hostname)) {
            $cmd[] = '--hostname';
            $cmd[] = $hostname;
        }

        foreach ($environment as $key => $value) {
            $cmd[] = '--env';
            $cmd[] = "{$key}={$value}";
        }

        $cmd[] = $image;

        foreach ($command as $arg) {
            $cmd[] = $arg;
        }

        $result = Console::execute($cmd, '', $output, 30);
        if ($result !== 0) {
            throw new ContainersException("Failed to create container, command returned {$result}, output: {$output}");
        }

        // Use first line only, CLI can add warnings or other messages
        $output = explode("\n", $output)[0];

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
        string &$output,
        array $vars,
        int $timeout
    ): bool {
        foreach ($command as $key => $value) {
            if (str_contains($value, ' ')) {
                $command[$key] = "'".$value."'";
            }
        }

        $parsedVariables = [];

        foreach ($vars as $key => $value) {
            $value = \escapeshellarg((empty($value)) ? '' : $value);
            $parsedVariables[$key] = "--env {$key}={$value}";
        }

        $vars = $parsedVariables;

        $result = Console::execute('docker exec '.\implode(' ', $vars)." {$name} ".implode(' ', $command), '', $output, $timeout);

        if ($result !== 0) {
            if ($result == 124) {
                throw new ContainersTimeoutException('Command timed out');
            } else {
                throw new ContainersException("Docker Error: {$output}");
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
            throw new ContainersException("Docker Error: {$output}");
        }

        return ! $result;
    }
}
