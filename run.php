<?php declare(strict_types=1);

require 'vendor/autoload.php';

use VerifierServer\PersistentState;
use VerifierServer\Server;

$envConfig = PersistentState::loadEnvConfig(); // Load environment configuration (or use your own implementation)

$server = new Server(
    $envConfig['HOST_ADDR'] . ':' . $envConfig['HOST_PORT'],
    null,
    new PersistentState(
        $envConfig['TOKEN'],
        $envConfig['STORAGE_TYPE'] ?? 'filesystem',
        $envConfig['JSON_PATH'] ?? 'json/verify.json',
    )    
);

//$server->init(null, true); // Standalone without an event loop or ReactPHP server
$server->init(); // Standalone ReactPHP server
$server->setLogger(true); // (Optional) Pass an instance of Psr\Log\LoggerInterface;
$server->start(true); // Start the server and the event loop
