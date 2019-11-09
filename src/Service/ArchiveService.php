<?php

namespace App\Service;

use Symfony\Component\Finder\Iterator\RecursiveDirectoryIterator;

class ArchiveService
{
    /**
     * @var array
     */
    private $archiveExtensions;

    /**
     * @var array
     */
    private $fileExtensions;

    /**
     * @param array $fileExtensions
     */
    public function setFileExtensions(array $fileExtensions)
    {
        $this->fileExtensions = $fileExtensions;
    }

    /**
     * @param array $archiveExtensions
     */
    public function setArchiveExtensions(array $archiveExtensions)
    {
        $this->archiveExtensions = $archiveExtensions;
    }

    /**
     * @param string $path
     * @return bool
     */
    public function isArchive($path)
    {
        if (!is_file($path)) {
            return false;
        }

        $pathInfo = pathinfo($path);

        return isset($pathInfo['extension']) && in_array($pathInfo['extension'], $this->archiveExtensions);
    }

    /**
     * @param string $path
     * @return bool
     */
    public function isZipArchive($path)
    {
        return $this->isArchive($path) && pathinfo($path, PATHINFO_EXTENSION) === 'zip';
    }

    /**
     * Creates zip archive from path and returns path to zip.
     *
     * @param string $path
     * @param array $excludePaths
     * @param string $archivePath
     * @return string
     * @throws \Exception if archive can not be created
     */
    public function folderToArchive($path, array $excludePaths = [], $archivePath = "")
    {
        $zip = new \ZipArchive();
        if (!$archivePath) {
            $archivePath = tempnam(sys_get_temp_dir(), 'RIPS');
        }
        $archiveCounter = 0;

        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception('Creating zip archive failed');
        }

        $directoryIterator = new \RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $directoryIterator->rewind();
        
        while ($directoryIterator->valid()) {
            /** @var \SplFileInfo $file */
            foreach ($directoryIterator as $file) {
                if ($file->isFile()) {
                    foreach ($excludePaths as $excludePath) {
                        // For easier usage we auto wrap the regex into slashes if it does not start with a slash.
                        if (substr($excludePath, 0, 1) !== '/') {
                            $excludePath = '/' . $excludePath . '/i';
                        }

                        // Skip file if there is a single regex that matches the full path.
                        if (preg_match($excludePath, $file->getRealPath())) {
                            continue 2;
                        }
                    }

                    $searchExtension = $file->getExtension();
                    if (in_array($searchExtension, $this->fileExtensions)) {
                        $filePath = $file->getRealPath();
                        $fileName = substr($filePath, strlen($path) + 1);
                        $zip->addFile($filePath, $fileName);
                        $archiveCounter++;
                    }
                }
            }
        }
        $zip->close();

        if ($archiveCounter === 0) {
            throw new \Exception('No files added to archive');
        }

        if (!file_exists($archivePath)) {
            throw new \Exception('Creating zip archive failed');
        }

        return $archivePath;
    }

    /**
     * The purpose of this method is to transform a given zip into another zip taking into account $this->fileExtensions
     * and $excludePaths.
     *
     * @param string $path
     * @param array $excludePaths
     * @param string $archivePath
     * @return string
     * @throws \Exception
     */
    public function archiveToArchive($path, array $excludePaths = [], $archivePath = "")
    {
        $inputZip = new \ZipArchive();

        if ($inputZip->open($path) !== true) {
            throw new \Exception('Opening zip archive failed');
        }

        $toExtract = [];
        for ($i = 0; $i < $inputZip->numFiles; $i++) {
            $file = $inputZip->getNameIndex($i);
            $pathInfo = pathinfo($file);

            if (isset($pathInfo['extension']) && in_array($pathInfo['extension'], $this->fileExtensions)) {
                $toExtract[] = $file;
            }
        }

        $tmpFolder = tempnam(sys_get_temp_dir(), 'RIPS');
        unlink($tmpFolder); // tempnam creates a file, we cheat and turn that into a folder
        if (mkdir($tmpFolder) === false) {
            throw new \Exception('Creating folder for temporary files extraction failed');
        }

        if ($inputZip->extractTo($tmpFolder, $toExtract) === false) {
            throw new \Exception('Extracting files to temporary directory failed');
        }

        return $this->folderToArchive($tmpFolder, $excludePaths, $archivePath);
    }
}
