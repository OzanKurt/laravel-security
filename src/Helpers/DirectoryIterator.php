<?php

namespace OzanKurt\Security\Helpers;

abstract class DirectoryIterator
{
    abstract public function file(string $file): void;

    protected string $directory;
    protected int $directory_limit;

    protected array $directories_entered = [];
    protected array $directories_processed = [];

    protected $callback;

    protected int $max_iterations;
    protected int $iterations;

    public function __construct(string $directory = ABSPATH, int $max_files_per_directory = 20000, int $max_iterations = 1000000)
    {
        $this->directory = $directory;
        $this->directory_limit = $max_files_per_directory;
        $this->max_iterations = $max_iterations;
    }

    public function run(): void
    {
        $this->iterations = 0;
        $this->scan($this->directory);
    }

    protected function scan(string $dir): bool
    {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        $handle = opendir($dir);
        $file_count = 0;
        while ($file = readdir($handle)) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $file_path = $dir . '/' . $file;
            $real_path = realpath($file_path);
            if (isset($this->directories_processed[$real_path]) || isset($this->directories_entered[$real_path])) { //Already processed or being processed, possibly a recursive symlink
                continue;
            } else if (is_dir($file_path)) {
                $this->directories_entered[$real_path] = 1;
                if ($this->scan($file_path) === false) {
                    closedir($handle);
                    return false;
                }
                $this->directories_processed[$real_path] = 1;
                unset($this->directories_entered[$real_path]);
            } else {
                if ($this->file($file_path) === false) {
                    closedir($handle);
                    return false;
                }
            }
            if (++$file_count >= $this->directory_limit) {
                break;
            }
            if (++$this->iterations >= $this->max_iterations) {
                closedir($handle);
                return false;
            }
        }
        closedir($handle);
        return true;
    }
}

