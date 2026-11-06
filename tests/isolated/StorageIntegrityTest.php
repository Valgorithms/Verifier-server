<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VerifierServer\Endpoints\SS14VerifiedEndpoint;
use VerifierServer\Endpoints\VerifiedEndpoint;
use VerifierServer\PersistentState;
use VerifierServer\SS14PersistentState;

final class MemoryDatabaseState extends PersistentState
{
    protected function connect(): PDO { return new PDO('sqlite::memory:'); }
    public static function writeJson(string $file, array $data): void { throw new LogicException('database storage must not write JSON'); }
}
final class MemorySS14DatabaseState extends SS14PersistentState
{
    protected function connect(): PDO { return new PDO('sqlite::memory:'); }
    public static function writeJson(string $file, array $data): void { throw new LogicException('database storage must not write JSON'); }
}

final class StorageIntegrityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/verifier-storage-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
    }

    public function testCorruptFileIsRefusedInsteadOfBecomingAnEmptyList(): void
    {
        $file = $this->directory . '/verify.json';
        file_put_contents($file, '[{"discord":"123456789012345678","ss13":"ali');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON');
        PersistentState::loadJsonFile($file);
    }

    public function testEmptyAndMissingFilesLoadAsEmptyLists(): void
    {
        $file = $this->directory . '/nested/verify.json';
        self::assertSame([], PersistentState::loadJsonFile($file));
        self::assertFileExists($file);
        file_put_contents($file, "\n");
        self::assertSame([], PersistentState::loadJsonFile($file));
        unlink($file);
        rmdir($this->directory . '/nested');
    }

    public function testWriteReplacesTheFileAtomicallyAndLeavesNoTemporaryFiles(): void
    {
        $file = $this->directory . '/verify.json';
        $records = [['discord' => '123456789012345678', 'ss13' => 'alice', 'create_time' => '2026-01-01 00:00:00']];
        PersistentState::writeJson($file, $records);
        self::assertSame($records, json_decode(file_get_contents($file), true));
        PersistentState::writeJson($file, []);
        self::assertSame([], json_decode(file_get_contents($file), true));
        self::assertSame(['verify.json'], array_map('basename', glob($this->directory . '/*')));
    }

    public function testInvalidUtf8IsSubstitutedRatherThanWritingAnEmptyFile(): void
    {
        $file = $this->directory . '/verify.json';
        PersistentState::writeJson($file, [['discord' => '123456789012345678', 'ss13' => "al\xffice"]]);
        self::assertSame("al\u{FFFD}ice", json_decode(file_get_contents($file), true)[0]['ss13']);
    }

    public function testAbsoluteJsonPathIsUsedAsIs(): void
    {
        $file = $this->directory . '/absolute.json';
        $state = new PersistentState('test-token', 'filesystem', $file);
        self::assertSame($file, $state->getJsonPath());
        self::assertSame(getcwd() . '/json/verify.json', (new PersistentState('test-token'))->getJsonPath());
        self::assertTrue(PersistentState::isAbsolutePath('C:\\verifier\\verify.json'));
        self::assertTrue(PersistentState::isAbsolutePath('D:/verifier/verify.json'));
        self::assertTrue(PersistentState::isAbsolutePath('\\\\host\\share\\verify.json'));
        self::assertFalse(PersistentState::isAbsolutePath('json/verify.json'));
        $endpoint = new VerifiedEndpoint($state);
        $endpoint->add('123456789012345678', 'alice');
        self::assertSame('alice', json_decode(file_get_contents($file), true)[0]['ss13']);
        $reopened = new PersistentState('test-token', 'filesystem', $file);
        self::assertSame('alice', $reopened->getVerifyList()[0]['ss13']);
    }

    public function testDatabaseStorageSurvivesRepeatedInsertsAndDeletes(): void
    {
        $state = new MemoryDatabaseState('test-token', 'database', 'unused.json', 'verify_list');
        $endpoint = new VerifiedEndpoint($state);
        $endpoint->add('123456789012345678', 'alice');
        $endpoint->add('234567890123456789', 'bob');
        $rows = $state->getVerifyList();
        self::assertCount(2, $rows);
        self::assertSame(['alice', 'bob'], array_column($rows, 'ss13'));
        self::assertArrayHasKey('id', $rows[0]);
        self::assertSame(0, $endpoint->getIndex('123456789012345678'));
        self::assertSame(['123456789012345678'], array_column($endpoint->remove('123456789012345678'), 'discord'));
        self::assertSame(['bob'], array_column($state->getVerifyList(), 'ss13'));
        $endpoint->add('345678901234567890', 'carol');
        self::assertSame(['bob', 'carol'], array_column($state->getVerifyList(), 'ss13'));
    }

    public function testSs14DatabaseStorageUsesItsOwnColumns(): void
    {
        $state = new MemorySS14DatabaseState('test-token', 'database', 'unused.json', 'ss14_verify_list');
        $endpoint = new SS14VerifiedEndpoint($state);
        $endpoint->add('123456789012345678', 'alice');
        $endpoint->add('234567890123456789', 'bob');
        self::assertSame(['alice', 'bob'], array_column($state->getVerifyList(), 'ss14'));
        self::assertSame('bob', $endpoint->fetch('discord', '234567890123456789')['ss14']);
    }
}
