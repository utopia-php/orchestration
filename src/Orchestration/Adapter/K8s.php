<?php

namespace Utopia\Orchestration\Adapter;

use RenokiCo\PhpK8s\Exceptions\KubernetesAPIException;
use RenokiCo\PhpK8s\K8s as PhpK8s;
use RenokiCo\PhpK8s\KubernetesCluster;
use Utopia\Orchestration\Adapter;
use Utopia\Orchestration\Container;
use Utopia\Orchestration\Container\Stats;
use Utopia\Orchestration\Exception\Orchestration;
use Utopia\Orchestration\Network;

class K8s extends Adapter
{
    /**
     * @var KubernetesCluster
     */
    private KubernetesCluster $cluster;

    /**
     * @var string
     */
    private string $k8sNamespace;

    /**
     * Constructor
     *
     * @param  string|null  $url  Kubernetes API URL
     * @param  string  $namespace  Kubernetes namespace
     * @param  array<string, mixed>  $auth  Authentication configuration
     */
    public function __construct(?string $url = null, string $namespace = 'default', array $auth = [])
    {
        $this->k8sNamespace = $namespace;

        if (empty($url)) {
            throw new Orchestration('K8s adapter requires an API URL (fromURL).');
        }

        // Initialize cluster connection using the KubernetesCluster::fromUrl helper
        $this->cluster = KubernetesCluster::fromUrl($url);

        // Configure authentication if provided
        if (! empty($auth)) {
            if (isset($auth['token'])) {
                $this->cluster->withToken($auth['token']);
            } elseif (isset($auth['username']) && isset($auth['password'])) {
                $this->cluster->httpAuthentication($auth['username'], $auth['password']);
            } elseif (isset($auth['cert']) && isset($auth['key'])) {
                // php-k8s expects separate calls for cert & private key
                $this->cluster->withCertificate($auth['cert']);
                $this->cluster->withPrivateKey($auth['key']);
            }

            // TLS verification options
            if (isset($auth['ca']) && is_string($auth['ca']) && $auth['ca'] !== '') {
                $this->cluster->withCaCertificate($auth['ca']);
            }

            if ((isset($auth['verify']) && $auth['verify'] === false) || (!empty($auth['insecure']))) {
                $this->cluster->withoutSslChecks();
            }
        }
    }

    /**
     * Build label selector query parameter from filters
     *
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function buildLabelSelector(array $filters): array
    {
        $query = ['pretty' => 1];
        if (! empty($filters)) {
            $labelSelector = [];
            foreach ($filters as $key => $value) {
                $labelSelector[] = "{$key}={$value}";
            }
            $query['labelSelector'] = implode(',', $labelSelector);
        }
        return $query;
    }

    /**
     * Sanitize pod name to comply with K8s RFC 1123 subdomain rules
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
     * Parse image reference into name and tag components
     * Rules:
     * - If image has a digest (e.g., repo@sha256:...), ignore tag and set by name only.
     * - Otherwise, detect tag using the last ':' that appears after the last '/'.
     * - Default tag to 'latest' when none provided.
     *
     * @return array{0: string, 1: string|null} [imageName, imageTag]
     */
    private function parseImageReference(string $image): array
    {
        $imageName = $image; // full reference by default
        $imageTag = null;

        $digestPos = strpos($image, '@');
        if ($digestPos !== false) {
            // e.g. alpine@sha256:...
            $imageName = substr($image, 0, $digestPos);
            $imageTag = null; // tag ignored when digest present
        } else {
            $lastSlash = strrpos($image, '/');
            $lastColon = strrpos($image, ':');
            if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
                // There is a tag part after the last '/'
                $imageName = substr($image, 0, $lastColon);
                $imageTag = substr($image, $lastColon + 1);
            } else {
                // No explicit tag -> default to latest
                $imageName = $image;
                $imageTag = 'latest';
            }
        }

