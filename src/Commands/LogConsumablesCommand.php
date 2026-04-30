<?php

namespace App\Commands;

use Discord\Parts\Interactions\Interaction;
use Discord\Builders\MessageBuilder;
use GuzzleHttp\Client;

class LogConsumablesCommand
{
    private static ?array $tbcSpellIds = null;

    private static function getSpellIds(): array
    {
        if (self::$tbcSpellIds === null) {
            $path = __DIR__ . '/../../consumables.json';
            self::$tbcSpellIds = file_exists($path)
                ? (json_decode(file_get_contents($path), true) ?? [])
                : [];
        }
        return self::$tbcSpellIds;
    }

    public static function run(Interaction $interaction)
    {
        $interaction->acknowledgeWithResponse(true)->then(function () use ($interaction) {
            try {
                $logUrl = $interaction->data->options['url']->value;
                $result = self::analyzeReportByUrl($logUrl);
                $report = $result['report'];
                $totals = $result['totals'];
                $playersWith = $result['playersWithConsumables'];
                $playersWithout = $result['playersWithoutConsumables'];

                $with = '';
                foreach ($playersWith as $p) {
                    $buffs = implode(', ', $p['buffs']);
                    $with .= "• **{$p['name']}**: `{$buffs}`\n";
                }

                $without = '';
                foreach ($playersWithout as $name) {
                    $without .= "• **{$name}**\n";
                }

                $embed = [
                    'title' => "Reporte: " . $report['title'],
                    'description' => "📊 **Resumen de Raid:**\n" .
                        "Total Participantes: **{$totals['totalPlayers']}**\n" .
                        "✅ Con consumibles: **{$totals['withConsumables']}**\n" .
                        "❌ Sin consumibles: **{$totals['withoutConsumables']}**",
                    'url' => $logUrl,
                    'color' => ($totals['withoutConsumables'] > 0) ? 0xe74c3c : 0x2ecc71,
                    'fields' => [
                        ['name' => "✅ PREPARADOS ({$totals['withConsumables']})", 'value' => $with ?: "Nadie.", 'inline' => false],
                        ['name' => "❌ SIN NADA ({$totals['withoutConsumables']})", 'value' => $without ?: "¡Todos listos!", 'inline' => false]
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

    public static function analyzeReportByUrl(string $logUrl): array
    {
        preg_match('/reports\/([a-zA-Z0-9]+)/', $logUrl, $matches);
        if (!$matches) {
            throw new \Exception("URL de log no válida.");
        }
        $reportId = $matches[1];

        $httpClient = new Client(['timeout' => 45.0]);

        $tokenResponse = $httpClient->post("https://www.warcraftlogs.com/oauth/token", [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $_ENV['WARCRAFTLOGS_CLIENT_ID'],
                'client_secret' => $_ENV['WARCRAFTLOGS_CLIENT_SECRET'],
            ]
        ]);
        $token = json_decode($tokenResponse->getBody())->access_token;

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
        if (!empty($dataJson['errors'])) {
            $firstError = $dataJson['errors'][0]['message'] ?? 'Error desconocido en WarcraftLogs.';
            throw new \Exception($firstError);
        }

        $report = $dataJson['data']['reportData']['report'];

        $activeIds = [];
        foreach ($report['dps']['data']['entries'] ?? [] as $entry) {
            $activeIds[] = $entry['id'];
        }
        foreach ($report['hps']['data']['entries'] ?? [] as $entry) {
            $activeIds[] = $entry['id'];
        }
        $activeIds = array_unique($activeIds);

        $playerResults = [];
        foreach ($report['masterData']['actors'] as $actor) {
            if (in_array($actor['id'], $activeIds, true)) {
                $playerResults[$actor['id']] = ['name' => $actor['name'], 'buffs' => []];
            }
        }

        foreach (self::getSpellIds() as $spellId => $spellName) {
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
            if (!empty($auraData['errors'])) {
                $firstError = $auraData['errors'][0]['message'] ?? 'Error desconocido en WarcraftLogs.';
                throw new \Exception($firstError);
            }

            $auras = $auraData['data']['reportData']['report']['table']['data']['auras'] ?? [];

            foreach ($auras as $aura) {
                $pId = $aura['id'];
                if (isset($playerResults[$pId])) {
                    $playerResults[$pId]['buffs'][] = $spellName;
                }
            }
        }

        $playersWithConsumables = [];
        $playersWithoutConsumables = [];

        foreach ($playerResults as $player) {
            $uniqueBuffs = array_values(array_unique($player['buffs']));
            sort($uniqueBuffs);

            if (!empty($uniqueBuffs)) {
                $playersWithConsumables[] = [
                    'name' => $player['name'],
                    'buffs' => $uniqueBuffs,
                ];
            } else {
                $playersWithoutConsumables[] = $player['name'];
            }
        }

        usort($playersWithConsumables, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        sort($playersWithoutConsumables);

        return [
            'report' => ['title' => $report['title'] ?? 'Sin titulo'],
            'totals' => [
                'totalPlayers' => count($playerResults),
                'withConsumables' => count($playersWithConsumables),
                'withoutConsumables' => count($playersWithoutConsumables),
            ],
            'playersWithConsumables' => $playersWithConsumables,
            'playersWithoutConsumables' => $playersWithoutConsumables,
        ];
    }
}
