<?php

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Commands/LogConsumablesCommand.php';

use App\Commands\LogConsumablesCommand;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$testUrl = $argv[1] ?? 'https://fresh.warcraftlogs.com/reports/7m3THzXG1qhQryj2';

try {
    $result = LogConsumablesCommand::analyzeReportByUrl($testUrl);

    $report      = $result['report'];
    $totals      = $result['totals'];
    $withCons    = $result['playersWithConsumables'];
    $withoutCons = $result['playersWithoutConsumables'];

    echo "\n========================================\n";
    echo "TEST CONSUMIBLES (CONSOLA)\n";
    echo "Reporte: {$report['title']}\n";
    echo "URL: {$testUrl}\n";
    echo "========================================\n";
    echo "Total participantes : {$totals['totalPlayers']}\n";
    echo "Con consumibles     : {$totals['withConsumables']}\n";
    echo "Sin consumibles     : {$totals['withoutConsumables']}\n";

    echo "\n--- ✅ CON CONSUMIBLES ({$totals['withConsumables']}) ---\n";
    if (empty($withCons)) {
        echo "  (ninguno)\n";
    } else {
        foreach ($withCons as $p) {
            $buffs = implode(', ', $p['buffs']);
            echo "  {$p['name']}: {$buffs}\n";
        }
    }

    echo "\n--- ❌ SIN CONSUMIBLES ({$totals['withoutConsumables']}) ---\n";
    if (empty($withoutCons)) {
        echo "  ¡Todos listos!\n";
    } else {
        foreach ($withoutCons as $name) {
            echo "  {$name}\n";
        }
    }

    echo "========================================\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
