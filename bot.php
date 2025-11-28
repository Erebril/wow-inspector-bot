<?php

include __DIR__ . '/vendor/autoload.php';

use Discord\Discord;
use Discord\Parts\Interactions\Interaction;
use Discord\Builders\MessageBuilder;
use Discord\WebSockets\Intents;
use Discord\WebSockets\Event; // <--- Importante para eventos modernos
use GuzzleHttp\Client;
use Dotenv\Dotenv;

// 1. Cargar configuración
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$discord = new Discord([
    'token' => $_ENV['DISCORD_TOKEN'],
    // Activamos todos los intents para asegurar que escuche todo durante el desarrollo
    'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT
]);

// 2. Usamos 'init' en lugar de 'ready' (Sintaxis moderna)
$discord->on('init', function (Discord $discord) {
    echo "✅ Bot iniciado correctamente (Evento INIT).\n";
    echo "👀 Escuchando eventos... (Si escribes /gs y no sale nada aquí, revisa el Developer Portal)\n";
});

// 3. Escuchar Interacciones
$discord->on(Event::INTERACTION_CREATE, function (Interaction $interaction, Discord $discord) {

    // Filtro: Solo nos interesa el comando /gs
    if ($interaction->data->name === 'gs') {

        echo "\n🔔 Interacción recibida de: {$interaction->user->username}\n";

        // Acknowledge inmediato
        $interaction->acknowledgeWithResponse(true)->then(function () use ($interaction) {

            // ... dentro del then() ...

            try {
                // --- CONFIGURACIÓN ---
                $blizzardClientId = $_ENV['BLIZZARD_CLIENT_ID'];
                $blizzardClientSecret = $_ENV['BLIZZARD_SECRET'];
                
                $region = 'us';
                $namespace = 'profile-classic1x-us'; // Anniversary
                $locale = 'en_US';

                $charName = strtolower($interaction->data->options['nombre']->value);
                $realmInput = isset($interaction->data->options['reino']) ? $interaction->data->options['reino']->value : 'nightslayer';
                $realmSlug = strtolower(str_replace([' ', '\''], ['-', ''], $realmInput));

                // 1. Obtener Token (Una sola vez vale para ambas llamadas)
                $httpClient = new Client();
                $authResponse = $httpClient->post("https://oauth.battle.net/token", [
                    'auth' => [$blizzardClientId, $blizzardClientSecret],
                    'form_params' => ['grant_type' => 'client_credentials']
                ]);
                $accessToken = json_decode($authResponse->getBody())->access_token;
                $headers = ['Authorization' => "Bearer $accessToken"];

                // --- LLAMADA A: EQUIPAMIENTO ---
                $equipUrl = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}/equipment";
                $equipResponse = $httpClient->get($equipUrl, [
                    'headers' => $headers,
                    'query' => ['namespace' => $namespace, 'locale' => $locale]
                ]);
                $equipData = json_decode($equipResponse->getBody(), true);

                // --- LLAMADA B: ESTADÍSTICAS (NUEVO) ---
                $statsUrl = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}/statistics";
                $statsResponse = $httpClient->get($statsUrl, [
                    'headers' => $headers,
                    'query' => ['namespace' => $namespace, 'locale' => $locale]
                ]);
                $statsData = json_decode($statsResponse->getBody(), true);

                // --- LLAMADA C: THUMBNAIL (NUEVO) ---
                $thumbnailUrl = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}/character-media";
                $thumbnailResponse = $httpClient->get($thumbnailUrl, [
                    'headers' => $headers,
                    'query' => ['namespace' => $namespace, 'locale' => $locale]
                ]);
                $thumbnailData = json_decode($thumbnailResponse->getBody(), true);
                $thumbnailPath = $thumbnailData['assets'][0]['value'] ?? 'https://render.worldofwarcraft.com/classic-us/icons/56/inv_misc_questionmark.jpg';
                
                // --- LLAMADA D: PROFILE DATA ---
                $profileUrl = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}";
                $profileResponse = $httpClient->get($profileUrl, [
                    'headers' => $headers,
                    'query' => ['namespace' => $namespace, 'locale' => $locale]
                ]);
                $profileData = json_decode($profileResponse->getBody(), true);

                // --- PROCESAMIENTO DE DATOS ---
                
                // 1. Procesar Equipo
                // $totalIlvl = 0;
                $itemsList = "";
                if (isset($equipData['equipped_items'])) {
                    foreach ($equipData['equipped_items'] as $item) {
                        if ((!isset($item['slot']['name']) || $item['slot']['name'] == 'Tabard' || $item['slot']['name'] == 'Shirt')) {
                            continue;
                        }
                        $slot = $item['slot']['name'];
                        $itemName = $item['name'];
                        $itemQuality = $item['quality']['name'];
                        $lvlrequiered = $item['requirements']['level']['value'] ?? 'N/A';
                        //agrega un emoticon dependiendo del $levelrequiered, un check verde si es lvl 60 o mas, un signo de exclamacion amarillo si es entre 50 y 59, y una cruz roja si es menor a 50, si no tiene item lvl requerido, no agrega emoticon
                        if ($lvlrequiered !== 'N/A') {
                            if ($lvlrequiered >= 60) {
                                $itemsList .= "✅ ";
                            } elseif ($lvlrequiered >= 50) {
                                $itemsList .= "⚠️ ";
                            } else {
                                $itemsList .= "❌ ";
                            }
                        } else {
                            //agregar un emoticon de interrogacion gris
                            $itemsList .= "❓ ";
                        }
                        //agrega un emoticon dependiendo de la calidad del item, circulos de colores gris para poor, blanco para common, verde para uncommon, azul para rare, morado para epic, naranja para legendary
                        switch ($itemQuality) {
                            case 'Poor':
                                $itemsList .= "⚪ ";
                                break;
                            case 'Common':
                                $itemsList .= "⚪ ";
                                break;
                            case 'Uncommon':
                                $itemsList .= "🟢 ";
                                break;
                            case 'Rare':
                                $itemsList .= "🔵 ";
                                break;
                            case 'Epic':
                                $itemsList .= "🟣 ";
                                break;
                            case 'Legendary':
                                $itemsList .= "🟠 ";
                                break;
                            default:
                                $itemsList .= "⚪ ";
                                break;
                        }
                        $itemsList .= "[$slot]: $itemName\n";
                    }
                }

                // 2. Procesar Stats (Extracción segura)
                // Nota: Usamos 'effective' para obtener el valor final con buffs/gear
                $hp = $statsData['health'] ?? 0;
                $powerType = $statsData['power_type']['name'] ?? 'Power';
                $power = $statsData['power'] ?? 0;
                
                $str = $statsData['strength']['effective'] ?? 0;
                $agi = $statsData['agility']['effective'] ?? 0;
                $sta = $statsData['stamina']['effective'] ?? 0;
                $int = $statsData['intellect']['effective'] ?? 0;
                $spr = $statsData['spirit']['effective'] ?? 0;

                // Críticos (Puede variar según clase, extraemos melee y spell)
                $meleeCrit = number_format($statsData['melee_crit']['value'] ?? 0, 2);
                $spellCrit = number_format($statsData['spell_crit']['value'] ?? 0, 2);

                // Construir string de stats
                $statsString = "❤️ **HP:** $hp\n";
                $statsString .= "⚡ **$powerType:** $power\n";
                $statsString .= "──────────\n";
                $statsString .= "💪 **Str:** $str\n";
                $statsString .= "🏃 **Agi:** $agi\n";
                $statsString .= "🛡️ **Sta:** $sta\n";
                $statsString .= "🧠 **Int:** $int\n";
                $statsString .= "👻 **Spi:** $spr\n";
                $statsString .= "──────────\n";
                $statsString .= "⚔️ **Crit:** $meleeCrit%\n";
                $statsString .= "🔥 **Spell Crit:** $spellCrit%";


                // --- RESPUESTA ---

                $charName = ucfirst($charName);
                $guildName = $profileData['guild']['name'] ?? 'Sin guild';
                $charRace = $profileData['race']['name'] ?? 'Desconocida';
                $charClass = $profileData['character_class']['name'] ?? 'Desconocida';
                $charLevel = $profileData['level'] ?? 'N/A';
                $charFaction = $profileData['faction']['name'] ?? 'Desconocida';
                $charRealm = $profileData['realm']['name'] ?? $realmInput;
                $equipItemLevel = $profileData['equipped_item_level'] ?? 'N/A';

                // Color from faction
                if ($charFaction === 'Horde') {
                    $color = 0xFF0000; // Rojo
                } elseif ($charFaction === 'Alliance') {
                    $color = 0x0000FF; // Azul
                } else {
                    $color = 0xAAAAAA; // Gris neutro
                }

                $builder = MessageBuilder::new()
                    ->setContent("Armory de **$charName**")
                    ->addEmbed([
                        'title' => "{$charName} - lvl {$charLevel} {$charRace} {$charClass} \n <{$guildName}> - {$charRealm} - ({$charFaction})",
                        'color' => $color,
                        'thumbnail' => ['url' => $thumbnailPath],
                        'fields' => [
                            [
                                'name' => "📦 Equipamiento (iLvl {$equipItemLevel})",
                                'value' => $itemsList ?: "Sin equipo",
                                'inline' => true
                            ],
                            [
                                'name' => '📊 Estadísticas',
                                'value' => $statsString,
                                'inline' => true
                            ]
                        ]
                    ]);

                $interaction->updateOriginalResponse($builder);

            } catch (\Exception $e) {
                // ... Tu manejo de errores anterior ...
                $msg = "Error: " . $e->getMessage();
                if (strpos($e->getMessage(), '404') !== false) {
                   $msg = "❌ Error 404: No se encontraron datos. Puede ser que el personaje sea nivel muy bajo y no tenga estadísticas generadas aún en la API, o el nombre/reino esté mal.";
                }
                $interaction->updateOriginalResponse(MessageBuilder::new()->setContent($msg));
            }

        }, function ($e) {
            echo "❌ Error al intentar conectar con Discord (Acknowledge): " . $e->getMessage() . "\n";
        });
    }
});

$discord->run();
