<?php

namespace App\Database;

use PDO;
use Exception;

/**
 * Singleton Database Connection Wrapper using PDO
 */
class Connection
{
    private static ?PDO $instance = null;

    /**
     * Get the active PDO instance
     * 
     * @return PDO
     * @throws Exception
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $configPath = dirname(__DIR__, 2) . '/config/database.php';
            
            if (!file_exists($configPath)) {
                throw new Exception("Database configuration file not found at " . $configPath);
            }

            $config = require $configPath;

            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );

            try {
                self::$instance = new PDO(
                    $dsn,
                    $config['username'],
                    $config['password'],
                    $config['options']
                );
            } catch (\PDOException $e) {
                throw new Exception("Database connection failure: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        return self::$instance;
    }

    /**
     * Prevent cloning or unserializing
     */
    private function __construct() {}
    private function __clone() {}
    public function __wakeup() {}
}
