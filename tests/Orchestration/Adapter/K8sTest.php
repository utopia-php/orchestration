<?php

namespace Utopia\Tests\Adapter;

use Utopia\Orchestration\Adapter\K8s;
use Utopia\Orchestration\Orchestration;
use Utopia\Tests\Base;

class K8sTest extends Base
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
        return 'Kubernetes SDK';
    }

    public static function getOrchestration(): Orchestration
    {
        if (! is_null(self::$orchestration)) {
            return self::$orchestration;
        }

        // Configure K8s adapter with API URL and authentication
        // You can set these via environment variables:
        // K8S_API_URL, K8S_TOKEN, K8S_USERNAME, K8S_PASSWORD, etc.
        $url = \getenv('K8S_API_URL') ?: null;
        $namespace = \getenv('K8S_NAMESPACE') ?: 'default';

        $auth = [];
        if ($token = \getenv('K8S_TOKEN')) {
            $auth['token'] = $token;
        } elseif ($username = \getenv('K8S_USERNAME') && $password = \getenv('K8S_PASSWORD')) {
            $auth['username'] = $username;
            $auth['password'] = $password;
        }

        $orchestration = new Orchestration(new K8s($url, $namespace, $auth));

        return self::$orchestration = $orchestration;
    }
}
