<?php

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Commands/PotionsCommand.php';

use App\Commands\PotionsCommand;

$method = new ReflectionMethod(PotionsCommand::class, 'getPotionSpellIds');
$method->setAccessible(true);
$spellIds = $method->invoke(null);

if (!is_array($spellIds) || count($spellIds) !== 2) {
    fwrite(STDERR, "FAIL: expected exactly 2 potion spells from the JSON dataset\n");
    exit(1);
}

$expectedIds = ['28507', '28508'];
foreach ($expectedIds as $spellId) {
    if (!array_key_exists($spellId, $spellIds)) {
        fwrite(STDERR, "FAIL: missing potion spell id '$spellId'\n");
        exit(1);
    }
}

fwrite(STDOUT, "PASS: only potion spell ids from the 'pociones' dataset were loaded\n");
