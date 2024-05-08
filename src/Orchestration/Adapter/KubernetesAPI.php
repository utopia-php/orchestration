<?php

namespace Utopia\Orchestration\Adapter;

use Utopia\App;
use Utopia\Orchestration\Adapter;

class KubernetesAPI extends Adapter
{
    /**
     * Constructor
     */
    public function __construct(string $namespace = null, string $username = null, string $password = null, string $email = null)
    {
        if (! empty($namespace)) {
            $this->namespace = $namespace;
        }

        if ($username && $password && $email) {
            $registryAuth = base64_encode(json_encode([
                'auths' => [
                    'https://index.docker.io/v1/' => [
                        'username' => $username,
                        'password' => $password,
                        'email' => $email,
                    ],
                ],
            ]));

            $result = $this->call('/api/v1/namespaces/'.$this->namespace.'/pods', 'POST', json_encode([
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => [
                    'name' => 'utopia-orchestration-regcred',
                    'namespace' => $this->namespace,
                    'labels' => [
                        'app.kubernetes.io/managed-by' => 'utopia-php-orchestration',
                    ],
                ],
                'data' => [
                    '.dockerconfigjson' => $registryAuth,
                ],
            ]), [
                'Content-Type: application/json',
                'Content-Length: '.\strlen(\json_encode($registryAuth)),
            ]);

            if (! in_array($result['code'], [200, 201, 202])) {
                throw new Orchestration('Failed to create regcred secret: '.$result['response'].' Response Code: '.$result['code']);
            } else {
                $this->regCred = true;
            }
        }
    }

    /**
     * @var string
     */
    private $regCred = false;

    public function createNetwork(string $name, bool $internal = false)
    {
        return true;
    }

    public function removeNetwork(string $name)
    {
        return true;
    }

    public function networkConnect(string $container, string $network)
    {
        return true;
    }

    public function networkDisconnect(string $container, string $network, bool $force = false)
    {
        return true;
    }

    public function listNetworks()
    {
        return [];
    }

    public function pull(string $image)
    {
        return true;
    }

