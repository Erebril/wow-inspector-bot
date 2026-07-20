<?php

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Commands/LogConsumablesCommand.php';

use App\Commands\LogConsumablesCommand;

$method = new ReflectionMethod(LogConsumablesCommand::class, 'getSpellIds');
$method->setAccessible(true);
$spellIds = $method->invoke(null);

$excludedSpellIds = ['28507', '28508'];
foreach ($excludedSpellIds as $spellId) {
    if (array_key_exists($spellId, $spellIds)) {
        fwrite(STDERR, "FAIL: spell id '$spellId' should be excluded from consumables analysis\n");
        exit(1);
    }
}

fwrite(STDOUT, "PASS: pociones were excluded from consumables analysis\n");
