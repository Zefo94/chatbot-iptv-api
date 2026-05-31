<?php

namespace App\Services;

use App\Database\Connection;
use PDO;
use Exception;

/**
 * High-performance System Logging and Database Audit Service
 */
class LoggerService
{
    private static ?string $logFile = null;

    /**
     * Get path to physical log file
     */
    private static function getLogFile(): string
    {
        if (self::$logFile === null) {
            self::$logFile = dirname(__DIR__, 2) . '/storage/logs/app.log';
            
            $logDir = dirname(self::$logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
        }
        return self::$logFile;
    }

    /**
     * Logs message to storage/logs/app.log
     * 
     * @param string $message
     * @param string $level (info|warning|error|debug)
     */
    public static function logFile(string $message, string $level = 'info'): void
    {
        $logPath = self::getLogFile();
        $timestamp = date('Y-m-d H:i:s');
        $formatted = sprintf("[%s] [%s]: %s%s", $timestamp, strtoupper($level), $message, PHP_EOL);
        
        // Append log, suppress warnings if directory lacks permissions (fails gracefully)
        @file_put_contents($logPath, $formatted, FILE_APPEND | LOCK_EX);
    }

    /**
     * Audits chatbot operations directly to the DB logs table
     * 
     * @param string $action
     * @param array|object|string|null $requestData
     * @param array|object|string|null $responseData
     * @return bool
     */
    public static function logAction(string $action, mixed $requestData, mixed $responseData): bool
    {
        try {
            $db = Connection::getInstance();
            
            $reqJson = is_string($requestData) ? $requestData : json_encode($requestData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $resJson = is_string($responseData) ? $responseData : json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $stmt = $db->prepare("
                INSERT INTO `logs` (`accion`, `request_json`, `response_json`, `created_at`) 
                VALUES (:action, :req, :res, NOW())
            ");
            
            return $stmt->execute([
                ':action' => $action,
                ':req'    => $reqJson,
                ':res'    => $resJson
            ]);
        } catch (Exception $e) {
            // Fallback to local files if database log insert fails
            self::logFile("Failed database log audit for action: {$action}. Error: " . $e->getMessage(), 'error');
            return false;
        }
    }
}
