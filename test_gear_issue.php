<?php

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Commands/EnchantsIssueCommand.php';

use App\Commands\EnchantsIssueCommand;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$testUrl = $argv[1] ?? 'https://fresh.warcraftlogs.com/reports/Btw3kAXn2hxgZFHY';

try {
    $result = EnchantsIssueCommand::analyzeReportByUrl($testUrl);

    $report = $result['report'];
    $totals = $result['totals'];
    $playersWithIssues = $result['playersWithIssues'];
    $totalPlayers = $result['totalPlayers'];
    $playersWithIssuesCount = $result['playersWithIssuesCount'];
    $playersOk = max(0, $totalPlayers - $playersWithIssuesCount);

    echo "\n========================================\n";
    echo "ENCHANTS TEST (CONSOLA)\n";
    echo 'Reporte: ' . ($report['title'] ?? 'Sin titulo') . "\n";
    echo "URL: {$testUrl}\n";
    if (isset($report['_fightUsed'])) {
        echo "Pelea analizada: {$report['_fightUsed']}\n";
    }
    echo "========================================\n";
    echo "Total: {$totalPlayers} | OK: {$playersOk} | Issues: {$playersWithIssuesCount} | Sin enchant: {$totals['missingEnchants']} | Enchant malo: {$totals['badEnchants']}\n\n";
    echo "\n";

    echo "Top jugadores con issues\n";
    if (empty($playersWithIssues)) {
        echo "- Sin problemas detectados.\n";
    } else {
        foreach (array_slice($playersWithIssues, 0, 25) as $entry) {
            $parts = [];
            if (!empty($entry['missingSlots'])) {
                $parts[] = '❌ ' . implode(', ', $entry['missingSlots']);
            }
            if (!empty($entry['badEnchantIssues'])) {
                $parts[] = '⚠️ ' . implode(', ', $entry['badEnchantIssues']);
            }
            echo '- ' . $entry['name'] . ' (' . $entry['role'] . '): ' . implode(' | ', $parts) . "\n";
        }
    }

    echo "\nLeyenda: ❌=Sin enchant | ⚠️=Enchant malo\n";
    echo "========================================\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
