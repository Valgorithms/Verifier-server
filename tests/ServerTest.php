<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use React\Http\HttpServer;
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
        $envConfig = PersistentState::loadEnvConfig();
        $hostAddr = $envConfig['HOST_ADDR'] . ':' . $envConfig['HOST_PORT'];
        $civToken = $envConfig['TOKEN'];
        $storageType = $envConfig['STORAGE_TYPE'] ?? 'filesystem';
        $jsonPath = $envConfig['JSON_PATH'] ?? 'json/verify.json';
        $state = new PersistentState($civToken, $storageType, $jsonPath);
        $this->server = new Server($hostAddr, null, $state);
    }

    /**
     * Tests the start method of the server.
     *
     * This test initializes the server, retrieves the server resource,
     * asserts that the resource is valid, and then stops the server.
     */
    public function testInit(): void
    {
        $this->server->init(null, true);
        $serverResource = $this->server->getServer();
        $this->assertIsResource($serverResource, "Expected start() to return a resource");
        $this->server->init(null, false);
        $serverResource = $this->server->getServer();
        $this->assertInstanceOf(HttpServer::class, $serverResource);
    }
}
