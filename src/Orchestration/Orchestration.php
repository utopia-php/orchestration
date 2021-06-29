<?php

namespace Utopia\Orchestartion;

use Utopia\Orchestration\Adapter;

class Orchestartion
{
    /**
     * @var Adapter
     */
    protected $adapter;

    /**
     * @param Adapter $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
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
        return $this->adapter->pull($image);
    }
        
    /**
     * List Containers
     *
     * @return bool
     */
    public function list(): bool
    {
        return $this->adapter->list();
    }

    /**
     * Run Container
     */
    public function run(string $image, string $name, string $entrypoint = '', string $command = '', string $workdir = '/', array $volumes = [], array $vars = []): bool
    {
        return $this->adapter->run($image, $name, $entrypoint, $command, $workdir, $volumes, $vars);
    }

    /**
     * Execute Container
     *
     * @param  mixed $name
     * @param  mixed $command
     * @param  mixed $vars
     * @return bool
     */
    public function execute(string $name, string $command, array $vars = []): bool
    {
        return $this->adapter->execute($name, $command, $vars);
    }
    
    /**
     * Remove Container
     *
     * @param  mixed $name
     * @return bool
     */
    public function remove($name): bool
    {
        return $this->adapter->remove($name);
    }

    /**
     * Set containers namespace
     * 
     * @param string $namespace
     * @return $this
     */
    public function setNamespace(string $namespace): self
    {
        $this->adapter->setNamespace($namespace);
        return $this;
    }

    /**
     * Set max allowed CPU cores per container
     * 
     * @param int $cores
     * @return $this
     */
    public function setCpus(int $cores): self
    {
        $this->adapter->setCpus($cores);
        return $this;
    }

    /**
     * Set max allowed memory in mb per container
     * 
     * @param int $mb
     * @return $this
     */
    public function setMemory(int $mb): self
    {
        $this->adapter->setMemory($mb);
        return $this;
    }

    /**
     * Set max allowed swap memory in mb per container
     * 
     * @param int $mb
     * @return $this
     */
    public function setSwap(int $mb): self
    {
        $this->adapter->setSwap($mb);
        return $this;
    }
}