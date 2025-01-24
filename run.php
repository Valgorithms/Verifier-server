<?php
require 'vendor/autoload.php';

use VerifierServer\PersistentState;
use VerifierServer\Server;

$verifyFile = PersistentState::loadVerifyFile();
$envConfig = PersistentState::loadEnvConfig();
$hostAddr = $envConfig['HOST_ADDR'] . ':' . $envConfig['HOST_PORT'];
$civToken = $envConfig['TOKEN'];
$storageType = $envConfig['STORAGE_TYPE'] ?? 'sql';
$state = new PersistentState($verifyFile, $civToken, $storageType);

$server = new Server($state, $hostAddr);
$server->start();
