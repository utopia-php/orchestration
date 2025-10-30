<?php

namespace Utopia\Tests\Adapter;

use Utopia\Orchestration\Adapter\K8sCLI;
use Utopia\Orchestration\Orchestration;
use Utopia\Tests\Base;

class K8sCLITest extends Base
{
    /**
     * @var Orchestration|null
     */
    public static $orchestration = null;

    /**
     * Return name of adapter
     */
    public static function getAdapterName(): string
    {
        return 'Kubernetes CLI';
    }

    public static function getOrchestration(): Orchestration
    {
        if (! is_null(self::$orchestration)) {
            return self::$orchestration;
        }

        $orchestration = new Orchestration(new K8sCLI());

        return self::$orchestration = $orchestration;
    }
}
