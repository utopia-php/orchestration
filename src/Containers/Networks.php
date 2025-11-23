<?php

namespace Utopia\Containers;

final readonly class Networks
{
    private function __construct(private Adapter $adapter)
    {
    }

    // Network
    /**
     * Create Network
     */
    public function create(string $name, bool $internal = false)
    {
        $this->adapter->createNetwork($name, $internal);
    }

    /**
     * Remove network
     *
     */
    public function remove(string $name)
    {
        $this->adapter->removeNetwork($name);
    }

    /**
     * List Networks
     *
     * @return Network[]
     */
    public function list(): array
    {
        return $this->adapter->listNetworks();
    }

    /**
     * Connect a container to a network
     *
     * @param string $container Container ID
     * @param string $network   Network ID
     * @return void
     */
    public function connect(string $container, string $network): void
    {
        $this->adapter->connect($container, $network);
    }

    /**
     * Disconnect a container from a network
     */
    public function disconnect(string $container, string $network, bool $force = false): bool
    {
        return $this->adapter->disconnect($container, $network, $force);
    }

    /**
     * Check if a network exists
     */
    public function exists(string $name): bool
    {
        return $this->adapter->networkExists($name);
    }

}
