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
     * Filter ENV vars
     * 
     * @param string $string
     * 
     * @return string
     */
    public function filterEnvKey(string $string): string
    {
        $string     = \str_split($string);
        $output     = '';

        foreach ($string as $char) {
            if(\in_array($char, ['0','1','2','3','4','5','6','7','8','9','A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z','a','b','c','d','e','f','g','h','i','j','k','l','m','n','o','p','q','r','s','t','u','v','w','x','y','z','_',])) {
                $output .= $char;
            }
        }
    
        return $output;
    }

    /**
     * Run Container
     * 
     * @param string $image
     * @param string $name
     * @param string $entrypoint
     * @param array $command
     * @param string $workdir
     * @param array $volumes
     * @param array $vars
     * @param string $mountFolder
     * 
     * @return bool
     */
    abstract public function run(string $image, string $name, string $entrypoint = '', array $command = [], string $workdir = '/', array $volumes = [], array $vars = [], string $mountFolder = '', array $labels = []): bool;

    /**
     * Execute Container
     *
     * @param string $name
     * @param array $command
     * @param string $stdout
     * @param string $stderr
     * @param array $vars
     * @param int $timeout
     * @return bool
     */
    abstract public function execute(string $name, array $command, string &$stdout = '', string &$stderr = '', array $vars = [], int $timeout = 0): bool;
    
    /**
     * Remove Container
     *
     * @param string $name
     * @param bool $force
     * @return bool
     */
    abstract public function remove(string $name, bool $force): bool;

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