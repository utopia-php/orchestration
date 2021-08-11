<?php

namespace Utopia\Orchestration\Adapter;

use CurlHandle;
use stdClass;
use Utopia\Orchestration\Adapter;
use Utopia\Orchestration\Container;
use Utopia\Orchestration\Exceptions\DockerAPIException;
use Utopia\Orchestration\Exceptions\TimeoutException;

class DockerAPI extends Adapter
{
    /**
     * Constructor
     * 
     * @param string $username
     * @param string $password
     * @param string $email
     */
    public function __construct(string $username = null, string $password = null, string $email = null)
    {
        if ($username && $password && $email) {
            $this->registryAuth = base64_encode(json_encode([
                'username' => $username,
                'password' => $password,
                'serveraddress' => 'https://index.docker.io/v1/',
                'email' => $email
            ]));
        }
    }

    /**
     * @var string
     */
    private $registryAuth = '';

    /**
     * Create a request with cURL via the Docker socket
     *
     * @param string $url
     * @param string $method
     * @param array|bool|int|float|object|resource|string|null $body
     * @param string[] $headers
     * @param int $timeout
     *
     * @return (bool|mixed|string)[]
     *
     * @psalm-return array{response: mixed, code: mixed}
     */
    protected function call(string $url, string $method, $body = null, array $headers = [], int $timeout = -1): array
    {
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, $url);
        \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        switch ($method) {
            case 'GET':
                if (!is_null($body)) {
                    \curl_setopt($ch, CURLOPT_URL, $url . '?' . $body);
                }
                break;
            case 'POST':
                \curl_setopt($ch, CURLOPT_POST, 1);

                if (!is_null($body)) {
                    \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
                break;
            case 'DELETE':
                \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

                if (!is_null($body)) {
                    \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
                break;
        }

        $result = \curl_exec($ch);
        $responseCode = \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        \curl_close($ch);

        return [
            'response' => $result,
            'code' => $responseCode
        ];
    }

    /**
     * Create a request with cURL via the Docker socket
     * but process a Docker Stream Response
     *
     * @param string $url
     * @param int $timeout
     *
     * @return (bool|mixed|string)[]
     *
     * @psalm-return array{response: bool|string, code: mixed, stdout: mixed, stderr: mixed}
     */
    protected function streamCall(string $url, int $timeout = -1): array
    {
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, $url);
        \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
        \curl_setopt($ch, CURLOPT_POST, 1);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, '{}'); // body is required
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $headers = [
            'Content-Type: application/json',
            'Content-Length: 2',
        ];
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        /*
         * Exec logs come back with STDOUT+STDERR multiplexed into a single stream.
         * Each frame of the stream has the following format: 
         *   header := [8]byte{STREAM_TYPE, 0, 0, 0, SIZE1, SIZE2, SIZE3, SIZE4}
         *     STREAM_TYPE is of the following: [0=>'stdin', 1=>'stdout', 2=>'stderr']
         *     SIZE1, SIZE2, SIZE3, SIZE4 are the four bytes of the uint32 size encoded as big endian.
         *     Following the header is the payload, which is the specified number of bytes of STREAM_TYPE.
         *
         * To assign the appropriate stream:
         *   - unpack as an unsigned char ('C*')
         *   - check the first byte of the header to assign stream
         *   - pack up stream, omitting the 8 bytes of header
         *   - concat to stream
         * 
         * Reference: https://docs.docker.com/engine/api/v1.41/#operation/ContainerAttach
         */

        $stdout = '';
        $stderr = '';

        $callback = function (CurlHandle $ch, string $str) use (&$stdout, &$stderr): int {
            $rawStream = unpack('C*', $str);
            $stream = $rawStream[1]; // 1-based index, not 0-based
            switch ($stream) { // only 1 or 2, as set while creating exec 
                case 1:
                    $packed = pack('C*', ...\array_slice($rawStream, 8));
                    $stdout .= $packed;
                    break;
                case 2:
                    $packed = pack('C*', ...\array_slice($rawStream, 8));
                    $stderr .= $packed;
                    break;
            }
            return strlen($str); // must return full frame from callback
        };
        \curl_setopt($ch, CURLOPT_WRITEFUNCTION, $callback);


        \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $result = \curl_exec($ch);
        $responseCode = \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if(curl_errno($ch))
        {
            if (\curl_errno($ch) == CURLE_OPERATION_TIMEOUTED) {
                throw new TimeoutException('Curl Error: ' . curl_error($ch));
            } else {
                throw new DockerAPIException('Curl Error: ' . curl_error($ch));
            }
        }

        \curl_close($ch);

