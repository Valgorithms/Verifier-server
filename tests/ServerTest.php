<?php
use PHPUnit\Framework\TestCase;
use VerifierServer\Server;
use VerifierServer\PersistentState;

class ServerTest extends TestCase {
    private Server $server;

    /**
     * Sets up the test environment before each test.
     *
     * This method initializes the server instance with the necessary configuration
     * and state. It loads the verification file and environment configuration,
     * constructs the host address, retrieves the token and storage type, and
     * creates a new PersistentState instance. Finally, it initializes the server
     * with the created state and host address.
     */
    protected function setUp(): void
    {
        $verifyFile = PersistentState::loadVerifyFile();
        $envConfig = PersistentState::loadEnvConfig();
        $hostAddr = $envConfig['HOST_ADDR'] . ':' . $envConfig['HOST_PORT'];
        $civToken = $envConfig['TOKEN'];
        $storageType = $envConfig['STORAGE_TYPE'] ?? 'filesystem';
        $state = new PersistentState($civToken, $verifyFile, $storageType);
        $this->server = new Server($state, $hostAddr);
    }

    /**
     * Tests the start method of the server.
     *
     * This test initializes the server, retrieves the server resource,
     * asserts that the resource is valid, and then stops the server.
     */
    public function testStart(): void
    {
        $this->server->init(true);
        $serverResource = $this->server->get();
        $this->assertIsResource($serverResource, "Expected start() to return a resource");
        $this->server->stop();
    }
}
