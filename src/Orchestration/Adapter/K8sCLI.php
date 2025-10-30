<?php

namespace Utopia\Orchestration\Adapter;

use Utopia\Console;
use Utopia\Orchestration\Adapter;
use Utopia\Orchestration\Container;
use Utopia\Orchestration\Container\Stats;
use Utopia\Orchestration\Exception\Orchestration;
use Utopia\Orchestration\Exception\Timeout;
use Utopia\Orchestration\Network;

class K8sCLI extends Adapter
{
    /**
     * @var string
     */
    private string $kubeconfig;

    /**
     * @var string
     */
    private string $k8sNamespace;

    /**
     * Constructor
     */
    public function __construct(?string $kubeconfig = null, ?string $namespace = 'default')
    {
        $this->kubeconfig = $kubeconfig ?? '';
        $this->k8sNamespace = $namespace;
    }

    /**
     * Build kubectl command prefix with optional kubeconfig
     */
    private function buildKubectlCmd(): string
    {
        $cmd = 'kubectl';

        if (! empty($this->kubeconfig)) {
            $cmd .= ' --kubeconfig='.$this->kubeconfig;
        }

        $cmd .= ' --namespace='.$this->k8sNamespace;

        return $cmd;
    }

    /**
     * Create Network (K8s NetworkPolicy)
     */
    public function createNetwork(string $name, bool $internal = false): bool
    {
        $output = '';

        // In Kubernetes, networks are typically managed via NetworkPolicies
        // For simplicity, we'll create a basic NetworkPolicy
        $yaml = <<<YAML
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: {$name}
  namespace: {$this->k8sNamespace}
spec:
  podSelector:
    matchLabels:
      network: {$name}
  policyTypes:
YAML;

        if ($internal) {
            $yaml .= <<<YAML

  - Ingress
  - Egress
  ingress: []
  egress: []
YAML;
        } else {
            $yaml .= <<<YAML

  - Ingress
  - Egress
  ingress:
  - {}
  egress:
  - {}
YAML;
        }

        $tmpFile = \tempnam(\sys_get_temp_dir(), 'k8s-network-');
        \file_put_contents($tmpFile, $yaml);

        $result = Console::execute($this->buildKubectlCmd().' apply -f '.$tmpFile, '', $output);

        \unlink($tmpFile);

        return $result === 0;
    }

    /**
     * Remove Network (K8s NetworkPolicy)
     */
    public function removeNetwork(string $name): bool
    {
        $output = '';

        $result = Console::execute($this->buildKubectlCmd().' delete networkpolicy '.$name, '', $output);

        return $result === 0;
    }

    /**
     * Connect a container to a network (Add label to pod)
     */
    public function networkConnect(string $container, string $network): bool
    {
        $output = '';

        // In K8s, we add a network label to the pod
        $result = Console::execute($this->buildKubectlCmd().' label pod '.$container.' network='.$network.' --overwrite', '', $output);

        return $result === 0;
    }

    /**
     * Disconnect a container from a network (Remove label from pod)
     */
    public function networkDisconnect(string $container, string $network, bool $force = false): bool
    {
        $output = '';

        // Remove the network label from the pod
        $result = Console::execute($this->buildKubectlCmd().' label pod '.$container.' network-', '', $output);

        return $result === 0;
    }

    /**
     * Check if a network exists
     */
    public function networkExists(string $name): bool
    {
        $output = '';

        $result = Console::execute($this->buildKubectlCmd().' get networkpolicy '.$name.' -o name', '', $output);

        return $result === 0 && str_contains($output, $name);
    }

    /**
     * List Networks (K8s NetworkPolicies)
     *
     * @return Network[]
     */
    public function listNetworks(): array
    {
        $output = '';

        $result = Console::execute($this->buildKubectlCmd().' get networkpolicies -o json', '', $output);

        if ($result !== 0) {
            throw new Orchestration("K8s Error: {$output}");
        }

        $list = [];
        $data = \json_decode($output, true);

        if (isset($data['items']) && \is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                if (isset($item['metadata']['name'])) {
                    $network = new Network(
                        $item['metadata']['name'],
                        $item['metadata']['uid'] ?? '',
                        'NetworkPolicy',
                        $item['metadata']['namespace'] ?? 'default'
                    );
                    $list[] = $network;
                }
            }
        }

