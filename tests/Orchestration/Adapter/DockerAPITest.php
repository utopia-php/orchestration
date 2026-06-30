<?php

namespace Utopia\Tests\Adapter;

use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;
use Utopia\Tests\Base;

class DockerAPITest extends Base
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
        return 'Docker API';
    }

    public static function getOrchestration(): Orchestration
    {
        if (! is_null(self::$orchestration)) {
            return self::$orchestration;
        }

        $orchestration = new Orchestration(new DockerAPI());

        return self::$orchestration = $orchestration;
    }

    public function testExecuteReturnsFalseForNonZeroExit(): void
    {
        $adapter = new class () extends DockerAPI {
            /**
             * @var array<int, array{response: mixed, code: mixed}>
             */
            private array $responses = [
                ['response' => '{"Id":"exec-id"}', 'code' => 201],
                ['response' => '{"Running":false,"ExitCode":1}', 'code' => 200],
            ];

            protected function call(string $url, string $method, $body = null, array $headers = [], int $timeout = -1): array
            {
                return \array_shift($this->responses);
            }

            protected function streamCall(string $url, int $timeout = -1): array
            {
                return [
                    'response' => '',
                    'code' => 200,
                    'stdout' => 'command output',
                    'stderr' => '',
                ];
            }
        };

        $output = '';

        $response = $adapter->execute(
            'TestContainer',
            [
                'php',
                'doesnotexist.php',
            ],
            $output,
            [
                'test' => 'testEnviromentVariable',
            ],
            1
        );

        $this->assertFalse($response);
        $this->assertSame('command output', $output);
    }
}
