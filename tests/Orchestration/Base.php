<?php

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Orchestration\Orchestration;

abstract class Base extends TestCase
{
    /**
     * @return Orchestration
     */
    abstract static protected function getOrchestration(): Orchestration;

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

        $testFailed = false;

        try {
            $response = static::getOrchestration()->pull('appwrite/tXDytMhecKCuz5B4PlITXL1yKhZXDP'); // Pull non-existent Container
        } catch (Exception $e) {
            $testFailed = true;
        }

        $this->assertEquals(true, $testFailed);
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
            array("sh",
            "-c",
            "cp /tmp/php.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null"),
            '/usr/local/src/',
            [],
            [],
            __DIR__.'/Resources'
        );

        $this->assertEquals(true, $response);

        /**
         * Test for Failure
         */

        $testFailed = false;

        try {
            $response = static::getOrchestration()->run(
                'appwrite/tXDytMhecKCuz5B4PlITXL1yKhZXDP', // Non-Existent Image
                'TestContainer',
                "",
                array("sh",
                "-c",
                "cp /tmp/php.tar.gz /usr/local/src/php.tar.gz && tar -zxf /usr/local/src/php.tar.gz --strip 1 && tail -f /dev/null"),
                '/usr/local/src/',
                [],
                [],
                __DIR__.'/Resources',
                array(
                    "test" => "Hello World!"
                )
            );
        } catch (Exception $e) {
            $testFailed = true;
        }

        $this->assertEquals(true, $testFailed);
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
            array(
                'php',
                'index.php'
            ),
            $stdout,
            $stderr,
            array(
                "test" => "testEnviromentVariable"
            )
        );

        $this->assertEquals("Hello World! testEnviromentVariable", $stdout);

        /**
         * Test for Failure
         */

        $testFailed = false;

        $stdout = '';

        try {
            $response = static::getOrchestration()->execute(
                '60clotVWpufbEpy33zJLcoYHrUTqWaD1FV0FZWsw', // Non-Existent Container
                array(
                    'php',
                    'index.php'
                ),
                $stdout
            );
        } catch (Exception $e) {
            $testFailed = true;
        }

        $this->assertEquals(true, $testFailed);
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
            if ($value->name == 'TestContainer') {
                $foundContainer = true;
            }
        }, $response);

        $this->assertEquals(true, $foundContainer);
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

        /**
         * Test for Failure
         */
        $testFailed = false;

        try {
            $response = static::getOrchestration()->remove('TestContainer', true);
        } catch (Exception $e) {
            $testFailed = true;
        }

        $this->assertEquals(true, $testFailed);
    }

    public function testParseCLICommand(): void
    {
        /**
         * Test for success
         */
        $test = static::getOrchestration()->parseCommandString("sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'");

        $this->assertEquals(array(
            "sh",
            "-c",
            "'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'"
        ), $test);

        $test = static::getOrchestration()->parseCommandString("sudo apt-get update");

        $this->assertEquals(array(
            "sudo",
            "apt-get",
            "update"
        ), $test);

        $test = static::getOrchestration()->parseCommandString("test");

        $this->assertEquals(array(
            "test"
        ), $test);

        /**
         * Test for failure
         */
        $testFailed = false;

        try {
            $test = static::getOrchestration()->parseCommandString("sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null");
        } catch (Exception $e) {
            $testFailed = true;
        }

        $this->assertEquals(true, $testFailed);
    }
}