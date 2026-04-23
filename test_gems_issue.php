<?php

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Commands/GemsIssueCommand.php';

use App\Commands\GemsIssueCommand;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$testUrl = $argv[1] ?? 'https://fresh.warcraftlogs.com/reports/VvYdAH91QqrXZLzj';

try {
    $result = GemsIssueCommand::analyzeReportByUrl($testUrl);

    $report = $result['report'];
    $totals = $result['totals'];
    $playersWithIssues = $result['playersWithIssues'];
    $totalPlayers = $result['totalPlayers'];
    $playersWithIssuesCount = $result['playersWithIssuesCount'];
    $playersOk = max(0, $totalPlayers - $playersWithIssuesCount);

    echo "\n========================================\n";
    echo "GEMS TEST (CONSOLA)\n";
    echo 'Reporte: ' . ($report['title'] ?? 'Sin titulo') . "\n";
    echo "URL: {$testUrl}\n";
    if (isset($report['_fightUsed'])) {
        echo "Pelea analizada: {$report['_fightUsed']}\n";
    }
    echo "========================================\n";
    echo "Total: {$totalPlayers} | OK: {$playersOk} | Issues: {$playersWithIssuesCount} | Gemas < rare: {$totals['badGems']} | Meta issues: {$totals['metaIssues']}\n\n";

    echo "Top jugadores con issues de gemas\n";
    if (empty($playersWithIssues)) {
        echo "- Sin problemas detectados.\n";
    } else {
        foreach (array_slice($playersWithIssues, 0, 25) as $entry) {
            $parts = [];
            if (!empty($entry['badGemIssues'])) {
                $parts[] = '⚠️ ' . implode(', ', $entry['badGemIssues']);
            }
            if (!empty($entry['metaIssues'])) {
                $parts[] = '🔶 ' . implode(', ', $entry['metaIssues']);
            }
            echo '- ' . $entry['name'] . ' (' . $entry['role'] . '): ' . implode(' | ', $parts) . "\n";
        }
    }

    echo "\nLeyenda: ⚠️=Gema por debajo de rare | 🔶=Meta faltante/inactiva\n";
    echo "========================================\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
