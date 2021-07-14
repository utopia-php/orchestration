<?php

namespace Utopia\Tests\Adapter;

use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;
use Utopia\Tests\Base;

class DockerAPITest extends Base {
    /**
     * @var Orchestration
     */

    static $orchestration = null;

    /**
     * Return name of adapter
     *
     * @return string
     */

    static function getAdapterName(): string
    {
    return "Docker API";
    }

    /**
     * @return Orchestration
     */

    static function getOrchestration(): Orchestration
    {
        if (!is_null(self::$orchestration)) {
            return self::$orchestration;
        }

        $orchestration = new Orchestration(new DockerAPI());

        return self::$orchestration = $orchestration;
    }
}