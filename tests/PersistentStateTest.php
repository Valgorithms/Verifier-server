<?php
use PHPUnit\Framework\TestCase;
use VerifierServer\PersistentState;

class PersistentStateTest extends TestCase {
    private $state;

    protected function setUp(): void
    {
        $verifyFile = PersistentState::loadVerifyFile();
        $envConfig = PersistentState::loadEnvConfig();
        $civToken = $envConfig['TOKEN'];
        $storageType = $envConfig['STORAGE_TYPE'] ?? 'filesystem';
        $this->state = new PersistentState($verifyFile, $civToken, $storageType);
    }

    public function testGetVerifyList()
    {
        try {
            $verifyList = $this->state->getVerifyList();
            $this->assertIsArray($verifyList);
            echo "PersistentStateTest::testGetVerifyList succeeded." . PHP_EOL;
        } catch (Exception $e) {
            echo "PersistentStateTest::testGetVerifyList failed: " . $e->getMessage() . PHP_EOL;
        }
    }

    public function testSetVerifyList()
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

    public function testGetToken()
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
