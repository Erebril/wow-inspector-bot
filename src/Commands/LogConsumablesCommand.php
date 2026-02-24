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
        $interaction->acknowledgeWithResponse(true)->then(function () use ($interaction) {
            try {
                $logUrl = $interaction->data->options['url']->value;
                preg_match('/reports\/([a-zA-Z0-9]+)/', $logUrl, $matches);
                if (!$matches) throw new \Exception("URL de log no válida.");
                $reportId = $matches[1];

                $httpClient = new Client(['timeout' => 45.0]);

                // 1. Token
                $tokenResponse = $httpClient->post("https://www.warcraftlogs.com/oauth/token", [
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $_ENV['WARCRAFTLOGS_CLIENT_ID'],
                        'client_secret' => $_ENV['WARCRAFTLOGS_CLIENT_SECRET'],
                    ]
                ]);
                $token = json_decode($tokenResponse->getBody())->access_token;

                // 2. Obtener Participantes Reales (DPS + Healers)
                $qPlayers = 'query($reportId: String!) { 
                    reportData { 
                        report(code: $reportId) { 
                            title 
                            masterData { actors(type: "Player") { id name } }
                            dps: table(dataType: DamageDone, startTime: 0, endTime: 9999999999999)
                            hps: table(dataType: Healing, startTime: 0, endTime: 9999999999999)
                        } 
                    } 
                }';

                $resPlayers = $httpClient->post("https://www.warcraftlogs.com/api/v2/client", [
                    'headers' => ['Authorization' => "Bearer $token"],
                    'json' => ['query' => $qPlayers, 'variables' => ['reportId' => $reportId]]
                ]);
                
                $dataJson = json_decode($resPlayers->getBody(), true);
                $report = $dataJson['data']['reportData']['report'];
                
                // Mapear quién participó de verdad
                $activeIds = [];
                // Sacar de la tabla de daño
                foreach ($report['dps']['data']['entries'] ?? [] as $entry) $activeIds[] = $entry['id'];
                // Sacar de la tabla de sanación (para no olvidar healers)
                foreach ($report['hps']['data']['entries'] ?? [] as $entry) $activeIds[] = $entry['id'];
                
                $activeIds = array_unique($activeIds);

                $playerResults = [];
                foreach ($report['masterData']['actors'] as $actor) {
                    if (in_array($actor['id'], $activeIds)) {
                        $playerResults[$actor['id']] = ['name' => $actor['name'], 'buffs' => []];
                    }
                }

                // 3. Escaneo de Auras (Lógica AbilityID que funciona)
                foreach (self::$tbcSpellIds as $spellId => $spellName) {
                    $qAura = 'query($reportId: String!, $abilityId: Float!) {
                        reportData { report(code: $reportId) {
                            table(dataType: Buffs, startTime: 0, endTime: 9999999999999, abilityID: $abilityId)
                        } }
                    }';

                    $resAura = $httpClient->post("https://www.warcraftlogs.com/api/v2/client", [
                        'headers' => ['Authorization' => "Bearer $token"],
                        'json' => ['query' => $qAura, 'variables' => ['reportId' => $reportId, 'abilityId' => (float)$spellId]]
                    ]);

                    $auraData = json_decode($resAura->getBody(), true);
                    $auras = $auraData['data']['reportData']['report']['table']['data']['auras'] ?? [];

                    foreach ($auras as $aura) {
                        $pId = $aura['id']; // ID del jugador en esta vista
                        if (isset($playerResults[$pId])) {
                            $playerResults[$pId]['buffs'][] = $spellName;
                        }
                    }
                }

                // 4. Estadísticas y Formateo
                $with = ""; $without = "";
                $cWith = 0; $cWithout = 0;

                foreach ($playerResults as $p) {
                    if (!empty($p['buffs'])) {
                        $cWith++;
                        $buffs = implode(", ", array_unique($p['buffs']));
                        $with .= "• **{$p['name']}**: `{$buffs}`\n";
                    } else {
                        $cWithout++;
                        $without .= "• **{$p['name']}**\n";
                    }
                }

                $total = $cWith + $cWithout;

                $embed = [
                    'title' => "Reporte: " . $report['title'],
                    'description' => "📊 **Resumen de Raid:**\n" .
                                     "Total Participantes: **{$total}**\n" .
                                     "✅ Con consumibles: **{$cWith}**\n" .
                                     "❌ Sin consumibles: **{$cWithout}**",
                    'url' => $logUrl,
                    'color' => ($cWithout > 0) ? 0xe74c3c : 0x2ecc71,
                    'fields' => [
                        ['name' => "✅ PREPARADOS ($cWith)", 'value' => $with ?: "Nadie.", 'inline' => false],
                        ['name' => "❌ SIN NADA ($cWithout)", 'value' => $without ?: "¡Todos listos!", 'inline' => false]
                    ],
                    'footer' => ['text' => "Solo incluye jugadores con actividad (DPS/Heal)"]
                ];

                $interaction->updateOriginalResponse(MessageBuilder::new()->addEmbed($embed));

            } catch (\Exception $e) {
                $interaction->updateOriginalResponse(MessageBuilder::new()->setContent("❌ Error: " . $e->getMessage()));
                //save error log
                file_put_contents(__DIR__ . '/../../logs/consumables_errors.log', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", FILE_APPEND);
            }
        });
    }
}