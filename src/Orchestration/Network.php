<?php

namespace Utopia\Orchestration;

class Network
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $id;

    /**
     * @var string
     */
    protected $driver;

    /**
     * @var string
     */
    protected $scope;

    /**
     * @param  string  $name
     * @param  string  $id
     * @param  string  $driver
     * @param  string  $scope
     */
    public function __construct(string $name = '', string $id = '', string $driver = '', string $scope = '')
    {
        $this->name = $name;
        $this->id = $id;
        $this->driver = $driver;
        $this->scope = $scope;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * @return string
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * @param  string  $name
     * @return Network
     */
    public function setName(string $name): Network
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @param  string  $id
     * @return Network
     */
    public function setId(string $id): Network
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @param  string  $driver
     * @return Network
     */
    public function setDriver(string $driver): Network
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * @param  string  $scope
     * @return Network
     */
    public function setScope(string $scope): Network
    {
        $this->scope = $scope;

        return $this;
    }
}
