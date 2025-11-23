<?php

namespace Utopia\Containers\Mount;

use Utopia\Containers\Mount;

final readonly class Tmpfs implements Mount {
    public function __construct(
        public string $containerPath,
        public ?int $sizeBytes = null
    ) {}

    public function getTarget(): string { return $this->containerPath; }
    public function isReadOnly(): bool { return false; }
}
