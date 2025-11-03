<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Orchestration\Adapter\K8sCLI;
use Utopia\Orchestration\Orchestration;

class K8sCLITest extends TestCase
{
    /**
     * @var Orchestration|null
     */
    private static ?Orchestration $orchestration = null;

    public static function setUpBeforeClass(): void
    {
        // Skip if kubectl cannot reach a cluster
        $rc = 0;
        $output = [];
        @\exec('kubectl cluster-info >/dev/null 2>&1', $output, $rc);
        if ($rc !== 0) {
            self::markTestSkipped('kubectl not configured or no Kubernetes cluster available. Skipping K8s CLI tests.');
        }

        self::$orchestration = new Orchestration(new K8sCLI());
    }

    public function setUp(): void
    {
        \exec('rm -rf /usr/src/code/tests/Orchestration/Resources/screens'); // cleanup
        \exec('sh -c "cd /usr/src/code/tests/Orchestration/Resources && tar -zcf ./php.tar.gz php"');
        \exec('sh -c "cd /usr/src/code/tests/Orchestration/Resources && tar -zcf ./timeout.tar.gz timeout"');
    }

    public function tearDown(): void
    {
        \exec('rm -rf /usr/src/code/tests/Orchestration/Resources/screens'); // cleanup
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$orchestration === null) {
            return;
        }

