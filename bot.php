<?php

include __DIR__ . '/vendor/autoload.php';

// Cargamos las dependencias locales
require_once __DIR__ . '/GearScoreCalculator.php';
require_once __DIR__ . '/ItemCacheManager.php';
// Cargamos el nuevo archivo de comando
require_once __DIR__ . '/src/Commands/GearScoreCommand.php';
require_once __DIR__ . '/src/Commands/LogConsumablesCommand.php';
require_once __DIR__ . '/src/Commands/GearIssueCommand.php';

use Discord\Discord;
use Discord\Parts\Interactions\Interaction;
use Discord\WebSockets\Intents;
use Discord\WebSockets\Event;
use Dotenv\Dotenv;
use App\Commands\GearScoreCommand; // Importamos el namespace del comando
use App\Commands\LogConsumablesCommand;
use App\Commands\GearIssueCommand;

// 1. Cargar configuración de entorno
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$discord = new Discord([
    'token' => $_ENV['DISCORD_TOKEN'],
    'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT
]);

// 2. Evento de inicio
$discord->on('init', function (Discord $discord) {
    echo "✅ Bot conectado y listo.\n";
    echo "📡 Esperando comandos en Discord...\n";
});

// 3. Receptor de Interacciones (Comandos de barra "/")
$discord->on(Event::INTERACTION_CREATE, function (Interaction $interaction, Discord $discord) {
    
    // Identificamos qué comando se ha ejecutado
    switch ($interaction->data->name) {
        case 'gs':
            // Delegamos toda la lógica a nuestra nueva clase
            GearScoreCommand::run($interaction, $discord);
            break;

        // Aquí podrás añadir más casos fácilmente en el futuro
        case 'consumibles': // Nuevo comando
            LogConsumablesCommand::run($interaction);
            break;

        case 'gearissue':
            GearIssueCommand::run($interaction);
            break;

        default:
            echo "❓ Comando no reconocido: {$interaction->data->name}\n";
            break;
    }
});

$discord->run();