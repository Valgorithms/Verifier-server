<?php
use PHPUnit\Framework\TestCase;
use VerifierServer\Server;
use VerifierServer\PersistentState;

class ServerTest extends TestCase {
    private Server $server;

    protected function setUp(): void
    {
        $verifyFile = PersistentState::loadVerifyFile();
        $envConfig = PersistentState::loadEnvConfig();
        $hostAddr = $envConfig['HOST_ADDR'] . ':' . $envConfig['HOST_PORT'];
        $civToken = $envConfig['TOKEN'];
        $storageType = $envConfig['STORAGE_TYPE'] ?? 'filesystem';
        $state = new PersistentState($verifyFile, $civToken, $storageType);
        $this->server = new Server($state, $hostAddr);
    }

    public function testStart()
    {
        $serverResource = $this->server->start($test = true);
        $this->assertIsResource($serverResource, "Expected start() to return a resource");
    }
}