        // Clean up any remaining test pods
        try {
            self::$orchestration->remove('testcontainer', true);
        } catch (\Exception $e) {
            // Ignore
        }
        try {
            self::$orchestration->remove('testcontainertimeout', true);
        } catch (\Exception $e) {
            // Ignore
        }
        try {
            self::$orchestration->remove('usagestats1', true);
        } catch (\Exception $e) {
            // Ignore
        }
        try {
            self::$orchestration->remove('usagestats2', true);
        } catch (\Exception $e) {
            // Ignore
        }
    }

    private static function getOrchestration(): Orchestration
    {
        return self::$orchestration;
    }

    public function testPullImage(): void
    {
        // Test successful pull
        $response = self::getOrchestration()->pull('appwrite/runtime-for-php:8.0');
        $this->assertTrue($response);

        // Pull alpine for later tests
        $response = self::getOrchestration()->pull('alpine:latest');
        $this->assertTrue($response);

        // Test failure with non-existent image
        $response = self::getOrchestration()->pull('appwrite/tXDytMhecKCuz5B4PlITXL1yKhZXDP');
        $this->assertFalse($response);
    }

    /**
     * @depends testPullImage
     */
    public function testRunContainer(): void
    {
        $response = self::getOrchestration()->run(
            'alpine:latest',
            'TestContainer',
            ['sh', '-c', 'echo "Hello K8s" && sleep 300'],
            '',
            '/workspace',
            [],
            ['TEST_VAR' => 'test_value'],
            '',
            ['app' => 'test', 'env' => 'testing']
        );

        $this->assertNotEmpty($response);

        // Wait for pod to be fully ready (run() already waits for container ready)
        sleep(2);
    }

    /**
     * @depends testRunContainer
     */
    public function testListContainers(): void
    {
        $response = self::getOrchestration()->list();

        $this->assertIsArray($response);
        $this->assertNotEmpty($response);

        $foundContainer = false;
        foreach ($response as $container) {
            if ($container->getName() === 'testcontainer') {
                $foundContainer = true;
                break;
            }
        }

        $this->assertTrue($foundContainer, 'TestContainer not found in list');
    }

    /**
     * @depends testRunContainer
     */
    public function testListFilters(): void
    {
        $response = self::getOrchestration()->list(['app' => 'test']);
        $this->assertNotEmpty($response);

        $foundContainer = false;
        foreach ($response as $container) {
            if ($container->getName() === 'testcontainer') {
                $foundContainer = true;
                break;
            }
        }

        $this->assertTrue($foundContainer);
    }

    /**
     * @depends testRunContainer
     */
    public function testExecuteContainer(): void
    {
        $output = '';

        // Test successful execution
        $result = self::getOrchestration()->execute(
            'TestContainer',
            ['echo', '-n', 'Hello from exec'],
            $output
        );

        $this->assertTrue($result);
        $this->assertEquals('Hello from exec', $output);

        // Test with environment variables
        $output = '';
        $result = self::getOrchestration()->execute(
            'TestContainer',
            ['sh', '-c', 'echo -n $CUSTOM_VAR'],
            $output,
            ['CUSTOM_VAR' => 'custom_value']
        );

        $this->assertTrue($result);
        $this->assertEquals('custom_value', $output);

        // Test execution failure - non-existent pod
        $this->expectException(\Exception::class);
        $output = '';
        self::getOrchestration()->execute(
            'NonExistentPod',
            ['echo', 'test'],
            $output
        );
    }

    public function testCreateNetwork(): void
    {
        $response = self::getOrchestration()->createNetwork('TestNetwork');
        $this->assertTrue($response);

        // Test creating internal network
        $response = self::getOrchestration()->createNetwork('TestNetworkInternal', true);
        $this->assertTrue($response);
    }

    /**
     * @depends testCreateNetwork
     */
    public function testNetworkExists(): void
    {
        // Test existing network
        $this->assertTrue(self::getOrchestration()->networkExists('TestNetwork'));
        $this->assertTrue(self::getOrchestration()->networkExists('TestNetworkInternal'));

        // Test non-existent network
        $this->assertFalse(self::getOrchestration()->networkExists('NonExistentNetwork'));
    }

    /**
     * @depends testCreateNetwork
     */
    public function testListNetworks(): void
    {
        $response = self::getOrchestration()->listNetworks();

        $this->assertIsArray($response);

        $foundNetwork = false;
        $foundInternalNetwork = false;

        foreach ($response as $network) {
            if ($network->getName() === 'TestNetwork') {
                $foundNetwork = true;
            }
            if ($network->getName() === 'TestNetworkInternal') {
                $foundInternalNetwork = true;
            }
        }

        $this->assertTrue($foundNetwork, 'TestNetwork not found');
        $this->assertTrue($foundInternalNetwork, 'TestNetworkInternal not found');
    }

    /**
     * @depends testRunContainer
     * @depends testCreateNetwork
     */
    public function testNetworkConnect(): void
    {
        $response = self::getOrchestration()->networkConnect('testcontainer', 'TestNetwork');
        $this->assertTrue($response);
    }

    /**
     * @depends testNetworkConnect
     */
    public function testNetworkDisconnect(): void
    {
        $response = self::getOrchestration()->networkDisconnect('testcontainer', 'TestNetwork');
        $this->assertTrue($response);
    }

    /**
     * @depends testNetworkDisconnect
     */
    public function testNetworkDisconnectWrongNetwork(): void
    {
        $this->expectException(\Utopia\Orchestration\Exception\Orchestration::class);
        $this->expectExceptionMessage('is not connected to network');

        // Try to disconnect from a network the pod is not connected to
        self::getOrchestration()->networkDisconnect('testcontainer', 'NonExistentNetwork');
    }

    /**
     * @depends testNetworkDisconnect
     */
    public function testNetworkDisconnectWithForce(): void
    {
        // With force=true, should succeed even if not connected
        $response = self::getOrchestration()->networkDisconnect('testcontainer', 'NonExistentNetwork', true);
        $this->assertTrue($response);
    }

    /**
     * @depends testNetworkExists
     */
    public function testRemoveNetwork(): void
    {
        $response = self::getOrchestration()->removeNetwork('TestNetwork');
        $this->assertTrue($response);

        $response = self::getOrchestration()->removeNetwork('TestNetworkInternal');
        $this->assertTrue($response);

        // Verify networks are removed
        $this->assertFalse(self::getOrchestration()->networkExists('TestNetwork'));
        $this->assertFalse(self::getOrchestration()->networkExists('TestNetworkInternal'));
    }

    /**
     * @depends testPullImage
     */
    public function testGetStats(): void
    {
        // Create a pod for stats testing
        $podId = self::getOrchestration()->run(
            'alpine:latest',
            'UsageStats1',
            ['sh', '-c', 'sleep 300'],
            workdir: '/tmp',
            labels: ['stats-test' => 'true']
        );

        $this->assertNotEmpty($podId);

        // Wait for pod to be running
        sleep(3);

        // Test getting stats for all pods
        $stats = self::getOrchestration()->getStats();
        $this->assertIsArray($stats);

        // Test getting stats for specific pod by name
        $podStats = self::getOrchestration()->getStats('UsageStats1');
        $this->assertIsArray($podStats);

        // Test getting stats with filters
        $filteredStats = self::getOrchestration()->getStats(filters: ['stats-test' => 'true']);
        $this->assertIsArray($filteredStats);

        // Clean up
        self::getOrchestration()->remove('usagestats1', true);

        // Test stats for non-existent pod
        $emptyStats = self::getOrchestration()->getStats('NonExistentPod');
        $this->assertIsArray($emptyStats);
        $this->assertCount(0, $emptyStats);
    }

    /**
     * @depends testRunContainer
     */
    public function testRemoveContainer(): void
    {
        // Test successful removal
        $response = self::getOrchestration()->remove('TestContainer', true);
        $this->assertTrue($response);

        // Verify container is removed
        $containers = self::getOrchestration()->list();
        $foundContainer = false;
        foreach ($containers as $container) {
            if ($container->getName() === 'testcontainer') {
                $foundContainer = true;
                break;
            }
        }
        $this->assertFalse($foundContainer, 'TestContainer should be removed');

        // Test removing non-existent container
        $this->expectException(\Exception::class);
        self::getOrchestration()->remove('TestContainer', true);
    }

    /**
     * @depends testPullImage
     */
    public function testRunWithRemove(): void
    {
        $response = self::getOrchestration()->run(
            'alpine:latest',
            'TestContainerRM',
            ['sh', '-c', 'echo "Auto remove test" && exit 0'],
            '',
            '/tmp',
            [],
            [],
            '',
            ['test' => 'rm'],
            '',
            true // remove flag
        );

        $this->assertNotEmpty($response);

        // Wait longer for container to finish and be auto-removed
        sleep(5);

        // Trigger cleanup by calling list (which cleans up completed auto-remove pods)
        $containers = self::getOrchestration()->list(['test' => 'rm']);

        // After cleanup, should be empty
        $this->assertCount(0, $containers, 'Container with remove=true should be auto-removed');
    }

    public function testParseCLICommand(): void
    {
        // Test parsing simple command
        $result = self::getOrchestration()->parseCommandString('echo hello');
        $this->assertEquals(['echo', 'hello'], $result);

        // Test parsing command with quotes
        $result = self::getOrchestration()->parseCommandString("sh -c 'echo hello world'");
        $this->assertEquals(['sh', '-c', "'echo hello world'"], $result);

        // Test parsing complex command
        $result = self::getOrchestration()->parseCommandString("sh -c 'tar -zxf /tmp/file.tar.gz && echo done'");
        $this->assertEquals(['sh', '-c', "'tar -zxf /tmp/file.tar.gz && echo done'"], $result);
    }

    /**
     * @depends testPullImage
     */
    public function testTimeout(): void
    {
        // Create a timeout test container
        $podId = self::getOrchestration()->run(
            'alpine:latest',
            'TestContainerTimeout',
            ['sh', '-c', 'sleep 300'],
            workdir: '/tmp'
        );

        $this->assertNotEmpty($podId);

        // Wait for pod to be running
        sleep(3);

        // Test timeout failure
        $output = '';
        $threwException = false;
        try {
            self::getOrchestration()->execute(
                'TestContainerTimeout',
                ['sh', '-c', 'sleep 10'],
                $output,
                [],
                1 // 1 second timeout
            );
        } catch (\Exception $e) {
            $threwException = true;
        }
        $this->assertTrue($threwException, 'Should throw timeout exception');

        // Test successful execution within timeout
        $output = '';
        $result = self::getOrchestration()->execute(
            'TestContainerTimeout',
            ['echo', '-n', 'Quick response'],
            $output,
            [],
            10 // 10 second timeout
        );

        $this->assertTrue($result);
        $this->assertEquals('Quick response', $output);

        // Clean up
        self::getOrchestration()->remove('testcontainertimeout', true);
    }

    public function testNetworkWithSpecialCharacters(): void
    {
        // Test network name with underscores (should be sanitized)
        $networkName = 'test_network_' . uniqid();

        $response = self::getOrchestration()->createNetwork($networkName);
        $this->assertTrue($response);

        // Verify network exists (should match sanitized name)
        $this->assertTrue(self::getOrchestration()->networkExists($networkName));

        // Clean up
        $response = self::getOrchestration()->removeNetwork($networkName);
        $this->assertTrue($response);
    }

    public function testPodWithSanitizedNames(): void
    {
        // Test pod name that needs sanitization
        $response = self::getOrchestration()->run(
            'alpine:latest',
            'Test_Container_With_Underscores',
            ['sh', '-c', 'sleep 5'],
            labels: ['test-label' => 'Hello World!']
        );

        $this->assertNotEmpty($response);

        sleep(2);

        // Verify pod exists with sanitized name
        $containers = self::getOrchestration()->list();
        $foundContainer = false;
        foreach ($containers as $container) {
            // Name should be sanitized to lowercase with hyphens
            if (str_contains(strtolower($container->getName()), 'test') &&
                str_contains(strtolower($container->getName()), 'container')) {
                $foundContainer = true;
                break;
            }
        }
        $this->assertTrue($foundContainer);

        // Clean up - use sanitized name
        try {
            self::getOrchestration()->remove('test-container-with-underscores', true);
        } catch (\Exception $e) {
            // Ignore if already removed
        }
    }
}
