<?php

namespace Utopia\Containers;

final readonly class Container
{
    /**
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public string $name = '',
        public string $id = '',
        public string $status = '',
        public array $labels = []
    ) {
    }
}
