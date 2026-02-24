<?php

include __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$token = $_ENV['DISCORD_TOKEN'];

/**
 * NOTA: Tu APPLICATION_ID suele ser la primera parte de tu Token de Discord.
 * Puedes encontrarlo en el Discord Developer Portal -> General Information.
 */
$applicationId = "1443925716717797478"; // <--- CAMBIA ESTO

$url = "https://discord.com/api/v10/applications/{$applicationId}/commands";

$client = new Client();

// Definición de los comandos
$commands = [
    [
        "name" => "gs",
        "description" => "Consulta el GearScore de un personaje de WoW TBC",
        "options" => [
            [
                "name" => "nombre",
                "description" => "Nombre del personaje",
                "type" => 3, // STRING
                "required" => true
            ],
            [
                "name" => "reino",
                "description" => "Nombre del reino (por defecto Nightslayer)",
                "type" => 3, // STRING
                "required" => false
            ]
        ]
    ],
    [
        "name" => "consumibles",
        "description" => "Resumen de Flasks y Elixires usados en un log de WarcraftLogs",
        "options" => [
            [
                "name" => "url",
                "description" => "Enlace completo al reporte de WarcraftLogs",
                "type" => 3, // STRING
                "required" => true
            ]
        ]
    ]
];

echo "Enviando comandos a Discord...\n";

try {
    $response = $client->put($url, [
        'headers' => [
            'Authorization' => "Bot {$token}",
            'Content-Type'  => 'application/json',
        ],
        'json' => $commands
    ]);

    if ($response->getStatusCode() === 200 || $response->getStatusCode() === 201) {
        echo "✅ Comandos registrados con éxito.\n";
        echo "Nota: Si los comandos no aparecen de inmediato, reinicia tu cliente de Discord (Ctrl+R).\n";
    }
} catch (\Exception $e) {
    echo "❌ Error al registrar comandos: " . $e->getMessage() . "\n";
}