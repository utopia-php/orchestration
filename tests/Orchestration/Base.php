<?php

namespace Utopia\Tests;

use Utopia\CLI\Console;
use PHPUnit\Framework\TestCase;
use Utopia\Orchestration\Orchestration;

abstract class Base extends TestCase
{
    abstract protected static function getOrchestration(): Orchestration;

    abstract protected static function getAdapterName(): string;

    /**
     * @var string
     */
    public static $containerID;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function testPullImage(): void
    {
        /**
         * Test for Success
         */
        $response = static::getOrchestration()->pull('appwrite/runtime-for-php:8.0');

        $this->assertEquals(true, $response);

        // Used later for CPU usage test
        $response = static::getOrchestration()->pull('containerstack/alpine-stress:latest');

        $this->assertEquals(true, $response);

        /**
         * Test for Failure
         */
        $response = static::getOrchestration()->pull('appwrite/tXDytMhecKCuz5B4PlITXL1yKhZXDP'); // Pull non-existent Container
        $this->assertEquals(false, $response);
    }

    /**
     * @depends testPullImage
     */
    public function testCreateContainer(): void
    {
        $response = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            'TestContainer',
            [
                'sh',
                '-c',
                'cp /tmp/php.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null',
            ],
            '',
            '/usr/local/src/',
            [
                __DIR__.'/Resources:/test:rw',
            ],
            [],
            __DIR__.'/Resources'
        );

        $this->assertNotEmpty($response);

        /**
         * Test for Failure
         */
        $this->expectException(\Exception::class);

