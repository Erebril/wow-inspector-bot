<?php

namespace App\Commands;

use Discord\Discord;
use Discord\Parts\Interactions\Interaction;
use Discord\Builders\MessageBuilder;
use GuzzleHttp\Client;
use GearScoreCalculator;
use ItemCacheManager;

class GearScoreCommand
{
    /**
     * Punto de entrada principal para el comando /gs
     */
    public static function run(Interaction $interaction, Discord $discord)
    {
        // 1. Acknowledge inmediato para evitar que la interacción expire (Discord da 3 segundos)
        $interaction->acknowledgeWithResponse(true)->then(function () use ($interaction) {
            try {
                self::executeLogic($interaction);
            } catch (\Exception $e) {
                self::handleError($interaction, $e);
            }
        });
    }

    /**
     * Lógica central del comando
     */
    private static function executeLogic(Interaction $interaction)
    {
        $httpClient = new Client();
        
        // --- CONFIGURACIÓN Y PARÁMETROS ---
        $region = 'us';
        $namespace = 'profile-classicann-us'; // Anniversary
        $locale = 'en_US';

        $charName = strtolower($interaction->data->options['nombre']->value);
        $charName = urlencode($charName); // Asegura que caracteres especiales no rompan la URL
        $realmInput = $interaction->data->options['reino']->value ?? 'nightslayer';
        $realmSlug = strtolower(str_replace([' ', '\''], ['-', ''], $realmInput));

        // 1. Obtener Access Token de Blizzard
        $accessToken = self::getAccessToken($httpClient);
        $headers = ['Authorization' => "Bearer $accessToken"];

        // 2. Obtener Datos del Personaje (Paralelizable, pero aquí secuencial para claridad)
        $equipData = self::fetchBlizzard($httpClient, "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}/equipment", $headers, $namespace);
        $statsData = self::fetchBlizzard($httpClient, "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}/statistics", $headers, $namespace);
        $mediaData = self::fetchBlizzard($httpClient, "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}/character-media", $headers, $namespace);
        $profileData = self::fetchBlizzard($httpClient, "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$charName}", $headers, $namespace);
        // Obtener Parses de WarcraftLogs (Opcional, requiere lógica adicional para mapear personaje a logs)
        $logsData = self::fetchWarcraftLogs($httpClient, $charName, $realmSlug);

        // 3. Procesar GearScore e Inventario
        $cache = new ItemCacheManager();
        $totalGearScore = 0;
        $itemsList = "";
        $items = $equipData['equipped_items'] ?? [];

        foreach ($items as $item) {
            if (in_array($item['slot']['name'] ?? '', ['Tabard', 'Shirt'])) continue;

            $itemId = $item['item']['id'];
            $cachedItem = $cache->getItem($itemId);

            if ($cachedItem) {
                $iLvl = $cachedItem['level'];
                $quality = $cachedItem['quality'];
                $invType = $cachedItem['inventory_type'];
            } else {
                // Consultar Data API para detalles del objeto
                $itemDetail = self::fetchBlizzard($httpClient, "https://{$region}.api.blizzard.com/data/wow/item/{$itemId}", $headers, "static-classicann-us");
                $iLvl = $itemDetail['level'] ?? 0;
                $quality = $itemDetail['quality']['type'] ?? 'COMMON';
                $invType = $itemDetail['inventory_type']['type'] ?? 'NON_EQUIP';
                $cache->saveItem($itemId, $itemDetail);
            }

            $totalGearScore += GearScoreCalculator::calculateItemScore($iLvl, $quality, $invType);
            $itemsList .= self::formatItemRow($item);
        }

        // 4. Preparar Datos Finales
        $tierInfo = GearScoreCalculator::getTierInfo(floor($totalGearScore));
        $thumbnail = $mediaData['assets'][0]['value'] ?? 'https://render.worldofwarcraft.com/classic-us/icons/56/inv_misc_questionmark.jpg';
        $statsString = self::buildStatsString($statsData);

        // 5. Construir Respuesta
        $charName = urldecode($charName); // Para mostrar el nombre con espacios y caracteres originales
        $builder = self::createEmbedBuilder(
            ucfirst($charName), $realmInput, $profileData, 
            floor($totalGearScore), $tierInfo['tier'], 
            $itemsList, $statsString, $thumbnail, $region
        );

        $interaction->updateOriginalResponse($builder);
    }

    /**
     * Helpers de Soporte
     */

    private static function getAccessToken(Client $client) {
        $response = $client->post("https://oauth.battle.net/token", [
            'auth' => [$_ENV['BLIZZARD_CLIENT_ID'], $_ENV['BLIZZARD_SECRET']],
            'form_params' => ['grant_type' => 'client_credentials']
        ]);
        return json_decode($response->getBody())->access_token;
    }

