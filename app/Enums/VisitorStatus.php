<?php

namespace App\Enums;

enum VisitorStatus: string
{
    case Expected = 'Expected';
    case CheckedIn = 'Checked In';
    case CheckedOut = 'Checked Out';
}
