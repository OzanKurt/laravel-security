<?php

namespace OzanKurt\Security\Helpers;


class RecentlyModifiedFiles extends DirectoryIterator
{

    /**
     * @var int
     */
    private $time_range = 604800;

    /**
     * @var array
     */
    private $files = array();
    private $excluded_directories;

    /**
     * @param string $directory
     * @param int    $max_files_per_directory
     * @param int    $max_iterations
     * @param int    $time_range
     */
    public function __construct($directory = '', $max_files_per_directory = 20000, $max_iterations = 250000, $time_range = 604800) {
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
            if (($dir = realpath($directory .'/'. $path)) !== false) {
                $this->excluded_directories[$dir] = 1;
            }
        }
    }

    /**
     * @param $dir
     * @return bool
     */
    protected function scan($dir) {
        if (!array_key_exists(realpath($dir), $this->excluded_directories)) {
            return parent::scan($dir);
        }
        return true;
    }


    /**
     * @param string $file
     */
    public function file($file) {
        $mtime = filemtime($file);
        if (time() - $mtime < $this->time_range) {
            $this->files[] = array($file, $mtime);
        }
    }

    /**
     * @param int $limit
     * @return array
     */
    public function mostRecentFiles($limit = 300) {
        usort($this->files, array(
            $this,
            '_sortMostRecentFiles',
        ));
        return array_slice($this->files, 0, $limit);
    }

    /**
     * Sort in descending order.
     *
     * @param $a
     * @param $b
     * @return int
     */
    private function _sortMostRecentFiles($a, $b) {
        if ($a[1] > $b[1]) {
            return -1;
        }
        if ($a[1] < $b[1]) {
            return 1;
        }
        return 0;
    }

    /**
     * @return mixed
     */
    public function getFiles() {
        return $this->files;
    }
}
