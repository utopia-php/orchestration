<?php

namespace Utopia\Containers;

final readonly class Usage
{
    /**
     * @param  array<string, float>  $diskIO
     * @param  array<string, float>  $memoryIO
     * @param  array<string, float>  $networkIO
     */
    public function __construct(
        public float $cpuUsage,
        public float $memoryUsage,
        public array $diskIO,
        public array $memoryIO,
        public array $networkIO)
    {}
}
