<?php

namespace Utopia\Containers\Mount;

use Utopia\Containers\Mount;

final readonly class Volume implements Mount {
    public function __construct(
        public string $volumeName,
        public string $containerPath,
        public bool $readOnly = false
    ) {}

    public function getTarget(): string { return $this->containerPath; }
    public function isReadOnly(): bool { return $this->readOnly; }
}
