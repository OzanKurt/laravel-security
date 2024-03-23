<?php

namespace OzanKurt\Security\Enums;

enum IpEntryType: string
{
    case BLACKLIST = 'blacklist';
    case BLOCK = 'block';
    case WHITELIST = 'whitelist';
}
