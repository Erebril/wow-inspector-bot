<?php

namespace App\Commands;

use Discord\Parts\Interactions\Interaction;
use Discord\Builders\MessageBuilder;
use GuzzleHttp\Client;

class LogConsumablesCommand
{
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
        // 1. Acknowledge inmediato para evitar el timeout de 3 segundos
        $interaction->acknowledgeWithResponse(true)->then(function () use ($interaction) {
            try {
                $logUrl = $interaction->data->options['url']->value;
                preg_match('/reports\/([a-zA-Z0-9]+)/', $logUrl, $matches);
                if (!$matches) throw new \Exception("URL de log no válida.");
                $reportId = $matches[1];

                $httpClient = new Client(['timeout' => 30.0]); // Timeout extendido para Guzzle

                // 2. Obtener Token
                $tokenResponse = $httpClient->post("https://www.warcraftlogs.com/oauth/token", [
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $_ENV['WARCRAFTLOGS_CLIENT_ID'],
                        'client_secret' => $_ENV['WARCRAFTLOGS_CLIENT_SECRET'],
                    ]
                ]);
                $token = json_decode($tokenResponse->getBody())->access_token;

                // 3. Obtener Actores (Jugadores)
                $qActors = 'query($reportId: String!) { reportData { report(code: $reportId) { title masterData { actors(type: "Player") { id name } } } } }';
                $resActors = $httpClient->post("https://www.warcraftlogs.com/api/v2/client", [
                    'headers' => ['Authorization' => "Bearer $token"],
                    'json' => ['query' => $qActors, 'variables' => ['reportId' => $reportId]]
                ]);
                $masterData = json_decode($resActors->getBody(), true)['data']['reportData']['report'];
                
                $playerResults = [];
                foreach ($masterData['masterData']['actors'] as $actor) {
                    $playerResults[$actor['id']] = ['name' => $actor['name'], 'buffs' => []];
                }

                // 4. Escaneo por AbilityID (Igual que en el test)
                foreach (self::$tbcSpellIds as $spellId => $spellName) {
                    $qAura = 'query($reportId: String!, $abilityId: Float!) {
                        reportData { report(code: $reportId) {
                            table(dataType: Buffs, startTime: 0, endTime: 9999999999999, abilityID: $abilityId)
                        } }
                    }';

                    $resAura = $httpClient->post("https://www.warcraftlogs.com/api/v2/client", [
                        'headers' => ['Authorization' => "Bearer $token"],
                        'json' => [
                            'query' => $qAura, 
                            'variables' => ['reportId' => $reportId, 'abilityId' => (float)$spellId]
                        ]
                    ]);

                    $auraData = json_decode($resAura->getBody(), true);
                    $aurasEncontradas = $auraData['data']['reportData']['report']['table']['data']['auras'] ?? [];

                    foreach ($aurasEncontradas as $aura) {
                        // LA CORRECCIÓN CLAVE: Usar el ID del objeto aura como ID del jugador
                        $pId = $aura['id']; 
                        if (isset($playerResults[$pId])) {
                            $playerResults[$pId]['buffs'][] = $spellName;
                        }
                    }
                }

                // 5. Formatear resultados
                $with = ""; $without = "";
                foreach ($playerResults as $p) {
                    if (!empty($p['buffs'])) {
                        $b = implode(", ", array_unique($p['buffs']));
                        $with .= "• **{$p['name']}**: `{$b}`\n";
                    } else {
                        if ($p['name'] !== "Multiple Players") {
                            $without .= "• **{$p['name']}**\n";
                        }
                    }
                }

                // Si el mensaje es muy largo, Discord lo rechazará (límite 4096 caracteres)
                $with = strlen($with) > 1000 ? substr($with, 0, 1000) . "..." : $with;
                $without = strlen($without) > 1000 ? substr($without, 0, 1000) . "..." : $without;

                $embed = [
                    'title' => "Consumibles: " . $masterData['title'],
                    'url' => $logUrl,
                    'color' => 0x2ecc71,
                    'fields' => [
                        ['name' => "✅ Con Flask / Elixires", 'value' => $with ?: "Nadie.", 'inline' => false],
                        ['name' => "❌ Sin Consumibles", 'value' => $without ?: "¡Todos full!", 'inline' => false]
                    ]
                ];

                $interaction->updateOriginalResponse(MessageBuilder::new()->addEmbed($embed));

            } catch (\Exception $e) {
                // Si algo falla, al menos enviamos el error a Discord para saber qué pasó
                $interaction->updateOriginalResponse(MessageBuilder::new()->setContent("❌ Error: " . $e->getMessage()));
            }
        });
    }
}