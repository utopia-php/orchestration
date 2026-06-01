<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Mount;

class MountTest extends TestCase
{
    public function testVolumeSerialization(): void
    {
        $mount = Mount::volume('openruntimes-build-cache', '/cache', false, 'cache-key');

        $this->assertSame([
            'Type' => 'volume',
            'Source' => 'openruntimes-build-cache',
            'Target' => '/cache',
            'ReadOnly' => false,
            'VolumeOptions' => [
                'Subpath' => 'cache-key',
            ],
        ], $mount->toDockerAPI());

        $this->assertSame(
            'type=volume,source=openruntimes-build-cache,target=/cache,volume-subpath=cache-key',
            $mount->toDockerCLI()
        );
    }

    public function testBindSerialization(): void
    {
        $mount = Mount::bind('/host/path', '/container/path');

        $this->assertSame([
            'Type' => 'bind',
            'Source' => '/host/path',
            'Target' => '/container/path',
            'ReadOnly' => false,
        ], $mount->toDockerAPI());

        $this->assertSame(
            'type=bind,source=/host/path,target=/container/path',
            $mount->toDockerCLI()
        );
    }

    public function testBindSerializationWithSubpath(): void
    {
        $mount = Mount::bind('/host/path', '/container/path', subpath: 'sub');

        $this->assertSame([
            'Type' => 'bind',
            'Source' => '/host/path/sub',
            'Target' => '/container/path',
            'ReadOnly' => false,
        ], $mount->toDockerAPI());

        $this->assertSame(
            'type=bind,source=/host/path/sub,target=/container/path',
            $mount->toDockerCLI()
        );
    }

    public function testDockerAPIRequestBodyIncludesMountsAndLegacyBinds(): void
    {
        $adapter = new MountTestDockerAPI();

        $adapter->run(
            'ubuntu:latest',
            'MountTestContainer',
            volumes: [
                '/host/path:/container/path:rw',
                Mount::volume('openruntimes-build-cache', '/cache', false, 'cache-key'),
            ]
        );

        $hostConfig = $adapter->createBody['HostConfig'];

        $this->assertSame(['/host/path:/container/path:rw'], $hostConfig['Binds']);
        $this->assertSame([
            [
                'Type' => 'volume',
                'Source' => 'openruntimes-build-cache',
                'Target' => '/cache',
                'ReadOnly' => false,
                'VolumeOptions' => [
                    'Subpath' => 'cache-key',
                ],
            ],
        ], $hostConfig['Mounts']);
    }

    public function testDockerCLIRendersMountsAndLegacyVolumes(): void
    {
        $adapter = new MountTestDockerCLI();

        $arguments = $adapter->getRunArguments([
            '/host/path:/container/path:rw',
            Mount::volume('openruntimes-build-cache', '/cache', false, 'cache-key'),
        ]);

        $this->assertContains('--volume', $arguments);
        $this->assertContains('/host/path:/container/path:rw', $arguments);
        $this->assertContains('--mount', $arguments);
        $this->assertContains('type=volume,source=openruntimes-build-cache,target=/cache,volume-subpath=cache-key', $arguments);
    }
}

class MountTestDockerAPI extends DockerAPI
{
    /**
     * @var array<string, mixed>
     */
    public array $createBody = [];

    /**
     * @param  array<mixed>|bool|int|float|object|resource|string|null  $body
     * @param  string[]  $headers
     * @return array{response: mixed, code: mixed}
     */
    protected function call(string $url, string $method, $body = null, array $headers = [], int $timeout = -1): array
    {
        if ($method === 'GET' && str_contains($url, '/images/')) {
            return ['response' => '{}', 'code' => 200];
        }

        if ($method === 'POST' && str_contains($url, '/containers/create')) {
            $this->createBody = \json_decode((string) $body, true);

            return ['response' => '{"Id":"container-id"}', 'code' => 201];
        }

        if ($method === 'POST' && str_contains($url, '/containers/container-id/start')) {
            return ['response' => '', 'code' => 204];
        }

        return ['response' => '', 'code' => 500];
    }
}

class MountTestDockerCLI extends DockerCLI
{
    /**
     * @param  array<int, string|Mount>  $volumes
     * @return string[]
     */
    public function getRunArguments(array $volumes): array
    {
        return $this->getRunCommand('ubuntu:latest', 'MountTestContainer', volumes: $volumes)->toArray();
    }
}
