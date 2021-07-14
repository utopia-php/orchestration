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
     * @return array
     */
    abstract public function list(): array;

    /**
     * Run Container
     */
    abstract public function run(string $image, string $name, string $entrypoint = '', array $command = [], string $workdir = '/', array $volumes = [], array $vars = [], string $mountFolder = ''): bool;

    /**
     * Execute Container
     *
     * @param string $name
     * @param array $command
     * @param array $vars
     * @return bool
     */
    abstract public function execute(string $name, array $command, array $vars = []): bool;

    /**
     * Execute Container return Stdout
     * 
     * @param string $name
     * @param array $command
     * @param array $vars
     * @return string
     */
    abstract public function executeWithStdout(string $name,array $command, array $vars = []): string;
    
    /**
     * Remove Container
     *
     * @param string $name
     * @param bool $force
     * @return bool
     */
    abstract public function remove($name, $force): bool;

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