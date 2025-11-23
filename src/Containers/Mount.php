<?php

namespace Utopia\Containers;

interface Mount {
    public function getTarget(): string;
    public function isReadOnly(): bool;
}
