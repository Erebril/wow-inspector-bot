<?php

namespace App\Commands;

use Discord\Discord;
use Discord\Parts\Interactions\Interaction;
use Discord\Builders\MessageBuilder;
use GuzzleHttp\Client;

class LogConsumablesCommand
{
    public static function run(Interaction $interaction)
    {
        $interaction->acknowledgeWithResponse(true)->then(function () use ($interaction) {
            try {
                $logUrl = $interaction->data->options['url']->value;
                
                // 1. Extraer el Report ID de la URL
                // Ejemplo: https://www.warcraftlogs.com/reports/A1b2C3d4E5f6G7h8
                preg_match('/reports\/([a-zA-Z0-9]+)/', $logUrl, $matches);
                if (!$matches) {
                    throw new \Exception("URL de log no válida. Asegúrate de que sea un enlace de WarcraftLogs.");
                }
                $reportId = $matches[1];

                $httpClient = new Client();

                // 2. Obtener Token de WarcraftLogs
                $tokenResponse = $httpClient->post("https://www.warcraftlogs.com/oauth/token", [
                    'auth' => [$_ENV['WARCRAFTLOGS_CLIENT_ID'], $_ENV['WARCRAFTLOGS_CLIENT_SECRET']],
                    'form_params' => ['grant_type' => 'client_credentials']
                ]);
                $token = json_decode($tokenResponse->getBody())->access_token;

                // 3. Query GraphQL para obtener consumibles (Buffs)
                // Buscamos en el "table" de buffs del reporte completo
                $query = '
                query($reportId: String!) {
                    reportData {
                        report(code: $reportId) {
                            title
                            table(dataType: Buffs, startTime: 0, endTime: 9999999999999)
                        }
                    }
                }';

                $response = $httpClient->post("https://www.warcraftlogs.com/api/v2/client", [
                    'headers' => ['Authorization' => "Bearer $token"],
                    'json' => [
                        'query' => $query,
                        'variables' => ['reportId' => $reportId]
                    ]
                ]);

                $data = json_decode($response->getBody(), true);
                $report = $data['data']['reportData']['report'];
                $buffs = $report['table']['data']['auras'] ?? [];

                // 4. Filtrar Flasks y Elixires de TBC
                $reportSummary = self::processConsumables($buffs);

                $embed = [
                    'title' => "Consumibles: " . $report['title'],
                    'url' => $logUrl,
                    'color' => 0x00ff00,
                    'description' => "Resumen de Flasks y Elixires detectados en el log:",
                    'fields' => [
                        [
                            'name' => "🧪 Jugadores con Flask/Elixires",
                            'value' => $reportSummary ?: "No se detectaron consumibles típicos.",
                            'inline' => false
                        ]
                    ],
                    'footer' => ['text' => "WarcraftLogs API v2 | TBC Anniversary"]
                ];

                $interaction->updateOriginalResponse(MessageBuilder::new()->addEmbed($embed));

            } catch (\Exception $e) {
                $interaction->updateOriginalResponse(MessageBuilder::new()->setContent("❌ Error: " . $e->getMessage()));
            }
        });
    }

    private static function processConsumables($auras)
    {
        $players = [];

        // IDs de ejemplo de TBC (Flasks y Elixires comunes)
        // Puedes ampliar esta lista con los IDs exactos de TBC
        $consumableIds = [
            28518 => "Flask of Relentless Assault",
            28519 => "Flask of Blinding Light",
            28520 => "Flask of Pure Death",
            28521 => "Flask of Mighty Restoration",
            28540 => "Flask of Fortification",
            // Elixires (Battle/Guardian)
            28497 => "Elixir of Greater Agility",
            28491 => "Healing Elixir",
            // ... añadir más según sea necesario
        ];

        foreach ($auras as $aura) {
            if (isset($consumableIds[$aura['guid']])) {
                foreach ($aura['bands'] as $band) {
                    // Aquí simplificamos: si el jugador aparece en el aura, lo contamos
                    // En un sistema real, podrías calcular el % de uptime
                    $playerName = $aura['name'] . " (" . $consumableIds[$aura['guid']] . ")";
                    
                    // Nota: WarcraftLogs devuelve quién tuvo el bufo en 'bands'
                    // Para este ejemplo, listaremos los consumibles activos encontrados
                }
                $players[] = "• **" . $aura['name'] . "**";
            }
        }

        // Eliminamos duplicados y unimos
        return implode("\n", array_unique($players));
    }
}