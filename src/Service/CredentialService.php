<?php

namespace App\Service;

class CredentialService
{
    const CONFIG_KEY = 'credentials';

    /**
     * @var ConfigService
     */
    private $configService;

    /**
     * @param ConfigService $configService
     */
    public function setConfigService(ConfigService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * @param string $email
     * @param string $password
     * @param string $apiUri
     */
    public function storeCredentials($email, $password, $apiUri)
    {
        $config = $this->configService->loadConfig();
        $config[self::CONFIG_KEY] = [
            'base_uri' => $apiUri,
            'email'    => $email,
            'password' => $password
        ];
        $this->configService->saveConfig($config);
    }

    /**
     * @return $this
     */
    public function removeCredentials()
    {
        $config = $this->configService->loadConfig();
        unset($config[self::CONFIG_KEY]);
        $this->configService->saveConfig($config);

        return $this;
    }

    /**
     * @return bool
     */
    public function hasCredentials()
    {
        $config = $this->configService->loadConfig();
        return isset($config[self::CONFIG_KEY]);
    }

    /**
     * @return mixed
     */
    public function getCredentials()
    {
        $config = $this->configService->loadConfig();
        return $config[self::CONFIG_KEY];
    }
}
