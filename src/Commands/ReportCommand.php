<?php

namespace App\Commands;

use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;

class ReportCommand
{
    public static function run(Interaction $interaction)
    {
        // false => mensaje visible para todos (no ephemereal)
        $interaction->acknowledgeWithResponse(false)->then(function () use ($interaction) {
            try {
                $logUrl = self::normalizeLogUrl((string)$interaction->data->options['url']->value);
                $result = self::analyzeReportByUrl($logUrl);

                $summary = $result['summary'];
                $enchants = $result['enchants'];
                $gems = $result['gems'];
                $consumables = $result['consumables'];

                $potentialColor = $summary['potential'] < 85.0 ? 0xe67e22 : 0x2ecc71;

                $embed = [
                    'title' => '# Reporte: ' . ($summary['title'] ?? 'Sin titulo'),
                    'description' =>
                        "📈 Potencial del raid: **{$summary['potentialFormatted']}**\n" .
                        "Faltantes totales: **{$summary['missingTotal']}**\n\n" .
                        "⚙️ Enchants: ❌ sin enchant **{$enchants['totals']['missingEnchants']}** | ⚠️ malos **{$enchants['totals']['badEnchants']}**\n" .
                        "💎 Gemas: ⚠️ < rare **{$gems['totals']['badGems']}** | 🔶 meta issues **{$gems['totals']['metaIssues']}**\n" .
                        "🧪 Consumibles: ✅ con **{$consumables['totals']['withConsumables']}** | ❌ sin **{$consumables['totals']['withoutConsumables']}**",
                    'color' => $potentialColor,
                    'footer' => ['text' => 'WarcraftLogs API v2 | Reporte consolidado'],
                ];

                if (filter_var($logUrl, FILTER_VALIDATE_URL)) {
                    $embed['url'] = $logUrl;
                }

                $followUps = [];
                $followUps[] = self::buildEnchantsSection($enchants);
                $followUps[] = self::buildGemsSection($gems);
                $followUps[] = self::buildConsumablesSection($consumables);

                $interaction->updateOriginalResponse(MessageBuilder::new()->addEmbed($embed))->then(function () use ($interaction, $followUps) {
                    foreach ($followUps as $content) {
                        foreach (self::chunkText($content, 1900) as $chunk) {
                            $interaction->sendFollowUpMessage(
                                MessageBuilder::new()->setContent($chunk),
                                false
                            );
                        }
                    }
                });
            } catch (\Throwable $e) {
                $interaction->updateOriginalResponse(MessageBuilder::new()->setContent('❌ Error en /report: ' . $e->getMessage()));
                file_put_contents(
                    __DIR__ . '/../../logs/report_errors.log',
                    date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . "\n",
                    FILE_APPEND
                );
            }
        });
    }

