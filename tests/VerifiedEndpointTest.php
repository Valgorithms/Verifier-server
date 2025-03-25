<?php declare(strict_types=1);

use React\Http\Message\Response;
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
        $envConfig = PersistentState::loadEnvConfig();
        $this->state = new PersistentState(
            $envConfig['TOKEN'],
            $envConfig['STORAGE_TYPE'] ?? 'filesystem'
        );

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
        $list = [
            ['ss13' => 'test1', 'discord' => 'test1', 'create_time' => date('Y-m-d H:i:s')],
            ['ss13' => 'test2', 'discord' => 'test2', 'create_time' => date('Y-m-d H:i:s')]
        ];
        $this->state->setVerifyList($list);

        $response = "";
        $content_type = [];
        $body = "";
        $this->endpoint->handleDefault($list, 'test3', 'test3', $response, $content_type, $body);

        $expectedList = [
            ['ss13' => 'test1', 'discord' => 'test1', 'create_time' => date('Y-m-d H:i:s')],
            ['ss13' => 'test2', 'discord' => 'test2', 'create_time' => date('Y-m-d H:i:s')],
            ['ss13' => 'test3', 'discord' => 'test3', 'create_time' => date('Y-m-d H:i:s')]
        ];

        $this->assertEquals($expectedList, $this->state->getVerifyList());
        $this->assertStringContainsString((string) Response::STATUS_OK, (string) $response);
    }
}
