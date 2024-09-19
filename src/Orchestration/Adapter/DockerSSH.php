<?php

namespace Utopia\Orchestration\Adapter;

use Utopia\CLI\Console;
use Utopia\Orchestration\Exception\Orchestration;

class DockerSSH extends DockerCLI
{
    /**
     * Constructor
     *
     * @return void
     */
    public function __construct(string $sshHost, string $sshUser)
    {
        $output = '';
        if (! Console::execute("docker context create --docker host=ssh://{$sshUser}@{$sshHost} ssh", '', $output)) {
            throw new Orchestration("Docker Error: {$output}");
        }
        $output = '';
        if (! Console::execute('docker context use ssh', '', $output)) {
            throw new Orchestration("Docker Error: {$output}");
        }
    }
}
