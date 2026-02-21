<?php

class GearScoreCalculator {
    // Pesos de ranura (SlotMOD) del script lua
    private static $slotMods = [
        'HEAD' => 1.0, 
        'NECK' => 0.5625, 
        'SHOULDER' => 0.75, 
        'BACK' => 0.5625,
        'CHEST' => 1.0, 
        'WRIST' => 0.5625, 
        'HAND' => 0.75, 
        'WAIST' => 0.75,
        'LEGS' => 1.0, 
        'FEET' => 0.75, 
        'FINGER' => 0.5625, 
        'TRINKET' => 0.5625,
        'CLOAK' => 0.5625, 
        'WEAPON' => 1.0, 
        'SHIELD' => 1.0, 
        'RANGED' => 0.3164,
        'TWOHAND' => 2.0, 
        'WEAPONMAINHAND' => 1.0, 
        'WEAPONOFFHAND' => 1.0,
        'HOLDABLE' => 1.0, 
        'RELIC' => 0.3164, 
        'THROWN' => 0.3164
    ];

    // Mapeo de Rarity String a ID (Blizzard API -> Addon ID)
    private static $rarityMap = [
        'POOR' => 0, 'COMMON' => 1, 'UNCOMMON' => 2, 'RARE' => 3, 'EPIC' => 4, 'LEGENDARY' => 5
    ];

    // Tablas de Fórmula A y B del script lua
    private static $formula = [
        'A' => [ // iLvl > 120
            4 => ['A' => 91.4500, 'B' => 0.6500], // Epic
            3 => ['A' => 81.3750, 'B' => 0.8125], // Rare
            2 => ['A' => 73.0000, 'B' => 1.0000], // Uncommon
        ],
        'B' => [ // iLvl <= 120
            4 => ['A' => 26.0000, 'B' => 1.2000],
            3 => ['A' => 0.7500,  'B' => 1.8000],
            2 => ['A' => 8.0000,  'B' => 2.0000],
            1 => ['A' => 0.0000,  'B' => 2.2500],
        ]
    ];

    public static function calculateItemScore($iLvl, $rarityStr, $invType) {
        $rarity = self::$rarityMap[$rarityStr] ?? 2;
        $slotMod = self::$slotMods[$invType] ?? 0;
        $scale = 1.8618;
        $qualityScale = 1.0;

        if ($slotMod == 0 || $rarity < 1) return 0;

        // Ajustes de calidad especiales del addon
        if ($rarity == 5) { $qualityScale = 1.3; $rarity = 4; } // Legendary
        elseif ($rarity < 2) { $qualityScale = 0.005; $rarity = 2; } // Common/Poor

        $table = ($iLvl > 120) ? self::$formula['A'] : self::$formula['B'];
        $f = $table[$rarity] ?? $table[2];

        $score = (($iLvl - $f['A']) / $f['B']) * $slotMod * $scale * $qualityScale;
        // retornar el entero redondeado hacia abajo para evitar decimales en el Gear Score final
        return floor(max(0, $score));
    }

    public static function getTierInfo($gs) {
        // En TBC el Bracket Size es 400
        if ($gs >= 2200) return ['tier' => 'Tier 6 / Sunwell', 'color' => 0xf1c40f];
        if ($gs >= 1800) return ['tier' => 'Tier 5 (SSC/TK)', 'color' => 0xa335ee];
        if ($gs >= 1400) return ['tier' => 'Tier 4 (Karazhan)', 'color' => 0x0070dd];
        if ($gs >= 1000)  return ['tier' => 'Heroics / Dungeon', 'color' => 0x1eff00];
        return ['tier' => 'Leveling / Fresh 70', 'color' => 0x9d9d9d];
    }
}