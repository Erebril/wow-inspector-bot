<?php

include __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// URL de prueba
$testUrl = $argv[1] ?? 'https://fresh.warcraftlogs.com/reports/Btw3kAXn2hxgZFHY';
preg_match('/reports\/([a-zA-Z0-9]+)/', $testUrl, $matches);
$reportId = $matches[1];

$tbcSpellIds = [
    28520 => "🧪 Relentless Assault",
    28540 => "🧪 Pure Death",
    28521 => "🧪 Blinding Light",
    28519 => "🧪 Mighty Restoration",
    28518 => "🧪 Fortification",
    28491 => "🧴 Healing Power",
    28497 => "🧴 G. Agility",
    28503 => "🧴 Major Shadow",
    28501 => "🧴 Major Fire",
    28502 => "🧴 M. Defense",
    28509 => "🧴 G. Versatility",
    39627 => "🧴 Draenic Wisdom",
    33721 => "🧴 Adept",
    39625 => "🧴 M. Fortitude",
    11406 => "🧴 Demonslaying",
    11371 => "⚠️ Gift of Arthas",
    17538 => "⚠️ Mongoose",
];

try {
    $httpClient = new Client(['timeout' => 45.0]);
    $tokenResponse = $httpClient->post("https://www.warcraftlogs.com/oauth/token", [
        'form_params' => [
            'grant_type' => 'client_credentials',
            'client_id' => $_ENV['WARCRAFTLOGS_CLIENT_ID'],
            'client_secret' => $_ENV['WARCRAFTLOGS_CLIENT_SECRET'],
        ]
    ]);
    $token = json_decode($tokenResponse->getBody())->access_token;

    // 1. Obtener Actores y Tablas de actividad (DPS y Healers)
    $queryPlayers = 'query($reportId: String!) { 
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
        'json' => ['query' => $queryPlayers, 'variables' => ['reportId' => $reportId]]
    ]);

    $dataJson = json_decode($resPlayers->getBody(), true);
    $report = $dataJson['data']['reportData']['report'];

    // 2. Filtrar solo jugadores que pegaron o curaron
    $activeIds = [];
    foreach ($report['dps']['data']['entries'] ?? [] as $entry) $activeIds[] = $entry['id'];
    foreach ($report['hps']['data']['entries'] ?? [] as $entry) $activeIds[] = $entry['id'];
    $activeIds = array_unique($activeIds);

    $playerBuffs = []; // <--- Definimos la variable correctamente aquí
    foreach ($report['masterData']['actors'] as $actor) {
        if (in_array($actor['id'], $activeIds)) {
            $playerBuffs[$actor['id']] = ['name' => $actor['name'], 'buffs' => []];
        }
    }

    echo "🔍 Escaneando consumibles para " . count($playerBuffs) . " jugadores activos...\n";

    // 3. Consultar cada Aura
    foreach ($tbcSpellIds as $spellId => $spellName) {
        $qAura = 'query($reportId: String!, $ability: Float!) {
            reportData { report(code: $reportId) {
                table(dataType: Buffs, startTime: 0, endTime: 9999999999999, abilityID: $ability)
            } }
        }';

        $resAura = $httpClient->post("https://www.warcraftlogs.com/api/v2/client", [
            'headers' => ['Authorization' => "Bearer $token"],
            'json' => ['query' => $qAura, 'variables' => ['reportId' => $reportId, 'ability' => (float)$spellId]]
        ]);

        $auraData = json_decode($resAura->getBody(), true);
        $auras = $auraData['data']['reportData']['report']['table']['data']['auras'] ?? [];

        foreach ($auras as $aura) {
            $pId = $aura['id']; // El ID del jugador en esta vista
            if (isset($playerBuffs[$pId])) {
                $playerBuffs[$pId]['buffs'][] = $spellName;
            }
        }
    }

    // 4. Mostrar Resultados y Resumen
    $cWith = 0;
    $cWithout = 0;
    $withText = "";
    $withoutText = "";

    foreach ($playerBuffs as $data) {
        if (!empty($data['buffs'])) {
            $cWith++;
            $withText .= "✅ {$data['name']}: " . implode(", ", array_unique($data['buffs'])) . "\n";
        } else {
            $cWithout++;
            $withoutText .= "❌ {$data['name']}\n";
        }
    }

    echo "\n📊 RESUMEN DE LA RAID\n";
    echo "------------------------------------------\n";
    echo "Total participantes: " . ($cWith + $cWithout) . "\n";
    echo "Con consumibles: $cWith\n";
    echo "Sin consumibles: $cWithout\n\n";

    echo "DETALLE:\n";
    echo $withText;
    echo $withoutText;
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
