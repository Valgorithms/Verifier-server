<?php declare(strict_types=1);

require 'vendor/autoload.php';

use VerifierServer\PersistentState;
use VerifierServer\Server;

$envConfig = PersistentState::loadEnvConfig(); // Load environment configuration (or use your own implementation)

$server = new Server(
    $envConfig['HOST_ADDR'] . ':' . $envConfig['HOST_PORT'],
);

//$server->init(null, true); // Standalone without an event loop or ReactPHP server
$server->init(); // Standalone ReactPHP server
$server->setLogger(true); // (Optional) Pass an instance of Psr\Log\LoggerInterface;
$server->setState([
    $envConfig['TOKEN'],
    $envConfig['STORAGE_TYPE'] ?? 'filesystem',
    $envConfig['JSON_PATH'] ?? 'json/verify.json',
]);
$server->setSS14State([
    $envConfig['TOKEN'],
    $envConfig['STORAGE_TYPE'] ?? 'filesystem',
    $envConfig['SS14_JSON_PATH'] ?? 'json/ss14verify.json',
]);
$server->setSS14OAuth2Endpoint(
    $_ENV['SS14_OAUTH2_CLIENT_ID'] ?? getenv('SS14_OAUTH2_CLIENT_ID'),
    $_ENV['SS14_OAUTH2_CLIENT_SECRET'] ?? getenv('SS14_OAUTH2_CLIENT_SECRET')
);
$server->setDiscordOAuth2Endpoint(
    $_ENV['dwa_client_id'] ?? getenv('dwa_client_id'),
    $_ENV['dwa_client_secret'] ?? getenv('dwa_client_secret')
);
$server->start(true); // Start the server and the event loop
