<?php
require 'vendor/autoload.php';

use VerifierServer\PersistentState;
use VerifierServer\Server;

$envConfig = PersistentState::loadEnvConfig();

$server = new Server(
    new PersistentState(
        $envConfig['TOKEN'],
        PersistentState::loadVerifyFile(),
        $envConfig['STORAGE_TYPE'] ?? 'filesystem'
    ),
    $envConfig['HOST_ADDR'] . ':' . $envConfig['HOST_PORT']
);

$server->init();
$server->setVerbose(true);
$server->start();
