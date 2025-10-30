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
        // K8S_API_URL, K8S_TOKEN, K8S_USERNAME, K8S_PASSWORD, K8S_CERT_FILE, K8S_KEY_FILE, K8S_CA_FILE, K8S_INSECURE
        $url = \getenv('K8S_API_URL') ?: null;
        $namespace = \getenv('K8S_NAMESPACE') ?: 'default';

        if (empty($url)) {
            self::markTestSkipped('K8S_API_URL not set. Skipping Kubernetes SDK tests.');
        }

        $auth = [];
        if ($token = \getenv('K8S_TOKEN')) {
            $auth['token'] = $token;
        } elseif ((\getenv('K8S_USERNAME') !== false) && (\getenv('K8S_PASSWORD') !== false)) {
            $auth['username'] = (string) \getenv('K8S_USERNAME');
            $auth['password'] = (string) \getenv('K8S_PASSWORD');
        }

        // Optional: client cert/key & CA
        if ($cert = \getenv('K8S_CERT_FILE')) {
            $auth['cert'] = $cert;
        }
        if ($key = \getenv('K8S_KEY_FILE')) {
            $auth['key'] = $key;
        }
        if ($ca = \getenv('K8S_CA_FILE')) {
            $auth['ca'] = $ca;
        }
        if (($insecure = \getenv('K8S_INSECURE')) !== false) {
            $auth['insecure'] = in_array(strtolower((string) $insecure), ['1','true','yes'], true);
        }

        $orchestration = new Orchestration(new K8s($url, $namespace, $auth));

        return self::$orchestration = $orchestration;
    }
}
