<?php

namespace Utopia\Tests\Adapter;

use Utopia\Orchestration\Orchestration;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Tests\Base;

class DockerCLITest extends Base {
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
    return "Docker CLI";
    }

    /**
     * @return Orchestration
     */

    static function getOrchestration(): Orchestration
    {
        if (!is_null(self::$orchestration)) {
            return self::$orchestration;
        }

        $orchestration = new Orchestration(new DockerCLI());

        return self::$orchestration = $orchestration;
    }
}