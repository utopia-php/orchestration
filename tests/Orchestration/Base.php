<?php

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Orchestration\Exception\Timeout;
use Utopia\Orchestration\Orchestration;

abstract class Base extends TestCase
{
    /**
     * @return Orchestration
     */
    abstract static protected function getOrchestration(): Orchestration;

    /**
     * @return string
     */
    abstract static protected function getAdapterName(): string;

    /**
     * @var string
     */
    static $containerID;

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

        /**
         * Test for Failure
         */

        $response = static::getOrchestration()->pull('appwrite/tXDytMhecKCuz5B4PlITXL1yKhZXDP'); // Pull non-existent Container
        $this->assertEquals(false, $response);
    }

    /**
     * @return void
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
                'cp /tmp/php.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null'
            ],
            '',
            '/usr/local/src/',
            [
                __DIR__ . '/Resources:/test:rw'
            ],
            [],
            __DIR__ . '/Resources'
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
                'cp /tmp/php.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null'
            ],
            '',
            '/usr/local/src/',
            [],
            [],
            __DIR__ . '/Resources',
        );
    }

    // Network Tests

    /**
     * @return void
     * @depends testCreateContainer
     */
    public function testCreateNetwork(): void
    {
        $response = static::getOrchestration()->createNetwork('TestNetwork');

        $this->assertEquals(true, $response);
    }

    /**
     * @return void
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
     * @return void
     * @depends testCreateNetwork
     */
    public function testnetworkConnect(): void
    {
        $response = static::getOrchestration()->networkConnect('TestContainer', 'TestNetwork');

        $this->assertEquals(true, $response);
    }

    /**
     * @return void
     * @depends testnetworkConnect
     */
    public function testnetworkDisconnect(): void
    {
        $response = static::getOrchestration()->networkDisconnect('TestContainer', 'TestNetwork', true);

        $this->assertEquals(true, $response);
    }


    /**
     * @return void
     * @depends testCreateNetwork
     */
    public function testRemoveNetwork(): void
    {
        $response = static::getOrchestration()->removeNetwork('TestNetwork');

        $this->assertEquals(true, $response);
    }

    /**
     * @return void
     * @depends testCreateContainer
     */
    public function testExecContainer(): void
    {
        $stdout = '';
        $stderr = '';

        $response = static::getOrchestration()->execute(
            'TestContainer',
            [
                'php',
                'index.php'
            ],
            $stdout,
            $stderr,
            [
                'test' => 'testEnviromentVariable'
            ],
        );

        $this->assertEquals('Hello World! testEnviromentVariable', $stdout);

        /**
         * Test for Failure
         */

        $stdout = '';
        $stderr = '';

        $this->expectException(\Exception::class);

        static::getOrchestration()->execute(
            '60clotVWpufbEpy33zJLcoYHrUTqWaD1FV0FZWsw', // Non-Existent Container
            [
                'php',
                'index.php'
            ],
            $stdout,
            $stderr
        );
    }

    /**
     * @return void
     * @depends testExecContainer
     */
    public function testCheckVolume(): void
    {
        $stdout = '';
        $stderr = '';

        $response = static::getOrchestration()->execute(
            'TestContainer',
            [
                'cat',
                '/test/testfile.txt'
            ],
            $stdout,
            $stderr
        );

        $this->assertEquals('Lorem ipsum dolor sit amet, consectetur adipiscing elit. Cras dapibus turpis mauris, ac consectetur odio varius ullamcorper.', $stdout);
    }

    /**
     * @return void
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
                'cp /tmp/timeout.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null'
            ],
            '',
            '/usr/local/src/',
            [],
            [
                'teasdsa' => '',
            ],
            __DIR__ . '/Resources',
            [
                'test2' => 'Hello World!'
            ]
        );

        $this->assertNotEmpty($response);

        self::$containerID = $response;

        /**
         * Test for Failure
         */

        $stdout = '';
        $stderr = '';

        $this->expectException(\Exception::class);

        $response = static::getOrchestration()->execute(
            'TestContainerTimeout',
            [
                'php',
                'index.php'
            ],
            $stdout,
            $stderr,
            [],
            1
        );

        /**
         * Test for Success
         */

        $stdout = '';
        $stderr = '';

        $response = static::getOrchestration()->execute(
            'TestContainerTimeout',
            [
                'php',
                'index.php'
            ],
            $stdout,
            $stderr,
            [],
            10
        );

        $this->assertEquals(true, $response);

        /**
         * Test for Success
         */

        $stdout = '';
        $stderr = '';

        $response = static::getOrchestration()->execute(
            'TestContainerTimeout',
            [
                'sh',
                '-c',
                'echo Hello World!'
            ],
            $stdout,
            $stderr,
            [],
            10
        );

        $this->assertEquals('Hello World!', $stdout);
        $this->assertEquals(true, $response);
    }

    /**
     * @return void
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
     * @return void
     * @depends testCreateContainer
     */

    public function testListFilters(): void
    {
        $response = $this->getOrchestration()->list(['id' => self::$containerID]);

        $this->assertEquals(self::$containerID, $response[0]->getId());
    }

    /**
     * @return void
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
            "'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'"
        ], $test);

        $test = static::getOrchestration()->parseCommandString('sudo apt-get update');

        $this->assertEquals([
            'sudo',
            'apt-get',
            'update'
        ], $test);

        $test = static::getOrchestration()->parseCommandString('test');

        $this->assertEquals([
            'test'
        ], $test);

        /**
         * Test for failure
         */
        $this->expectException(\Exception::class);

        $test = static::getOrchestration()->parseCommandString("sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null");
    }

    public function testRunRemove():void
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
                'echo Hello World!'
            ],
            '',
            '/usr/local/src/',
            [],
            [
                'teasdsa' => '',
            ],
            __DIR__ . '/Resources',
            [
                'test2' => 'Hello World!'
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
     * @return void
     * @depends testPullImage
     */
    public function testUsageStats(): void {
        /**
         * Test for Success
         */

        $stats = static::getOrchestration()->getStats();
        $this->assertCount(0, $stats);

        $containerId1 = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            'UsageStats1',
            [
                'sh',
                '-c',
                'tail -f /dev/null'
            ],
            '',
            '/usr/local/src/',
            [],
            [],
            __DIR__ . '/Resources',
        );

        $this->assertNotEmpty($containerId1);

        $containerId2 = static::getOrchestration()->run(
            'appwrite/runtime-for-php:8.0',
            'UsageStats2',
            [
                'sh',
                '-c',
                'tail -f /dev/null'
            ],
        );

        $this->assertNotEmpty($containerId2);

        $stats = static::getOrchestration()->getStats();

        $this->assertCount(2, $stats);

        $this->assertNotEmpty($stats[0]['id']);
        $this->assertEquals(64, \strlen($stats[0]['id']));

        $this->assertEquals('UsageStats2', $stats[0]['name']);

        $this->assertIsFloat($stats[0]['cpu']);
        $this->assertGreaterThanOrEqual(0, $stats[0]['cpu']);
        $this->assertLessThanOrEqual(1, $stats[0]['cpu']);

        $this->assertIsFloat($stats[0]['memory']);
        $this->assertGreaterThanOrEqual(0, $stats[0]['memory']);
        $this->assertLessThanOrEqual(1, $stats[0]['memory']);

        $this->assertIsNumeric($stats[0]['diskIO']['in']);
        $this->assertGreaterThanOrEqual(0, $stats[0]['diskIO']['in']);
        $this->assertIsNumeric($stats[0]['diskIO']['out']);
        $this->assertGreaterThanOrEqual(0, $stats[0]['diskIO']['out']);

        $this->assertIsNumeric($stats[0]['memoryIO']['in']);
        $this->assertGreaterThanOrEqual(0, $stats[0]['memoryIO']['in']);
        $this->assertIsNumeric($stats[0]['memoryIO']['out']);
        $this->assertGreaterThanOrEqual(0, $stats[0]['memoryIO']['out']);

        $this->assertIsNumeric($stats[0]['networkIO']['in']);
        $this->assertGreaterThanOrEqual(0, $stats[0]['networkIO']['in']);
        $this->assertIsNumeric($stats[0]['networkIO']['out']);
        $this->assertGreaterThanOrEqual(0, $stats[0]['networkIO']['out']);

        $stats1 = static::getOrchestration()->getStats($containerId1);
        $stats2 = static::getOrchestration()->getStats($containerId2);

        $statsName1 = static::getOrchestration()->getStats('UsageStats1');
        $statsName2 = static::getOrchestration()->getStats('UsageStats2');

        $this->assertEquals($statsName1[0]['id'], $stats1[0]['id']);
        $this->assertEquals($statsName1[0]['name'], $stats1[0]['name']);
        $this->assertEquals($statsName2[0]['name'], $stats2[0]['name']);
        $this->assertEquals($statsName2[0]['name'], $stats2[0]['name']);

        $this->assertEquals($stats[1]['id'], $stats1[0]['id']);
        $this->assertEquals($stats[1]['name'], $stats1[0]['name']);
        $this->assertEquals($stats[0]['id'], $stats2[0]['id']);
        $this->assertEquals($stats[0]['name'], $stats2[0]['name']);

        $response = static::getOrchestration()->remove('UsageStats1', true);

        $this->assertEquals(true, $response);

        $response = static::getOrchestration()->remove('UsageStats2', true);

        $this->assertEquals(true, $response);

        /**
         * Test for Failure
         */

        $this->expectException(\Exception::class);

        $stats = static::getOrchestration()->getStats("IDontExist");
    }
}
