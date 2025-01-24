<?php
use PHPUnit\Framework\TestCase;
use VerifierServer\Endpoints\VerifiedEndpoint;
use VerifierServer\PersistentState;

class VerifiedEndpointTest extends TestCase {
    private VerifiedEndpoint $endpoint;
    private PersistentState $state;

    protected function setUp(): void {
        $verifyFile = PersistentState::loadVerifyFile();
        $envConfig = PersistentState::loadEnvConfig();
        $civToken = $envConfig['TOKEN'];
        $storageType = $envConfig['STORAGE_TYPE'] ?? 'filesystem';
        $this->state = new PersistentState($verifyFile, $civToken, $storageType);
        $this->endpoint = new VerifiedEndpoint($this->state);
    }

    public function testHandleDefault() {
        try {
            $list = [
                ['ss13' => 'test1', 'discord' => 'test1', 'create_time' => date('Y-m-d H:i:s')],
                ['ss13' => 'test2', 'discord' => 'test2', 'create_time' => date('Y-m-d H:i:s')]
            ];
            $this->state->setVerifyList($list);

            $response = "";
            $this->endpoint->handleDefault(false, $list, 'test3', 'test3', $response);

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
