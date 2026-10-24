<?php

namespace App\Enums;

enum ValidationStatus: string
{
    case Allow = 'ALLOW';
    case Deny = 'DENY';
}
