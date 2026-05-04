<?php

namespace App\Commands;

use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use GuzzleHttp\Client;

class GemsIssueCommand
{
    private static ?array $excludeGear = null;

    private const META_GEM_IDS = [
        '25890', '25893', '25894', '25895', '25896', '25897', '25898', '25899',
        '25901', '28556', '28557', '32409', '32410', '32640', '32641', '34220',
        '35501', '35503',
    ];

    private const RARE_QUALITY_IDS = [
        '25890', '25893', '25894', '25895', '25896', '25897', '25898', '25899',
        '25901', '27679', '27777', '27785', '27786', '27809', '27812', '27820',
        '28118', '28119', '28120', '28123', '28360', '28361', '28362', '28363',
        '28556', '28557', '30571', '30598', '32409', '32410', '32640', '32641',
        '32836', '34220', '35501', '35503', '38545', '38546', '38547', '38548',
        '38549', '38550','31868','24058','30546',
    ];

    private const YELLOW_GEM_IDS = [
        '22459', '22460', '23097', '23098', '23099', '23100', '23101', '23103', '23104', '23105', '23106',
        '23113', '23114', '23115', '23116', '24047', '24048', '24050', '24051', '24052', '24053', '24058',
        '24059', '24060', '24061', '24062', '24065', '24066', '24067', '27679', '27785', '27786', '27809',
        '27820', '28119', '28120', '28123', '28290', '28363', '28466', '28467', '28468', '28469', '28470',
        '30547', '30548', '30550', '30551', '30553', '30554', '30558', '30559', '30560', '30564', '30565',
        '30573', '30575', '30581', '30582', '30584', '30585', '30587', '30588', '30589', '30590', '30591',
        '30592', '30593', '30594', '30601', '30602', '30604', '30605', '30606', '30607', '30608', '31860',
        '31861', '31866', '31867', '32204', '32205', '32207', '32208', '32209', '32210', '32217', '32218',
        '32219', '32220', '32221', '32222', '32223', '32224', '32225', '32226', '32735', '33138', '33139',
        '33140', '33143', '33144', '33633', '35315', '35316', '35758', '35759', '35760', '35761', '38546',
        '38547', '38548', '38550','31868',
    ];

    private const RED_GEM_IDS = [
        '22459', '22460', '23094', '23095', '23096', '23097', '23098', '23099', '23108', '23109', '23110',
        '23111', '24027', '24028', '24029', '24030', '24031', '24032', '24036', '24054', '24055', '24056',
        '24057', '24058', '24059', '24060', '24061', '27679', '27777', '27812', '28118', '28123', '28360',
        '28361', '28362', '28363', '28458', '28459', '28460', '28461', '28462', '30546', '30547', '30549',
        '30551', '30552', '30553', '30554', '30555', '30556', '30558', '30559', '30563', '30564', '30565',
        '30566', '30571', '30572', '30573', '30574', '30575', '30581', '30582', '30584', '30585', '30587',
        '30588', '30593', '30598', '30600', '30601', '30603', '31116', '31117', '31118', '31862', '31863',
        '31864', '31865', '31866', '31867', '31868', '31869', '32193', '32194', '32195', '32196', '32197',
        '32198', '32199', '32211', '32212', '32213', '32214', '32215', '32216', '32217', '32218', '32219',
        '32220', '32221', '32634', '32636', '32637', '32638', '32833', '32836', '33131', '33132', '33133',
        '33134', '35316', '35487', '35488', '35489', '35707', '35760', '37503', '38545', '38547', '38548',
        '38549',
    ];

    private const BLUE_GEM_IDS = [
        '22459', '22460', '23108', '23109', '23110', '23111', '23118', '23119', '23120', '23121', '24033',
        '24035', '24037', '24039', '24054', '24055', '24056', '24057', '24062', '24065', '24066', '24067',
        '27785', '27786', '27820', '28463', '28464', '28465', '30546', '30548', '30549', '30550', '30552',
        '30555', '30560', '30563', '30566', '30572', '30574', '30583', '30586', '30589', '30592', '30594',
        '30600', '30602', '30603', '30605', '30606', '30608', '31862', '31863', '31864', '31865', '32200',
        '32201', '32202', '32203', '32211', '32212', '32213', '32214', '32215', '32216', '32222', '32223',
        '32224', '32225', '32226', '32634', '32635', '32636', '32639', '32735', '32833', '32836', '33135',
        '33633', '33782', '34256', '34831', '35318', '35707', '35758', '35759', '31116', '31117', '31118',
        '23104',
    ];

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
                    if (!empty($entry['badGemIssues'])) {
                        $parts[] = '⚠️ ' . implode(', ', $entry['badGemIssues']);
                    }
                    if (!empty($entry['metaIssues'])) {
                        $parts[] = '🔶 ' . implode(', ', $entry['metaIssues']);
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
                    'title' => 'Análisis de Gemas: ' . $report['title'],
                    'description' =>
                        "Total: **{$totalPlayers}** | ✅ OK: **{$playersOk}** | ⚠️ Issues: **{$playersWithIssuesCount}** | " .
                        "⚠️ Gemas < rare: **{$totals['badGems']}** | 🔶 Meta issues: **{$totals['metaIssues']}**" .
                        $fightNote,
                    'url' => $logUrl,
                    'color' => ($playersWithIssuesCount > 0) ? 0xe67e22 : 0x2ecc71,
                    'fields' => [],
                    'footer' => ['text' => 'WarcraftLogs API v2 | Análisis exclusivo de gemas']
                ];

