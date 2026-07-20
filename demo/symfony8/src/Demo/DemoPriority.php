<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * Sample backed enum used in demo payloads / Twig examples.
 */
enum DemoPriority: string
{
    case High = 'high';
    case Normal = 'normal';
}
