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
                
                preg_match('/reports\/([a-zA-Z0-9]+)/', $logUrl, $matches);
                if (!$matches) {
                    throw new \Exception("URL de log no válida.");
                }
                $reportId = $matches[1];

                $httpClient = new Client();

                // 1. Obtener Token (Usando el método más compatible)
                $tokenResponse = $httpClient->post("https://www.warcraftlogs.com/oauth/token", [
                    'form_params' => [
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $_ENV['WARCRAFTLOGS_CLIENT_ID'],
                        'client_secret' => $_ENV['WARCRAFTLOGS_CLIENT_SECRET'],
                    ]
                ]);
                $token = json_decode($tokenResponse->getBody())->access_token;

                // 2. Query GraphQL: Pedimos los actores (personajes) y la tabla de buffs
                $query = '
                query($reportId: String!) {
                    reportData {
                        report(code: $reportId) {
                            title
                            masterData {
                                actors(type: "Player") {
                                    id
                                    name
                                    subType
                                }
                            }
                            table(dataType: Buffs, startTime: 0, endTime: 9999999999999)
                        }
                    }
                }';

                $response = $httpClient->post("https://www.warcraftlogs.com/api/v2/client", [
                    'headers' => ['Authorization' => "Bearer $token"],
                    'json' => ['query' => $query, 'variables' => ['reportId' => $reportId]]
                ]);

                $data = json_decode($response->getBody(), true);
                $report = $data['data']['reportData']['report'];
                $actors = $report['masterData']['actors'];
                $auras = $report['table']['data']['auras'] ?? [];

                // 3. Procesar y Cruzar Datos
                $reponseData = self::analyzeConsumables($actors, $auras);

                // 4. Construir Embed
                $embed = [
                    'title' => "Análisis de Consumibles: " . $report['title'],
                    'url' => $logUrl,
                    'color' => 0x3498db,
                    'fields' => [
                        [
                            'name' => "✅ Con Flask o Elixires",
                            'value' => $reponseData['with_buffs'] ?: "Nadie.",
                            'inline' => false
                        ],
                        [
                            'name' => "❌ SIN CONSUMIBLES",
                            'value' => $reponseData['without_buffs'] ?: "¡Todos van full!",
                            'inline' => false
                        ]
                    ],
                    'footer' => ['text' => "TBC Anniversary - WarcraftLogs"]
                ];

                $interaction->updateOriginalResponse(MessageBuilder::new()->addEmbed($embed));

            } catch (\Exception $e) {
                $interaction->updateOriginalResponse(MessageBuilder::new()->setContent("❌ Error: " . $e->getMessage()));
            }
        });
    }

    private static function analyzeConsumables($actors, $auras)
    {
        // Diccionario de IDs de TBC (Flasks y Elixires)
        $ids = [
            // Flasks
            28518 => "Relentless Assault", 28519 => "Blinding Light", 
            28520 => "Pure Death", 28521 => "Mighty Restoration", 28540 => "Fortification",
            // Elixires de Batalla
            28497 => "G. Agility", 28493 => "Major Mageblood", 28503 => "Major Shadow",
            28501 => "Major Fire", 28491 => "Healing", 28488 => "Major Conf.",
            // Elixires Guardianes
            28509 => "G. Defense", 28514 => "Empowerment", 28502 => "Major Armor"
        ];

        $playerConsumes = [];
        // Inicializamos a todos los jugadores como "sin consumibles"
        foreach ($actors as $player) {
            $playerConsumes[$player['id']] = [
                'name' => $player['name'],
                'buffs' => []
            ];
        }

        // Mapeamos los bufos encontrados a los jugadores
        foreach ($auras as $aura) {
            if (isset($ids[$aura['guid']])) {
                foreach ($aura['bands'] as $band) {
                    // WarcraftLogs asocia el aura a los IDs de los actores
                    // Buscamos a qué jugadores pertenece esta aura
                    foreach ($aura['type'] === 'Buff' ? ($aura['sourceIDs'] ?? []) : [] as $sourceId) {
                        if (isset($playerConsumes[$sourceId])) {
                            $playerConsumes[$sourceId]['buffs'][] = $ids[$aura['guid']];
                        }
                    }
                }
            }
        }

        $with = "";
        $without = "";

        foreach ($playerConsumes as $p) {
            if (!empty($p['buffs'])) {
                $buffList = implode(", ", array_unique($p['buffs']));
                $with .= "• **{$p['name']}**: `{$buffList}`\n";
            } else {
                $without .= "• **{$p['name']}**\n";
            }
        }

        return [
            'with_buffs' => $with,
            'without_buffs' => $without
        ];
    }
}