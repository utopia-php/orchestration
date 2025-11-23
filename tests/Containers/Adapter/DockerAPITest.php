<?php

namespace Utopia\Tests\Adapter;

use Utopia\Containers\Adapter\DockerAPI;
use Utopia\Containers\Containers;
use Utopia\Tests\Base;

class DockerAPITest extends Base
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
        return 'Docker API';
    }

    public static function getContainers(): Containers
    {
        if (! is_null(self::$containers)) {
            return self::$containers;
        }

        $containers = new Containers(new DockerAPI());

        return self::$containers = $containers;
    }
}
