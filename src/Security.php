<?php

namespace OzanKurt\Security;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

class Security
{
    public function checkProjectFiles()
    {
        $paths = [
//            'app',
            'bootstrap',
            'config',
//            'database',
//            'public',
//            'resources',
//            'routes',
        ];

        $files = [];


        foreach ($paths as $path) {
            $directoryPath = base_path($path);

            $files = $this->checkPath($directoryPath, $files);
        }

        dd($files);
    }

    public function checkPath(string $path, array $files = [])
    {
        try {
            $pathFiles = File::files($path);
            $directories = File::directories($path);

            foreach ($pathFiles as $file) {
                $files[] = [
                    'path' => $file->getPathname(),
                    'last_modified' => $file->getMTime(),
                ];
            }

            foreach ($directories as $directoryPath) {
                $directoryFiles = $this->checkPath($directoryPath, $files);

                $files = array_merge($files, $directoryFiles);
            }
        } catch (\Exception $e) {
            dump($e->getMessage());
        }

        return $files;
    }
}
