<?php

namespace AppBundle\Service;

use Symfony\Component\Yaml\Yaml;

class EnvService
{
    /**
     * @param string $env
     * @param string $file
     * @return array
     * @throws \Exception if env does not exist
     */
    public function loadEnvFromFile($env, $file)
    {
        if (!file_exists($file)) {
            throw new \Exception('Env file not found');
        }

        $content = Yaml::parse(file_get_contents($file));

        if (!isset($content[$env])) {
            throw new \Exception('Env "' . $env . '" not found');
        }

        return $content[$env];
    }
}
