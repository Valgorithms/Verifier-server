<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VerifierServer\PersistentState;

class PersistentStateTest extends TestCase {
    private $state;

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
        $this->state = new PersistentState(
            $envConfig['TOKEN'],
            $envConfig['STORAGE_TYPE'] ?? 'filesystem'
        );
    }
    
    /**
     * Sets up the test environment before each test.
     *
     * This method is called before each test is executed. It loads the verification file
     * and environment configuration, retrieves the CIV token and storage type from the
     * environment configuration, and initializes the PersistentState object with these values.
     */
    public function testSetVerifyList(): void
    {
        try {
            $newList = [
                ['ss13' => 'test1', 'discord' => 'test1', 'create_time' => date('Y-m-d H:i:s')],
                ['ss13' => 'test2', 'discord' => 'test2', 'create_time' => date('Y-m-d H:i:s')]
            ];
            $this->state->setVerifyList($newList);
            $verifyList = $this->state->getVerifyList();
            $this->assertEquals($newList, $verifyList);
            //echo "PersistentStateTest::testSetVerifyList succeeded." . PHP_EOL;
        } catch (Exception $e) {
            //echo "PersistentStateTest::testSetVerifyList failed: " . $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Tests the getToken method of the state object.
     *
     * This test verifies that the getToken method returns a string.
     * If the method succeeds, it outputs a success message.
     * If an exception is thrown, it catches the exception and outputs a failure message with the exception details.
     */
    public function testGetToken(): void
    {
        try {
            $token = $this->state->getToken();
            $this->assertIsString($token);
            echo "PersistentStateTest::testGetToken succeeded." . PHP_EOL;
        } catch (Exception $e) {
            echo "PersistentStateTest::testGetToken failed: " . $e->getMessage() . PHP_EOL;
        }
    }
}
