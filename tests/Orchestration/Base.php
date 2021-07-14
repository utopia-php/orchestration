<?php

namespace Utopia\Tests;

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
        $response = static::getOrchestration()->pull('appwrite/runtime-for-php:8.0');
        
        $this->assertEquals(true, $response);
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
    }

    /**
     * @return void
     * @depends testCreateContainer
     */
    public function testExecContainer(): void
    {
        $response = static::getOrchestration()->executeWithStdout(
            'TestContainer',
            array(
                'php',
                'index.php'
            )
        );

        $this->assertEquals("Hello World!", $response);
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
        $response = static::getOrchestration()->remove('TestContainer', true);

        $this->assertEquals(true, $response);
    }
}