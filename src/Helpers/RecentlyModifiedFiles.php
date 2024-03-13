<?php

namespace OzanKurt\Security\Helpers;

class RecentlyModifiedFiles extends DirectoryIterator
{
    private int $time_range = 604800;
    private array $files = [];
    private array $excluded_directories = [];

    /**
     * @param string $directory
     * @param int $max_files_per_directory
     * @param int $max_iterations
     * @param int $time_range
     */
    public function __construct(
        string $directory = '',
        int    $time_range = 604800,
        int    $max_files_per_directory = 20000,
        int    $max_iterations = 250000
    )
    {
        parent::__construct($directory, $max_files_per_directory, $max_iterations);
        $this->time_range = $time_range;
        $excluded_directories = [
            '.idea',
            '.git',
            '.vitepress',
            '.vscode',
            'vendor',
            'node_modules',
            'storage/logs',
            'storage/framework',
            'storage/debugbar',
        ];
        $this->excluded_directories = [];
        foreach ($excluded_directories as $index => $path) {
            if (($dir = realpath($directory . '/' . $path)) !== false) {
                $this->excluded_directories[$dir] = 1;
            }
        }
    }

    protected function scan(string $dir): bool
    {
        if (!array_key_exists(realpath($dir), $this->excluded_directories)) {
            return parent::scan($dir);
        }
        return true;
    }

    public function file(string $file): void
    {
        $mtime = filemtime($file);
        if (time() - $mtime < $this->time_range) {
            $this->files[] = array($file, $mtime);
        }
    }

    public function mostRecentFiles(int $limit = 300): array
    {
        usort($this->files, array(
            $this,
            '_sortMostRecentFiles',
        ));

        return array_slice($this->files, 0, $limit);
    }

    /**
     * Sort in descending order.
     */
    private function _sortMostRecentFiles(mixed $a, mixed $b): int
    {
        if ($a[1] > $b[1]) {
            return -1;
        }
        if ($a[1] < $b[1]) {
            return 1;
        }
        return 0;
    }

    public function getFiles(): mixed
    {
        return $this->files;
    }
}
