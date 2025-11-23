<?php

namespace Utopia\Containers;

final readonly class Network
{
    public function __construct(
        public string $name = '',
        public string $id = '',
        public string $driver = '',
        public string $scope = ''
    )
    {}
}
