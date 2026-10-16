<?php
/**
 * ============================================================================
 * ENTER SOLUTIONS S.A.S. - ECOSISTEMA ENTER-HUB
 * ============================================================================
 * @file        api-procesar.php
 * @package     EnterHub\Webhooks
 * @version     1.3.0-environment-aware
 * @description Módulo centralizado con soporte multi-entorno (Producción / Test).
 * ============================================================================
 */

declare(strict_types=1);

namespace EnterHub\Webhooks;

use EnterHub\Config\AppConfig;
use Exception;
use Throwable;

// Cargar configuración centralizada de entorno
require_once __DIR__ . '/config.php';

// Desactivar despliegue nativo de errores en HTML
error_reporting(E_ALL);
ini_set('display_errors', IS_DEV_MODE ? '1' : '0');

// Configuración global de encabezados CORS y JSON
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-ENTER-API-KEY, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Capturador Global de Errores Fatales
 */
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        http_response_code(500);
        echo json_encode([
            'status'         => 'error',
            'code'           => 500,
            'message'        => 'Error interno crítico del servidor en tiempo de ejecución.',
            'error_detalle'  => $error['message'],
            'environment'    => \EnterHub\Config\APP_ENV,
            'timestamp'      => date('c')
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
});

final class WebhookProcessor {
    private string $transactionId;
    private string $logRuta;

    public function __construct() {
        // Generación de UUID v4 único para la transacción
        $this->transactionId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $logDir = \EnterHub\Config\LOGS_BASE_PATH;
        $this->logRuta = $logDir . 'webhooks_' . (\EnterHub\Config\APP_ENV) . '_' . date('Y-m') . '.log';
    }

    public function execute(): void {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método HTTP no permitido. Utilice POST.', 405);
            }

            $this->authenticateRequest();

            $rawInput    = (string)file_get_contents('php://input');
            $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
            $payload     = $this->parseInput($rawInput, $contentType);

            // 1. Extracción y Normalización de Identificadores
            $origen = strtolower(trim((string)($payload['origen'] ?? $_SERVER['HTTP_HOST'] ?? 'entersolutions.co')));
            $origenLimpio = rtrim(preg_replace('/^https?:\/\//i', '', $origen), '/');

            $subdomainConfig = AppConfig::SUBDOMAINS[$origenLimpio] ?? AppConfig::SUBDOMAINS['entersolutions.co'];
            $moduleKey       = $subdomainConfig['modulo'];
            
            $touchpointKey   = (string)($payload['touchpoint'] ?? 'contacto_general');
            
            // Normalización del Teléfono como id_cliente maestro (E.164)
            $telefonoRaw     = (string)($payload['telefono'] ?? '');
            $idClienteMaster = $this->normalizarTelefono($telefonoRaw);

