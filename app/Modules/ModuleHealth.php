<?php

namespace App\Modules;

/**
 * Health state a module reports from its self-check.
 */
enum ModuleHealth: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Unavailable = 'unavailable';
}
