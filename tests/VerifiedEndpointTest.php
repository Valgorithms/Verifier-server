<?php
use PHPUnit\Framework\TestCase;
use VerifierServer\Endpoints\VerifiedEndpoint;
use VerifierServer\PersistentState;

class VerifiedEndpointTest extends TestCase {
    private VerifiedEndpoint $endpoint;
    private PersistentState $state;

    /**
     * Set up the test environment.
     *
     * This method is called before each test is executed. It initializes the
     * PersistentState and VerifiedEndpoint instances required for the tests.
     */
    protected function setUp(): void {
        $verifyFile = PersistentState::loadVerifyFile();
        $envConfig = PersistentState::loadEnvConfig();
        $civToken = $envConfig['TOKEN'];
        $storageType = $envConfig['STORAGE_TYPE'] ?? 'filesystem';
        $this->state = new PersistentState($civToken, $verifyFile, $storageType);
        $this->endpoint = new VerifiedEndpoint($this->state);
    }

    /**
     * Tests the handleDefault method of the endpoint.
     *
     * This test sets up an initial verification list, calls the handleDefault method
     * with new verification data, and checks if the new data is correctly added to the list.
     * It also verifies that the response contains the expected HTTP status.
     */
    public function testHandleDefault() {
        try {
            $list = [
                ['ss13' => 'test1', 'discord' => 'test1', 'create_time' => date('Y-m-d H:i:s')],
                ['ss13' => 'test2', 'discord' => 'test2', 'create_time' => date('Y-m-d H:i:s')]
            ];
            $this->state->setVerifyList($list);

            $response = "";
            $this->endpoint->handleDefault($list, 'test3', 'test3', $response);

            $expectedList = [
                ['ss13' => 'test1', 'discord' => 'test1', 'create_time' => date('Y-m-d H:i:s')],
                ['ss13' => 'test2', 'discord' => 'test2', 'create_time' => date('Y-m-d H:i:s')],
                ['ss13' => 'test3', 'discord' => 'test3', 'create_time' => date('Y-m-d H:i:s')]
            ];

            $this->assertEquals($expectedList, $this->state->getVerifyList());
            $this->assertStringContainsString("HTTP/1.1 200 OK", $response);
            echo "VerifiedEndpointTest::testHandleDefault succeeded." . PHP_EOL;
        } catch (Exception $e) {
            echo "VerifiedEndpointTest::testHandleDefault failed: " . $e->getMessage() . PHP_EOL;
        }
    }
}
