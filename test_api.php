<?php

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// --- CONFIGURACIÓN A REVISAR ---
// Si juegas en Europa, cambia esto a 'eu'
$region = 'us';

// Tu corrección aplicada aquí:
// Nota: Si cambias a region 'eu', esto debería ser 'profile-classic1x-eu'
$namespace = "profile-classicann-{$region}";

$locale = 'en_US'; // Si es EU, a veces conviene probar 'en_GB' o 'es_ES'
// -------------------------------

if (!isset($argv[1])) {
    die("Uso: php test_api.php <NombrePersonaje> [Reino]\n");
}

$charName = strtolower($argv[1]);
$realmRaw = $argv[2] ?? 'nightslayer';
// Convertir reino a slug (minúsculas y guiones en vez de espacios)
$realmSlug = strtolower(str_replace([' ', '\''], ['-', ''], $realmRaw));

// Credenciales
$blizzardClientId = $_ENV['BLIZZARD_CLIENT_ID'];
$blizzardClientSecret = $_ENV['BLIZZARD_SECRET'];

try {
    // 1. Obtener Token (Una sola vez vale para ambas llamadas)
    $httpClient = new Client();
    $authResponse = $httpClient->post("https://oauth.battle.net/token", [
        'auth' => [$blizzardClientId, $blizzardClientSecret],
        'form_params' => ['grant_type' => 'client_credentials']
    ]);
    $accessToken = json_decode($authResponse->getBody())->access_token;
    $headers = ['Authorization' => "Bearer $accessToken"];

    // Construcción de la URL
    // $url = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}";

    echo "2. Consultando Blizzard...\n";
    echo "   URL: $url\n";
    echo "   Namespace: $namespace\n";
    echo "   Region: $region\n";

    // $response = $client->get($url, [
    //     'query' => [
    //         'namespace' => $namespace,
    //         'locale' => $locale,
    //         // 'access_token' => $accessToken
    //     ],
    //     'headers' => [
    //         'Authorization' => "Bearer {$accessToken}"
    //     ],
    // ]);

    // $data = json_decode($response->getBody(), true);
    // echo "\n✅ ¡ÉXITO! Personaje encontrado.\n";
    // echo "---------------------------------\n";

    // // guardar el archivo JSON para revisión manual
    // file_put_contents("{$charName}_{$realmSlug}_profile.json", json_encode($data, JSON_PRETTY_PRINT));

    // echo "Datos guardados en: {$charName}_{$realmSlug}_profile.json\n";

    // --- LLAMADA A: EQUIPAMIENTO ---
    $equipUrl = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}/equipment";
    $equipResponse = $httpClient->get($equipUrl, [
        'headers' => $headers,
        'query' => ['namespace' => $namespace, 'locale' => $locale]
    ]);
    if ($equipResponse->getStatusCode() != 200) {
        echo "Error al obtener equipamiento: " . $equipResponse->getStatusCode();
    } else {
        echo "Equipamiento obtenido correctamente.\n";
    }

    $equipData = json_decode($equipResponse->getBody(), true);

    // --- LLAMADA B: ESTADÍSTICAS (NUEVO) ---
    $statsUrl = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}/statistics";
    $statsResponse = $httpClient->get($statsUrl, [
        'headers' => $headers,
        'query' => ['namespace' => $namespace, 'locale' => $locale]
    ]);
    if ($statsResponse->getStatusCode() != 200) {
        echo "Error al obtener estadísticas: " . $statsResponse->getStatusCode();
    } else {
        echo "Estadísticas obtenidas correctamente.\n";
    }

    $statsData = json_decode($statsResponse->getBody(), true);


    // --- LLAMADA C: THUMBNAIL (NUEVO) ---
    // $thumbnailUrl = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}/character-media";
    // $thumbnailResponse = $httpClient->get($thumbnailUrl, [
    //     'headers' => $headers,
    //     'query' => ['namespace' => $namespace, 'locale' => $locale]
    // ]);
    // if ($thumbnailResponse->getStatusCode() != 200) {
    //     echo "Error al obtener thumbnail: " . $thumbnailResponse->getStatusCode();
    // } else {
    //     echo "Thumbnail obtenido correctamente.\n";
    // }
    // $thumbnailData = json_decode($thumbnailResponse->getBody(), true);
    

    // --- LLAMADA D: PROFILE DATA ---
    $profileUrl = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}";
    $profileResponse = $httpClient->get($profileUrl, [
        'headers' => $headers,
        'query' => ['namespace' => $namespace, 'locale' => $locale]
    ]);
    if ($profileResponse->getStatusCode() != 200) {
        echo "Error al obtener datos del perfil: " . $profileResponse->getStatusCode();
    } else {
        echo "Datos del perfil obtenidos correctamente.\n";
    }
    $profileData = json_decode($profileResponse->getBody(), true);


    echo "\n✅ ¡ÉXITO! Personaje encontrado y datos obtenidos.\n";
    echo "---------------------------------\n";

    // guardar el archivo JSON para revisión manual
    $combinedData = [
        'profile' => $profileData,
        'equipment' => $equipData,
        'statistics' => $statsData,
        // 'thumbnail' => $thumbnailData,
    ];
    file_put_contents("{$charName}_{$realmSlug}_full_profile.json", json_encode($combinedData, JSON_PRETTY_PRINT));
    echo "Datos guardados en: {$charName}_{$realmSlug}_full_profile.json\n";

} catch (ClientException $e) {
    echo "\n❌ ERROR " . $e->getResponse()->getStatusCode() . "\n";

    // Imprimir el cuerpo del error que devuelve Blizzard para saber EXACTAMENTE qué pasa
    $errorText = (string) $e->getResponse()->getBody();
    $errorBody = json_decode($errorText, true)['detail'] ?? $errorText;
    echo "Respuesta de Blizzard: " . $errorBody . "\n";
    // Si $errorBody viene vacio, puede ser un problema de conexión o similar.

    if ($e->getResponse()->getStatusCode() == 404) {
        echo "\nPOSIBLES CAUSAS:\n";
        echo "1. El personaje no existe en el reino '$realmSlug'.\n";
        echo "2. El reino '$realmSlug' está mal escrito.\n";
        echo "3. Estás buscando en la región '$region' pero el personaje está en otra.\n";
        echo "4. El namespace '$namespace' no es válido para este tipo de reino (Era vs SoD).\n";
    }
} catch (Exception $e) {
    echo "Error General: " . $e->getMessage() . "\n";
}
