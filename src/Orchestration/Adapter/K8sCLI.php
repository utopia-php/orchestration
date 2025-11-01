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
     * Sanitize pod name to comply with K8s RFC 1123 subdomain rules
     * Converts to lowercase and replaces invalid characters
     */
    private function sanitizePodName(string $name): string
    {
        $name = \strtolower($name);
        $name = \preg_replace('/[^a-z0-9\-.]/', '-', $name);
        return \trim($name, '-.');
    }

    /**
     * Sanitize label value to comply with K8s label requirements
     */
    private function sanitizeLabelValue(string $value): string
    {
        // Replace invalid characters with '-'
        $value = \preg_replace('/[^A-Za-z0-9\-_.]+/', '-', $value);

        // Collapse consecutive separators to a single hyphen
        $value = \preg_replace('/[-_.]{2,}/', '-', $value);

        // Trim separators from the start and end (labels must start/end with alphanumeric)
        $value = \trim($value, '-_.');

        // Truncate to max 63 characters for k8s label value
        if (\strlen($value) > 63) {
            $value = \substr($value, 0, 63);
            // Ensure truncated value doesn't end with a separator
            $value = rtrim($value, '-_.');
        }

        // Fallback to a safe value if all characters were invalid
        if ($value === '') {
            return 'value';
        }

        return $value;
    }

    /**
     * Build label selector string from filters array
     */
    private function buildLabelSelector(array $filters): string
    {
        if (empty($filters)) {
            return '';
        }

        $labelFilters = [];
        foreach ($filters as $key => $value) {
            $labelFilters[] = $key.'='.$value;
        }
        return ' -l '.implode(',', $labelFilters);
    }

    /**
     * Apply YAML content via kubectl
     * Creates a temporary file, applies it, and cleans up
     */
    private function applyYaml(string $yaml, int $timeout = -1): int
    {
        $output = '';
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'k8s-');
        \file_put_contents($tmpFile, $yaml);

        $result = Console::execute($this->buildKubectlCmd().' apply -f '.$tmpFile, '', $output, $timeout);

        \unlink($tmpFile);

        if ($result !== 0) {
            throw new Orchestration("K8s Error: {$output}");
        }

        return $result;
    }

    /**
     * Delete pod with cleanup
     */
    private function deletePod(string $podName, bool $force = false): void
    {
        $output = '';
        $flags = $force ? ' --grace-period=0 --force' : '';
        $flags .= ' --ignore-not-found=true';

        Console::execute(
            $this->buildKubectlCmd().' delete pod '.$podName.$flags,
            '',
            $output
        );
    }

    /**
     * Create Network (K8s NetworkPolicy)
     */
    public function createNetwork(string $name, bool $internal = false): bool
    {
        // In Kubernetes, networks are typically represented via NetworkPolicies.
        // Use a sanitized resource name (RFC1123) for metadata.name and a
        // sanitized label value for the pod selector. Store the original
        // requested name in an annotation so callers can map back if needed.

        $resourceName = $this->sanitizePodName($name);
        $labelValue = $this->sanitizeLabelValue($name);

        $policy = [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'NetworkPolicy',
            'metadata' => [
                'name' => $resourceName,
                'namespace' => $this->k8sNamespace,
                'annotations' => [
                    'orchestration/original-name' => $name,
                ],
            ],
            'spec' => [
                'podSelector' => [
                    'matchLabels' => [
                        'network' => $labelValue,
                    ],
                ],
                'policyTypes' => ['Ingress', 'Egress'],
            ],
        ];

        if ($internal) {
            $policy['spec']['ingress'] = [];
            $policy['spec']['egress'] = [];
        } else {
            // Use an empty object inside the array to indicate "allow all" for that direction
            $policy['spec']['ingress'] = [new \stdClass()];
            $policy['spec']['egress'] = [new \stdClass()];
        }

        $this->applyYaml(json_encode($policy));

        return true;
    }

    /**
     * Remove Network (K8s NetworkPolicy)
     */
    public function removeNetwork(string $name): bool
    {
        $output = '';

        $resourceName = $this->sanitizePodName($name);
        $result = Console::execute($this->buildKubectlCmd().' delete networkpolicy '.$resourceName, '', $output);

        return $result === 0;
    }

    /**
     * Connect a container to a network (Add label to pod)
     */
    public function networkConnect(string $container, string $network): bool
    {
        $output = '';

        // In K8s, we add a network label to the pod
        $labelValue = $this->sanitizeLabelValue($network);
        $result = Console::execute($this->buildKubectlCmd().' label pod '.$container.' network='.$labelValue.' --overwrite', '', $output);

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

        $resourceName = $this->sanitizePodName($name);
        $result = Console::execute($this->buildKubectlCmd().' get networkpolicy '.$resourceName.' --namespace='.$this->k8sNamespace.' -o name 2>/dev/null', '', $output);

        return $result === 0 && str_contains($output, $resourceName);
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
                    $displayName = $item['metadata']['annotations']['orchestration/original-name'] ?? $item['metadata']['name'];

                    $network = new Network(
                        $displayName,
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
        // If no filters provided and no specific container, filter by namespace and created label
        if ($container === null && empty($filters)) {
            $filters = ['label' => $this->namespace.'-created'];
        }

        $output = '';
        $stats = [];

        // Get pod metrics using kubectl top (requires metrics-server)
        if ($container !== null) {
            // Sanitize container name
            $container = $this->sanitizePodName($container);

            $result = Console::execute($this->buildKubectlCmd().' top pod '.$container.' --namespace='.$this->k8sNamespace.' --no-headers 2>/dev/null', '', $output);
        } else {
            $selector = $this->buildLabelSelector($filters);
            $result = Console::execute($this->buildKubectlCmd().' top pod'.$selector.' --namespace='.$this->k8sNamespace.' --no-headers 2>/dev/null', '', $output);
        }

        // If kubectl top fails (e.g., metrics-server not installed), return empty array
        if ($result !== 0 || empty(\trim($output))) {
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
                        $this->deletePod($tempPodName, true);
                        return false;
                    }
                }
            }

            // Clean up the test pod
            $this->deletePod($tempPodName, true);

            return true;
        } catch (\Exception $e) {
            // Clean up on error
            $this->deletePod($tempPodName, true);
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

        $selector = $this->buildLabelSelector($filters);

        $result = Console::execute($this->buildKubectlCmd().' get pods'.$selector.' --namespace='.$this->k8sNamespace.' -o json', '', $output);

        if ($result !== 0) {
            throw new Orchestration("K8s Error: {$output}");
        }

        $list = [];
        $data = \json_decode($output, true);

        if (isset($data['items']) && \is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                if (isset($item['metadata']['name'])) {
                    $podName = $item['metadata']['name'];
                    $phase = $item['status']['phase'] ?? 'Unknown';
                    $labels = $item['metadata']['labels'] ?? [];

                    // Check if this is an auto-remove pod that has completed
                    $autoRemove = $labels[$this->namespace.'-auto-remove'] ?? '';
                    if ($autoRemove === 'true' && in_array($phase, ['Succeeded', 'Failed'])) {
                        // Delete the completed pod in the background
                        try {
                            $this->deletePod($podName, false);
                        } catch (\Exception $e) {
                            // Ignore deletion errors
                        }
                        // Don't include in list since it's being removed
                        continue;
                    }

                    $container = new Container(
                        $podName,
                        $item['metadata']['uid'] ?? '',
                        $phase,
                        $labels
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
        // Kubernetes pod names must be lowercase RFC 1123 subdomain
        $name = $this->sanitizePodName($name);

        // Add default labels
        $labels[$this->namespace.'-type'] = 'runtime';
        $labels[$this->namespace.'-created'] = (string) time();

        if (! empty($network)) {
            $labels['network'] = $network;
        }

        // Track auto-remove pods with a label
        if ($remove) {
            $labels[$this->namespace.'-auto-remove'] = 'true';
        }

        // Sanitize label values - must be alphanumeric, '-', '_', '.' only
        foreach ($labels as $key => $value) {
            $labels[$key] = $this->sanitizeLabelValue((string) $value);
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
                // Use an emptyDir inside the pod as hostPath won't work in many
                // kubernetes environments (hostPath mounts the node filesystem,
                // not the test runner container). We'll copy files into the pod
                // via `kubectl cp` after the pod is Running.
                $volumeMounts[] = [
                    'name' => 'mount-folder',
                    'mountPath' => '/tmp',
                ];
                // emptyDir represented as an empty object in JSON
                $volumeList[] = [
                    'name' => 'mount-folder',
                    'emptyDir' => new \stdClass(),
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

        $output = '';
        $result = Console::execute($this->buildKubectlCmd().' apply -f '.$tmpFile, '', $output, 30);

        \unlink($tmpFile);

        if ($result !== 0) {
            throw new Orchestration("K8s Error: {$output}");
        }

        // Wait for pod to be created and get its UID
        $podData = null;

        // Poll the pod until it exists (or timeout)
        $tries = 0;
        while ($tries < 20) {
            $podOutput = '';
            Console::execute($this->buildKubectlCmd().' get pod '.$name.' -o json', '', $podOutput);
            $podData = \json_decode($podOutput, true);
            if (is_array($podData) && isset($podData['metadata']['uid'])) {
                break;
            }
            \sleep(1);
            $tries++;
        }

        // If a local mountFolder was provided, copy its files into the pod's /tmp
        if (!empty($mountFolder) && is_dir($mountFolder) && is_array($podData) && isset($podData['metadata']['uid'])) {
            // Wait for the pod to be in Running (or Succeeded) state
            $tries = 0;
            while ($tries < 20) {
                $statusOutput = '';
                Console::execute($this->buildKubectlCmd().' get pod '.$name.' -o json', '', $statusOutput);
                $statusData = \json_decode($statusOutput, true);
                $phase = $statusData['status']['phase'] ?? '';
                if ($phase === 'Running' || $phase === 'Succeeded') {
                    break;
                }
                \sleep(1);
                $tries++;
            }

            // Copy files from the local mount folder into /tmp in the pod
            $files = @\scandir(rtrim($mountFolder, '/')) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $local = rtrim($mountFolder, '/').'/'.$file;
                if (!is_file($local)) {
                    continue;
                }

                $output = '';
                // Use kubectl cp: <local> <namespace>/<pod>:/tmp/<file> -c main
                $cmd = $this->buildKubectlCmd().' cp '.\escapeshellarg($local).' '.$this->k8sNamespace.'/'.$name.':/tmp/'.str_replace("'", "'\\''", $file).' -c main';
                Console::execute($cmd, '', $output, 30);
            }
        }

        // Wait for container to be ready (not just pod running)
        $tries = 0;
        while ($tries < 30) {
            $statusOutput = '';
            Console::execute($this->buildKubectlCmd().' get pod '.$name.' -o json', '', $statusOutput);
            $statusData = \json_decode($statusOutput, true);

            // Check if container is ready
            $containerStatuses = $statusData['status']['containerStatuses'] ?? [];
            if (!empty($containerStatuses)) {
                $mainContainer = $containerStatuses[0] ?? null;
                if ($mainContainer && ($mainContainer['ready'] ?? false)) {
                    break;
                }
            }

            \sleep(1);
            $tries++;
        }

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
        // Sanitize pod name to match K8s naming rules
        $name = $this->sanitizePodName($name);

        $cmdStr = '';
        foreach ($command as $cmd) {
            if (str_contains($cmd, ' ')) {
                $cmdStr .= " '".$cmd."'";
            } else {
                $cmdStr .= ' '.$cmd;
            }
        }

        $execCmd = $this->buildKubectlCmd().' exec '.$name.' --namespace='.$this->k8sNamespace.' -- ';

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
        // Sanitize pod name to match K8s naming rules
        $name = $this->sanitizePodName($name);

        $output = '';

        $forceFlag = $force ? ' --force --grace-period=0' : '';
        $result = Console::execute($this->buildKubectlCmd().' delete pod '.$name.$forceFlag.' --namespace='.$this->k8sNamespace, '', $output);

        if ($result !== 0) {
            throw new Orchestration("K8s Error: {$output}");
        }

        return true;
    }
}
