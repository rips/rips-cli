<?php

namespace AppBundle\Service;

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
     * Creates zip archive from path and returns path to zip.
     *
     * @param string $path
     * @param array $excludePaths
     * @return string
     * @throws \Exception if archive can not be created
     */
    public function folderToArchive($path, array $excludePaths = [])
    {
        $zip = new \ZipArchive();
        $archivePath = tempnam(sys_get_temp_dir(), 'RIPS');
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

                    $search_extension = $file->getExtension();
                    if (in_array($search_extension, $this->fileExtensions)) {
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
}
