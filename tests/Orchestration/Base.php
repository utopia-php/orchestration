<?php

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Orchestration\Exceptions\TimeoutException;
use Utopia\Orchestration\Orchestration;

abstract class Base extends TestCase
{
    /**
     * @return Orchestration
     */
    abstract static protected function getOrchestration(): Orchestration;

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

    public function testPullImage(): void {
        /**
         * Test for Success
         */
        $response = static::getOrchestration()->pull('appwrite/runtime-for-php:8.0');
        
        $this->assertEquals(true, $response);

        /**
         * Test for Failure
         */

        $this->expectException(\Exception::class);

        $response = static::getOrchestration()->pull('appwrite/tXDytMhecKCuz5B4PlITXL1yKhZXDP'); // Pull non-existent Container
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
            "",
            ["sh",
            "-c",
            "cp /tmp/php.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null"],
            '/usr/local/src/',
            [],
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
            "",
            ["sh",
            "-c",
            "cp /tmp/php.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null"],
            '/usr/local/src/',
            [],
            [],
            __DIR__.'/Resources',
        );
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
                "test" => "testEnviromentVariable"
            ],
        );

        $this->assertEquals("Hello World! testEnviromentVariable", $stdout);

        /**
         * Test for Failure
         */

        $testFailed = false;

        $stdout = '';

        $this->expectException(\Exception::class);

        static::getOrchestration()->execute(
            '60clotVWpufbEpy33zJLcoYHrUTqWaD1FV0FZWsw', // Non-Existent Container
            [
                'php',
                'index.php'
            ],
            $stdout
        );
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
            "",
            ["sh",
            "-c",
            "cp /tmp/timeout.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null"],
            '/usr/local/src/',
            [],
            [],
            __DIR__.'/Resources',
            [
                "test2" => "Hello World!"
            ]
        );

        $this->assertNotEmpty($response);

        self::$containerID = $response;

        /**
         * Test for Failure
         */

        $testFailed = false;

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
    }

    /**
     * @return void
     * @depends testCreateContainer
     */
    public function testListContainers(): void
    {
        $response = static::getOrchestration()->list();

        $foundContainer = false;

        \array_map(function($value) use (&$foundContainer) {
            if ($value->getName() == 'TestContainer') {
                $foundContainer = true;
            }
        }, $response);

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
            "sh",
            "-c",
            "'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'"
        ], $test);

        $test = static::getOrchestration()->parseCommandString("sudo apt-get update");

        $this->assertEquals([
            "sudo",
            "apt-get",
            "update"
        ], $test);

        $test = static::getOrchestration()->parseCommandString("test");

        $this->assertEquals([
            "test"
        ], $test);

        /**
         * Test for failure
         */
        $this->expectException(\Exception::class);

        $test = static::getOrchestration()->parseCommandString("sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null");
    }
}