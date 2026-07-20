<?php

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Commands/PotionsCommand.php';

use App\Commands\PotionsCommand;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$testUrl = $argv[1] ?? 'https://fresh.warcraftlogs.com/reports/7m3THzXG1qhQryj2';

try {
    $result = PotionsCommand::analyzeReportByUrl($testUrl);

    $report = $result['report'];
    $players = $result['players'];

    echo "\n========================================\n";
    echo "TEST POCIONES (CONSOLA)\n";
    echo "Reporte: {$report['title']}\n";
    echo "URL: {$testUrl}\n";
    echo "========================================\n";
    echo "Total jugadores analizados : {$result['totals']['totalPlayers']}\n";

    echo "\n--- 📋 DETALLE POR JUGADOR ---\n";
    if (empty($players)) {
        echo "  (ninguno)\n";
    } else {
        foreach ($players as $player) {
            $details = [];
            foreach ($player['potionCounts'] as $potionName => $count) {
                $details[] = "$potionName: $count";
            }

            $detailsText = empty($details) ? 'Sin pociones' : implode(' | ', $details);
            echo "  {$player['name']}: {$detailsText}\n";
        }
    }

    echo "========================================\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