    private static function normalizeLogUrl(string $input): string
    {
        $url = trim($input);
        if ($url === '') {
            return $url;
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return $url;
    }

    public static function analyzeReportByUrl(string $logUrl): array
    {
        $enchants = EnchantsIssueCommand::analyzeReportByUrl($logUrl);
        $gems = GemsIssueCommand::analyzeReportByUrl($logUrl);
        $consumables = LogConsumablesCommand::analyzeReportByUrl($logUrl);

        $missingTotal =
            (int)$enchants['totals']['missingEnchants'] +
            (int)$enchants['totals']['badEnchants'] +
            (int)$gems['totals']['badGems'] +
            (int)$gems['totals']['metaIssues'] +
            (int)$consumables['totals']['withoutConsumables'];

        // Potencial ponderado por faltantes reales con pesos definidos por categoria.
        $playersForEnchants = max(1, (int)$enchants['totalPlayers']);
        $playersForGems = max(1, (int)$gems['totalPlayers']);
        $playersForConsumables = max(1, (int)$consumables['totals']['totalPlayers']);

        // Pesos solicitados:
        // - Enchant faltante: 1.0
        // - Enchant malo: 0.5
        // - Gema baja calidad: 0.5
        // - Meta issue (incluye meta desactivada): 1.0
        // - Sin consumible: 1.0
        $weightMissingEnchant = 1.0;
        $weightBadEnchant = 0.5;
        $weightMissingGem = 1.0;
        $weightBadGem = 0.5;
        $weightMetaIssue = 1.0;
        $weightMissingConsumable = 1.0;

        // Actualmente no existe un contador dedicado de gema faltante en el analizador de gemas.
        // Se deja en 0 para mantener la formula alineada con la regla pedida y facilitar futura extension.
        $missingGems = 0;

        $weightedPenalty =
            ((int)$enchants['totals']['missingEnchants'] * $weightMissingEnchant) +
            ((int)$enchants['totals']['badEnchants'] * $weightBadEnchant) +
            ((int)$missingGems * $weightMissingGem) +
            ((int)$gems['totals']['badGems'] * $weightBadGem) +
            ((int)$gems['totals']['metaIssues'] * $weightMetaIssue) +
            ((int)$consumables['totals']['withoutConsumables'] * $weightMissingConsumable);

        // Capacidad maxima de penalizacion esperada del raid para normalizar a 0..100.
        // Enchants: 9 slots por jugador.
        // Gemas: 6 sockets promedio por jugador + 1 meta por jugador.
        // Consumibles: 1 check por jugador.
        $weightedCapacity =
            ($playersForEnchants * 9 * $weightMissingEnchant) +
            ($playersForGems * 6 * $weightMissingGem) +
            ($playersForGems * 1 * $weightMetaIssue) +
            ($playersForConsumables * 1 * $weightMissingConsumable);

        $penaltyRatio = $weightedCapacity > 0 ? ($weightedPenalty / $weightedCapacity) : 0.0;
        $potential = round(max(0.0, 100.0 - ($penaltyRatio * 100.0)), 2);

        return [
            'summary' => [
                'title' => (string)($enchants['report']['title'] ?? $gems['report']['title'] ?? $consumables['report']['title'] ?? 'Sin titulo'),
                'missingTotal' => $missingTotal,
                'potential' => $potential,
                'potentialFormatted' => number_format($potential, 2) . '%',
            ],
            'enchants' => $enchants,
            'gems' => $gems,
            'consumables' => $consumables,
        ];
    }

    private static function buildEnchantsSection(array $enchants): string
    {
        $lines = [];
        $lines[] = '# === ENCHANTS ===';
        $lines[] = 'Total: ' . $enchants['totalPlayers'] .
            ' | OK: ' . max(0, $enchants['totalPlayers'] - $enchants['playersWithIssuesCount']) .
            ' | Issues: ' . $enchants['playersWithIssuesCount'] .
            ' | Sin enchant: ' . $enchants['totals']['missingEnchants'] .
            ' | Enchant malo: ' . $enchants['totals']['badEnchants'];

        if (isset($enchants['report']['_fightUsed'])) {
            $lines[] = 'Pelea: ' . $enchants['report']['_fightUsed'];
        }

        $lines[] = '';
        $lines[] = 'Top jugadores con issues:';
        if (empty($enchants['playersWithIssues'])) {
            $lines[] = '- Sin problemas detectados.';
        } else {
            foreach (array_slice($enchants['playersWithIssues'], 0, 20) as $entry) {
                $parts = [];
                if (!empty($entry['missingSlots'])) {
                    $parts[] = '❌ ' . implode(', ', $entry['missingSlots']);
                }
                if (!empty($entry['badEnchantIssues'])) {
                    $parts[] = '⚠️ ' . implode(', ', $entry['badEnchantIssues']);
                }
                $lines[] = '- ' . $entry['name'] . ' (' . $entry['role'] . '): ' . implode(' | ', $parts);
            }
        }

        return implode("\n", $lines);
    }

    private static function buildGemsSection(array $gems): string
    {
        $lines = [];
        $lines[] = '# === GEMAS ===';
        $lines[] = 'Total: ' . $gems['totalPlayers'] .
            ' | OK: ' . max(0, $gems['totalPlayers'] - $gems['playersWithIssuesCount']) .
            ' | Issues: ' . $gems['playersWithIssuesCount'] .
            ' | Gemas < rare: ' . $gems['totals']['badGems'] .
            ' | Meta issues: ' . $gems['totals']['metaIssues'];

        if (isset($gems['report']['_fightUsed'])) {
            $lines[] = 'Pelea: ' . $gems['report']['_fightUsed'];
        }

        $lines[] = '';
        $lines[] = 'Top jugadores con issues:';
        if (empty($gems['playersWithIssues'])) {
            $lines[] = '- Sin problemas detectados.';
        } else {
            foreach (array_slice($gems['playersWithIssues'], 0, 20) as $entry) {
                $parts = [];
                if (!empty($entry['badGemIssues'])) {
                    $parts[] = '⚠️ ' . implode(', ', $entry['badGemIssues']);
                }
                if (!empty($entry['metaIssues'])) {
                    $parts[] = '🔶 ' . implode(', ', $entry['metaIssues']);
                }
                $lines[] = '- ' . $entry['name'] . ' (' . $entry['role'] . '): ' . implode(' | ', $parts);
            }
        }

        return implode("\n", $lines);
    }

    private static function buildConsumablesSection(array $consumables): string
    {
        $lines = [];
        $lines[] = '# === CONSUMIBLES ===';
        $lines[] = 'Total: ' . $consumables['totals']['totalPlayers'] .
            ' | Con consumibles: ' . $consumables['totals']['withConsumables'] .
            ' | Sin consumibles: ' . $consumables['totals']['withoutConsumables'];
        $lines[] = '';

        $lines[] = 'Sin consumibles:';
        if (empty($consumables['playersWithoutConsumables'])) {
            $lines[] = '- Todos tienen consumibles.';
        } else {
            foreach ($consumables['playersWithoutConsumables'] as $name) {
                $lines[] = '- ' . $name;
            }
        }

        return implode("\n", $lines);
    }

    private static function chunkText(string $text, int $maxChars): array
    {
        if (strlen($text) <= $maxChars) {
            return [$text];
        }

        $chunks = [];
        $current = '';
        foreach (explode("\n", $text) as $line) {
            $candidate = $current === '' ? $line : ($current . "\n" . $line);
            if (strlen($candidate) > $maxChars) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = $line;
                } else {
                    $chunks[] = substr($line, 0, $maxChars - 3) . '...';
                    $current = '';
                }
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