        return [$imageName, $imageTag];
    }

    /**
     * Create Network (K8s NetworkPolicy)
     */
    public function createNetwork(string $name, bool $internal = false): bool
    {
        try {
            $resourceName = $this->sanitizePodName($name);
            $labelValue = $this->sanitizeLabelValue($name);

            $payload = [
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
                        'matchLabels' => ['network' => $labelValue],
                    ],
                    'policyTypes' => ['Ingress', 'Egress'],
                ],
            ];

            if ($internal) {
                // Deny all ingress and egress for internal networks
                $payload['spec']['ingress'] = [];
                $payload['spec']['egress'] = [];
            } else {
                // Allow all ingress and egress for external networks
                // The correct way to allow-all is [{}] for both ingress & egress.
                $payload['spec']['ingress'] = [ (object) [] ];
                $payload['spec']['egress'] = [ (object) [] ];
            }

            $this->cluster->call(
                'POST',
                "/apis/networking.k8s.io/v1/namespaces/{$this->k8sNamespace}/networkpolicies",
                json_encode($payload)
            );

            return true;
        } catch (KubernetesAPIException $e) {
            throw new Orchestration("Failed to create network: {$e->getMessage()}");
        }
    }

    /**
     * Remove Network (K8s NetworkPolicy)
     */
    public function removeNetwork(string $name): bool
    {
        try {
            $resourceName = $this->sanitizePodName($name);
            $this->cluster->call(
                'DELETE',
                "/apis/networking.k8s.io/v1/namespaces/{$this->k8sNamespace}/networkpolicies/{$resourceName}"
            );

            return true;
        } catch (KubernetesAPIException $e) {
            throw new Orchestration("Failed to remove network: {$e->getMessage()}");
        }
    }

    /**
     * Connect a container to a network (Add label to pod)
     */
    public function networkConnect(string $container, string $network): bool
    {
        try {
            $labelValue = $this->sanitizeLabelValue($network);
            $pod = $this->cluster->getPodByName($container, $this->k8sNamespace);

            $labels = $pod->getLabels();
            $labels['network'] = $labelValue;
            $pod->setLabels($labels);

            $pod->update();

            return true;
        } catch (KubernetesAPIException $e) {
            throw new Orchestration("Failed to connect network: {$e->getMessage()}");
        }
    }

    /**
     * Disconnect a container from a network (Remove label from pod)
     */
    public function networkDisconnect(string $container, string $network, bool $force = false): bool
    {
        try {
            $pod = $this->cluster->getPodByName($container, $this->k8sNamespace);

            $labels = $pod->getLabels();
            unset($labels['network']);
            $pod->setLabels($labels);

            $pod->update();

            return true;
        } catch (KubernetesAPIException $e) {
            throw new Orchestration("Failed to disconnect network: {$e->getMessage()}");
        }
    }

    /**
     * Check if a network exists
     */
    public function networkExists(string $name): bool
    {
        try {
            $resourceName = $this->sanitizePodName($name);
            $this->cluster->call(
                'GET',
                "/apis/networking.k8s.io/v1/namespaces/{$this->k8sNamespace}/networkpolicies/{$resourceName}"
            );
            return true;
        } catch (KubernetesAPIException $e) {
            return false;
        }
    }

    /**
     * List Networks (K8s NetworkPolicies)
     *
     * @return Network[]
     */
    public function listNetworks(): array
    {
        try {
            $response = $this->cluster->call(
                'GET',
                "/apis/networking.k8s.io/v1/namespaces/{$this->k8sNamespace}/networkpolicies"
            );

            $json = @json_decode((string) $response->getBody(), true) ?: [];
            $items = $json['items'] ?? [];

            $list = [];
            foreach ($items as $np) {
                $metadata = $np['metadata'] ?? [];
                $annotations = $metadata['annotations'] ?? [];
                $displayName = $annotations['orchestration/original-name'] ?? $metadata['name'] ?? '';

                $list[] = new Network(
                    $displayName,
                    $metadata['uid'] ?? '',
                    'NetworkPolicy',
                    $metadata['namespace'] ?? $this->k8sNamespace
                );
            }

            return $list;
        } catch (KubernetesAPIException $e) {
            throw new Orchestration("Failed to list networks: {$e->getMessage()}");
        }
    }

    /**
     * Get usage stats of containers
     *
     * @param  array<string, string>  $filters
     * @return array<Stats>
     */
    public function getStats(?string $container = null, array $filters = []): array
    {
        $stats = [];

        try {
            if ($container !== null) {
                $pods = [$this->cluster->getPodByName($container, $this->k8sNamespace)];
            } else {
                // Apply label filters
                $query = $this->buildLabelSelector($filters);
                $pods = $this->cluster->getAllPods($this->k8sNamespace, $query);
            }

            foreach ($pods as $pod) {
                // Get pod metrics - Note: This requires Metrics Server to be installed
                // The php-k8s library doesn't have direct metrics support, so we'd need to make custom API calls
                // For now, we'll return basic stats with zero values for metrics

                $podName = $pod->getName();
                $podId = $pod->getResourceUid();

                // In a real implementation, you would call the metrics API:
                // GET /apis/metrics.k8s.io/v1beta1/namespaces/{namespace}/pods/{name}
                // For now, return basic structure

                $stat = new Stats(
                    containerId: $podId,
                    containerName: $podName,
                    cpuUsage: 0.0,
                    memoryUsage: 0.0,
                    diskIO: ['in' => 0, 'out' => 0],
                    memoryIO: ['in' => 0, 'out' => 0],
                    networkIO: ['in' => 0, 'out' => 0]
                );

                $stats[] = $stat;
            }

            return $stats;
        } catch (KubernetesAPIException $e) {
            return [];
        }
    }

    /**
     * Pull Image
     * Note: In K8s, images are pulled automatically when pods are created
     */
    public function pull(string $image): bool
    {
        // Kubernetes handles image pulling automatically
        return true;
    }

    /**
     * List Containers (K8s Pods)
     *
     * @param  array<string, string>  $filters
     * @return Container[]
     */
    public function list(array $filters = []): array
    {
        try {
            $query = $this->buildLabelSelector($filters);
            $pods = $this->cluster->getAllPods($this->k8sNamespace, $query);

            $list = [];

            foreach ($pods as $pod) {
                $podName = $pod->getName();
                $phase = $pod->getAttribute('status.phase', 'Unknown');
                $labels = $pod->getLabels();

                // Skip pods that are being deleted (have deletionTimestamp set)
                $deletionTimestamp = $pod->getAttribute('metadata.deletionTimestamp', null);
                if ($deletionTimestamp !== null) {
                    continue;
                }

                // Check if this is an auto-remove pod that has completed
                $autoRemove = $labels[$this->namespace.'-auto-remove'] ?? '';
                if ($autoRemove === 'true' && in_array($phase, ['Succeeded', 'Failed'])) {
                    // Delete the completed pod in the background
                    try {
                        $pod->delete();
                    } catch (KubernetesAPIException $e) {
                        // Ignore deletion errors
                    }
                    // Don't include in list since it's being removed
                    continue;
                }

                $container = new Container(
                    $podName,
                    $pod->getResourceUid(),
                    $phase,
                    $labels
                );
                $list[] = $container;
            }

            return $list;
        } catch (KubernetesAPIException $e) {
            throw new Orchestration("Failed to list containers: {$e->getMessage()}");
        }
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
        try {
            // Sanitize pod name
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

            // Sanitize label values
            foreach ($labels as $key => $value) {
                $labels[$key] = $this->sanitizeLabelValue((string) $value);
            }

            // Create container configuration
            $container = PhpK8s::container();
            $container->setAttribute('name', 'main');

            // Parse and set image
            [$imageName, $imageTag] = $this->parseImageReference($image);

            if ($imageTag !== null && $imageTag !== '') {
                $container->setImage($imageName, $imageTag);
            } else {
                $container->setImage($imageName);
            }

            // Set command and args
            if (! empty($entrypoint)) {
                $container->setAttribute('command', [$entrypoint]);
            }

            if (! empty($command)) {
                $container->setAttribute('args', $command);
            }

            // Set working directory
            if (! empty($workdir)) {
                $container->setAttribute('workingDir', $workdir);
            }

            // Set environment variables
            if (! empty($vars)) {
                $envVars = [];
                foreach ($vars as $key => $value) {
                    $key = $this->filterEnvKey($key);
                    $envVars[$key] = $value;
                }
                $container->setEnv($envVars);
            }

            // Set resource limits
            if ($this->cpus > 0 || $this->memory > 0) {
                $resources = [];

                if ($this->cpus > 0) {
                    $resources['limits']['cpu'] = (string) $this->cpus;
                    $resources['requests']['cpu'] = (string) ($this->cpus / 2);
                }

                if ($this->memory > 0) {
                    $resources['limits']['memory'] = $this->memory.'Mi';
                    $resources['requests']['memory'] = ($this->memory / 2).'Mi';
                }

                $container->setAttribute('resources', $resources);
            }

            // Create pod
            $pod = $this->cluster->pod()
                ->setName($name)
                ->setNamespace($this->k8sNamespace)
                ->setLabels($labels)
                ->setContainers([$container]);

            // Set hostname
            if (! empty($hostname)) {
                $spec = $pod->getAttribute('spec', []);
                $spec['hostname'] = $hostname;
                $pod->setAttribute('spec', $spec);
            }

            // Set restart policy
            $restartPolicies = [
                self::RESTART_NO => 'Never',
                self::RESTART_ALWAYS => 'Always',
                self::RESTART_ON_FAILURE => 'OnFailure',
                self::RESTART_UNLESS_STOPPED => 'Always',
            ];
            $restartPolicy = $restartPolicies[$restart] ?? 'Never';
            $spec = $pod->getAttribute('spec', []);
            $spec['restartPolicy'] = $restartPolicy;
            $pod->setAttribute('spec', $spec);

            // Handle volumes
            if (! empty($volumes) || ! empty($mountFolder)) {
                $volumeList = [];
                $volumeMounts = [];
                $volumeIndex = 0;

                foreach ($volumes as $volume) {
                    // Parse volume format: /host/path:/container/path or /host/path:/container/path:ro
                    $parts = \explode(':', $volume);
                    if (\count($parts) >= 2) {
                        $volumeName = 'vol-'.$volumeIndex;

                        $vol = PhpK8s::volume();
                        $vol->setAttribute('name', $volumeName);
                        $vol->setAttribute('hostPath', ['path' => $parts[0]]);
                        $volumeList[] = $vol;

                        $volumeMounts[] = [
                            'name' => $volumeName,
                            'mountPath' => $parts[1],
                            'readOnly' => isset($parts[2]) && $parts[2] === 'ro',
                        ];

                        $volumeIndex++;
                    }
                }

                if (! empty($mountFolder)) {
                    $volumeName = 'mount-folder';
                    $vol = PhpK8s::volume();
                    $vol->setAttribute('name', $volumeName);
                    $vol->setAttribute('hostPath', ['path' => $mountFolder]);
                    $volumeList[] = $vol;

                    $volumeMounts[] = [
                        'name' => $volumeName,
                        'mountPath' => '/tmp',
                    ];
                }

                if (! empty($volumeList)) {
                    $pod->setVolumes($volumeList);
                    $container->setAttribute('volumeMounts', $volumeMounts);
                }
            }

            // Create the pod
            $pod = $pod->create();

            // Wait for container to be ready
            $tries = 0;
            while ($tries < 30) {
                try {
                    $pod->refresh();
                    $containerStatuses = $pod->getAttribute('status.containerStatuses', []);
                    if (!empty($containerStatuses)) {
                        $mainContainer = $containerStatuses[0] ?? null;
                        if ($mainContainer && ($mainContainer['ready'] ?? false)) {
                            break;
                        }
                    }
                } catch (KubernetesAPIException $e) {
                    // Pod might not be fully created yet
                }

                \sleep(1);
                $tries++;
            }

            return $pod->getResourceUid();
        } catch (KubernetesAPIException $e) {
            throw new Orchestration("Failed to run container: {$e->getMessage()}");
        }
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
        try {
            $name = $this->sanitizePodName($name);
            $pod = $this->cluster->getPodByName($name, $this->k8sNamespace);
            // Ensure we have latest pod state
            $pod->refresh();

            // Environment variables need to be set when the pod is created
            // Exec doesn't support setting env vars dynamically
            // For now, we'll just execute the command

            // Determine the container name explicitly to avoid defaults
            $containerName = $pod->getAttribute('spec.containers.0.name', null);
            if ($containerName === null) {
                $statuses = $pod->getContainerStatuses(false);
                $containerName = $statuses[0]['name'] ?? null;
            }

            $result = $pod->exec($command, $containerName);

            // The exec method returns a string
            $output = (string) $result;

            return true;
        } catch (\RenokiCo\PhpK8s\Exceptions\KubernetesExecException $e) {
            throw new Orchestration("Failed to execute command: {$e->getMessage()}");
        } catch (KubernetesAPIException $e) {
            throw new Orchestration("Failed to execute command: {$e->getMessage()}");
        }
    }

    /**
     * Remove Container (Delete K8s Pod)
     */
    public function remove(string $name, bool $force = false): bool
    {
        try {
            $name = $this->sanitizePodName($name);
            $pod = $this->cluster->getPodByName($name, $this->k8sNamespace);

            if ($force) {
                // Force delete by setting grace period to 0
                $pod->delete(['gracePeriodSeconds' => 0]);
            } else {
                $pod->delete();
            }

            return true;
        } catch (KubernetesAPIException $e) {
            throw new Orchestration("Failed to remove container: {$e->getMessage()}");
        }
    }
}
