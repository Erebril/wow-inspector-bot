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
$namespace = "profile-classic1x-{$region}"; 

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
$clientId = $_ENV['BLIZZARD_CLIENT_ID'];
$clientSecret = $_ENV['BLIZZARD_SECRET'];

try {
    $client = new Client();

    echo "1. Obteniendo Token OAuth...\n";
    $tokenResponse = $client->post("https://oauth.battle.net/token", [
        'auth' => [$clientId, $clientSecret],
        'form_params' => ['grant_type' => 'client_credentials'],
    ]);
    $accessToken = json_decode($tokenResponse->getBody())->access_token;

    // Construcción de la URL
    $url = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}/equipment";
    
    echo "2. Consultando Blizzard...\n";
    echo "   URL: $url\n";
    echo "   Namespace: $namespace\n";
    echo "   Region: $region\n";

    $response = $client->get($url, [
        'query' => [
            'namespace' => $namespace,
            'locale' => $locale,
            // 'access_token' => $accessToken
        ],
        'headers' => [
            'Authorization' => "Bearer {$accessToken}"
        ],
    ]);

    $data = json_decode($response->getBody(), true);
    echo "\n✅ ¡ÉXITO! Personaje encontrado.\n";
    echo "---------------------------------\n";

    // guardar el archivo JSON para revisión manual
    file_put_contents("{$charName}_{$realmSlug}_equipment.json", json_encode($data, JSON_PRETTY_PRINT));
    
    // Aquí puedes procesar $data para calcular el GearScore o lo que necesites
    //muestra una lista de los items con el siguiente formato [slot]: [item name] (item quiality: [quality])
    foreach ($data['equipped_items'] as $item) {
        if ((!isset($item['slot']['name']) || $item['slot']['name'] == 'Tabard' || $item['slot']['name'] == 'Shirt')) {
            continue;
        }
        $slot = $item['slot']['name'];
        $itemName = $item['name'];
        $itemQuality = $item['quality']['name'];
        $lvlrequiered = $item['requirements']['level']['value'] ?? 'N/A';
        echo "- [$slot]: $itemName ($itemQuality - Lvl Req: $lvlrequiered)\n";
    }

    // imprime la suma de stats de todos los items equipados
    $stats = [
        'strength' => 0,
        'agility' => 0,
        'stamina' => 0,
        'intellect' => 0,
        'spirit' => 0,
    ];
    foreach ($data['equipped_items'] as $item) {
        if (isset($item['stats'])) {
            foreach ($item['stats'] as $stat) {
                $statName = strtolower(str_replace(' ', '_', $stat['type']['name']));
                if (isset($stats[$statName])) {
                    $stats[$statName] += $stat['value'];
                }
            }
        }
    }
    echo "\n📊 Suma de Stats Equipados:\n"
       . "Fuerza: {$stats['strength']}\n"
       . "Agilidad: {$stats['agility']}\n"
       . "Aguante: {$stats['stamina']}\n"
       . "Intelecto: {$stats['intellect']}\n"
       . "Espíritu: {$stats['spirit']}\n";



    
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