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
     * This function will convert a string containing a command into an array of arguments.
     * It will go through the string and find all instances of spaces, and will split the string
     * however if it detects a apostrophe comes after the space it will find the next apostrophe and split the entire thing
     * and add it to the array. This is so arguments with spaces in them can be passed such as scripts for sh or bash.
     * 
     * If there are no spaces detected in the first place it will just return the string as an array.
     *
     * @param string $command
     *
     * @return (false|string)[]
     */
    public function parseCommandString(string $command): array {
        $currentPos = 0;
        $commandProcessed = [];

        if (strpos($command, ' ', $currentPos) === false) {
            return [$command];
        }
        
        while (true) {
            if (strpos($command, ' ', $currentPos) !== false) {
                $place = (int) strpos($command, ' ', $currentPos);
    
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
                
                if (strpos($command, ' ', $place) === false) {
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
     * @param array<string, string> $filters
     *
     * @return Container[]
     */
    public function list(array $filters = []): array
    {
        return $this->adapter->list($filters);
    }

    /**
     * Run Container
     * 
     * Creates and runs a new container, On success it will return a string containing the container ID.
     * On fail it will throw an exception.
     * 
     * @param string $image
     * @param string $name
     * @param string $entrypoint
     * @param string[] $command
     * @param string $workdir
     * @param string[] $volumes
     * @param array<string, string> $vars
     * @param string $mountFolder
     * 
     * @return string
     */
    public function run(string $image, string $name, string $entrypoint = '', array $command, string $workdir = '/', array $volumes = [], array $vars = [], string $mountFolder = '', array $labels = []): string
    {
        return $this->adapter->run($image, $name, $entrypoint, $command, $workdir, $volumes, $vars, $mountFolder, $labels);
    }

    /**
     * Execute Container
     *
     * @param string $name
     * @param string[] $command
     * @param string &$stdout
     * @param string &$stderr
     * @param array<string, string> $vars
     * @param int $timeout
     * @return bool
     */
    public function execute(string $name, array $command, string &$stdout, string &$stderr, array $vars = [], int $timeout = -1): bool
    {
        return $this->adapter->execute($name, $command, $stdout, $stderr, $vars, $timeout);
    }
    
    /**
     * Remove Container
     *
     * @param string $name
     * @param bool $force
     * @return bool
     */
    public function remove(string $name, bool $force = false): bool
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