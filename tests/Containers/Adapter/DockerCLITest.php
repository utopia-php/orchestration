<?php

namespace Utopia\Tests\Adapter;

use Utopia\Containers\Adapter\DockerCLI;
use Utopia\Containers\Containers;
use Utopia\Tests\Base;

class DockerCLITest extends Base
{
    /**
     * @var Containers|null
     */
    public static $containers = null;

    /**
     * Return name of adapter
     */
    public static function getAdapterName(): string
    {
        return 'Docker CLI';
    }

    public static function getContainers(): Containers
    {
        if (! is_null(self::$containers)) {
            return self::$containers;
        }

        $containers = new Containers(new DockerCLI());

        return self::$containers = $containers;
    }
}
