<?php declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
$state = new VerifierServer\PersistentState('benchmark-only');
$endpoint = new VerifierServer\Endpoints\VerifiedEndpoint($state);
foreach ([1000, 10000, 50000] as $size) {
    $rows = [];
    for ($i = 0; $i < $size; ++$i) $rows[] = ['discord' => (string) (100000 + $i), 'ss13' => 'ckey' . $i];
    $state->setVerifyList($rows, false);
    $target = (string) (100000 + $size - 1);
    $start = hrtime(true);
    for ($i = 0; $i < 200; ++$i) {
        $index = array_search('absent', array_column($rows, 'ss13'));
        if ($index === false) $index = array_search($target, array_column($rows, 'discord'));
    }
    $before = (hrtime(true) - $start) / 1e6;
    $start = hrtime(true);
    for ($i = 0; $i < 200; ++$i) $index = $endpoint->getIndex($target, 'absent');
    $after = (hrtime(true) - $start) / 1e6;
    if ($index !== $size - 1) throw new RuntimeException('Lookup mismatch');
    printf("%d rows, 200 lookups: columns %.3f ms; cached columns %.3f ms\n", $size, $before, $after);
}
