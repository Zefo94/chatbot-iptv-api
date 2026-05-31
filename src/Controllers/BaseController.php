<?php

namespace App\Controllers;

use App\Services\LoggerService;
use Exception;

/**
 * Base Controller with validation and API response helpers
 */
abstract class BaseController
{
    /**
     * Decode and retrieve incoming JSON request payload
     * 
     * @return array
     */
    protected function getRequestData(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return $_POST; // Fallback to standard POST form params
        }
        
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Standardized JSON Response sender
     * 
     * @param array $payload
     * @param int $statusCode
     */
    protected function json(array $payload, int $statusCode = 200): void
    {
        // Enforce headers
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($statusCode);
        
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit; // Terminate execution to prevent trailing output corruption
    }

    /**
     * Success JSON standard response format
     * 
     * @param string $message
     * @param array $data
     * @param int $status
     */
    protected function success(string $message = 'Operación exitosa', array $data = [], int $status = 200): void
    {
        $this->json([
            'success' => true,
            'message' => $message,
            'data'    => $data
        ], $status);
    }

    /**
     * Error JSON standard response format
     * 
     * @param string $message
     * @param int $status
     * @param array $errors
     */
    protected function error(string $message, int $status = 400, array $errors = []): void
    {
        $payload = [
            'success' => false,
            'message' => $message
        ];

        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }

        $this->json($payload, $status);
    }

    /**
     * Standard input validation rules
     * 
     * Rules format: ['field_name' => 'required|integer|string']
     * 
     * @param array $data
     * @param array $rules
     * @throws Exception
     */
    protected function validate(array $data, array $rules): void
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $rulesArray = explode('|', $ruleString);
            $hasField = array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '';

            foreach ($rulesArray as $rule) {
                if ($rule === 'required' && !$hasField) {
                    $errors[$field][] = "El campo {$field} es requerido.";
                    continue 2; // Skip other rules for this field
                }

                if ($hasField) {
                    if ($rule === 'integer' && !is_numeric($data[$field])) {
                        $errors[$field][] = "El campo {$field} debe ser un número entero.";
                    }
                    if ($rule === 'string' && !is_string($data[$field])) {
                        $errors[$field][] = "El campo {$field} debe ser una cadena de texto.";
                    }
                    if ($rule === 'numeric' && !is_numeric($data[$field])) {
                        $errors[$field][] = "El campo {$field} debe ser un valor numérico.";
                    }
                }
            }
        }

        if (!empty($errors)) {
            // Render beautiful structured error payload
            $this->error("Error en las validaciones de entrada.", 422, $errors);
        }
    }
}
