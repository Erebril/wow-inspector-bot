<?php

namespace App\Commands;

use Discord\Parts\Interactions\Interaction;
use Discord\Builders\MessageBuilder;
use GuzzleHttp\Client;

class EnchantsIssueCommand
{
    private static ?array $cheapEnchants = null;
    private static ?array $excludeGear = null;

    private static function getCheapEnchants(): array
    {
        if (self::$cheapEnchants === null) {
            $path = __DIR__ . '/../../cheap_enchants.json';
            self::$cheapEnchants = file_exists($path)
                ? (json_decode(file_get_contents($path), true) ?? [])
                : [];
        }
        return self::$cheapEnchants;
    }

    private static function getExcludeGear(): array
    {
        if (self::$excludeGear === null) {
            $path = __DIR__ . '/../../exclude_gear.json';
            self::$excludeGear = file_exists($path)
                ? (json_decode(file_get_contents($path), true) ?? [])
                : [];
        }
        return self::$excludeGear;
    }

    public static function run(Interaction $interaction)
    {
        $interaction->acknowledgeWithResponse(true)->then(function () use ($interaction) {
            try {
                $logUrl = $interaction->data->options['url']->value;
                $result = self::analyzeReportByUrl($logUrl);

                $report = $result['report'];
                $totals = $result['totals'];
                $playersWithIssues = $result['playersWithIssues'];
                $totalPlayers = $result['totalPlayers'];
                $playersWithIssuesCount = $result['playersWithIssuesCount'];
                $playersOk = max(0, $totalPlayers - $playersWithIssuesCount);

                $issuesByRole = [
                    'DPS' => [],
                    'HEALERS' => [],
                    'TANKS' => [],
                ];

                foreach (array_slice($playersWithIssues, 0, 25) as $entry) {
                    $parts = [];
                    if (!empty($entry['missingSlots'])) {
                        $parts[] = "❌ " . implode(', ', $entry['missingSlots']);
                    }
                    if (!empty($entry['badEnchantIssues'])) {
                        $parts[] = "⚠️ " . implode(', ', $entry['badEnchantIssues']);
                    }
                    $line = implode(' | ', $parts);
                    $roleKey = strtoupper((string)($entry['role'] ?? 'DPS'));
                    if (!isset($issuesByRole[$roleKey])) {
                        $issuesByRole[$roleKey] = [];
                    }
                    $issuesByRole[$roleKey][] = "• **{$entry['name']}**: {$line}";
                }

                $fightNote = isset($report['_fightUsed'])
                    ? "\n⚔️ Pelea analizada: **{$report['_fightUsed']}**"
                    : '';

                $embed = [
                    'title' => "Análisis de Equipo: " . $report['title'],
                    'description' =>
                        "Total: **{$totalPlayers}** | ✅ OK: **{$playersOk}** | ⚠️ Issues: **{$playersWithIssuesCount}** | " .
                        "❌ Sin enchant: **{$totals['missingEnchants']}** | ⚠️ Enchant malo: **{$totals['badEnchants']}**" .
                        $fightNote,
                    'url' => $logUrl,
                    'color' => ($playersWithIssuesCount > 0) ? 0xe67e22 : 0x2ecc71,
                    'fields' => [],
                    'footer' => ['text' => "WarcraftLogs API v2 | Análisis exclusivo de enchants"]
                ];

                $followUpMessages = [];
                if ($playersWithIssuesCount === 0) {
                    $followUpMessages[] = "✅ No se detectaron issues de enchants en esta pelea.";
                } else {
                    foreach (['DPS', 'HEALERS', 'TANKS'] as $role) {
                        $lines = $issuesByRole[$role] ?? [];
                        if (empty($lines)) {
                            continue;
                        }

                        foreach (self::chunkLinesForDiscord($lines, 1600) as $idx => $chunk) {
                            $title = "⚠️ {$role} con issues";
                            if ($idx > 0) {
                                $title .= " (parte " . ($idx + 1) . ")";
                            }
                            $followUpMessages[] = $title . "\n" . $chunk;
                        }
                    }
                }

                $interaction->updateOriginalResponse(MessageBuilder::new()->addEmbed($embed))->then(function () use ($interaction, $followUpMessages) {
                    foreach ($followUpMessages as $content) {
                        $interaction->sendFollowUpMessage(
                            MessageBuilder::new()->setContent($content),
                            true
                        );
                    }
                });

            } catch (\Exception $e) {
                $interaction->updateOriginalResponse(MessageBuilder::new()->setContent("❌ Error: " . $e->getMessage()));
                file_put_contents(
                    __DIR__ . '/../../logs/enchants_errors.log', 
                    date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", 
                    FILE_APPEND
                );
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

        $query = 'query($reportId: String!) { 
            reportData { 
                report(code: $reportId) { 
                    title 
                    fights { id name encounterID startTime endTime kill }
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
        $fights = $report['fights'] ?? [];

        // Buscar la primera pelea que NO sea High King Maulgar y que sea un kill de boss
        $skipNames = ['high king maulgar', 'maulgar'];
        $selectedFight = null;
        foreach ($fights as $fight) {
            if (empty($fight['encounterID'])) continue; // solo boss fights
            if (!($fight['kill'] ?? false)) continue;   // solo kills
            $name = strtolower($fight['name'] ?? '');
            $isMaulgar = false;
            foreach ($skipNames as $skip) {
                if (str_contains($name, $skip)) { $isMaulgar = true; break; }
            }
            if (!$isMaulgar) {
                $selectedFight = $fight;
                break;
            }
        }

        // Si no hay otro kill disponible, usar la primera pelea de boss aunque sea Maulgar
        if ($selectedFight === null) {
            foreach ($fights as $fight) {
                if (!empty($fight['encounterID'])) {
                    $selectedFight = $fight;
                    break;
                }
            }
        }

        $startTime = $selectedFight['startTime'] ?? 0;
        $endTime   = $selectedFight['endTime']   ?? 9999999999999;

        // Segunda query: obtener el Summary para la pelea seleccionada
        $query2 = 'query($reportId: String!, $startTime: Float!, $endTime: Float!) { 
            reportData { 
                report(code: $reportId) { 
                    table(dataType: Summary, startTime: $startTime, endTime: $endTime)
                } 
            } 
        }';

        $response2 = $httpClient->post("https://www.warcraftlogs.com/api/v2/client", [
            'headers' => ['Authorization' => "Bearer $token"],
            'json' => ['query' => $query2, 'variables' => [
                'reportId' => $reportId,
                'startTime' => $startTime,
                'endTime'   => $endTime,
            ]]
        ]);

        $dataJson2 = json_decode($response2->getBody(), true);
        if (!empty($dataJson2['errors'])) {
            $firstError = $dataJson2['errors'][0]['message'] ?? 'Error desconocido en WarcraftLogs.';
            throw new \Exception($firstError);
        }

        $report    = $dataJson['data']['reportData']['report'];
        $tableData = $dataJson2['data']['reportData']['report']['table']['data'] ?? [];
        if (is_string($tableData)) {
            $tableData = json_decode($tableData, true) ?? [];
        }
        // Añadir la pelea usada al título para referencia
        if ($selectedFight !== null) {
            $report['_fightUsed'] = $selectedFight['name'];
        }

        $playerDetails = $tableData['playerDetails'] ?? [];
        $roles = ['dps', 'healers', 'tanks'];
        $playersWithIssues = [];
        $totalPlayers = 0;
        $totals = [
            'missingEnchants' => 0,
            'badEnchants' => 0,
        ];

        foreach ($roles as $role) {
            foreach ($playerDetails[$role] ?? [] as $player) {
                $totalPlayers++;
                $analysis = self::analyzePlayer($player, $role);

                $totals['missingEnchants'] += $analysis['missingEnchants'];
                $totals['badEnchants'] += $analysis['badEnchants'];

                if ($analysis['totalIssues'] > 0) {
                    $playersWithIssues[] = $analysis;
                }
            }
        }

        usort($playersWithIssues, function ($a, $b) {
            return $b['totalIssues'] <=> $a['totalIssues'];
        });

        return [
            'report' => $report,
            'totals' => $totals,
            'playersWithIssues' => $playersWithIssues,
            'totalPlayers' => $totalPlayers,
            'playersWithIssuesCount' => count($playersWithIssues),
        ];
    }

    private static function analyzePlayer(array $player, string $role): array
    {
        $name = $player['name'] ?? 'Desconocido';
        $gear = $player['combatantInfo']['gear'] ?? $player['gear'] ?? [];

        $missingEnchants = 0;
        $badEnchants = 0;
        $missingSlots = [];
        $badEnchantIssues = [];
        $playerClass = self::getPlayerClass($player);

        foreach ($gear as $item) {
            if (!is_array($item)) {
                continue;
            }

            $slot = isset($item['slot']) ? (string)$item['slot'] : '';
            $slotName = self::getSlotName($slot);

            // Saltar items excluidos (ej: cañas de pescar, items de fase, etc.)
            $itemId = (string)($item['id'] ?? '');
            if (isset(self::getExcludeGear()[$itemId])) {
                continue;
            }

            if (self::isEnchantableSlot($slot, $item)) {
                $permanentEnchant = $item['permanentEnchant'] ?? $item['enchant'] ?? null;
                if ($permanentEnchant === null || (string)$permanentEnchant === '' || (string)$permanentEnchant === '0') {
                    $missingEnchants++;
                    $missingSlots[] = $slotName;
                } else {
                    $enchantIdStr = (string)$permanentEnchant;
                    $cheapList = self::getCheapEnchants();
                    if (isset($cheapList[$enchantIdStr])) {
                        if (self::isAllowedCheapEnchantForPlayer($enchantIdStr, $playerClass, $role, $slot)) {
                            continue;
                        }
                        $enchantNames = implode('/', $cheapList[$enchantIdStr]);
                        $badEnchants++;
                        $badEnchantIssues[] = "{$slotName}: {$enchantNames}";
                    }
                }
            }

            $temporaryEnchant = $item['temporaryEnchant'] ?? null;
            if ($temporaryEnchant !== null && (string)$temporaryEnchant !== '' && (string)$temporaryEnchant !== '0') {
                if (self::isBadEnchantForClass((string)$temporaryEnchant, $playerClass, true)) {
                    $badEnchants++;
                    $badEnchantIssues[] = "{$slotName}: temp";
                }
            }
        }

        return [
            'name' => $name,
            'role' => strtoupper($role),
            'missingEnchants' => $missingEnchants,
            'badEnchants' => $badEnchants,
            'totalIssues' => $missingEnchants + $badEnchants,
            'missingSlots' => $missingSlots,
            'badEnchantIssues' => $badEnchantIssues,
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

    private static function isAllowedCheapEnchantForPlayer(string $enchantId, string $playerClass, string $role, string $slot): bool
    {
        $class = strtolower($playerClass);
        $roleNormalized = strtolower($role);

        // Excepción: Paladín tanque con 40 SP en main hand (ID 2669) es válido.
        if ($class === 'paladin' && $roleNormalized === 'tanks' && $slot === '15' && $enchantId === '2669') {
            return true;
        }

        return false;
    }

    private static function isEnchantableSlot(string $slot, array $item = []): bool
    {
        // Slot 16 = Off Hand: solo enchantable si es escudo (type/subType contiene 'Shield')
        if ($slot === '16') {
            $itemType = strtolower((string)($item['type'] ?? $item['subType'] ?? $item['itemSubType'] ?? ''));
            return str_contains($itemType, 'shield');
        }
        // Slots inspirados en GearIssues.gs (head, shoulder, chest, legs, etc.).
        return in_array($slot, ['0', '2', '4', '6', '7', '8', '9', '14', '15'], true);
    }

    private static function getSlotName(string $slot): string
    {
        $slotMap = [
            '0' => 'Head',
            '1' => 'Neck',
            '2' => 'Shoulder',
            '3' => 'Shirt',
            '4' => 'Chest',
            '5' => 'Waist',
            '6' => 'Legs',
            '7' => 'Feet',
            '8' => 'Wrist',
            '9' => 'Hands',
            '10' => 'Finger 1',
            '11' => 'Finger 2',
            '12' => 'Trinket 1',
            '13' => 'Trinket 2',
            '14' => 'Back',
            '15' => 'Main Hand',
            '16' => 'Off Hand',
            '17' => 'Ranged',
        ];

        return $slotMap[$slot] ?? ('Slot ' . $slot);
    }

    private static function truncateFieldValue(string $value, int $max = 1000): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max - 3) . '...';
    }

    private static function chunkLinesForDiscord(array $lines, int $maxChars = 1600): array
    {
        $chunks = [];
        $current = '';

        foreach ($lines as $line) {
            $candidate = $current === '' ? $line : ($current . "\n" . $line);
            if (strlen($candidate) > $maxChars) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = $line;
                } else {
                    $chunks[] = substr($line, 0, $maxChars - 3) . '...';
                    $current = '';
                }
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
