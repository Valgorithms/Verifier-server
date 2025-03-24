<?php declare(strict_types=1);

require 'vendor/autoload.php';

use VerifierServer\PersistentState;
use VerifierServer\Server;

$envConfig = PersistentState::loadEnvConfig();

$server = new Server(
    new PersistentState(
        $envConfig['TOKEN'],
        PersistentState::loadVerifyFile($envConfig['JSON_PATH'] ?? 'verify.json'),
        $envConfig['STORAGE_TYPE'] ?? 'filesystem',
        $envConfig['JSON_PATH'] ?? 'verify.json',
    ),
    $envConfig['HOST_ADDR'] . ':' . $envConfig['HOST_PORT']
);

$server->init(null, true);
$server->setLogger(true);
$server->start();
