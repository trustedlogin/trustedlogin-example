<?php

namespace CoenJacobs\Mozart;

use CoenJacobs\Mozart\Composer\Autoload\Autoloader;
use CoenJacobs\Mozart\Composer\Autoload\Classmap;
use CoenJacobs\Mozart\Composer\Autoload\NamespaceAutoloader;
use CoenJacobs\Mozart\Composer\Autoload\Psr0;
use CoenJacobs\Mozart\Composer\Autoload\Psr4;
use CoenJacobs\Mozart\Composer\Package;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Mover
{
    /** @var string */
    protected $workingDir;

    /** @var string */
    protected $targetDir;

    /** @var \stdClass */
    protected $config;

    /** @var Filesystem */
    protected $filesystem;

    /** @var array */
    protected $movedPackages = [];

    public function __construct($workingDir, $config)
    {
        $this->workingDir = $workingDir;
        $this->targetDir = $config->dep_directory;
        $this->config = $config;

        $this->filesystem = new Filesystem(new Local($this->workingDir));
    }

    /**
     * Create the required `dep_directory` and `classmap_directory` and delete targetDirs of packages about to be moved.
     *
     * @param Package[] $packages The packages that, in the next step, will be moved.
     */
    public function deleteTargetDirs($packages)
    {
        $this->filesystem->createDir($this->config->dep_directory);

        $this->filesystem->createDir($this->config->classmap_directory);

        foreach ($packages as $package) {
            $this->deleteDepTargetDirs($package);
        }
    }

    /**
     * Delete the directories about to be used for packages earmarked for Mozart namespacing.
     *
     * @visibility private to allow recursion through packages and subpackages.
     *
     * @param Package $package
     */
    private function deleteDepTargetDirs($package)
    {
        foreach ($package->autoloaders as $packageAutoloader) {
            $autoloaderType = get_class($packageAutoloader);

            switch ($autoloaderType) {
                case Psr0::class:
                case Psr4::class:
                    $outputDir = $this->config->dep_directory . $packageAutoloader->namespace;
                    $outputDir = str_replace('\\', DIRECTORY_SEPARATOR, $outputDir);
                    $this->filesystem->deleteDir($outputDir);
                    break;
                case Classmap::class:
                    $outputDir = $this->config->classmap_directory . $package->config->name;
                    $outputDir = str_replace('\\', DIRECTORY_SEPARATOR, $outputDir);
                    $this->filesystem->deleteDir($outputDir);
                    break;
            }
        }

        foreach ($package->dependencies as $subPackage) {
            $this->deleteDepTargetDirs($subPackage);
        }
    }

    public function movePackage(Package $package)
    {
        if (in_array($package->config->name, $this->movedPackages)) {
            return;
        }

        foreach ($package->autoloaders as $autoloader) {
            if ($autoloader instanceof NamespaceAutoloader) {
                $finder = new Finder();

                foreach ($autoloader->paths as $path) {
                    $source_path = $this->workingDir . '/vendor/' . $package->config->name . '/' . $path;

                    $finder->files()->in($source_path);

                    foreach ($finder as $file) {
                        $this->moveFile($package, $autoloader, $file, $path);
                    }
                }
            } elseif ($autoloader instanceof Classmap) {
                $finder = new Finder();

                foreach ($autoloader->files as $file) {
                    $source_path = $this->workingDir . '/vendor/' . $package->config->name;
                    $finder->files()->name($file)->in($source_path);

                    foreach ($finder as $foundFile) {
                        $this->moveFile($package, $autoloader, $foundFile);
                    }
                }

                $finder = new Finder();

                foreach ($autoloader->paths as $path) {
                    $source_path = $this->workingDir . '/vendor/' . $package->config->name . '/' . $path;

                    $finder->files()->in($source_path);

                    foreach ($finder as $file) {
                        $this->moveFile($package, $autoloader, $file);
                    }
                }
            }

            $this->movedPackages[] = $package->config->name;
        }

        if (!isset($this->config->delete_vendor_directories) || $this->config->delete_vendor_directories === true) {
            $this->deletePackageVendorDirectories();
        }
    }

    /**
     * @param Package $package
     * @param Autoloader $autoloader
     * @param SplFileInfo $file
     * @param string $path
     * @return string
     */
    public function moveFile(Package $package, $autoloader, $file, $path = '')
    {
        if ($autoloader instanceof NamespaceAutoloader) {
            $namespacePath = $autoloader->getNamespacePath();
            $replaceWith = $this->config->dep_directory . $namespacePath;
            $targetFile = str_replace($this->workingDir, $replaceWith, $file->getPathname());

            $packageVendorPath = '/vendor/' . $package->config->name . '/' . $path;
            $packageVendorPath = str_replace('/', DIRECTORY_SEPARATOR, $packageVendorPath);
            $targetFile = str_replace($packageVendorPath, '', $targetFile);
        } else {
            $namespacePath = $package->config->name;
            $replaceWith = $this->config->classmap_directory . '/' . $namespacePath;
            $targetFile = str_replace($this->workingDir, $replaceWith, $file->getPathname());

            $packageVendorPath = '/vendor/' . $package->config->name . '/';
            $packageVendorPath = str_replace('/', DIRECTORY_SEPARATOR, $packageVendorPath);
            $targetFile = str_replace($packageVendorPath, DIRECTORY_SEPARATOR, $targetFile);
        }

        $this->filesystem->copy(
            str_replace($this->workingDir, '', $file->getPathname()),
            $targetFile
        );

        return $targetFile;
    }

    /**
     * Deletes all the packages that are moved from the /vendor/ directory to
     * prevent packages that are prefixed/namespaced from being used or
     * influencing the output of the code. They just need to be gone.
     */
    protected function deletePackageVendorDirectories()
    {
        foreach ($this->movedPackages as $movedPackage) {
            $packageDir = '/vendor/' . $movedPackage;
            if (is_link($packageDir)) {
                continue;
            }
            $this->filesystem->deleteDir($packageDir);
        }
    }
}
