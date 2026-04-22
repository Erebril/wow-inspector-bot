<?php

namespace App\Commands;

use Discord\Parts\Interactions\Interaction;
use Discord\Builders\MessageBuilder;
use GuzzleHttp\Client;

class GearIssueCommand
{
    private const UNCUT_GEM_IDS = [
        '23112', '23436', '23077', '23441', '23440', '23117', '23438', '23437', '23107',
        '23079', '21929', '23439', '32227', '32229', '32228', '32231', '32249', '32230'
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

                // 1. Obtener Token de WarcraftLogs
                $tokenResponse = $httpClient->post("https://www.warcraftlogs.com/oauth/token", [
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $_ENV['WARCRAFTLOGS_CLIENT_ID'],
                        'client_secret' => $_ENV['WARCRAFTLOGS_CLIENT_SECRET'],
                    ]
                ]);
                $token = json_decode($tokenResponse->getBody())->access_token;

                // 2. Query GraphQL para obtener detalles de jugadores y su equipo
                // Usamos dataType: Summary para obtener playerDetails que incluye gemas/enchants
                $query = 'query($reportId: String!) { 
                    reportData { 
                        report(code: $reportId) { 
                            title 
                            table(dataType: Summary, startTime: 0, endTime: 9999999999999)
                        } 
                    } 
                }';

                $response = $httpClient->post("https://www.warcraftlogs.com/api/v2/client", [
                    'headers' => ['Authorization' => "Bearer $token"],
                    'json' => ['query' => $query, 'variables' => ['reportId' => $reportId]]
                ]);

                $dataJson = json_decode($response->getBody(), true);
                if (!empty($dataJson['errors'])) {
                    $firstError = $dataJson['errors'][0]['message'] ?? 'Error desconocido en WarcraftLogs.';
                    throw new \Exception($firstError);
                }

                $report = $dataJson['data']['reportData']['report'];
                $tableData = $report['table']['data'] ?? [];
                if (is_string($tableData)) {
                    $tableData = json_decode($tableData, true) ?? [];
                }

                $playerDetails = $tableData['playerDetails'] ?? [];

                $roles = ['dps', 'healers', 'tanks'];
                $playersWithIssues = [];
                $totalPlayers = 0;
                $totals = [
                    'missingEnchants' => 0,
                    'badEnchants' => 0,
                    'missingGems' => 0,
                    'badGems' => 0,
                    'uncutGems' => 0,
                ];

                foreach ($roles as $role) {
                    foreach ($playerDetails[$role] ?? [] as $player) {
                        $totalPlayers++;
                        $analysis = self::analyzePlayer($player, $role);

                        $totals['missingEnchants'] += $analysis['missingEnchants'];
                        $totals['badEnchants'] += $analysis['badEnchants'];
                        $totals['missingGems'] += $analysis['missingGems'];
                        $totals['badGems'] += $analysis['badGems'];
                        $totals['uncutGems'] += $analysis['uncutGems'];

                        if ($analysis['totalIssues'] > 0) {
                            $playersWithIssues[] = $analysis;
                        }
                    }
                }

                usort($playersWithIssues, function ($a, $b) {
                    return $b['totalIssues'] <=> $a['totalIssues'];
                });

                $playersWithIssuesCount = count($playersWithIssues);
                $playersOk = max(0, $totalPlayers - $playersWithIssuesCount);

                $summaryByPlayer = "";
                $detailLines = "";
                foreach (array_slice($playersWithIssues, 0, 15) as $entry) {
                    $summaryByPlayer .= "• **{$entry['name']}** ({$entry['role']}): " .
                        "E{$entry['missingEnchants']} | EB{$entry['badEnchants']} | G{$entry['missingGems']} | BG{$entry['badGems']} | UC{$entry['uncutGems']}\n";

                    $firstIssue = $entry['issues'][0] ?? null;
                    if ($firstIssue) {
                        $detailLines .= "• **{$entry['name']}**: {$firstIssue}\n";
                    }
                }

                if ($summaryByPlayer === '') {
                    $summaryByPlayer = "Sin problemas detectados en el resumen de equipo.";
                }

                if ($detailLines === '') {
                    $detailLines = "No hay detalle adicional.";
                }

                $legend = "E=Sin enchant | EB=Enchant malo (slot/clase) | G=Sin gema | BG=Gema baja calidad | UC=Gema sin cortar";

                $embed = [
                    'title' => "Análisis de Equipo: " . $report['title'],
                    'description' =>
                        "📊 **Resumen de Issues (basado en GearIssues):**\n" .
                        "Total jugadores: **{$totalPlayers}**\n" .
                        "✅ Sin issues: **{$playersOk}**\n" .
                        "⚠️ Con issues: **{$playersWithIssuesCount}**",
                    'url' => $logUrl,
                    'color' => ($playersWithIssuesCount > 0) ? 0xe67e22 : 0x2ecc71,
                    'fields' => [
                        [
                            'name' => "🧩 Comparaciones",
                            'value' =>
                                "• Sin enchant en slots relevantes: **{$totals['missingEnchants']}**\n" .
                                "• Encantamientos malos (slot/clase): **{$totals['badEnchants']}**\n" .
                                "• Gemas faltantes: **{$totals['missingGems']}**\n" .
                                "• Gemas de baja calidad: **{$totals['badGems']}**\n" .
                                "• Gemas sin cortar: **{$totals['uncutGems']}**\n\n" .
                                $legend,
                            'inline' => false
                        ],
                        [
                            'name' => "👥 Top jugadores con issues",
                            'value' => self::truncateFieldValue($summaryByPlayer),
                            'inline' => false
                        ],
                        [
                            'name' => "🔎 Primer issue detectado por jugador",
                            'value' => self::truncateFieldValue($detailLines),
                            'inline' => false
                        ]
                    ],
                    'footer' => ['text' => "WarcraftLogs API v2 | Reglas resumidas desde GearIssues.gs"]
                ];

                $interaction->updateOriginalResponse(MessageBuilder::new()->addEmbed($embed));

            } catch (\Exception $e) {
                $interaction->updateOriginalResponse(MessageBuilder::new()->setContent("❌ Error: " . $e->getMessage()));
                file_put_contents(
                    __DIR__ . '/../../logs/gear_issue_errors.log', 
                    date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", 
                    FILE_APPEND
                );
            }
        });
    }

    private static function analyzePlayer(array $player, string $role): array
    {
        $name = $player['name'] ?? 'Desconocido';
        $gear = $player['combatantInfo']['gear'] ?? $player['gear'] ?? [];

        $missingEnchants = 0;
        $badEnchants = 0;
        $missingGems = 0;
        $badGems = 0;
        $uncutGems = 0;
        $issues = [];
        $playerClass = self::getPlayerClass($player);

        foreach ($gear as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemName = $item['name'] ?? ('Item ' . ($item['id'] ?? '?'));
            $slot = isset($item['slot']) ? (string)$item['slot'] : '';

            if (self::isEnchantableSlot($slot)) {
                $permanentEnchant = $item['permanentEnchant'] ?? $item['enchant'] ?? null;
                if ($permanentEnchant === null || (string)$permanentEnchant === '' || (string)$permanentEnchant === '0') {
                    $missingEnchants++;
                    $issues[] = "Sin enchant en {$itemName}";
                } elseif (self::isBadEnchantForClass((string)$permanentEnchant, $playerClass, false)) {
                    $badEnchants++;
                    $issues[] = "Enchant incorrecto para {$playerClass} en {$itemName} (ID {$permanentEnchant})";
                }
            }

            $temporaryEnchant = $item['temporaryEnchant'] ?? null;
            if ($temporaryEnchant !== null && (string)$temporaryEnchant !== '' && (string)$temporaryEnchant !== '0') {
                if (self::isBadEnchantForClass((string)$temporaryEnchant, $playerClass, true)) {
                    $badEnchants++;
                    $issues[] = "Enchant temporal incorrecto para {$playerClass} en {$itemName} (ID {$temporaryEnchant})";
                }
            }

            $expectedSockets = self::getExpectedSockets($item);
            $gems = is_array($item['gems'] ?? null) ? $item['gems'] : [];
            $actualGems = count($gems);

            if ($expectedSockets > 0 && $actualGems < $expectedSockets) {
                $missing = $expectedSockets - $actualGems;
                $missingGems += $missing;
                $issues[] = "{$itemName} con {$missing} gema(s) faltante(s)";
            }

            foreach ($gems as $gem) {
                $gemId = (string)($gem['id'] ?? '');
                $gemItemLevel = isset($gem['itemLevel']) ? (int)$gem['itemLevel'] : 0;

                if ($gemId !== '' && in_array($gemId, self::UNCUT_GEM_IDS, true)) {
                    $uncutGems++;
                    $issues[] = "Gema sin cortar en {$itemName} (ID {$gemId})";
                    continue;
                }

                // En GearIssues.gs las gemas por debajo de calidad rara se marcan como problema.
                if ($gemItemLevel > 0 && $gemItemLevel < 60) {
                    $badGems++;
                    $issues[] = "Gema de baja calidad en {$itemName} (iLvl {$gemItemLevel})";
                }
            }
        }

        $issues = array_values(array_unique($issues));

        return [
            'name' => $name,
            'role' => strtoupper($role),
            'missingEnchants' => $missingEnchants,
            'badEnchants' => $badEnchants,
            'missingGems' => $missingGems,
            'badGems' => $badGems,
            'uncutGems' => $uncutGems,
            'totalIssues' => $missingEnchants + $badEnchants + $missingGems + $badGems + $uncutGems,
            'issues' => $issues,
        ];
    }

    private static function getPlayerClass(array $player): string
    {
        return (string)($player['type'] ?? $player['class'] ?? $player['combatantInfo']['type'] ?? 'Unknown');
    }

    private static function isBadEnchantForClass(string $enchantId, string $playerClass, bool $isTemporary): bool
    {
        if (!$isTemporary) {
            return false;
        }

        // Reglas tomadas de GearIssues.gs:
        // - Hunter/Rogue/Warrior con enchants de spell hit.
        // - Mage/Priest/Warlock con enchants de melee hit.
        $meleeClasses = ['Hunter', 'Rogue', 'Warrior'];
        $casterClasses = ['Mage', 'Priest', 'Warlock'];
        $spellHitEnchantIds = ['3002', '2935'];
        $meleeHitEnchantIds = ['3003', '2658'];

        if (in_array($playerClass, $meleeClasses, true) && in_array($enchantId, $spellHitEnchantIds, true)) {
            return true;
        }

        if (in_array($playerClass, $casterClasses, true) && in_array($enchantId, $meleeHitEnchantIds, true)) {
            return true;
        }

        return false;
    }

    private static function isEnchantableSlot(string $slot): bool
    {
        // Slots inspirados en GearIssues.gs (head, shoulder, chest, legs, etc.).
        return in_array($slot, ['0', '2', '4', '6', '7', '8', '9', '14', '15', '16'], true);
    }

    private static function getExpectedSockets(array $item): int
    {
        if (isset($item['numSockets'])) {
            return (int)$item['numSockets'];
        }

        if (isset($item['socketCount'])) {
            return (int)$item['socketCount'];
        }

        if (isset($item['sockets']) && is_array($item['sockets'])) {
            return count($item['sockets']);
        }

        return 0;
    }

    private static function truncateFieldValue(string $value, int $max = 1000): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max - 3) . '...';
    }
}
