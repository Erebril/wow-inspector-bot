<?php

include __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;
use GuzzleHttp\Client;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$testUrl = $argv[1] ?? 'https://fresh.warcraftlogs.com/reports/3ZvMtB42K9n1JAND';
preg_match('/reports\/([a-zA-Z0-9]+)/', $testUrl, $matches);
$reportId = $matches[1];

// IDs Corregidos según tu lista
$tbcSpellIds = [
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

try {
    $httpClient = new Client();
    $tokenResponse = $httpClient->post("https://www.warcraftlogs.com/oauth/token", [
        'form_params' => [
            'grant_type' => 'client_credentials',
            'client_id' => $_ENV['WARCRAFTLOGS_CLIENT_ID'],
            'client_secret' => $_ENV['WARCRAFTLOGS_CLIENT_SECRET'],
        ]
    ]);
    $token = json_decode($tokenResponse->getBody())->access_token;

    // 1. Obtener los Actores (Jugadores)
    $qActors = 'query($reportId: String!) { reportData { report(code: $reportId) { masterData { actors(type: "Player") { id name } } } } }';
    $resActors = $httpClient->post("https://www.warcraftlogs.com/api/v2/client", [
        'headers' => ['Authorization' => "Bearer $token"],
        'json' => ['query' => $qActors, 'variables' => ['reportId' => $reportId]]
    ]);
    $actors = json_decode($resActors->getBody(), true)['data']['reportData']['report']['masterData']['actors'];

    $playerBuffs = [];
    foreach ($actors as $a) {
        $playerBuffs[$a['id']] = ['name' => $a['name'], 'buffs' => []];
    }

    echo "🚀 Iniciando escaneo profundo por AbilityID...\n";

    // 2. Consultar cada Aura individualmente para obtener los sourceIDs
    foreach ($tbcSpellIds as $spellId => $spellName) {
        echo "Consultando $spellName ($spellId)... ";

        $query = 'query($reportId: String!, $ability: Float!) {
            reportData {
                report(code: $reportId) {
                    table(dataType: Buffs, startTime: 0, endTime: 9999999999999, abilityID: $ability)
                }
            }
        }';

        $response = $httpClient->post("https://www.warcraftlogs.com/api/v2/client", [
            'headers' => ['Authorization' => "Bearer $token"],
            'json' => [
                'query' => $query,
                'variables' => ['reportId' => $reportId, 'ability' => (float)$spellId]
            ]
        ]);

        $data = json_decode($response->getBody(), true);
        //guardar el resultado completo para análisis
        // file_put_contents("log_{$spellId}.json", json_encode($data, JSON_PRETTY_PRINT));
        $auras = $data['data']['reportData']['report']['table']['data']['auras'] ?? [];
        
        $foundInThisAura = 0;
        foreach ($auras as $aura) {
            // EXPLICACIÓN: En esta query, $aura['id'] es el ID del JUGADOR
            // y $aura['name'] es el nombre del JUGADOR.
            $playerId = $aura['id'] ?? null;

            if ($playerId && isset($playerBuffs[$playerId])) {
                $playerBuffs[$playerId]['buffs'][] = $spellName;
                $foundInThisAura++;
            }
        }
        echo "[$foundInThisAura encontrados]\n";
    }

    // 3. Mostrar Resultados
    echo "\n📊 RESULTADOS DEL LOG:\n";
    echo "------------------------------------------\n";
    
    $with = "";
    $without = "";

    foreach ($playerBuffs as $id => $data) {
        if (!empty($data['buffs'])) {
            $with .= "✅ {$data['name']}: " . implode(", ", array_unique($data['buffs'])) . "\n";
        } else {
            if ($data['name'] !== "Multiple Players") {
                $without .= "❌ {$data['name']}\n";
            }
        }
    }

    echo "CON CONSUMIBLES:\n$with";
    echo "\nSIN CONSUMIBLES:\n$without";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}