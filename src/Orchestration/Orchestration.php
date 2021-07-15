<?php

namespace Utopia\Orchestration;

use Utopia\Orchestration\Adapter;
use Exception;

class Orchestration
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
     * Command Line String into Array
     * 
     * @param string $command
     * @return array
     */
    public function parseCommandString(string $command): array {
        $currentPos = 0;
        $commandProcessed = [];
        
        while (true) {
            if (strpos($command, " ", $currentPos) !== false) {
                $place = strpos($command, " ", $currentPos);
    
                if ($command[$place + 1] !== "'") {
                    array_push($commandProcessed, substr($command, $currentPos, $place - $currentPos));
                    $place = $place + 1;
                } else {
                    array_push($commandProcessed, substr($command, $currentPos, $place - $currentPos));
                    
    
                    $closingString = strpos($command, "'", $place + 2);

                    if ($closingString == false) {
                        throw new Exception("Invalid Command given, are you missing an `'` at the end?");
                    }

                    array_push($commandProcessed, substr($command, $place + 1, $closingString));
                    $place = $closingString + 1;
                }
                
                if (strpos($command, " ", $place) === false) {
                   if (!empty(substr($command, $place, strlen($command) - $currentPos))) {
                        array_push($commandProcessed, substr($command, $place, strlen($command) - $currentPos));
                    }
                }
                
                $currentPos = $place;
            } else {
                break;
            }
        }

        return $commandProcessed;
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
     * @return array
     */
    public function list(): array
    {
        return $this->adapter->list();
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
     * @return bool
     */
    public function run(string $image, string $name, string $entrypoint = '', array $command = [], string $workdir = '/', array $volumes = [], array $vars = [], string $mountFolder = ''): bool
    {
        return $this->adapter->run($image, $name, $entrypoint, $command, $workdir, $volumes, $vars, $mountFolder);
    }

    /**
     * Execute Container
     *
     * @param string $name
     * @param array $command
     * @param array $vars
     * @return bool
     */
    public function execute(string $name, array $command, array $vars = []): bool
    {
        return $this->adapter->execute($name, $command, $vars);
    }

    /**
     * Execute Container but return Stdout as a string
     * 
     * @param string $name
     * @param array $command
     * @param array $vars
     * @return string
     */
    public function executeWithStdout(string $name, array $command, array $vars = []): string 
    {
        return $this->adapter->executeWithStdout($name, $command, $vars);
    }
    
    /**
     * Remove Container
     *
     * @param string $name
     * @param bool $force
     * @return bool
     */
    public function remove(string $name, $force = false): bool
    {
        return $this->adapter->remove($name, $force);
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