        return [
            'response' => $result,
            'code' => $responseCode,
            'stdout' => $stdout,
            'stderr' => $stderr
        ];
    }

    /**
     * Pull Image
     * 
     * @param string $image
     * 
     * @return bool
     */
    public function pull(string $image): bool
    {
        $result = $this->call('http://localhost/images/create', 'POST', \http_build_query([
            'fromImage' => $image
        ]), [
            'X-Registry-Auth' => $this->registryAuth
        ]);

        if ($result['code'] !== 200 && $result['code'] !== 204) {
            $data = json_decode($result['response'], true);
            if (isset($data['message'])) {
                throw new DockerAPIException('Failed to pull container: ' . $data['message']);
            } else {
                throw new DockerAPIException('Failed to pull container: Internal Docker Error');
            }
            return false;
        } else {
            return true;
        }
    }

    /**
     * List Containers
     * @param array<string, string> $filters
     *
     * @return Container[]
     */
    public function list(array $filters = []): array
    {
        $filtersSorted = [];

        foreach($filters as $key => $value) {
            $filtersSorted[$key] = [$value];
        }

        $body = [
            'all' => true,
            'filters' => empty($filtersSorted) ? new stdClass() : json_encode($filtersSorted)
        ];

        $result = $this->call('http://localhost/containers/json'.'?'.\http_build_query($body), 'GET');

        $list = [];

        if ($result['code'] !== 200) {
            throw new DockerAPIException($result['response']);
        }

        foreach (\json_decode($result['response'], true) as $value) {
            if(isset($value['Names'][0])) {
                $parsedContainer = new Container(
                    \str_replace('/', '', $value['Names'][0]), 
                    $value['Id'],
                    $value['Status'],
                    $value['Labels']
                );

            
                array_push($list, $parsedContainer);
            }
        }

        return $list;
    }

    /**
     * Run Container
     * 
     * Creates and runs a new container, On success it will return a string containing the container ID.
     * On fail it will throw an exception.
     * 
     * @param string $image
     * @param string $name
     * @param string[] $command
     * @param string $entrypoint
     * @param string $workdir
     * @param string[] $volumes
     * @param array<string, string> $vars
     * @param string $mountFolder
     * 
     * @return string
     */
    public function run(string $image, string $name, array $command, string $entrypoint = '', string $workdir = '/', array $volumes = [], array $vars = [], string $mountFolder = '', array $labels = []): string
    {
        foreach ($vars as $key => $value) {
            if ($value !== '') {
                $key = $this->filterEnvKey($key);
                $value = $key.'='.$value;
            } else {
                unset($vars[$key]);
            }
        }

        $body = [
            'Entrypoint' => '',
            'Image' => $image,
            'Cmd' => $command,
            'WorkingDir' => '/usr/local/src',
            'Labels' => (object) $labels,
            'Env' => array_values($vars),
            'HostConfig' => [
                'Binds' => [
                    $mountFolder.':/tmp'
                ],
                'CpuQuota' => floatval($this->cpus) * 100000,
                'CpuPeriod' => 100000,
                'Memory' => intval($this->memory) * 1e+6, // Convert into bytes
                'MemorySwap' => intval($this->swap) * 1e+6 // Convert into bytes
            ],
        ];

        $result = $this->call('http://localhost/containers/create?name='.$name, 'POST', json_encode($body), [
            'Content-Type: application/json',
            'Content-Length: ' . \strlen(\json_encode($body))
        ]);

        if ($result['code'] !== 201) {
            throw new DockerAPIException('Failed to create function environment: '. $result['response']. ' Response Code: '. $result['code']);
        }

        $parsedResponse = json_decode($result['response'], true);

        // Run Created Container
        $result = $this->call('http://localhost/containers/'.$parsedResponse['Id'].'/start', 'POST', '{}');
        
        if ($result['code'] !== 204) {
            throw new DockerAPIException('Failed to create function environment: '.$result['response'].' Response Code: '.$result['code']);
        } else {
            return $parsedResponse['Id'];
        }
    }

    /**
     * Execute Container
     *
     * @param string $name
     * @param string[] $command
     * @param string &$stdout
     * @param string &$stderr
     * @param array<string, string> $vars
     * @param int $timeout
     * @return bool
     */
    public function execute(string $name, array $command, string &$stdout, string &$stderr, array $vars = [], int $timeout = -1): bool
    {
        foreach ($vars as $key => &$value) {
            $key = $this->filterEnvKey($key);
            $value = $key.'='.$value;
        }

        $body = [
            'Env' => \array_values($vars),
            'Cmd' => $command,
            'AttachStdout' => true,
            'AttachStderr' => true
        ];

        $result = $this->call('http://localhost/containers/'.$name.'/exec', 'POST', json_encode($body), [
            'Content-Type: application/json',
            'Content-Length: ' . \strlen(\json_encode($body)),
        ], $timeout);

        if ($result['code'] !== 201) {
            throw new DockerAPIException('Failed to create execute command: '.$result['response'].' Response Code: '.$result['code']);
        }

        $parsedResponse = json_decode($result['response'], true);

        $result = $this->streamCall('http://localhost/exec/'.$parsedResponse['Id'].'/start', $timeout);

        $stdout = $result['stdout'];
        $stderr = $result['stderr'];

        if ($result['code'] !== 200) {
            throw new DockerAPIException('Failed to create execute command: '.$result['response'].' Response Code: '. $result['code']);
        } else {
            return true;
        }
    }

    /**
     * Remove Container
     *
     * @param string $name
     * @param bool $force
     * @return bool
     */
    public function remove(string $name, bool $force = false): bool
    {
        $result = $this->call('http://localhost/containers/'.$name.($force ? '?force=true': ''), 'DELETE');

        if ($result['code'] !== 204) {
            throw new DockerAPIException('Failed to remove container: '.$result['response'].' Response Code: '.$result['code']);
        } else {
            return true;
        }
    }
}
