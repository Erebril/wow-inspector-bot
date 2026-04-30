<?php

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Commands/LogConsumablesCommand.php';
require_once __DIR__ . '/src/Commands/EnchantsIssueCommand.php';
require_once __DIR__ . '/src/Commands/GemsIssueCommand.php';
require_once __DIR__ . '/src/Commands/ReportCommand.php';

use App\Commands\ReportCommand;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$testUrl = $argv[1] ?? 'https://fresh.warcraftlogs.com/reports/Btw3kAXn2hxgZFHY';

try {
    $result = ReportCommand::analyzeReportByUrl($testUrl);

    $summary = $result['summary'];
    $enchants = $result['enchants'];
    $gems = $result['gems'];
    $consumables = $result['consumables'];

    echo "\n========================================\n";
    echo "REPORT TEST (CONSOLA)\n";
    echo 'Reporte: ' . ($summary['title'] ?? 'Sin titulo') . "\n";
    echo "URL: {$testUrl}\n";
    echo "========================================\n";
    echo "Potencial: {$summary['potentialFormatted']}\n";
    echo "Faltantes totales: {$summary['missingTotal']}\n\n";

    echo "[ENCHANTS]\n";
    echo "Total: {$enchants['totalPlayers']} | OK: " . max(0, $enchants['totalPlayers'] - $enchants['playersWithIssuesCount']) . " | Issues: {$enchants['playersWithIssuesCount']}\n";
    echo "Sin enchant: {$enchants['totals']['missingEnchants']} | Enchant malo: {$enchants['totals']['badEnchants']}\n";
    if (isset($enchants['report']['_fightUsed'])) {
        echo "Pelea: {$enchants['report']['_fightUsed']}\n";
    }
    echo "\n";

    echo "[GEMAS]\n";
    echo "Total: {$gems['totalPlayers']} | OK: " . max(0, $gems['totalPlayers'] - $gems['playersWithIssuesCount']) . " | Issues: {$gems['playersWithIssuesCount']}\n";
    echo "Gemas < rare: {$gems['totals']['badGems']} | Meta issues: {$gems['totals']['metaIssues']}\n";
    if (isset($gems['report']['_fightUsed'])) {
        echo "Pelea: {$gems['report']['_fightUsed']}\n";
    }
    echo "\n";

    echo "[CONSUMIBLES]\n";
    echo "Total: {$consumables['totals']['totalPlayers']} | Con consumibles: {$consumables['totals']['withConsumables']} | Sin consumibles: {$consumables['totals']['withoutConsumables']}\n\n";

    echo "Jugadores sin consumibles:\n";
    if (empty($consumables['playersWithoutConsumables'])) {
        echo "- Todos tienen consumibles.\n";
    } else {
        foreach ($consumables['playersWithoutConsumables'] as $name) {
            echo "- {$name}\n";
        }
    }

    echo "========================================\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
