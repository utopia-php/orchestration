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

        // Skip if kubectl cannot reach a cluster (common in docker-compose test container)
        $rc = 0;
        $output = [];
        @\exec('kubectl cluster-info >/dev/null 2>&1', $output, $rc);
        if ($rc !== 0) {
            self::markTestSkipped('kubectl not configured or no Kubernetes cluster available. Skipping K8s CLI tests.');
        }

        $orchestration = new Orchestration(new K8sCLI());

        return self::$orchestration = $orchestration;
    }
}
