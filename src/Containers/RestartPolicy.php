<?php

namespace Utopia\Containers;

enum RestartPolicy: string
{
    case No = 'no';
    case Always = 'always';
    case OnFailure = 'on-failure';
    case UnlessStopped = 'unless-stopped';
}