        $response = static::getOrchestration()->run(
            'appwrite/tXDytMhecKCuz5B4PlITXL1yKhZXDP', // Non-Existent Image
            'TestContainer',
            [
                'sh',
                '-c',
                'cp /tmp/php.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null',
            ],
            '',
            '/usr/local/src/',
            [],
            [],
            __DIR__.'/Resources',
        );
    }

    // Network Tests

    /**
     * @depends testCreateContainer
     */
    public function testCreateNetwork(): void
    {
        $response = static::getOrchestration()->createNetwork('TestNetwork');

        $this->assertEquals(true, $response);
    }

    /**
     * @depends testCreateNetwork
     */
    public function testListNetworks(): void
    {
        $response = static::getOrchestration()->listNetworks();

        $foundNetwork = false;

        foreach ($response as $value) {
            if ($value->getName() == 'TestNetwork') {
                $foundNetwork = true;
            }
        }

        $this->assertEquals(true, $foundNetwork);
    }

    /**
     * @depends testCreateNetwork
     */
    public function testnetworkConnect(): void
    {
        $response = static::getOrchestration()->networkConnect('TestContainer', 'TestNetwork');

        $this->assertEquals(true, $response);
    }

    /**
     * @depends testCreateNetwork
     */
    public function testCreateContainerWithNetwork(): void
    {
        $response = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            'TestContainerRM',
            [
                'sh',
                '-c',
                'echo Hello World!',
            ],
            '',
            '/usr/local/src/',
            [],
            [
                'teasdsa' => '',
            ],
            __DIR__.'/Resources',
            [
                'test2' => 'Hello World!',
            ],
            '',
            true,
            'TestNetwork'
        );

        $this->assertNotEmpty($response);
    }

    /**
     * @depends testnetworkConnect
     */
    public function testnetworkDisconnect(): void
    {
        $response = static::getOrchestration()->networkDisconnect('TestContainer', 'TestNetwork', true);

        $this->assertEquals(true, $response);
    }

    /**
     * @depends testCreateNetwork
     */
    public function testRemoveNetwork(): void
    {
        $response = static::getOrchestration()->removeNetwork('TestNetwork');

        $this->assertEquals(true, $response);
    }

    /**
     * @depends testCreateContainer
     */
    public function testExecContainer(): void
    {
        $output = '';

        $response = static::getOrchestration()->execute(
            'TestContainer',
            [
                'php',
                'index.php',
            ],
            $output,
            [
                'test' => 'testEnviromentVariable',
            ],
        );

        $this->assertEquals('Hello World! testEnviromentVariable', $output);

        /**
         * Test for Failure
         */
        $output = '';

        $this->expectException(\Exception::class);

        static::getOrchestration()->execute(
            '60clotVWpufbEpy33zJLcoYHrUTqWaD1FV0FZWsw', // Non-Existent Container
            [
                'php',
                'index.php',
            ],
            $output
        );
    }

    /**
     * @depends testExecContainer
     */
    public function testCheckVolume(): void
    {
        $output = '';

        $response = static::getOrchestration()->execute(
            'TestContainer',
            [
                'cat',
                '/test/testfile.txt',
            ],
            $output
        );

        $this->assertEquals('Lorem ipsum dolor sit amet, consectetur adipiscing elit. Cras dapibus turpis mauris, ac consectetur odio varius ullamcorper.', $output);
    }

    /**
     * @depends testExecContainer
     */
    public function testTimeoutContainer(): void
    {
        // Create container
        $response = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            'TestContainerTimeout',
            [
                'sh',
                '-c',
                'cp /tmp/timeout.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null',
            ],
            '',
            '/usr/local/src/',
            [],
            [
                'teasdsa' => '',
            ],
            __DIR__.'/Resources',
            [
                'test2' => 'Hello World!',
            ]
        );

        $this->assertNotEmpty($response);

        self::$containerID = $response;

        /**
         * Test for Failure
         */
        $output = '';

        $this->expectException(\Exception::class);

        $response = static::getOrchestration()->execute(
            'TestContainerTimeout',
            [
                'php',
                'index.php',
            ],
            $output,
            [],
            1
        );

        /**
         * Test for Success
         */
        $output = '';

        $response = static::getOrchestration()->execute(
            'TestContainerTimeout',
            [
                'php',
                'index.php',
            ],
            $output,
            [],
            10
        );

        $this->assertEquals(true, $response);

        /**
         * Test for Success
         */
        $output = '';

        $response = static::getOrchestration()->execute(
            'TestContainerTimeout',
            [
                'sh',
                '-c',
                'echo Hello World!',
            ],
            $output,
            [],
            10
        );

        $this->assertEquals('Hello World!', $output);
        $this->assertEquals(true, $response);
    }

    /**
     * @depends testCreateContainer
     */
    public function testListContainers(): void
    {
        $response = static::getOrchestration()->list();

        $foundContainer = false;

        foreach ($response as $value) {
            if ($value->getName() == 'TestContainer') {
                $foundContainer = true;
            }
        }

        $this->assertEquals(true, $foundContainer);
    }

    /**
     * @depends testCreateContainer
     */
    public function testListFilters(): void
    {
        $response = $this->getOrchestration()->list(['id' => self::$containerID]);

        $this->assertEquals(self::$containerID, $response[0]->getId());
    }

    /**
     * @depends testCreateContainer
     */
    public function testRemoveContainer(): void
    {
        /**
         * Test for Success
         */
        $response = static::getOrchestration()->remove('TestContainer', true);

        $this->assertEquals(true, $response);

        $response = static::getOrchestration()->remove('TestContainerTimeout', true);
        $this->assertEquals(true, $response);

        /**
         * Test for Failure
         */
        $this->expectException(\Exception::class);

        $response = static::getOrchestration()->remove('TestContainer', true);
    }

    public function testParseCLICommand(): void
    {
        /**
         * Test for success
         */
        $test = static::getOrchestration()->parseCommandString("sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'");

        $this->assertEquals([
            'sh',
            '-c',
            "'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'",
        ], $test);

        $test = static::getOrchestration()->parseCommandString('sudo apt-get update');

        $this->assertEquals([
            'sudo',
            'apt-get',
            'update',
        ], $test);

        $test = static::getOrchestration()->parseCommandString('test');

        $this->assertEquals([
            'test',
        ], $test);

        /**
         * Test for failure
         */
        $this->expectException(\Exception::class);

        $test = static::getOrchestration()->parseCommandString("sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null");
    }

    public function testRunRemove(): void
    {
        /**
         * Test for success
         */
        $response = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            'TestContainerRM',
            [
                'sh',
                '-c',
                'echo Hello World!',
            ],
            '',
            '/usr/local/src/',
            [],
            [
                'teasdsa' => '',
            ],
            __DIR__.'/Resources',
            [
                'test2' => 'Hello World!',
            ],
            '',
            true
        );

        $this->assertNotEmpty($response);

        sleep(1);

        // Check if container exists
        $statusResponse = static::getOrchestration()->list(['id' => $response]);

        $this->assertEquals(0, count($statusResponse));
    }

    /**
     * @depends testPullImage
     */
    public function testUsageStats(): void
    {
        /**
         * Test for Success
         */
        $stats = static::getOrchestration()->getStats();
        $this->assertCount(0, $stats);

        // This allows CPU-heavy load check
        static::getOrchestration()->setCpus(1);

        $containerId1 = static::getOrchestration()->run(
            'containerstack/alpine-stress',  // https://github.com/containerstack/alpine-stress
            'UsageStats1',
            [
                'sh',
                '-c',
                'apk update && apk add screen && tail -f /dev/null',
            ],
            workdir: '/usr/local/src/',
            mountFolder: __DIR__.'/Resources',
            labels: ['utopia-container-type' => 'stats']
        );
        $dump = function($value) {
            $p = var_export($value, true);
            $b = debug_backtrace();
            print($b[0]['file'] . ':' . $b[0]['line'] . ' - ' . $p . "\n");
        };
        $dump($containerId1);

        $output = '';
        Console::execute("docker logs $containerId1", '', $output);
        $dump($output);

        $this->assertNotEmpty($containerId1);

        $output = '';
        Console::execute("docker inspect $containerId1", '', $output);
        $dump($output);

        $containerId2 = static::getOrchestration()->run(
            'containerstack/alpine-stress',
            'UsageStats2',
            [
                'sh',
                '-c',
                'apk update && apk add screen && tail -f /dev/null',
            ],
            workdir: '/usr/local/src/',
            mountFolder: __DIR__.'/Resources',
        );
        $dump($containerId2);

        $output = '';
        Console::execute("docker logs $containerId2", '', $output);
        $dump($output);

        $this->assertNotEmpty($containerId2);

        $output = '';
        Console::execute("docker inspect $containerId2", '', $output);
        $dump($output);

        $output = '';
        static::getOrchestration()->execute($containerId1, ['which', 'screen'], $output);
        $dump($output);
        sleep(5);

        $output = '';
        Console::execute('docker ps -a', '', $output);
        $dump($output);

        $output = '';
        Console::execute('docker stats --no-stream', '', $output);
        $dump($output);

        // This allows CPU-heavy load check
        $output = '';
        static::getOrchestration()->execute($containerId1, ['screen', '-d', '-m', 'stress --cpu 1 --timeout 5'], $output); // Run in screen so it's background task
        $dump($output);
        $output = '';
        static::getOrchestration()->execute($containerId2, ['screen', '-d', '-m', 'stress --cpu 1 --timeout 5'], $output);
        $dump($output);

        // Set CPU stress-test start
        \sleep(1);

        $output = '';
        Console::execute('docker stats --no-stream', '', $output);
        $dump($output);

        // Fetch stats, should include high CPU usage
        $stats = static::getOrchestration()->getStats();

        $this->assertCount(2, $stats);

        $this->assertNotEmpty($stats[0]->getContainerId());
        $this->assertEquals(64, \strlen($stats[0]->getContainerId()));

        $this->assertEquals('UsageStats2', $stats[0]->getContainerName());

        $this->assertGreaterThanOrEqual(0, $stats[0]->getCpuUsage());
        $this->assertLessThanOrEqual(2, $stats[0]->getCpuUsage()); // Sometimes it gives like 102% usage

        $this->assertGreaterThanOrEqual(0, $stats[0]->getMemoryUsage());
        $this->assertLessThanOrEqual(1, $stats[0]->getMemoryUsage());

        $this->assertIsNumeric($stats[0]->getDiskIO()['in']);
        $this->assertGreaterThanOrEqual(0, $stats[0]->getDiskIO()['in']);
        $this->assertIsNumeric($stats[0]->getDiskIO()['out']);
        $this->assertGreaterThanOrEqual(0, $stats[0]->getDiskIO()['out']);

        $this->assertIsNumeric($stats[0]->getMemoryIO()['in']);
        $this->assertGreaterThanOrEqual(0, $stats[0]->getMemoryIO()['in']);
        $this->assertIsNumeric($stats[0]->getMemoryIO()['out']);
        $this->assertGreaterThanOrEqual(0, $stats[0]->getMemoryIO()['out']);

        $this->assertIsNumeric($stats[0]->getNetworkIO()['in']);
        $this->assertGreaterThanOrEqual(0, $stats[0]->getNetworkIO()['in']);
        $this->assertIsNumeric($stats[0]->getNetworkIO()['out']);
        $this->assertGreaterThanOrEqual(0, $stats[0]->getNetworkIO()['out']);

        $stats1 = static::getOrchestration()->getStats($containerId1);
        $stats2 = static::getOrchestration()->getStats($containerId2);

        $statsName1 = static::getOrchestration()->getStats('UsageStats1');
        $statsName2 = static::getOrchestration()->getStats('UsageStats2');

        $this->assertEquals($statsName1[0]->getContainerId(), $stats1[0]->getContainerId());
        $this->assertEquals($statsName1[0]->getContainerName(), $stats1[0]->getContainerName());
        $this->assertEquals($statsName2[0]->getContainerName(), $stats2[0]->getContainerName());
        $this->assertEquals($statsName2[0]->getContainerName(), $stats2[0]->getContainerName());

        $this->assertEquals($stats[1]->getContainerId(), $stats1[0]->getContainerId());
        $this->assertEquals($stats[1]->getContainerName(), $stats1[0]->getContainerName());
        $this->assertEquals($stats[0]->getContainerId(), $stats2[0]->getContainerId());
        $this->assertEquals($stats[0]->getContainerName(), $stats2[0]->getContainerName());

        $this->assertGreaterThanOrEqual(0.5, $stats[0]->getCpuUsage());
        $this->assertGreaterThanOrEqual(0.5, $stats[1]->getCpuUsage());

        $statsFiltered = static::getOrchestration()->getStats(filters: ['label' => 'utopia-container-type=stats']);
        $this->assertCount(1, $statsFiltered);
        $this->assertEquals($containerId1, $statsFiltered[0]->getContainerId());

        $statsFiltered = static::getOrchestration()->getStats(filters: ['label' => 'utopia-container-type=non-existing-type']);
        $this->assertCount(0, $statsFiltered);

        $response = static::getOrchestration()->remove('UsageStats1', true);

        $this->assertEquals(true, $response);

        $response = static::getOrchestration()->remove('UsageStats2', true);

        $this->assertEquals(true, $response);

        /**
         * Test for Failure
         */
        $this->expectException(\Exception::class);

        $stats = static::getOrchestration()->getStats('IDontExist');
    }
}
