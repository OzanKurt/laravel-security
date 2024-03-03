<?php

namespace OzanKurt\Security;

use Illuminate\Support\Facades\File;
use OzanKurt\Security\Helpers\RecentlyModifiedFiles;
use Symfony\Component\Finder\Finder;

class Security
{
    public function getRecentlyModifiedFiles(int $time_range = 604800): array
    {
        $rmf = new RecentlyModifiedFiles(base_path(), 20000, 250000, $time_range);

        $rmf->run();

        $mostRecentFiles = $rmf->mostRecentFiles(15);

        return $mostRecentFiles;
    }
}
