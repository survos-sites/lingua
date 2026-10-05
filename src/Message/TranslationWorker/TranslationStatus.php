<?php

declare(strict_types=1);

namespace App\Message\TranslationWorker;

enum TranslationStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}
