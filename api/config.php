<?php
/**
 * ============================================================================
 * ENTER SOLUTIONS S.A.S. - ECOSISTEMA ENTER-HUB
 * ============================================================================
 * @file        config.php
 * @description Configuración centralizada de entornos (Producción vs Desarrollo/Test)
 * ============================================================================
 */

declare(strict_types=1);

namespace EnterHub\Config;

// Detección automática del entorno mediante variable de entorno o subdominio/host de pruebas
// Si APP_ENV es 'development' o 'test' (o si el host contiene '-test' o 'localhost'), opera en modo test.
$detectedEnv = getenv('APP_ENV');
if (!$detectedEnv) {
    $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'entersolutions.co';
    if (strpos($serverHost, '-test') !== false || strpos($serverHost, 'localhost') !== false || strpos($serverHost, '127.0.0.1') !== false) {
        $detectedEnv = 'development';
    } else {
        $detectedEnv = 'production';
    }
}

define('APP_ENV', $detectedEnv);
define('IS_DEV_MODE', APP_ENV === 'development' || APP_ENV === 'test');

// Definición de Credenciales y Rutas Base
define('ENTER_HUB_API_KEY_PROD', 'ENTER_HUB_API_KEY_2026');
define('ENTER_HUB_API_KEY_TEST', 'ENTER_HUB_API_KEY_TEST_2026');

define('API_KEY_ACTIVA', IS_DEV_MODE ? (getenv('ENTER_HUB_API_KEY') ?: ENTER_HUB_API_KEY_TEST) : (getenv('ENTER_HUB_API_KEY') ?: ENTER_HUB_API_KEY_PROD));
define('LOGS_BASE_PATH', '/home/entersol/logs/');

// URLs de Webhooks adaptadas por entorno (Modo Producción vs Modo Listener Test)
define('N8N_WEBHOOK_URL_PROD', 'https://n8n.entersolutions.co/webhook/enter-hub-router');
define('N8N_WEBHOOK_URL_TEST', 'http://localhost:5678/webhook-test/enter-hub-router');

define('N8N_WEBHOOK_URL_ACTUAL', IS_DEV_MODE 
    ? (getenv('N8N_WEBHOOK_URL') ?: N8N_WEBHOOK_URL_TEST) 
    : (getenv('N8N_WEBHOOK_URL') ?: N8N_WEBHOOK_URL_PROD)
);

// Mapeo de Subdominios y Touchpoints Operativos
class AppConfig {
    public const SUBDOMAINS = [
        'entersolutions.co'             => ['modulo' => 'GLOBAL_HOLDING', 'nombre' => 'Portal Raíz ENTER-HUB'],
        'academia.entersolutions.co'    => ['modulo' => 'HUB-EDU',        'nombre' => 'Academia Técnica In-Company'],
        'consultoria.entersolutions.co' => ['modulo' => 'HUB-OPS',        'nombre' => 'Consultoría Operativa B2B'],
        'ia.entersolutions.co'          => ['modulo' => 'HUB-AI',         'nombre' => 'Agencia de Growth & IA B2B'],
        'suministros.entersolutions.co' => ['modulo' => 'HUB-SUPPLY',     'nombre' => 'Suministros Corporativos B2B']
    ];

    public const TOUCHPOINTS = [
        'HUB-EDU'        => ['correos' => ['contacto@academia.entersolutions.co']],
        'HUB-OPS'        => ['correos' => ['contacto@consultoria.entersolutions.co']],
        'HUB-AI'         => ['correos' => ['proyectos@ia.entersolutions.co']],
        'HUB-SUPPLY'     => ['correos' => ['cotizaciones@suministros.entersolutions.co']],
        'GLOBAL_HOLDING' => ['correos' => ['contacto@entersolutions.co']]
    ];
}