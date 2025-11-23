<?php

namespace Utopia\Containers;

use Utopia\Containers\Usage;

abstract class Adapter
{
    abstract public function createNetwork(string $name, bool $internal): void;

    abstract public function removeNetwork(string $name): void;

    abstract public function networkExists(string $name): bool;

    abstract public function connect(string $container, string $network): void;

    abstract public function disconnect(string $container, string $network, bool $force);

    /**
     * @return list<Network>
     */
    abstract public function listNetworks(): array;

    abstract public function getUsage(string $container): ?Usage;

    /**
     * @param  array<string, string> $filters
     * @return array<string, Usage>  Map of container ids to usage stats
     */
    abstract public function listUsage(array $filters): array;

    abstract public function pull(string $image): void;

    /**
     * @param  array<string, string>  $filters
     * @return Container[]
     */
    abstract public function list(array $filters): array;

    abstract public function run(
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
    ): string;

    /**
     * @param  string[]  $command
     * @param  array<string, string>  $vars
     */
    abstract public function execute(string $name, array $command, string &$output, array $vars, int $timeout): bool;

    /**
     * Remove Container
     */
    abstract public function remove(string $name, bool $force): bool;
}