    private static function fetchBlizzard(Client $client, $url, $headers, $namespace) {
        $response = $client->get($url, [
            'headers' => $headers,
            'query' => ['namespace' => $namespace, 'locale' => 'en_US']
        ]);
        return json_decode($response->getBody(), true);
    }

    private static function fetchWarcraftLogs(Client $client, $charName, $realmSlug) {
        // Lógica para obtener datos de WarcraftLogs (requiere token y consultas GraphQL)
        // Este es un placeholder y debería implementarse según las necesidades específicas
        return null;
    }

    private static function formatItemRow($item) {
        $lvlReq = $item['requirements']['level']['value'] ?? 'N/A';
        $quality = $item['quality']['name'] ?? 'Common';
        
        $statusEmoji = ($lvlReq >= 70) ? "✅" : (($lvlReq >= 66) ? "⚠️" : "❌");
        if ($lvlReq === 'N/A') $statusEmoji = "❓";

        $qualityEmojis = [
            'Poor' => "⚪", 'Common' => "⚪", 'Uncommon' => "🟢", 
            'Rare' => "🔵", 'Epic' => "🟣", 'Legendary' => "🟠"
        ];
        $qEmoji = $qualityEmojis[$quality] ?? "⚪";

        return "{$statusEmoji} {$qEmoji} [{$item['slot']['name']}]: {$item['name']}\n";
    }

    private static function buildStatsString($stats) {
        $powerType = $stats['power_type']['name'] ?? 'Power';
        return "❤️ **HP:** {$stats['health']}\n" .
               "⚡ **{$powerType}:** {$stats['power']}\n" .
               "──────────\n" .
               "💪 **Str:** {$stats['strength']['effective']}\n" .
               "🏃 **Agi:** {$stats['agility']['effective']}\n" .
               "🛡️ **Sta:** {$stats['stamina']['effective']}\n" .
               "🧠 **Int:** {$stats['intellect']['effective']}\n" .
               "──────────\n" .
               "🪓 **AP:** {$stats['attack_power']}\n" .
               "🧙 **SP:** {$stats['spell_power']}\n" .
               "🛡️ **Armor:** {$stats['armor']['effective']}\n" .
               "⚔️ **Melee Crit:** " . number_format($stats['melee_crit']['value'] ?? 0, 2) . "%\n" .
               "🏹 **Ranged Crit:** " . number_format($stats['ranged_crit']['value'] ?? 0, 2) . "%\n" .
               "🔥 **Spell Crit:** " . number_format($stats['spell_crit']['value'] ?? 0, 2) . "%";
    }

    private static function createEmbedBuilder($name, $realm, $profile, $gs, $tier, $items, $stats, $thumb, $region) {
        $guild = $profile['guild']['name'] ?? 'Sin guild';
        $color = ($profile['faction']['name'] === 'Horde') ? 0xFF0000 : 0x0000FF;
        $logsUrl = "https://fresh.warcraftlogs.com/character/{$region}/{$realm}/{$name}";

        return MessageBuilder::new()
            ->setContent("Reporte de **$name**")
            ->addEmbed([
                'title' => "{$name} - lvl {$profile['level']} {$profile['race']['name']} {$profile['character_class']['name']} \n <{$guild}> - {$realm} - ({$profile['faction']['name']})",
                'url' => $logsUrl,
                'color' => $color,
                'thumbnail' => ['url' => $thumb],
                'fields' => [
                    ['name' => "🏅 Gear Score", 'value' => "**$gs** - $tier", 'inline' => false],
                    // Logs
                    // ['name' => "📈 WarcraftLogs", 'value' => "[Ver Perfil en WCL]({$logsUrl})", 'inline' => false],
                    ['name' => "📦 Equipamiento (iLvl {$profile['equipped_item_level']})", 'value' => $items ?: "Sin equipo", 'inline' => true],
                    ['name' => '📊 Estadísticas', 'value' => $stats, 'inline' => true]
                ],
                'footer' => ['text' => "Datos Blizzard API | Programado por Erebril para <HATERS>"]
            ]);
    }

    private static function handleError(Interaction $interaction, \Exception $e) {
        $msg = (strpos($e->getMessage(), '404') !== false) 
            ? "❌ Error 404: Personaje no encontrado o nivel demasiado bajo." 
            : "Error: " . $e->getMessage();
        $interaction->updateOriginalResponse(MessageBuilder::new()->setContent($msg));
        //save error log
        file_put_contents(__DIR__ . '/../../logs/gear_score_errors.log', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", FILE_APPEND);
    }
}