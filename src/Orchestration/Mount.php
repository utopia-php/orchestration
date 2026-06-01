<?php

namespace Utopia\Orchestration;

class Mount
{
    public const TYPE_BIND = 'bind';

    public const TYPE_VOLUME = 'volume';

    private function __construct(
        private string $type,
        private string $source,
        private string $target,
        private bool $readOnly = false,
        private string $subpath = ''
    ) {
    }

    public static function bind(string $source, string $target, bool $readOnly = false, string $subpath = ''): self
    {
        return new self(self::TYPE_BIND, $source, $target, $readOnly, $subpath);
    }

    public static function volume(string $source, string $target, bool $readOnly = false, string $subpath = ''): self
    {
        return new self(self::TYPE_VOLUME, $source, $target, $readOnly, $subpath);
    }

    /**
     * @return array<string, array<string, string>|bool|string>
     */
    public function toDockerAPI(): array
    {
        $mount = [
            'Type' => $this->type,
            'Source' => $this->getSource(),
            'Target' => $this->target,
            'ReadOnly' => $this->readOnly,
        ];

        if ($this->type === self::TYPE_VOLUME && $this->subpath !== '') {
            $mount['VolumeOptions'] = [
                'Subpath' => $this->subpath,
            ];
        }

        return $mount;
    }

    public function toDockerCLI(): string
    {
        $mount = [
            'type='.$this->type,
            'source='.$this->getSource(),
            'target='.$this->target,
        ];

        if ($this->readOnly) {
            $mount[] = 'readonly';
        }

        if ($this->type === self::TYPE_VOLUME && $this->subpath !== '') {
            $mount[] = 'volume-subpath='.$this->subpath;
        }

        return \implode(',', $mount);
    }

    private function getSource(): string
    {
        if ($this->type !== self::TYPE_BIND || $this->subpath === '') {
            return $this->source;
        }

        return \rtrim($this->source, '/').'/'.\ltrim($this->subpath, '/');
    }
}
