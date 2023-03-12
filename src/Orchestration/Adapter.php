<?php

namespace Utopia\Orchestration;

abstract class Adapter
{
    /**
     * @var string
     */
    protected $namespace = 'utopia';

    /**
     * @var int
     */
    protected $cpus = 0;

    /**
     * @var int
     */
    protected $memory = 0;

    /**
     * @var int
     */
    protected $swap = 0;

    /**
     * Filter ENV vars
     *
     * @param  string  $string
     * @return string
     */
    public function filterEnvKey(string $string): string
    {
        return preg_replace('/[^A-Za-z0-9\_\.\-]/', '', $string);
    }

    /**
     * Create Network
     *
     * @param  string  $name
     * @return bool
     */
    abstract public function createNetwork(string $name, bool $internal = false): bool;

    /**
     * Remove Network
     *
     * @param  string  $name
     * @return bool
     */
    abstract public function removeNetwork(string $name): bool;

    /**
     * Connect a container to a network
     *
     * @param  string  $container
     * @param  string  $network
     * @return bool
     */
    abstract public function networkConnect(string $container, string $network): bool;

    /**
     * Disconnect a container from a network
     *
     * @param  string  $container
     * @param  string  $network
     * @param  bool  $force
     * @return bool
     */
    abstract public function networkDisconnect(string $container, string $network, bool $force = false): bool;

    /**
     * List Networks
     *
     * @return array
     */
    abstract public function listNetworks(): array;

    /**
     * Get usage stats of containers
     *
     * @param  string  $container
     * @param  array<string, string>  $filters
     * @return array<Stats>
     */
    abstract public function getStats(string $container = null, array $filters = []): array;

    /**
     * Pull Image
     *
     * @param  string  $image
     * @return bool
     */
    abstract public function pull(string $image): bool;

    /**
     * List Containers
     *
     * @param  array<string, string>  $filters
     * @return Container[]
     */
    abstract public function list(array $filters = []): array;

    /**
     * Run Container
     *
     * Creates and runs a new container, On success it will return a string containing the container ID.
     * On fail it will throw an exception.
     *
     * @param  string  $image
     * @param  string  $name
     * @param  string[]  $command
     * @param  string  $entrypoint
     * @param  string  $workdir
     * @param  string[]  $volumes
     * @param  array<string, string>  $vars
     * @param  string  $mountFolder
     * @param  string  $hostname
     * @param  bool  $remove
     * @param  array $hosts
     * @return string
     */
    abstract public function run(
        string $image,
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
        array $hosts = []): string;

    /**
     * Execute Container
     *
     * @param  string  $name
     * @param  string[]  $command
     * @param  string  &$stdout
     * @param  string  &$stderr
     * @param  array<string, string>  $vars
     * @param  int  $timeout
     * @return bool
     */
    abstract public function execute(string $name, array $command, string &$stdout, string &$stderr, array $vars = [], int $timeout = -1): bool;

    /**
     * Remove Container
     *
     * @param  string  $name
     * @param  bool  $force
     * @return bool
     */
    abstract public function remove(string $name, bool $force): bool;

    /**
     * Set containers namespace
     *
     * @param  string  $namespace
     * @return $this
     */
    public function setNamespace(string $namespace): self
    {
        $this->namespace = $namespace;

        return $this;
    }

    /**
     * Set max allowed CPU cores per container
     *
     * @param  int  $cores
     * @return $this
     */
    public function setCpus(int $cores): self
    {
        $this->cpus = $cores;

        return $this;
    }

    /**
     * Set max allowed memory in mb per container
     *
     * @param  int  $mb
     * @return $this
     */
    public function setMemory(int $mb): self
    {
        $this->memory = $mb;

        return $this;
    }

    /**
     * Set max allowed swap memory in mb per container
     *
     * @param  int  $mb
     * @return $this
     */
    public function setSwap(int $mb): self
    {
        $this->swap = $mb;

        return $this;
    }
}
