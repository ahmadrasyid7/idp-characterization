<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Characterization\Config;
use Characterization\Fixtures;

if ($argc !== 3 || !in_array($argv[1], ['seed', 'cleanup'], true)) {
    fwrite(STDERR, "Usage: php fixtures.php seed|cleanup ZZTEST_<8 lowercase hex characters>\n");
    exit(2);
}

$fixtures = new Fixtures(new Config(), $argv[2]);
try {
    if ($argv[1] === 'seed') {
        $fixtures->seed();
        $fixtures->status();
    } else {
        $fixtures->cleanup();
    }
} finally {
    $fixtures->close();
}
fwrite(STDOUT, $argv[1].' complete: '.$fixtures->prefix."\n");
