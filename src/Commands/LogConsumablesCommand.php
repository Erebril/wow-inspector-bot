<?php

namespace App\Commands;

use Discord\Parts\Interactions\Interaction;
use Discord\Builders\MessageBuilder;
use GuzzleHttp\Client;

class LogConsumablesCommand
{
    // IDs Corregidos y mapeados
    private static $tbcSpellIds = [
        28520 => "Flask: Relentless Assault",
        28540 => "Flask: Pure Death",
        28521 => "Flask: Blinding Light",
        28519 => "Flask: Mighty Restoration",
        28518 => "Flask: Fortification",
        28491 => "Elixir: Healing Power",
        28497 => "Elixir: G. Agility",
        28503 => "Elixir: Major Shadow",
        28501 => "Elixir: Major Fire",
        28509 => "Elixir: G. Defense"
    ];

    public static function run(Interaction $interaction)
    {
        $interaction->acknowledgeWithResponse(true)->then(function () use ($interaction) {
            try {
                $logUrl = $interaction->data->options['url']->value;
                preg_match('/reports\/([a-zA-Z0-9]+)/', $logUrl, $matches);
                if (!$matches) throw new \Exception("URL de log no válida.");
                $reportId = $matches[1];

                $httpClient = new Client();

                // 1. Obtener Token
                $tokenResponse = $httpClient->post("https://www.warcraftlogs.com/oauth/token", [
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $_ENV['WARCRAFTLOGS_CLIENT_ID'],
                        'client_secret' => $_ENV['WARCRAFTLOGS_CLIENT_SECRET'],
                    ]
                ]);
                $token = json_decode($tokenResponse->getBody())->access_token;

                // 2. Obtener lista de jugadores primero
                $masterQuery = 'query($reportId: String!) {
                    reportData {
                        report(code: $reportId) {
                            title
                            masterData { actors(type: "Player") { id name } }
                        }
                    }
                }';

                $masterRes = $httpClient->post("https://www.warcraftlogs.com/api/v2/client", [
                    'headers' => ['Authorization' => "Bearer $token"],
                    'json' => ['query' => $masterQuery, 'variables' => ['reportId' => $reportId]]
                ]);
                $masterData = json_decode($masterRes->getBody(), true);
                $actors = $masterData['data']['reportData']['report']['masterData']['actors'];
                
                $playerResults = [];
                foreach ($actors as $actor) {
                    $playerResults[$actor['id']] = ['name' => $actor['name'], 'buffs' => []];
                }

                // 3. CONSULTAS POR AURA (El nuevo enfoque)
                // Iteramos los IDs y pedimos la tabla filtrada por cada uno
                foreach (self::$tbcSpellIds as $spellId => $spellName) {
                    $auraQuery = 'query($reportId: String!, $abilityId: Float!) {
                        reportData {
                            report(code: $reportId) {
                                table(dataType: Buffs, startTime: 0, endTime: 9999999999999, abilityID: $abilityId)
                            }
                        }
                    }';

                    $auraRes = $httpClient->post("https://www.warcraftlogs.com/api/v2/client", [
                        'headers' => ['Authorization' => "Bearer $token"],
                        'json' => [
                            'query' => $auraQuery, 
                            'variables' => ['reportId' => $reportId, 'abilityId' => (float)$spellId]
                        ]
                    ]);

                    $auraData = json_decode($auraRes->getBody(), true);
                    $auras = $auraData['data']['reportData']['report']['table']['data']['auras'] ?? [];

                    foreach ($auras as $aura) {
                        // Al filtrar por abilityID, la API devuelve los sourceIDs en 'bands' o 'sourceIDs'
                        $sources = $aura['sourceIDs'] ?? [];
                        if (empty($sources) && isset($aura['bands'])) {
                            foreach ($aura['bands'] as $band) {
                                if (isset($band['sourceID'])) $sources[] = $band['sourceID'];
                            }
                        }

                        foreach (array_unique($sources) as $sId) {
                            if (isset($playerResults[$sId])) {
                                $playerResults[$sId]['buffs'][] = $spellName;
                            }
                        }
                    }
                }

                // 4. Formatear resultados
                $with = ""; $without = "";
                foreach ($playerResults as $p) {
                    if (!empty($p['buffs'])) {
                        $b = implode(", ", array_unique($p['buffs']));
                        $with .= "• **{$p['name']}**: `{$b}`\n";
                    } else {
                        if ($p['name'] !== "Multiple Players") $without .= "• **{$p['name']}**\n";
                    }
                }

                $embed = [
                    'title' => "Consumibles: " . $masterData['data']['reportData']['report']['title'],
                    'url' => $logUrl,
                    'color' => 0x2ecc71,
                    'fields' => [
                        ['name' => "✅ Con Flask / Elixires", 'value' => $with ?: "Nadie.", 'inline' => false],
                        ['name' => "❌ Sin Consumibles", 'value' => $without ?: "¡Todos full!", 'inline' => false]
                    ]
                ];

                $interaction->updateOriginalResponse(MessageBuilder::new()->addEmbed($embed));

            } catch (\Exception $e) {
                $interaction->updateOriginalResponse(MessageBuilder::new()->setContent("❌ Error: " . $e->getMessage()));
            }
        });
    }
}