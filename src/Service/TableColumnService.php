<?php

namespace App\Service;

class TableColumnService
{
    const CONFIG_KEY = 'tables';
    const COLUMN_KEY = 'columns';
    const SERVICE_KEY = 'service';

    /**
     * @var ConfigService
     */
    private $configService;

    /**
     * @var array
     */
    private $tables;

    /**
     * @param ConfigService $configService
     */
    public function setConfigService(ConfigService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * @param array $tables
     */
    public function setTables($tables)
    {
        $this->tables = $tables;
    }

    /**
     * @param string $table
     * @return array
     * @throws \Exception if table is not found
     */
    public function getColumns($table)
    {
        // First try to read columns from config.
        $config = $this->configService->loadConfig();

        if (isset($config[self::CONFIG_KEY][$table][self::COLUMN_KEY])) {
            return $config[self::CONFIG_KEY][$table][self::COLUMN_KEY];
        }

        // If there are no columns in the config, use the default ones.
        if (!isset($this->tables[$table])) {
            throw new \Exception('Unknown table ' . $table);
        }

        $columns = [];
        foreach ($this->tables[$table][self::COLUMN_KEY] as $column => $details) {
            if (isset($details['default']) && $details['default']) {
                $columns[] = $column;
            }
        }
        return $columns;
    }

    /**
     * @param string $table
     * @param array $columns
     * @return $this
     */
    public function storeColumns($table, $columns)
    {
        $config = $this->configService->loadConfig();
        $config[self::CONFIG_KEY][$table][self::COLUMN_KEY] = $columns;
        $this->configService->saveConfig($config);

        return $this;
    }

    /**
     * @param string $table
     * @return $this
     */
    public function removeColumns($table)
    {
        $config = $this->configService->loadConfig();
        unset($config[self::CONFIG_KEY][$table][self::COLUMN_KEY]);
        $this->configService->saveConfig($config);

        return $this;
    }

    /**
     * @param string $table
     * @return array
     * @throws \Exception if table is not found
     */
    public function getColumnDetails($table)
    {
        // If there are no columns in the config, use the default ones.
        if (!isset($this->tables[$table][self::COLUMN_KEY])) {
            throw new \Exception('Unknown table ' . $table);
        }

        return $this->tables[$table][self::COLUMN_KEY];
    }

    /**
     * @param string $table
     * @return array
     * @throws \Exception if table is not found
     */
    public function getServiceDetails($table)
    {
        // If there are no columns in the config, use the default ones.
        if (!isset($this->tables[$table][self::SERVICE_KEY])) {
            throw new \Exception('Unknown table ' . $table);
        }

        return $this->tables[$table][self::SERVICE_KEY];
    }
}
