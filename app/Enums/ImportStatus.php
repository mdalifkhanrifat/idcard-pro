<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Analyzed = 'analyzed';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
