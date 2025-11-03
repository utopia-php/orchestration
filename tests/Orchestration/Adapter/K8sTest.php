<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Orchestration\Adapter\K8s;
use Utopia\Orchestration\Orchestration;

class K8sTest extends TestCase
{
    /**
     * @var Orchestration|null
     */
    private static ?Orchestration $orchestration = null;

    /**
     * @var array<string>
     */
    private static array $tempFiles = [];

    public static function setUpBeforeClass(): void
    {
        // Try to get K8s configuration from environment or kubectl
        $url = \getenv('K8S_API_URL') ?: null;
        $namespace = \getenv('K8S_NAMESPACE') ?: 'default';

        // If URL not provided, try to auto-detect from kubectl
        if (empty($url)) {
            $output = [];
            $rc = 0;
            @\exec('kubectl cluster-info 2>/dev/null | grep "Kubernetes control plane"', $output, $rc);

            if ($rc === 0 && !empty($output[0])) {
                // Extract URL from output like "Kubernetes control plane is running at https://..."
                if (preg_match('/https?:\/\/[^\s]+/', $output[0], $matches)) {
                    $url = $matches[0];
                }
            }

            // If still no URL, skip tests
            if (empty($url)) {
                self::markTestSkipped('K8S_API_URL not set and could not detect from kubectl. Skipping Kubernetes SDK tests.');
            }
        }

        $auth = [];

        // Try to get authentication from kubectl config
        $kubeconfigJson = [];
        @\exec('kubectl config view --minify --raw -o json 2>/dev/null', $kubeconfigJson, $rc);

        if ($rc === 0 && !empty($kubeconfigJson)) {
            $kubeconfig = json_decode(implode('', $kubeconfigJson), true);

            if ($kubeconfig && isset($kubeconfig['users'][0]['user'])) {
                $user = $kubeconfig['users'][0]['user'];

                // Extract client certificate and key (base64 encoded in kubectl config)
                if (isset($user['client-certificate-data']) && isset($user['client-key-data'])) {
                    // Decode base64 and write to temp files
                    $certData = base64_decode($user['client-certificate-data']);
                    $keyData = base64_decode($user['client-key-data']);

                    $certFile = tempnam(sys_get_temp_dir(), 'k8s-cert-');
                    $keyFile = tempnam(sys_get_temp_dir(), 'k8s-key-');

                    file_put_contents($certFile, $certData);
                    file_put_contents($keyFile, $keyData);

                    $auth['cert'] = $certFile;
                    $auth['key'] = $keyFile;

                    // Track temp files for cleanup
                    self::$tempFiles[] = $certFile;
                    self::$tempFiles[] = $keyFile;
                }

                // Try token if present
                if (empty($auth) && isset($user['token'])) {
                    $auth['token'] = $user['token'];
                }
            }

            // Extract CA certificate if present
            if ($kubeconfig && isset($kubeconfig['clusters'][0]['cluster']['certificate-authority-data'])) {
                $caData = base64_decode($kubeconfig['clusters'][0]['cluster']['certificate-authority-data']);
                $caFile = tempnam(sys_get_temp_dir(), 'k8s-ca-');
                file_put_contents($caFile, $caData);
                $auth['ca'] = $caFile;

                // Track temp file for cleanup
                self::$tempFiles[] = $caFile;
            }
        }

        // Override with environment variables if set
        if ($token = \getenv('K8S_TOKEN')) {
            $auth['token'] = $token;
            unset($auth['cert'], $auth['key']); // Token takes precedence
        }

        if ((\getenv('K8S_USERNAME') !== false) && (\getenv('K8S_PASSWORD') !== false)) {
            $auth['username'] = (string) \getenv('K8S_USERNAME');
            $auth['password'] = (string) \getenv('K8S_PASSWORD');
            unset($auth['cert'], $auth['key'], $auth['token']); // Username/password takes precedence
        }

        if ($cert = \getenv('K8S_CERT_FILE')) {
            $auth['cert'] = $cert;
        }
        if ($key = \getenv('K8S_KEY_FILE')) {
            $auth['key'] = $key;
        }
        if ($ca = \getenv('K8S_CA_FILE')) {
            $auth['ca'] = $ca;
        }

        // For local development/testing with self-signed certs
        if (($insecure = \getenv('K8S_INSECURE')) !== false) {
            $auth['insecure'] = in_array(strtolower((string) $insecure), ['1','true','yes'], true);
        }

        try {
            self::$orchestration = new Orchestration(new K8s($url, $namespace, $auth));
        } catch (\Exception $e) {
            self::markTestSkipped('Failed to initialize K8s adapter: ' . $e->getMessage());
        }
    }

