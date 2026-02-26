<?php

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/GearScoreCalculator.php';
require_once __DIR__ . '/ItemCacheManager.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// --- CONFIGURACIÓN ---
$region = 'us';
$namespace = "profile-classicann-{$region}";
$locale = 'en_US';

if (!isset($argv[1])) {
    die("Uso: php test_api.php <NombrePersonaje> [Reino]\n");
}

$charName = strtolower($argv[1]);
$realmRaw = $argv[2] ?? 'nightslayer';
$realmSlug = strtolower(str_replace([' ', '\''], ['-', ''], $realmRaw));

$blizzardClientId = $_ENV['BLIZZARD_CLIENT_ID'];
$blizzardClientSecret = $_ENV['BLIZZARD_SECRET'];

try {
    $httpClient = new Client();

    // 1. Obtener Token
    $authResponse = $httpClient->post("https://oauth.battle.net/token", [
        'auth' => [$blizzardClientId, $blizzardClientSecret],
        'form_params' => ['grant_type' => 'client_credentials']
    ]);
    $accessToken = json_decode($authResponse->getBody())->access_token;
    $headers = ['Authorization' => "Bearer $accessToken"];

    echo "Consultando datos para {$charName} en {$realmSlug}...\n";

    // --- LLAMADAS A LA API ---

    // A: Equipamiento
    $equipUrl = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}/equipment";
    $equipResponse = $httpClient->get($equipUrl, [
        'headers' => $headers,
        'query' => ['namespace' => $namespace, 'locale' => $locale]
    ]);
    $equipData = json_decode($equipResponse->getBody(), true);
    echo "✅ Equipamiento obtenido.\n";

    // ... (Después de obtener $accessToken y $headers) ...

    $cache = new ItemCacheManager();
    $totalGearScore = 0;
    $items = $equipData['equipped_items'] ?? [];

    foreach ($items as $item) {
        $itemId = $item['item']['id'];

        // 1. Intentar obtener de la caché local
        $cachedItem = $cache->getItem($itemId);

        if ($cachedItem) {
            $iLvl = $cachedItem['level'];
            $quality = $cachedItem['quality'];
            $invType = $cachedItem['inventory_type'];
        } else {
            // 2. Si no existe, consultar Blizzard Data API
            $itemDataUrl = "https://{$region}.api.blizzard.com/data/wow/item/{$itemId}";
            $itemResponse = $httpClient->get($itemDataUrl, [
                'headers' => $headers,
                'query' => ['namespace' => "static-classicann-us", 'locale' => $locale]
            ]);

            $itemDetail = json_decode($itemResponse->getBody(), true);
            $iLvl = $itemDetail['level'] ?? 0;
            $quality = $itemDetail['quality']['type'] ?? 'COMMON';
            $invType = $itemDetail['inventory_type']['type'] ?? 'NON_EQUIP';

            // 3. Guardar en caché para la próxima vez
            $cache->saveItem($itemId, $itemDetail);
            echo "💾 Objeto {$itemDetail['name']} guardado en caché.\n";
        }

        // 4. Calcular usando la lógica de TacoTip
        $itemScore = GearScoreCalculator::calculateItemScore($iLvl, $quality, $invType);
        $totalGearScore += $itemScore;
    }

    $totalGearScore = floor($totalGearScore);
    $tierInfo = GearScoreCalculator::getTierInfo($totalGearScore);

    // B: Estadísticas
    $statsUrl = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}/statistics";
    $statsResponse = $httpClient->get($statsUrl, [
        'headers' => $headers,
        'query' => ['namespace' => $namespace, 'locale' => $locale]
    ]);
    $statsData = json_decode($statsResponse->getBody(), true);
    echo "✅ Estadísticas obtenidas.\n";

    // C: Perfil General
    $profileUrl = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}";
    $profileResponse = $httpClient->get($profileUrl, [
        'headers' => $headers,
        'query' => ['namespace' => $namespace, 'locale' => $locale]
    ]);
    $profileData = json_decode($profileResponse->getBody(), true);
    echo "✅ Perfil general obtenido.\n";
    // D: Pvp (Opcional, no se muestra en este ejemplo pero se puede agregar de forma similar)
    $pvpUrl = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}/pvp-summary";
    $pvpResponse = $httpClient->get($pvpUrl, [
        'headers' => $headers,
        'query' => ['namespace' => $namespace, 'locale' => $locale]
    ]);
    $pvpData = json_decode($pvpResponse->getBody(), true);
    echo "✅ Resumen PvP obtenido.\n";

    echo "\n✅ ¡ÉXITO! Personaje encontrado.\n";
    echo "\n=================================\n";
    echo "Nombre: " . ($profileData['name'] ?? 'N/A') . "\n";
    echo "Guild:  " . ($profileData['guild']['name'] ?? 'Sin Guild') . "\n";
    echo "Nivel:  " . ($profileData['level'] ?? 'N/A') . "\n";
    echo "Clase:  " . ($profileData['character_class']['name'] ?? 'N/A') . "\n";
    echo "Raza:  " . ($profileData['race']['name'] ?? 'N/A') . "\n";
    // Melee Crit y Ranged Crit y Spell Crit
    echo "Attack Power: " . ($statsData['attack_power'] ?? 'N/A') . "\n";
    echo "Spell Power: " . ($statsData['spell_power'] ?? 'N/A') . "\n";
    echo "Melee Crit: " . ($statsData['melee_crit']['value'] ?? 'N/A') . "%\n";
    echo "Ranged Crit: " . ($statsData['ranged_crit']['value'] ?? 'N/A') . "%\n";
    echo "Spell Crit: " . ($statsData['spell_crit']['value'] ?? 'N/A') . "%\n";
    //Armor and Defense
    echo "Armor: " . ($statsData['armor']['effective'] ?? 'N/A') . "\n";
    echo "Defense: " . ($statsData['defense']['effective'] ?? 'N/A') . "\n";


    // ilvl de los items equipados
    echo "Item Level: " . ($profileData['equipped_item_level'] ?? 'N/A') . "\n";

    echo "GEARSCORE: " . $totalGearScore . "\n";
    echo "RANGO:     " . $tierInfo['tier'] . "\n";
    echo "=================================\n";

    // --- LÓGICA DE EXPORTACIÓN INTERACTIVA ---
    $confirmacion = readline("¿Deseas exportar los datos a un archivo JSON? (s/n): ");

    if (strtolower(trim($confirmacion)) === 's') {
        $combinedData = [
            'profile' => $profileData,
            'equipment' => $equipData,
            'statistics' => $statsData,
            'pvp_summary' => $pvpData
        ];

        $fileName = "{$charName}_{$realmSlug}_full_profile.json";
        file_put_contents($fileName, json_encode($combinedData, JSON_PRETTY_PRINT));
        echo "💾 Datos guardados exitosamente en: {$fileName}\n";
    } else {
        echo "🚫 Exportación cancelada por el usuario.\n";
    }
} catch (ClientException $e) {
    echo "\n❌ ERROR " . $e->getResponse()->getStatusCode() . "\n";
    $errorText = (string) $e->getResponse()->getBody();
    $errorBody = json_decode($errorText, true)['detail'] ?? $errorText;
    echo "Respuesta de Blizzard: " . $errorBody . "\n";
} catch (Exception $e) {
    echo "Error General: " . $e->getMessage() . "\n";
}
