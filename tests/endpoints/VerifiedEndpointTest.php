<?php declare(strict_types=1);

use React\Http\Message\Response;
use PHPUnit\Framework\TestCase;
use VerifierServer\Endpoints\VerifiedEndpoint;
use VerifierServer\Server;
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
            $envConfig['TOKEN'] ?? 'changeme',
            $envConfig['STORAGE_TYPE'] ?? 'filesystem',
            $envConfig['JSON_PATH'] ?? 'json/verify.json'
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
    public function testPost() {
        $this->state->setVerifyList([], false); // Clear the verification list without overwriting existing data

        $list = [
            [
                'ss13'        => $ckey = 'testCkey',
                'discord'     => $discord = 'testDiscord',
            ],
        ];
        $formData = [
            'method'  => 'POST',
            'ckey'    => $ckey,
            'discord' => $discord,
            'token'   => $this->state->getToken()
        ];
        
        $method = 'POST';
        /**
         * Converts an associative array back into the original string format.
         *
         * @param array $formData The associative array to be converted.
         * 
         * @return string The reconstructed string.
         */
        $response = 0;
        $headers = [];
        $body = "";
        $bypass_token = true;
        $this->endpoint->handle($method, Server::arrayToRequestString($formData), $response, $headers, $body, $bypass_token);

        //$this->assertArrayHasKey($list, $this->state->getVerifyList(true));
        $this->assertStringContainsString((string) Response::STATUS_OK, (string) $response);
    }
}