                $followUpMessages = [];
                if ($playersWithIssuesCount === 0) {
                    $followUpMessages[] = '✅ No se detectaron issues de gemas en esta pelea.';
                } else {
                    foreach (['DPS', 'HEALERS', 'TANKS'] as $role) {
                        $lines = $issuesByRole[$role] ?? [];
                        if (empty($lines)) {
                            continue;
                        }

                        foreach (self::chunkLinesForDiscord($lines, 1600) as $idx => $chunk) {
                            $title = "⚠️ {$role} con issues de gemas";
                            if ($idx > 0) {
                                $title .= ' (parte ' . ($idx + 1) . ')';
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
                $interaction->updateOriginalResponse(MessageBuilder::new()->setContent('❌ Error: ' . $e->getMessage()));
                file_put_contents(
                    __DIR__ . '/../../logs/gems_errors.log',
                    date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . "\n",
                    FILE_APPEND
                );
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

        $response = $httpClient->post('https://www.warcraftlogs.com/api/v2/client', [
            'headers' => ['Authorization' => "Bearer $token"],
            'json' => ['query' => $query, 'variables' => ['reportId' => $reportId]]
        ]);

        $dataJson = json_decode($response->getBody(), true);
        if (!empty($dataJson['errors'])) {
            $firstError = $dataJson['errors'][0]['message'] ?? 'Error desconocido en WarcraftLogs.';
            throw new \Exception($firstError);
        }

        $report = $dataJson['data']['reportData']['report'];
        $selectedFight = self::selectFightForAnalysis($report['fights'] ?? []);

        $query2 = 'query($reportId: String!, $startTime: Float!, $endTime: Float!) {
            reportData {
                report(code: $reportId) {
                    table(dataType: Summary, startTime: $startTime, endTime: $endTime)
                }
            }
        }';

        $response2 = $httpClient->post('https://www.warcraftlogs.com/api/v2/client', [
            'headers' => ['Authorization' => "Bearer $token"],
            'json' => ['query' => $query2, 'variables' => [
                'reportId' => $reportId,
                'startTime' => $selectedFight['startTime'] ?? 0,
                'endTime' => $selectedFight['endTime'] ?? 9999999999999,
            ]]
        ]);

        $dataJson2 = json_decode($response2->getBody(), true);
        if (!empty($dataJson2['errors'])) {
            $firstError = $dataJson2['errors'][0]['message'] ?? 'Error desconocido en WarcraftLogs.';
            throw new \Exception($firstError);
        }

        $tableData = $dataJson2['data']['reportData']['report']['table']['data'] ?? [];
        if (is_string($tableData)) {
            $tableData = json_decode($tableData, true) ?? [];
        }
        if ($selectedFight !== null) {
            $report['_fightUsed'] = $selectedFight['name'];
        }

        $playerDetails = $tableData['playerDetails'] ?? [];
        $roles = ['dps', 'healers', 'tanks'];
        $playersWithIssues = [];
        $totalPlayers = 0;
        $totals = [
            'badGems' => 0,
            'metaIssues' => 0,
        ];

        foreach ($roles as $role) {
            foreach ($playerDetails[$role] ?? [] as $player) {
                $totalPlayers++;
                $analysis = self::analyzePlayer($player, $role);
                $totals['badGems'] += $analysis['badGems'];
                $totals['metaIssues'] += $analysis['metaIssuesCount'];

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

        $badGems = 0;
        $badGemIssues = [];
        $metaIssues = [];
        $metaGemId = null;
        $metaGemSlot = 'Head';
        $headHasGems = false;
        $blueGemsFound = 0;
        $redGemsFound = 0;
        $yellowGemsFound = 0;

        foreach ($gear as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemId = (string)($item['id'] ?? '');
            if (isset(self::getExcludeGear()[$itemId])) {
                continue;
            }

            $slot = isset($item['slot']) ? (string)$item['slot'] : '';
            $slotName = self::getSlotName($slot);
            $gems = is_array($item['gems'] ?? null) ? $item['gems'] : [];

            if ($slot === '0' && !empty($gems)) {
                $headHasGems = true;
            }

            foreach ($gems as $gem) {
                $gemId = (string)($gem['id'] ?? '');
                $itemLevel = (int)($gem['itemLevel'] ?? 0);

                if (self::isMetaGem($gemId)) {
                    $metaGemId = $gemId;
                    $metaGemSlot = $slotName;
                }

                $colors = self::getGemColors($gemId);
                if (in_array('blue', $colors, true)) {
                    $blueGemsFound++;
                }
                if (in_array('red', $colors, true)) {
                    $redGemsFound++;
                }
                if (in_array('yellow', $colors, true)) {
                    $yellowGemsFound++;
                }

                if (self::isGemBelowRare($gemId, $itemLevel)) {
                    $badGems++;
                    $badGemIssues[] = $slotName . ': ' . self::getGemQualityLabel($gemId, $itemLevel);
                }
            }
        }

        if ($headHasGems && $metaGemId === null) {
            $metaIssues[] = 'Head: sin meta';
        } elseif ($metaGemId !== null && !self::isMetaGemActive($metaGemId, $redGemsFound, $blueGemsFound, $yellowGemsFound)) {
            $metaIssues[] = $metaGemSlot . ': meta inactiva';
        }

        $badGemIssues = array_values(array_unique($badGemIssues));
        $metaIssues = array_values(array_unique($metaIssues));

        return [
            'name' => $name,
            'role' => strtoupper($role),
            'badGems' => $badGems,
            'metaIssuesCount' => count($metaIssues),
            'totalIssues' => $badGems + count($metaIssues),
            'badGemIssues' => $badGemIssues,
            'metaIssues' => $metaIssues,
        ];
    }

    private static function isGemBelowRare(string $gemId, int $itemLevel): bool
    {
        if ($gemId === '' || $gemId === '0') {
            return false;
        }

        if ($itemLevel < 60) {
            return true;
        }

        if ($itemLevel === 60 && !in_array($gemId, self::RARE_QUALITY_IDS, true)) {
            return true;
        }

        return false;
    }

    private static function getGemQualityLabel(string $gemId, int $itemLevel): string
    {
        if ($itemLevel < 60) {
            return 'common';
        }

        if ($itemLevel === 60 && !in_array($gemId, self::RARE_QUALITY_IDS, true)) {
            return 'uncommon';
        }

        return 'rare';
    }

    private static function isMetaGem(string $gemId): bool
    {
        return in_array($gemId, self::META_GEM_IDS, true);
    }

    private static function getGemColors(string $gemId): array
    {
        $colors = [];

        if (in_array($gemId, self::BLUE_GEM_IDS, true)) {
            $colors[] = 'blue';
        }
        if (in_array($gemId, self::RED_GEM_IDS, true)) {
            $colors[] = 'red';
        }
        if (in_array($gemId, self::YELLOW_GEM_IDS, true)) {
            $colors[] = 'yellow';
        }

        return $colors;
    }

    private static function isMetaGemActive(string $metaGemId, int $redGemsFound, int $blueGemsFound, int $yellowGemsFound): bool
    {
        if ($metaGemId === '25896' && $blueGemsFound > 2) {
            return true;
        }
        if ($metaGemId === '25897' && $redGemsFound > $blueGemsFound) {
            return true;
        }
        if (in_array($metaGemId, ['32409', '25899', '25901', '25890', '32410'], true) && $redGemsFound > 1 && $blueGemsFound > 1 && $yellowGemsFound > 1) {
            return true;
        }
        if ($metaGemId === '25898' && $blueGemsFound > 4) {
            return true;
        }
        if (in_array($metaGemId, ['25893', '32640'], true) && $blueGemsFound > $yellowGemsFound) {
            return true;
        }
        if ($metaGemId === '34220' && $blueGemsFound > 1) {
            return true;
        }
        if ($metaGemId === '25895' && $redGemsFound > $yellowGemsFound) {
            return true;
        }
        if (in_array($metaGemId, ['25894', '28556', '28557'], true) && $redGemsFound > 0 && $yellowGemsFound > 1) {
            return true;
        }
        if ($metaGemId === '32641' && $yellowGemsFound > 2) {
            return true;
        }
        if ($metaGemId === '35503' && $redGemsFound > 2) {
            return true;
        }
        if ($metaGemId === '35501' && $blueGemsFound > 1 && $yellowGemsFound > 0) {
            return true;
        }

        return false;
    }

    private static function selectFightForAnalysis(array $fights): ?array
    {
        $skipNames = ['high king maulgar', 'maulgar'];
        foreach ($fights as $fight) {
            if (empty($fight['encounterID']) || !($fight['kill'] ?? false)) {
                continue;
            }

            $name = strtolower($fight['name'] ?? '');
            $isSkipped = false;
            foreach ($skipNames as $skipName) {
                if (str_contains($name, $skipName)) {
                    $isSkipped = true;
                    break;
                }
            }

            if (!$isSkipped) {
                return $fight;
            }
        }

        foreach ($fights as $fight) {
            if (!empty($fight['encounterID'])) {
                return $fight;
            }
        }

        return null;
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