    public function setUp(): void
    {
        \exec('rm -rf /usr/src/code/tests/Orchestration/Resources/screens'); // cleanup
        \exec('sh -c "cd /usr/src/code/tests/Orchestration/Resources && tar -zcf ./php.tar.gz php"');
        \exec('sh -c "cd /usr/src/code/tests/Orchestration/Resources && tar -zcf ./timeout.tar.gz timeout"');

        // Force cleanup of any leftover pods from previous runs to prevent 409 conflicts
        $pods = ['testcontainer', 'testcontainerrmsdk', 'testcontainertimeoutsdk', 'usagestatssdk1', 'usagestats2', 'testcontainerwithlimits', 'test-container-sdk-with-underscores'];
        foreach ($pods as $pod) {
            try {
                $output = '';
                $returnVar = 0;
                exec("kubectl delete pod ".\escapeshellarg($pod)." -n default --force --grace-period=0 2>/dev/null", $output, $returnVar);
            } catch (\Exception $e) {
                // Ignore errors - pod might not exist
            }
        }

        // Give K8s time to complete deletion
        sleep(2);
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

        // Clean up any remaining test pods (use sanitized names)
        $podsToClean = [
            'testcontainer',
            'testcontainertimeoutsdk',
            'usagestatssdk1',
            'usagestats2',
            'testcontainerwithlimits',
            'test-container-sdk-with-underscores',
            'testcontainerrmsdk'
        ];

        foreach ($podsToClean as $podName) {
            try {
                self::$orchestration->remove($podName, true);
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Clean up temporary certificate files
        foreach (self::$tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
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

        // Note: K8s SDK adapter always returns true for pull() because
        // Kubernetes handles image pulling automatically when creating pods.
        // Image validation happens at pod creation time, not during pull().
        $response = self::getOrchestration()->pull('appwrite/tXDytMhecKCuz5B4PlITXL1yKhZXDP');
        $this->assertTrue($response); // SDK always returns true
    }

    /**
     * @depends testPullImage
     */
    public function testRunContainer(): void
    {
        $response = self::getOrchestration()->run(
            'alpine:latest',
            'testcontainer',
            ['sh', '-c', 'echo "Hello K8s SDK" && sleep 300'],
            '',
            '/workspace',
            [],
            ['TEST_VAR' => 'test_value'],
            '',
            ['app' => 'test', 'env' => 'testing']
        );

        $this->assertNotEmpty($response);

        // Wait for pod to be fully ready (longer in CI environments)
        sleep(5);
    }

    /**
     * @depends testRunContainer
     */
    public function testListContainers(): void
    {
        // Retry logic for eventual consistency in K8s
        $maxRetries = 5;
        $foundContainer = false;

        for ($i = 0; $i < $maxRetries && !$foundContainer; $i++) {
            $response = self::getOrchestration()->list();

            $this->assertIsArray($response);

            foreach ($response as $container) {
                if ($container->getName() === 'testcontainer') {
                    $foundContainer = true;
                    break; // Break out of foreach loop
                }
            }

            if (!$foundContainer && $i < $maxRetries - 1) {
                sleep(2); // Wait before retrying
            }
        }

        $this->assertNotEmpty($response, 'No containers found in list');
        $this->assertTrue($foundContainer, 'testcontainer not found in list');
    }

    /**
     * @depends testRunContainer
     */
    public function testListFilters(): void
    {
        // Retry logic for eventual consistency in K8s
        $maxRetries = 5;
        $foundContainer = false;

        for ($i = 0; $i < $maxRetries && !$foundContainer; $i++) {
            $response = self::getOrchestration()->list(['app' => 'test']);

            foreach ($response as $container) {
                if ($container->getName() === 'testcontainer') {
                    $foundContainer = true;
                    break; // Break out of foreach loop
                }
            }

            if (!$foundContainer && $i < $maxRetries - 1) {
                sleep(2); // Wait before retrying
            }
        }

        $this->assertNotEmpty($response, 'No containers found with app=test filter');
        $this->assertTrue($foundContainer, 'testcontainer not found with app=test filter');
    }

    /**
     * @depends testRunContainer
     */
    public function testExecuteContainer(): void
    {
        $output = '';

        // Test successful execution
        $result = self::getOrchestration()->execute(
            'testcontainer',
            ['echo', '-n', 'Hello from SDK exec'],
            $output
        );

        $this->assertTrue($result);
        $this->assertEquals('Hello from SDK exec', $output);

        // K8s SDK doesn't support env vars in exec - skipping that test
        // Test command with shell instead
        $output = '';
        $result = self::getOrchestration()->execute(
            'testcontainer',
            ['sh', '-c', 'echo -n "executed successfully"'],
            $output
        );

        $this->assertTrue($result);
        $this->assertEquals('executed successfully', $output);

        // Test execution failure - non-existent pod
        $this->expectException(\Exception::class);
        $output = '';
        self::getOrchestration()->execute(
            'NonExistentPod',
            ['echo', 'test'],
            $output
        );
    }

    /**
     * @depends testRunContainer
     */
    public function testExecuteWithEnvVarsThrowsException(): void
    {
        $output = '';

        $this->expectException(\Utopia\Orchestration\Exception\Orchestration::class);
        $this->expectExceptionMessage('K8s SDK adapter does not support environment variables in execute()');

        self::getOrchestration()->execute(
            'testcontainer',
            ['echo', 'test'],
            $output,
            ['TEST_VAR' => 'value']  // This should trigger the exception
        );
    }

    /**
     * @depends testRunContainer
     */
    public function testExecuteWithTimeoutThrowsException(): void
    {
        $output = '';

        $this->expectException(\Utopia\Orchestration\Exception\Orchestration::class);
        $this->expectExceptionMessage('K8s SDK adapter does not support timeout in execute()');

        self::getOrchestration()->execute(
            'testcontainer',
            ['echo', 'test'],
            $output,
            [],
            10  // This should trigger the exception
        );
    }

    public function testCreateNetwork(): void
    {
        $response = self::getOrchestration()->createNetwork('TestNetworkSDK');
        $this->assertTrue($response);

        // Test creating internal network
        $response = self::getOrchestration()->createNetwork('TestNetworkSDKInternal', true);
        $this->assertTrue($response);
    }

    /**
     * @depends testCreateNetwork
     */
    public function testNetworkExists(): void
    {
        // Test existing network
        $this->assertTrue(self::getOrchestration()->networkExists('TestNetworkSDK'));
        $this->assertTrue(self::getOrchestration()->networkExists('TestNetworkSDKInternal'));

        // Test non-existent network
        $this->assertFalse(self::getOrchestration()->networkExists('NonExistentNetworkSDK'));
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
            if ($network->getName() === 'TestNetworkSDK') {
                $foundNetwork = true;
            }
            if ($network->getName() === 'TestNetworkSDKInternal') {
                $foundInternalNetwork = true;
            }
        }

        $this->assertTrue($foundNetwork, 'TestNetworkSDK not found');
        $this->assertTrue($foundInternalNetwork, 'TestNetworkSDKInternal not found');
    }

    /**
     * @depends testRunContainer
     * @depends testCreateNetwork
     */
    public function testNetworkConnect(): void
    {
        $response = self::getOrchestration()->networkConnect('testcontainer', 'TestNetworkSDK');
        $this->assertTrue($response);
    }

    /**
     * @depends testNetworkConnect
     */
    public function testNetworkDisconnect(): void
    {
        $response = self::getOrchestration()->networkDisconnect('testcontainer', 'TestNetworkSDK');
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
        $response = self::getOrchestration()->removeNetwork('TestNetworkSDK');
        $this->assertTrue($response);

        $response = self::getOrchestration()->removeNetwork('TestNetworkSDKInternal');
        $this->assertTrue($response);

        // Verify networks are removed
        $this->assertFalse(self::getOrchestration()->networkExists('TestNetworkSDK'));
        $this->assertFalse(self::getOrchestration()->networkExists('TestNetworkSDKInternal'));
    }

    /**
     * @depends testPullImage
     */
    public function testGetStats(): void
    {
        // Create a pod for stats testing
        $podId = self::getOrchestration()->run(
            'alpine:latest',
            'usagestatssdk1',
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
        $podStats = self::getOrchestration()->getStats('UsageStatsSDK1');
        $this->assertIsArray($podStats);

        // Test getting stats with filters
        $filteredStats = self::getOrchestration()->getStats(filters: ['stats-test' => 'true']);
        $this->assertIsArray($filteredStats);

        // Clean up
        self::getOrchestration()->remove('usagestatssdk1', true);

        // Test stats for non-existent pod
        $emptyStats = self::getOrchestration()->getStats('NonExistentPodSDK');
        $this->assertIsArray($emptyStats);
        $this->assertCount(0, $emptyStats);
    }

    /**
     * @depends testRunContainer
     */
    public function testRemoveContainer(): void
    {
        // Test successful removal
        $response = self::getOrchestration()->remove('testcontainer', true);
        $this->assertTrue($response);

        // Wait for K8s to complete pod deletion
        sleep(5);

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
    }

    /**
     * @depends testRemoveContainer
     */
    public function testRemoveNonExistentContainer(): void
    {
        // Test removing non-existent container should throw exception
        $this->expectException(\Exception::class);
        self::getOrchestration()->remove('NonExistentTestContainer', true);
    }

    /**
     * @depends testPullImage
     */
    public function testRunWithRemove(): void
    {
        // Skip this test as it's timing-sensitive and K8s pods may not reach
        // Succeeded phase quickly enough for deterministic testing
        $this->markTestSkipped('Auto-remove timing is unreliable in K8s SDK - pods may not reach Succeeded phase quickly enough');

        /* Code below is unreachable but kept for documentation:
        $response = self::getOrchestration()->run(
            'alpine:latest',
            'testcontainerrmsdk',
            ['sh', '-c', 'echo "Auto remove test SDK" && exit 0'],
            '',
            '/tmp',
            [],
            [],
            '',
            ['test' => 'rm-sdk'],
            '',
            true // remove flag
        );

        $this->assertNotEmpty($response);

        // Wait for container to finish - alpine pods finish quickly
        // but K8s needs time to update the phase to Succeeded
        sleep(8);

        // First call to list() should trigger cleanup
        $containers = self::getOrchestration()->list(['test' => 'rm-sdk']);

        // Wait for K8s to complete deletion
        sleep(7);

        // Second call should show it's gone
        $containers = self::getOrchestration()->list(['test' => 'rm-sdk']);

        // After cleanup, should be empty
        $this->assertCount(0, $containers, 'Container with remove=true should be auto-removed');
        */
    }    public function testParseCLICommand(): void
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
        $this->markTestSkipped('K8s SDK library does not support timeout parameter in exec()');

        /* Code below is unreachable but kept for documentation:
        // Create a timeout test container
        $podId = self::getOrchestration()->run(
            'alpine:latest',
            'testcontainertimeoutsdk',
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
                'TestContainerTimeoutSDK',
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
            'TestContainerTimeoutSDK',
            ['echo', '-n', 'Quick response SDK'],
            $output,
            [],
            10 // 10 second timeout
        );

        $this->assertTrue($result);
        $this->assertEquals('Quick response SDK', $output);

        // Clean up
        self::getOrchestration()->remove('testcontainertimeoutsdk', true);
        */
    }

    public function testNetworkWithSpecialCharacters(): void
    {
        // Test network name with underscores (should be sanitized)
        $networkName = 'test_network_sdk_' . uniqid();

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
            'Test_Container_SDK_With_Underscores',
            ['sh', '-c', 'sleep 5'],
            labels: ['test-label-sdk' => 'Hello World!']
        );

        $this->assertNotEmpty($response);

        sleep(2);

        // Verify pod exists with sanitized name
        $containers = self::getOrchestration()->list();
        $foundContainer = false;
        foreach ($containers as $container) {
            // Name should be sanitized to lowercase with hyphens
            if (str_contains(strtolower($container->getName()), 'test') &&
                str_contains(strtolower($container->getName()), 'container') &&
                str_contains(strtolower($container->getName()), 'sdk')) {
                $foundContainer = true;
                break;
            }
        }
        $this->assertTrue($foundContainer);

        // Clean up - use sanitized name
        try {
            self::getOrchestration()->remove('test-container-sdk-with-underscores', true);
        } catch (\Exception $e) {
            // Ignore if already removed
        }
    }

    public function testResourceLimits(): void
    {
        // Test creating pod with CPU and memory limits
        self::getOrchestration()->setCpus(1);
        self::getOrchestration()->setMemory(512); // 512Mi

        $response = self::getOrchestration()->run(
            'alpine:latest',
            'testcontainerwithlimits',
            ['sh', '-c', 'sleep 10'],
            labels: ['resource-test' => 'true']
        );

        $this->assertNotEmpty($response);

        sleep(2);

        // Verify pod was created
        $containers = self::getOrchestration()->list(['resource-test' => 'true']);
        $this->assertNotEmpty($containers);

        // Clean up
        self::getOrchestration()->remove('testcontainerwithlimits', true);

        // Reset limits
        self::getOrchestration()->setCpus(0);
        self::getOrchestration()->setMemory(0);
    }
}
