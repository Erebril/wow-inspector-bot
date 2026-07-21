<?php

namespace App\Commands;

use Discord\Parts\Interactions\Interaction;
use Discord\Builders\MessageBuilder;
use GuzzleHttp\Client;

class PotionsCommand
{
    private static ?array $potionSpellIds = null;

    private static function getPotionSpellIds(): array
    {
        if (self::$potionSpellIds === null) {
            $path = __DIR__ . '/../../consumables.json';
            $data = file_exists($path)
                ? (json_decode(file_get_contents($path), true) ?? [])
                : [];

            self::$potionSpellIds = [];
            if (isset($data['pociones']) && is_array($data['pociones'])) {
                self::$potionSpellIds = $data['pociones'];
            }
        }

        return self::$potionSpellIds;
    }

    public static function run(Interaction $interaction)
    {
        $interaction->acknowledgeWithResponse(true)->then(function () use ($interaction) {
            try {
                $logUrl = $interaction->data->options['url']->value;
                $result = self::analyzeReportByUrl($logUrl);

                $report = $result['report'];
                $players = $result['players'];

                $lines = [];
                foreach ($players as $player) {
                    $details = [];
                    foreach ($player['potionCounts'] as $potionName => $count) {
                        $details[] = "$potionName: $count";
                    }
                    $detailsText = empty($details) ? 'Sin pociones' : implode(' | ', $details);
                    $lines[] = "• **{$player['name']}**: {$detailsText}";
                }

                $embed = [
                    'title' => 'Pociones: ' . $report['title'],
                    'description' => "📊 **Resumen de pociones por jugador**\n" .
                        "Total jugadores analizados: **{$result['totals']['totalPlayers']}**",
                    'url' => $logUrl,
                    'color' => 0x3498db,
                    'fields' => [
                        [
                            'name' => 'Detalle',
                            'value' => empty($lines) ? 'No se encontraron registros.' : implode("\n", $lines),
                            'inline' => false,
                        ],
                    ],
                    'footer' => ['text' => 'Solo considera el dataset "pociones" de consumables.json'],
                ];

                $interaction->updateOriginalResponse(MessageBuilder::new()->addEmbed($embed));
            } catch (\Exception $e) {
                $interaction->updateOriginalResponse(MessageBuilder::new()->setContent('❌ Error: ' . $e->getMessage()));
                file_put_contents(__DIR__ . '/../../logs/potions_errors.log', date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . "\n", FILE_APPEND);
            }
        });
    }

    public static function analyzeReportByUrl(string $logUrl): array
    {
        preg_match('/reports\/([a-zA-Z0-9]+)/', $logUrl, $matches);
        if (!$matches) {
            throw new \Exception('URL de log no válida.');
        }
        $reportId = $matches[1];

        $httpClient = new Client(['timeout' => 45.0]);

        $tokenResponse = $httpClient->post('https://www.warcraftlogs.com/oauth/token', [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $_ENV['WARCRAFTLOGS_CLIENT_ID'],
                'client_secret' => $_ENV['WARCRAFTLOGS_CLIENT_SECRET'],
            ],
        ]);
        $token = json_decode($tokenResponse->getBody())->access_token;

        $query = 'query($reportId: String!) {
            reportData {
                report(code: $reportId) {
                    title
                    masterData { actors(type: "Player") { id name } }
                    dps: table(dataType: DamageDone, startTime: 0, endTime: 9999999999999)
                    hps: table(dataType: Healing, startTime: 0, endTime: 9999999999999)
                }
            }
        }';

        $response = $httpClient->post('https://www.warcraftlogs.com/api/v2/client', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'json' => ['query' => $query, 'variables' => ['reportId' => $reportId]],
        ]);

        $dataJson = json_decode($response->getBody(), true);
        if (!empty($dataJson['errors'])) {
            $firstError = $dataJson['errors'][0]['message'] ?? 'Error desconocido en WarcraftLogs.';
            throw new \Exception($firstError);
        }

        $report = $dataJson['data']['reportData']['report'];

        // Collect active actor IDs from DPS and HPS tables (raid participants)
        $activeIds = [];
        foreach ($report['dps']['data']['entries'] ?? [] as $entry) {
            $activeIds[] = $entry['id'];
        }
        foreach ($report['hps']['data']['entries'] ?? [] as $entry) {
            $activeIds[] = $entry['id'];
        }
        $activeIds = array_unique($activeIds);

        $players = [];
        foreach ($report['masterData']['actors'] as $actor) {
            $players[$actor['id']] = [
                'name' => $actor['name'],
                'potionCounts' => [],
            ];
        }

        foreach (self::getPotionSpellIds() as $spellId => $spellName) {
            $abilityQuery = 'query($reportId: String!, $abilityId: Float!) {
                reportData {
                    report(code: $reportId) {
                        table(dataType: Casts, startTime: 0, endTime: 9999999999999, abilityID: $abilityId)
                    }
                }
            }';

            $abilityResponse = $httpClient->post('https://www.warcraftlogs.com/api/v2/client', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'json' => ['query' => $abilityQuery, 'variables' => ['reportId' => $reportId, 'abilityId' => (float) $spellId]],
            ]);

            $abilityData = json_decode($abilityResponse->getBody(), true);
            if (!empty($abilityData['errors'])) {
                $firstError = $abilityData['errors'][0]['message'] ?? 'Error desconocido en WarcraftLogs.';
                throw new \Exception($firstError);
            }

            $tableData = $abilityData['data']['reportData']['report']['table']['data'] ?? [];

            $entries = $tableData['casts'] ?? $tableData['entries'] ?? $tableData['auras'] ?? [];
            $isAuraFormat = isset($tableData['auras']);

            foreach ($entries as $entry) {
                $playerId = $entry['id'] ?? $entry['sourceID'] ?? $entry['source'] ?? null;
                if ($playerId === null) {
                    continue;
                }

                if (!isset($players[$playerId])) {
                    continue;
                }

                $rawCount = $entry['total'] ?? $entry['totalCasts'] ?? $entry['totalUses'] ?? $entry['uses'] ?? 0;
                $count = (int)$rawCount;
                if ($count > 0) {
                    $players[$playerId]['potionCounts'][$spellName] = $count;
                }
            }
        }

        // Keep only players who are raid participants or who used a potion (non-empty counts)
        $players = array_filter($players, function ($p, $id) use ($activeIds) {
            return !empty($p['potionCounts']) || in_array($id, $activeIds, true);
        }, ARRAY_FILTER_USE_BOTH);

        uasort($players, function ($a, $b) {
            $sumA = array_sum($a['potionCounts']);
            $sumB = array_sum($b['potionCounts']);

            if ($sumA === $sumB) {
                return strcmp($a['name'], $b['name']);
            }

            return $sumB <=> $sumA;
        });

        return [
            'report' => ['title' => $report['title'] ?? 'Sin titulo'],
            'totals' => [
                'totalPlayers' => count($players),
            ],
            'players' => array_values($players),
        ];
    }
}