        return $list;
    }

    /**
     * Get usage stats of containers
     *
     * @param  array<string, string>  $filters
     * @return array<Stats>
     */
    public function getStats(?string $container = null, array $filters = []): array
    {
        $output = '';
        $stats = [];

        // Get pod metrics using kubectl top
        if ($container !== null) {
            $result = Console::execute($this->buildKubectlCmd().' top pod '.$container.' --no-headers', '', $output);
        } else {
            $selector = '';
            if (! empty($filters)) {
                $labelFilters = [];
                foreach ($filters as $key => $value) {
                    $labelFilters[] = $key.'='.$value;
                }
                $selector = ' -l '.implode(',', $labelFilters);
            }
            $result = Console::execute($this->buildKubectlCmd().' top pod'.$selector.' --no-headers', '', $output);
        }

        if ($result !== 0) {
            return [];
        }

        $lines = \explode("\n", \trim($output));

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            // Parse kubectl top output: NAME CPU(cores) MEMORY(bytes)
            $parts = \preg_split('/\s+/', $line);

            if (\count($parts) < 3) {
                continue;
            }

            $podName = $parts[0];
            $cpuStr = $parts[1];
            $memoryStr = $parts[2];

            // Parse CPU (e.g., "100m" = 0.1 cores, "1" = 1 core)
            $cpuUsage = $this->parseCpuValue($cpuStr);

            // Parse Memory (e.g., "100Mi", "1Gi")
            $memoryBytes = $this->parseMemoryValue($memoryStr);

            // Get pod details for total memory limit
            $podOutput = '';
            Console::execute($this->buildKubectlCmd().' get pod '.$podName.' -o json', '', $podOutput);

            $memoryUsagePercent = 0.0;
            $podData = \json_decode($podOutput, true);

            if ($podData && isset($podData['spec']['containers'][0]['resources']['limits']['memory'])) {
                $memoryLimit = $this->parseMemoryValue($podData['spec']['containers'][0]['resources']['limits']['memory']);
                if ($memoryLimit > 0) {
                    $memoryUsagePercent = ($memoryBytes / $memoryLimit) * 100.0;
                }
            }

            // Get container ID from pod
            $containerId = $podData['metadata']['uid'] ?? $podName;

            $stats[] = new Stats(
                containerId: $containerId,
                containerName: $podName,
                cpuUsage: $cpuUsage,
                memoryUsage: $memoryUsagePercent,
                diskIO: ['in' => 0, 'out' => 0], // K8s metrics-server doesn't provide disk I/O by default
                memoryIO: ['in' => $memoryBytes, 'out' => 0],
                networkIO: ['in' => 0, 'out' => 0] // K8s metrics-server doesn't provide network I/O by default
            );
        }

        return $stats;
    }

    /**
     * Parse CPU value from K8s format (e.g., "100m", "1", "0.5")
     */
    private function parseCpuValue(string $cpu): float
    {
        if (\str_ends_with($cpu, 'm')) {
            return \floatval(\rtrim($cpu, 'm')) / 1000.0;
        }

        return \floatval($cpu);
    }

    /**
     * Parse memory value from K8s format (e.g., "100Mi", "1Gi", "512Ki")
     */
    private function parseMemoryValue(string $memory): float
    {
        $units = [
            'Ki' => 1024,
            'Mi' => 1048576,
            'Gi' => 1073741824,
            'Ti' => 1099511627776,
            'K' => 1000,
            'M' => 1000000,
            'G' => 1000000000,
            'T' => 1000000000000,
        ];

        foreach ($units as $unit => $multiplier) {
            if (\str_ends_with($memory, $unit)) {
                return \floatval(\rtrim($memory, $unit)) * $multiplier;
            }
        }

        return \floatval($memory);
    }

    /**
     * Pull Image
     * Note: In K8s, images are pulled automatically when pods are created.
     * This method attempts to validate the image by creating a temporary pod.
     */
    public function pull(string $image): bool
    {
        // Try to validate the image by creating a temporary pod with imagePullPolicy: IfNotPresent
        // and then deleting it. If the image doesn't exist or is invalid, this will fail.
        $tempPodName = 'pull-test-'.uniqid();
        $output = '';

        $yaml = <<<YAML
apiVersion: v1
kind: Pod
metadata:
  name: {$tempPodName}
  namespace: {$this->k8sNamespace}
spec:
  containers:
  - name: validator
    image: {$image}
    command: ['sh', '-c', 'exit 0']
  restartPolicy: Never
YAML;

        try {
            // Create the pod
            $exitCode = Console::execute(
                $this->buildKubectlCmd().' apply -f -',
                $yaml,
                $output,
                '',
                30
            );

            if ($exitCode !== 0) {
                return false;
            }

            // Wait a bit for the pod to be scheduled and image pull to start
            sleep(2);

            // Check pod status for image pull errors
            $statusOutput = '';
            Console::execute(
                $this->buildKubectlCmd().' get pod '.$tempPodName.' -o json',
                '',
                $statusOutput
            );

            $podData = \json_decode($statusOutput, true);
            $containerStatuses = $podData['status']['containerStatuses'] ?? [];

            // Check for image pull errors
            foreach ($containerStatuses as $status) {
                if (isset($status['state']['waiting']['reason'])) {
                    $reason = $status['state']['waiting']['reason'];
                    if (in_array($reason, ['ErrImagePull', 'ImagePullBackOff', 'InvalidImageName'])) {
                        // Clean up
                        Console::execute(
                            $this->buildKubectlCmd().' delete pod '.$tempPodName.' --grace-period=0 --force',
                            '',
                            $output
                        );
                        return false;
                    }
                }
            }

            // Clean up the test pod
            Console::execute(
                $this->buildKubectlCmd().' delete pod '.$tempPodName.' --grace-period=0 --force',
                '',
                $output
            );

            return true;
        } catch (\Exception $e) {
            // Clean up on error
            Console::execute(
                $this->buildKubectlCmd().' delete pod '.$tempPodName.' --ignore-not-found=true --grace-period=0 --force',
                '',
                $output
            );
            return false;
        }
    }

    /**
     * List Containers (K8s Pods)
     *
     * @param  array<string, string>  $filters
     * @return Container[]
     */
    public function list(array $filters = []): array
    {
        $output = '';

        $selector = '';
        if (! empty($filters)) {
            $labelFilters = [];
            foreach ($filters as $key => $value) {
                $labelFilters[] = $key.'='.$value;
            }
            $selector = ' -l '.implode(',', $labelFilters);
        }

        $result = Console::execute($this->buildKubectlCmd().' get pods'.$selector.' -o json', '', $output);

        if ($result !== 0) {
            throw new Orchestration("K8s Error: {$output}");
        }

        $list = [];
        $data = \json_decode($output, true);

        if (isset($data['items']) && \is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                if (isset($item['metadata']['name'])) {
                    $container = new Container(
                        $item['metadata']['name'],
                        $item['metadata']['uid'] ?? '',
                        $item['status']['phase'] ?? 'Unknown',
                        $item['metadata']['labels'] ?? []
                    );
                    $list[] = $container;
                }
            }
        }

        return $list;
    }

    /**
     * Run Container (Create and run a K8s Pod)
     *
     * @param  string[]  $command
     * @param  string[]  $volumes
     * @param  array<string, string>  $vars
     * @param  array<string, string>  $labels
     */
    public function run(
        string $image,
        string $name,
        array $command = [],
        string $entrypoint = '',
        string $workdir = '',
        array $volumes = [],
        array $vars = [],
        string $mountFolder = '',
        array $labels = [],
        string $hostname = '',
        bool $remove = false,
        string $network = '',
        string $restart = self::RESTART_NO
    ): string {
        $output = '';

        // Add default labels
        $labels[$this->namespace.'-type'] = 'runtime';
        $labels[$this->namespace.'-created'] = (string) time();

        if (! empty($network)) {
            $labels['network'] = $network;
        }

        // Build pod specification
        $pod = [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => [
                'name' => $name,
                'namespace' => $this->k8sNamespace,
                'labels' => $labels,
            ],
            'spec' => [
                'containers' => [
                    [
                        'name' => 'main',
                        'image' => $image,
                    ],
                ],
            ],
        ];

        // Add hostname if specified
        if (! empty($hostname)) {
            $pod['spec']['hostname'] = $hostname;
        }

        // Set restart policy
        $restartPolicies = [
            self::RESTART_NO => 'Never',
            self::RESTART_ALWAYS => 'Always',
            self::RESTART_ON_FAILURE => 'OnFailure',
            self::RESTART_UNLESS_STOPPED => 'Always',
        ];
        $pod['spec']['restartPolicy'] = $restartPolicies[$restart] ?? 'Never';

        // Add command
        if (! empty($command)) {
            $pod['spec']['containers'][0]['args'] = $command;
        }

        // Add entrypoint
        if (! empty($entrypoint)) {
            $pod['spec']['containers'][0]['command'] = [$entrypoint];
        }

        // Add working directory
        if (! empty($workdir)) {
            $pod['spec']['containers'][0]['workingDir'] = $workdir;
        }

        // Add environment variables
        if (! empty($vars)) {
            $env = [];
            foreach ($vars as $key => $value) {
                $key = $this->filterEnvKey($key);
                $env[] = ['name' => $key, 'value' => $value];
            }
            $pod['spec']['containers'][0]['env'] = $env;
        }

        // Add resource limits
        if ($this->cpus > 0 || $this->memory > 0) {
            $resources = ['limits' => [], 'requests' => []];

            if ($this->cpus > 0) {
                $resources['limits']['cpu'] = (string) $this->cpus;
                $resources['requests']['cpu'] = (string) ($this->cpus / 2);
            }

            if ($this->memory > 0) {
                $resources['limits']['memory'] = $this->memory.'Mi';
                $resources['requests']['memory'] = ($this->memory / 2).'Mi';
            }

            $pod['spec']['containers'][0]['resources'] = $resources;
        }

        // Add volumes
        if (! empty($volumes) || ! empty($mountFolder)) {
            $volumeMounts = [];
            $volumeList = [];

            $volumeIndex = 0;
            foreach ($volumes as $volume) {
                // Parse volume format: /host/path:/container/path or /host/path:/container/path:ro
                $parts = \explode(':', $volume);
                if (\count($parts) >= 2) {
                    $volumeName = 'vol-'.$volumeIndex;
                    $volumeMounts[] = [
                        'name' => $volumeName,
                        'mountPath' => $parts[1],
                        'readOnly' => isset($parts[2]) && $parts[2] === 'ro',
                    ];
                    $volumeList[] = [
                        'name' => $volumeName,
                        'hostPath' => ['path' => $parts[0]],
                    ];
                    $volumeIndex++;
                }
            }

            if (! empty($mountFolder)) {
                $volumeMounts[] = [
                    'name' => 'mount-folder',
                    'mountPath' => '/tmp',
                ];
                $volumeList[] = [
                    'name' => 'mount-folder',
                    'hostPath' => ['path' => $mountFolder],
                ];
            }

            if (! empty($volumeMounts)) {
                $pod['spec']['containers'][0]['volumeMounts'] = $volumeMounts;
                $pod['spec']['volumes'] = $volumeList;
            }
        }

        // Convert to YAML and create pod
        $yaml = \json_encode($pod);
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'k8s-pod-');
        \file_put_contents($tmpFile, $yaml);

        $result = Console::execute($this->buildKubectlCmd().' apply -f '.$tmpFile, '', $output, 30);

        \unlink($tmpFile);

        if ($result !== 0) {
            throw new Orchestration("K8s Error: {$output}");
        }

        // Wait for pod to be created and get its UID
        \sleep(1);
        $podOutput = '';
        Console::execute($this->buildKubectlCmd().' get pod '.$name.' -o json', '', $podOutput);
        $podData = \json_decode($podOutput, true);

        return $podData['metadata']['uid'] ?? $name;
    }

    /**
     * Execute Container (Execute command in K8s Pod)
     *
     * @param  string[]  $command
     * @param  array<string, string>  $vars
     */
    public function execute(
        string $name,
        array $command,
        string &$output,
        array $vars = [],
        int $timeout = -1
    ): bool {
        $envArgs = '';

        if (! empty($vars)) {
            $envVars = [];
            foreach ($vars as $key => $value) {
                $key = $this->filterEnvKey($key);
                $envVars[] = $key.'='.\escapeshellarg($value);
            }
            $envArgs = ' -- env '.implode(' ', $envVars);
        }

        $cmdStr = '';
        foreach ($command as $cmd) {
            if (str_contains($cmd, ' ')) {
                $cmdStr .= " '".$cmd."'";
            } else {
                $cmdStr .= ' '.$cmd;
            }
        }

        $execCmd = $this->buildKubectlCmd().' exec '.$name.' -- ';

        if (! empty($vars)) {
            $execCmd .= 'env ';
            foreach ($vars as $key => $value) {
                $key = $this->filterEnvKey($key);
                $execCmd .= $key.'='.\escapeshellarg($value).' ';
            }
        }

        $execCmd .= $cmdStr;

        $result = Console::execute($execCmd, '', $output, $timeout);

        if ($result !== 0) {
            if ($result == 124) {
                throw new Timeout('Command timed out');
            } else {
                throw new Orchestration("K8s Error: {$output}");
            }
        }

        return true;
    }

    /**
     * Remove Container (Delete K8s Pod)
     */
    public function remove(string $name, bool $force = false): bool
    {
        $output = '';

        $forceFlag = $force ? ' --force --grace-period=0' : '';
        $result = Console::execute($this->buildKubectlCmd().' delete pod '.$name.$forceFlag, '', $output);

        if ($result !== 0) {
            throw new Orchestration("K8s Error: {$output}");
        }

        return true;
    }
}
