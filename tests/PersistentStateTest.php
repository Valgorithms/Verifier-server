<?php
use PHPUnit\Framework\TestCase;

class PersistentStateTest extends TestCase {
    private $state;

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
            echo "PersistentStateTest::testSetVerifyList succeeded." . PHP_EOL;
        } catch (Exception $e) {
            echo "PersistentStateTest::testSetVerifyList failed: " . $e->getMessage() . PHP_EOL;
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
