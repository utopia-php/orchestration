<?php

namespace Orchestration\Adapter;

use Utopia\Orchestration\Adapter\DockerSwarmCLI;
use Utopia\Orchestration\Orchestration;
use Utopia\Tests\Base;

class DockerSwarmCLITest extends Base
{
    /**
     * @var Orchestration
     */
    public static $orchestration = null;

    /**
     * Return name of adapter
     */
    public static function getAdapterName(): string
    {
        return 'Docker Swarm';
    }

    public static function getOrchestration(): Orchestration
    {
        if (! is_null(self::$orchestration)) {
            return self::$orchestration;
        }

        $orchestration = new Orchestration(new DockerSwarmCLI());

        return self::$orchestration = $orchestration;
    }
}