    /**
     * Create a request with cURL via the Kubernetes API
     *
     * @param  array|bool|int|float|object|resource|string|null  $body
     * @param  string[]  $headers
     * @return (bool|mixed|string)[]
     *
     * @psalm-return array{response: mixed, code: mixed}
     */
    protected function call(string $url, string $method, $body = null, array $headers = [], int $timeout = -1): array
    {
        $url = 'https://'.App::getEnv('KUBERNETES_SERVICE_HOST', 'kubernetes.default.svc').'/'.$url;
        $token = file_get_contents('/var/run/secrets/kubernetes.io/serviceaccount/token', false);

        array_push($headers, 'Authorization: Bearer '.$token);

        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, $url);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYSTATUS, 0);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        switch ($method) {
            case 'GET':
                if (! is_null($body)) {
                    \curl_setopt($ch, CURLOPT_URL, $url.'?'.$body);
                }
                break;
            case 'POST':
                \curl_setopt($ch, CURLOPT_POST, 1);

                if (! is_null($body)) {
                    \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
                break;
            case 'DELETE':
                \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

                if (! is_null($body)) {
                    \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
                break;
        }

        $result = \curl_exec($ch);
        $responseCode = \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        \curl_close($ch);

        return [
            'response' => $result,
            'code' => $responseCode,
        ];
    }

    /**
     * Get usage stats of pods
     *
     * @param  array<string, string>  $filters
     * @return array<Stats>
     */
    public function getStats(string $pod = null, array $filters = []): array
    {
        // List ahead of time, since API does not allow listing all usage stats
        $podIds = [];

        if ($pod === null) {
            $pods = $this->list($filters);
            $podIds = \array_map(fn ($c) => $c->getId(), $pods);
        } else {
            $podIds[] = $pod;
        }

        $list = [];

        foreach ($podIds as $podId) {
            $result = $this->call('/apis/metrics.k8s.io/v1beta1/namespaces/'.$this->namespace.'/pods/'.$podId, 'GET');

            if ($result['code'] !== 200) {
                throw new Orchestration($result['response']);
            }

            $stats = \json_decode($result['response'], true);

            $list[] = new Stats(
                podId: $podId,
                containerName: current($stats)['name'],
                cpuUsage: current($stats)['usage']['cpu'],
                memoryUsage: current($stats)['usage']['memory'],
                diskIO: ['in' => 0, 'out' => 0], // TODO: Implement (API does not provide these values)
                memoryIO: ['in' => 0, 'out' => 0], // TODO: Implement (API does not provide these values
                networkIO: ['in' => 0, 'out' => 0], // TODO: Implement (API does not provide these values)
            );
        }

        return $list;
    }

    /**
     * List Pods
     *
     * @param  array<string, string>  $filters
     * @return Pod[]
     */
    public function list(array $filters = []): array
    {
        $filtersSorted = [];

        foreach ($filters as $key => $value) {
            $filtersSorted[$key] = [$value];
        }

        $body = [
            'labelSelector' => empty($filtersSorted) ? new stdClass() : json_encode($filtersSorted),
        ];

        $result = $this->call('/api/v1/namespaces/'.$this->namespace.'/pods'.'?'.\http_build_query($body), 'GET');

        $list = [];

        if ($result['code'] !== 200) {
            throw new Orchestration($result['response']);
        }

        foreach (\json_decode($result['response']['items'], true) as $value) {

            if (isset($value['spec']['containers'][0])) {
                $parsedContainer = new Container(
                    $value['spec']['containers'][0]['name'],
                    $value['metadata']['name'],
                    $value['status'],
                    $value['metadata']['labels']
                );

                array_push($list, $parsedContainer);
            }
        }

        return $list;
    }

    /**
     * Run Pod
     *
     * Creates and runs a new pod, On success it will return a string containing the pod name.
     * On fail it will throw an exception.
     *
     * @param  string[]  $command
     * @param  string[]  $volumes
     * @param  array<string, string>  $vars
     */
    public function run(
        string $image,
        string $name,
        array $command = [],
        string $entrypoint = '',
        string $workdir = '',
        array $volumes = [],
        array $vars = [],
        string $mountFolder = '',
        array $labels = [],
        string $hostname = '',
        bool $remove = false
    ): string {
        $parsedVariables = [];

        foreach ($vars as $key => $value) {
            array_push($parsedVariables, [
              'name'=> $key,
              'value'=> $value,
            ]);
        }

        $vars = $parsedVariables;

        $body = [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => [
                'generateName' => $name,
                'namespace' => $this->namespace,
                'labels' => array_merge($labels, [
                    'app.kubernetes.io/component' => $name,
                    //'app.kubernetes.io/created-by' => $hostname,
                    'app.kubernetes.io/managed-by' => 'utopia-php-orchestration',
                ]),
                'annotations' => [],
            ],
            'spec' => [
                'containers' => [[
                    'imagePullSecrets' => ($this->regCred ? [[
                        'name' => 'utopia-orchestration-regcred',
                    ]] : []),
                    'name' => $name,
                    'image' => $image,
                    'imagePullPolicy' => 'IfNotPresent',
                    'command' => ($entrypoint ? [[$entrypoint]] : []),
                    'args' => [$command],
                    'workingDir' => $workdir,
                    'env' => $vars,
                ]],
            ],
            'volumes' => $volumes,
            'restartPolicy' => 'Never',
            'hostname' => $hostname,
            'setHostnameAsFQDN' => true,
            //'subdomain' => 'utopia',
            'resources' => [
                'limits' => [
                    'cpu' => floatval($this->cpus),
                    'memory' => intval($this->memory) * 1e+6, // Convert into bytes
                ],
                'requests' => [
                    'cpu' => floatval($this->cpus),
                    'memory' => intval($this->memory) * 1e+6, // Convert into bytes
                ],
            ],
        ];

        $body = array_filter($body, function ($value) {
            return ! empty($value);
        });

        $result = $this->call('/api/v1/namespaces/'.$this->namespace.'/pods', 'POST', json_encode($body), [
            'Content-Type: application/json',
            'Content-Length: '.\strlen(\json_encode($body)),
        ]);

        if (! in_array($result['code'], [200, 201, 202])) {
            throw new Orchestration('Failed to create function environment: '.$result['response'].' Response Code: '.$result['code']);
        }

        $parsedResponse = json_decode($result['response'], true);

        return $parsedResponse['metadata']['name'];
    }

    /**
     * Execute Pod
     *
     * @param  string[]  $command
     * @param  array<string, string>  $vars
     */
    public function execute(
        string $name,
        array $command,
        string &$output,
        array $vars = [],
        int $timeout = -1
    ): bool {
        $body = [
            'Env' => \array_values($vars),
            'Cmd' => $command,
            'AttachStdout' => true,
            'AttachStderr' => true,
        ];

        // /api/v1/namespaces/:namespace/pods/:name/exec?command=laboris anim ullamco consequat&stderr=true&stdout=true&container=
        // Connection upgrade needed to websocket

        $result = [];

        $output = $result['stdout'].$result['stderr'];

        if ($result['code'] !== 200) {
            throw new Orchestration('Failed to create execute command: '.$result['response'].' Response Code: '.$result['code']);
        } else {
            return true;
        }
    }

    /**
     * Remove Pod
     */
    public function remove(string $name, bool $force = false): bool
    {
        $result = $this->call('/api/v1/namespaces/'.$this->namespace.'/pods/'.$name.($force ? '?gracePeriodSeconds=0' : ''), 'DELETE');

        if ($result['code'] !== 200 || $result['code'] !== 202) {
            throw new Orchestration('Failed to remove container: '.$result['response'].' Response Code: '.$result['code']);
        } else {
            return true;
        }
    }
}
