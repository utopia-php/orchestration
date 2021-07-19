<?php

namespace Utopia\Orchestration\Adapter;

use CurlHandle;
use Utopia\Orchestration\Adapter;
use Utopia\Orchestration\StandardContainer;
use Utopia\Orchestration\Exceptions\DockerAPIException;

class DockerAPI extends Adapter
{
    /**
     * Constructor
     * 
     * @param string $usernam
     * @param string $password
     */
    public function __construct(string $username = null, string $password = null)
    {
        if ($username && $password) {
            //TODO: Handle Dockerhub Sign-in
        }
    }


    /**
     * Create a request with cURL
     * 
     * @param string $url
     * @param string $method
     * @param array|bool|int|float|object|resource|string|null $body
     * @param array $headers
     * @return array
     */
    protected function requestWrapper($url, $method, $body = null, $headers = []): array
    {
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, $url);
        \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        switch ($method) {
            case "GET":
                if (!is_null($body)) {
                    \curl_setopt($ch, CURLOPT_URL, $url . '?' . $body);
                }
                break;
            case "POST":
                \curl_setopt($ch, CURLOPT_POST, 1);

                if (!is_null($body)) {
                    \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
                break;
            case "DELETE":
                \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");

                if (!is_null($body)) {
                    \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
                break;
        }

        $result = \curl_exec($ch);
        $responseCode = \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        \curl_close($ch);

        return array(
            "response" => $result,
            "code" => $responseCode
        );
    }

    /**
     * Create a request with cURL
     * but process a Docker Stream Response
     * 
     * @param string $url
     * @return array
     */
    protected function streamRequestWrapper($url): array
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

        $result = \curl_exec($ch);
        $responseCode = \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        \curl_close($ch);

        return array(
            "response" => $result,
            "code" => $responseCode,
            "stdout" => $stdout,
            "stderr" => $stderr
        );
    }

    public function pull(string $image): bool
    {
        $result = $this->requestWrapper("http://localhost/images/create", "POST", \http_build_query(array(
            "fromImage" => $image
        )));

        if ($result["code"] !== 200 && $result["code"] !== 204) {
            $data = json_decode($result["response"], true);
            if (isset($data['message'])) {
                throw new DockerAPIException('Failed to pull container: ' . $data["message"]);
            } else {
                throw new DockerAPIException('Failed to pull container: Internal Docker Error');
            }
            return false;
        } else {
            return true;
        }
    }

    public function list(): array
    {
        $body = array(
            "all" => true
        );

        $result = $this->requestWrapper("http://localhost/containers/json".'?'.\http_build_query($body), "GET");

        $list = [];

        \array_map(function(array $value) use (&$list) {
            if(isset($value['Names'][0])) {
                $parsedContainer = new StandardContainer();
                $parsedContainer->name = \str_replace("/", "", $value['Names'][0]);
                $parsedContainer->id = $value['Id'];
                $parsedContainer->status = $value['Status'];
                $parsedContainer->labels = $value["Labels"];
            
                array_push($list, $parsedContainer);
            }
        }, \json_decode($result['response'], true));

        return $list;
    }

    public function run(string $image, string $name, string $entrypoint = '', array $command = [], string $workdir = '/', array $volumes = [], array $vars = [], string $mountFolder = '', array $labels = []): bool
    {
        $varsList = [];

        \array_walk($vars, function (string &$value, string $key) use (&$varsList) {
            $key = $this->filterEnvKey($key);
            array_push($varsList, strval($key)."=".strval($value));
        });

        $body = array(
            "Entrypoint" => "",
            "Image" => $image,
            "Cmd" => $command,
            "WorkingDir" => "/usr/local/src",
            "Labels" => (object) $labels,
            "Env" => $vars,
            "HostConfig" => array(
                "Binds" => array(
                    "{$mountFolder}:/tmp"
                ),
                "CpuQuota" => floatval($this->cpus) * 100000,
                "CpuPeriod" => 100000,
                "Memory" => intval($this->memory) * 1e+6, // Convert into bytes
                "MemorySwap" => intval($this->swap) * 1e+6 // Convert into bytes
            ),
        );

        $result = $this->requestWrapper("http://localhost/containers/create?name={$name}", "POST", json_encode($body), array(
            'Content-Type: application/json',
            'Content-Length: ' . \strlen(\json_encode($body))
        ));

        if ($result['code'] !== 201) {
            throw new DockerAPIException("Failed to create function environment: {$result['response']} Response Code: {$result['code']}");
        }

        $parsedResponse = json_decode($result['response'], true);

        // Run Created Container
        $result = $this->requestWrapper("http://localhost/containers/{$parsedResponse['Id']}/start", "POST", "{}");
        
        if ($result['code'] !== 204) {
            throw new DockerAPIException("Failed to create function environment: {$result['response']} Response Code: {$result['code']}");
        } else {
            return true;
        }
    }

    public function execute(string $name, array $command, string &$stdout = '', string &$stderr = '', array $vars = [], int $timeout = 0): bool
    {
        \array_walk($vars, function (string &$value, string $key) {
            $key = $this->filterEnvKey($key);
            $value = "{$key}={$value}";
        });

        $body = array(
            "Env" => \array_values($vars),
            "Cmd" => $command,
            "AttachStdout" => true,
            "AttachStderr" => true
        );

        $result = $this->requestWrapper("http://localhost/containers/{$name}/exec", "POST", json_encode($body), array(
            'Content-Type: application/json',
            'Content-Length: ' . \strlen(\json_encode($body))
        ));

        if ($result['code'] !== 201) {
            throw new DockerAPIException("Failed to create execute command: {$result['response']} Response Code: {$result['code']}");
        }

        $parsedResponse = json_decode($result['response'], true);

        $result = $this->streamRequestWrapper("http://localhost/exec/{$parsedResponse['Id']}/start");

        $stdout = $result['stdout'];
        $stderr = $result['stderr'];

        if ($result['code'] !== 200) {
            throw new DockerAPIException("Failed to create execute command: {$result['response']} Response Code: {$result['code']}");
        } else {
            return true;
        }
    }

    public function remove($name, $force = false): bool
    {
        $result = $this->requestWrapper("http://localhost/containers/{$name}".($force ? '?force=true': ''), "DELETE");

        if ($result['code'] !== 204) {
            throw new DockerAPIException("Failed to remove container: {$result['response']} Response Code: {$result['code']}");
        } else {
            return true;
        }
    }
}
