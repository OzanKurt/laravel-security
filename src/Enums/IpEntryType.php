<?php

namespace OzanKurt\Shield\Enums;

enum IpEntryType: string
{
    case BLACKLIST = 'blacklist';
    case BLOCK = 'block';
    case WHITELIST = 'whitelist';
}
