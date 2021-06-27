<?php

namespace Utopia\Orchestration;

use Exception;

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
     * Pull Image
     * 
     * @param string $image
     * 
     * @return bool
     */
    abstract public function pull(string $image): bool;
        
    /**
     * List Containers
     *
     * @return bool
     */
    abstract public function list(): bool;

    /**
     * Run Container
     */
    abstract public function run(string $image, string $name, string $entrypoint = '', string $command = '', string $workdir = '/', array $volumes = [], array $vars = []): bool;

    /**
     * Execute Container
     *
     * @param  mixed $name
     * @param  mixed $command
     * @param  mixed $vars
     * @return bool
     */
    abstract public function execute(string $name, string $command, array $vars = []): bool;
    
    /**
     * Remove Container
     *
     * @param  mixed $name
     * @return bool
     */
    abstract public function remove($name): bool;

    /**
     * Set containers namespace
     * 
     * @param string $namespace
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
     * @param int $cores
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
     * @param int $mb
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
     * @param int $mb
     * @return $this
     */
    public function setSwap(int $mb): self
    {
        $this->swap = $mb;
        return $this;
    }
}