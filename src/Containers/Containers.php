<?php

namespace Utopia\Containers;

class Containers
{
    public Networks $networks;

    /**
     * Create Containers instance
     */
    public function __construct(private readonly Adapter $adapter)
    {
        $this->networks = new Networks($adapter);
    }

    /**
     * Pull container image
     *
     * @param string $image
     * @return void
     */
    public function pull(string $image): void
    {
        $this->adapter->pull($image);
    }

    /**
     * List containers
     *
     * @param  array<string, string>  $filters
     * @return Container[]
     */
    public function list(array $filters = []): array
    {
        return $this->adapter->list($filters);
    }

    /**
     *
     * Remove Container
     *
     * @param string $name  Container ID
     * @param bool   $force Force removal
     * @return bool
     */
    public function remove(string $name, bool $force = false): bool
    {
        return $this->adapter->remove($name, $force);
    }

    /**
     * Create container
     *
     * Creates and runs a new container. On success it will return a string containing the container name.
     * On fail it will throw an exception.
     *
     * @param  string    $name       Assign the specified name to the container.
     * @param  string    $image      The image to use for the container.
     * @param  string    $hostname   The hostname to use for the container, as a valid RFC 1123 hostname.
     * @param  string[]  $command    Command to run as array of strings.
     * @param  string[]  $entrypoint The entry point for the container as an array of strings.
     * @param  ?string   $workdir    The working directory for the container.
     * @param  array<string, string> $environment Environment variables to set in the container.
     * @param  Mount[]   $mounts     Mounts to apply to the container.
     * @param  array<string, string> $labels     Labels to apply to the container.
     * @param  ?string   $network    The network to connect the container to.
     * @param  ?float    $cpus       CPU time the container is allowed to use. 1 is entire CPU, 0.5 is half CPU. Default is no limit.
     * @param  ?int      $memory     Memory limit in MB. Default is no limit.
     * @param  ?int      $swap       Swap limit in MB. Default is no limit.
     * @param  RestartPolicy $restart Restart policy for the container.
     * @param  bool      $remove     Automatically remove the container when it exits.
     */
    public function run(
        string $name,
        string $image,
        string $hostname,
        array $command = [],
        array $entrypoint = [],
        ?string $workdir = null,
        array $environment = [],
        array $mounts = [],
        array $labels = [],
        ?string $network = null,
        ?float $cpus = null,
        ?int $memory = null,
        ?int $swap = null,
        RestartPolicy $restart = RestartPolicy::No,
        bool $remove = false,
    ): string {
        if (empty($name)) {
            throw new \InvalidArgumentException('Container name cannot be empty');
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]+$/i', $name)) {
            throw new \InvalidArgumentException('Container name must start with a letter or number and can contain letters, numbers, dots, underscores, and dashes');
        }
        if (empty($image)) {
            throw new \InvalidArgumentException('Image name cannot be empty');
        }

        // Filter environment variables to valid characters.
        // TODO: (@loks0n) Strongly feel we should consider removing invalid keys instead of fixing them.
        $environment = array_map(fn ($var) => preg_replace('/[^A-Za-z0-9\_\.\-]/', '', $var), $environment);
        $environment = array_filter($environment, fn($var) => !empty($var));

        return $this->adapter->run(
            $name,
            $image,
            $hostname,
            $command,
            $entrypoint,
            $workdir,
            $environment,
            $mounts,
            $labels,
            $network,
            $cpus,
            $memory,
            $swap,
            $restart,
            $remove,
        );
    }

    /**
     * Execute command inside an existing container
     *
     * @param  string[]  $command
     * @param  array<string, string>  $vars
     */
    public function execute(
        string $container,
        array $command,
        string &$output,
        array $vars = [],
        int $timeout = -1
    ): bool {
        return $this->adapter->execute($container, $command, $output, $vars, $timeout);
    }

    // Usage
    /**
     * Get usage stats of container
     *
     * @return ?Usage
     */
    public function getUsage(string $container): ?Usage
    {
        return $this->adapter->getUsage($container);
    }

    /**
     * Get usage stats of containers
     *
     * @param  array<string, string> $filters
     * @return array<string, Usage>  Map of container ids to usage stats
     */
    public function listUsage(array $filters = []): array
    {
        return $this->adapter->listUsage($filters);
    }
}
