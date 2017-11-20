<?php

namespace AppBundle\Service;

use Symfony\Component\Yaml\Yaml;

class ConfigService
{
    /**
     * @var string
     */
    private $file;

    /**
     * @var array
     */
    private $config;

    /**
     * @param string $file
     */
    public function setFile($file)
    {
        $this->file = $file;
    }

    /**
     * @return array
     */
    public function loadConfig()
    {
        // First try to read the config from memory.
        if (isset($this->config) && !is_null($this->config)) {
            return $this->config;
        }

        // If it is not in memory, read from the disk.
        if (!file_exists($this->file)) {
            return [];
        }

        $this->config = Yaml::parse(file_get_contents($this->file));
        return $this->config;
    }

    /**
     * @param array $content
     * @return $this
     */
    public function saveConfig($content)
    {
        // Store on disk.
        file_put_contents($this->file, Yaml::dump($content));

        // Also save in memory.
        $this->config = $content;

        return $this;
    }
}