            // 2. Construcción del Payload Enriquecido
            $contactData = [
                'transaction_id'    => $this->transactionId,
                'environment'       => \EnterHub\Config\APP_ENV,
                'id_cliente'        => $idClienteMaster,
                'canal_origen'      => $payload['canal_origen'] ?? 'web_landing',
                'origen_dominio'    => $origenLimpio,
                'modulo'            => $moduleKey,
                'subdominio_nombre' => $subdomainConfig['nombre'],
                'touchpoint_clave'  => $touchpointKey,
                'nombre'            => filter_var($payload['nombre'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS),
                'empresa'           => filter_var($payload['empresa'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS),
                'cargo'             => filter_var($payload['cargo'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS),
                'email'             => filter_var($payload['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: ($payload['email'] ?? ''),
                'telefono_raw'      => $telefonoRaw,
                'telefono_e164'     => $idClienteMaster,
                'mensaje'           => filter_var($payload['mensaje'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS),
                'timestamp'         => date('c')
            ];

            // 3. Registro local de respaldo (Log diferenciado por entorno)
            $this->writeLog($contactData);

            // 4. Envío automático hacia n8n (Webhook dinámico: Test o Producción)
            $this->enviarAN8n($contactData);

            // 5. Envío de notificación por correo operativo (respaldo en producción)
            if (!\EnterHub\Config\IS_DEV_MODE) {
                $destinatarios = AppConfig::TOUCHPOINTS[$moduleKey]['correos'] ?? AppConfig::TOUCHPOINTS['GLOBAL_HOLDING']['correos'];
                $this->sendNotificationEmail($destinatarios, $contactData);
            }

            // 6. Respuesta limpia y estandarizada con metadatos de diagnóstico en desarrollo
            $responsePayload = [
                'status'         => 'success',
                'code'           => 200,
                'message'        => 'Lead procesado y enrutado con éxito (' . \EnterHub\Config\APP_ENV . ').',
                'transaction_id' => $this->transactionId,
                'environment'    => \EnterHub\Config\APP_ENV,
                'id_cliente'     => $idClienteMaster,
                'data'           => $contactData
            ];

            if (\EnterHub\Config\IS_DEV_MODE) {
                $responsePayload['debug_info'] = [
                    'n8n_target_url' => \EnterHub\Config\N8N_WEBHOOK_URL_ACTUAL
                ];
            }

            $this->sendResponse(200, $responsePayload);

        } catch (Throwable $e) {
            $httpCode = ($e->getCode() >= 400 && $e->getCode() <= 599) ? (int)$e->getCode() : 500;
            $this->sendResponse($httpCode, [
                'status'         => 'error',
                'code'           => $httpCode,
                'message'        => 'Excepción en api-procesar.',
                'error_detalle'  => $e->getMessage(),
                'environment'    => \EnterHub\Config\APP_ENV,
                'transaction_id' => $this->transactionId,
                'timestamp'      => date('c')
            ]);
        }
    }

    private function normalizarTelefono(string $telefono): string {
        $limpio = preg_replace('/[^\d+]/', '', $telefono);
        if (empty($limpio)) {
            return 'SIN_TEL_' . substr($this->transactionId, 0, 8);
        }
        if ($limpio[0] !== '+') {
            if (strlen($limpio) === 10 && $limpio[0] === '3') {
                $limpio = '+57' . $limpio;
            } else {
                $limpio = '+' . $limpio;
            }
        }
        return $limpio;
    }

    private function authenticateRequest(): void {
        $headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);
        $apiKey = $headers['x-enter-api-key'] ?? null;

        if (!$apiKey && isset($headers['authorization']) && preg_match('/Bearer\s+(.*)$/i', $headers['authorization'], $matches)) {
            $apiKey = $matches[1];
        }

        $expectedKey = \EnterHub\Config\API_KEY_ACTIVA;
        if (!$apiKey || !hash_equals($expectedKey, (string)$apiKey)) {
            throw new Exception('Autenticación fallida: API Key inválida o ausente para el entorno [' . \EnterHub\Config\APP_ENV . '].', 401);
        }
    }

    private function parseInput(string $rawInput, string $contentType): array {
        if (trim($rawInput) === '') throw new Exception('Cuerpo de petición vacío.', 400);
        if (strpos($contentType, 'application/json') !== false) {
            $data = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('JSON malformado.', 400);
            return (array)$data;
        }
        parse_str($rawInput, $data);
        return $data;
    }

    private function writeLog(array $data): void {
        $logDir = \EnterHub\Config\LOGS_BASE_PATH;
        if (!is_dir($logDir) && !@mkdir($logDir, 0755, true)) return;
        @file_put_contents($this->logRuta, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function enviarAN8n(array $data): void {
        $ch = curl_init(\EnterHub\Config\N8N_WEBHOOK_URL_ACTUAL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-ENTER-API-KEY: ' . \EnterHub\Config\API_KEY_ACTIVA
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        @curl_exec($ch);
        @curl_close($ch);
    }

    private function sendNotificationEmail(array $destinatarios, array $data): void {
        $asunto = "Lead [{$data['modulo']}]: {$data['nombre']} ({$data['id_cliente']})";
        $body   = "<h3>Nuevo Lead en ENTER-HUB</h3><p><strong>Módulo:</strong> {$data['subdominio_nombre']}</p><p><strong>Cliente ID (Teléfono):</strong> {$data['id_cliente']}</p><p><strong>Mensaje:</strong> {$data['mensaje']}</p>";
        $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: ENTER-HUB <noreply@entersolutions.co>\r\n";
        foreach ($destinatarios as $dest) { @mail($dest, $asunto, $body, $headers); }
    }

    private function sendResponse(int $statusCode, array $body): void {
        http_response_code($statusCode);
        echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

(new WebhookProcessor())->execute();