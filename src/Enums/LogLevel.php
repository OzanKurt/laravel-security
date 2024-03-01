<?php

namespace OzanKurt\Security\Enums;

enum LogLevel: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';
}
