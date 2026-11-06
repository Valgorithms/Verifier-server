<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VerifierServer\PersistentState;

final class EnvConfigTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/verifier-env-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->clearEnvironment();
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/.env');
        rmdir($this->directory);
        $this->clearEnvironment();
    }

    /** Dotenv is immutable: a value already in the environment wins, so each test starts clean. */
    private function clearEnvironment(): void
    {
        foreach (array_keys(PersistentState::ENV_DEFAULTS) as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    public function testMissingFileIsCreatedWithLocalhostDefaultsAndNoWarnings(): void
    {
        $config = PersistentState::loadEnvConfig($this->directory);
        self::assertFileExists($this->directory . '/.env');
        self::assertSame(PersistentState::ENV_DEFAULTS, $config);
        self::assertStringNotContainsString("\nDB_DSN=", file_get_contents($this->directory . '/.env'), 'no live database line in the generated file');
    }

    public function testFileValuesAreReadFromTheGivenDirectory(): void
    {
        file_put_contents($this->directory . '/.env', "HOST_ADDR=0.0.0.0\nHOST_PORT=16261\nTOKEN=secret\nSTORAGE_TYPE=database\nDB_DSN=sqlite:verify.db\n");
        $config = PersistentState::loadEnvConfig($this->directory);
        self::assertSame('0.0.0.0', $config['HOST_ADDR']);
        self::assertSame('16261', $config['HOST_PORT']);
        self::assertSame('secret', $config['TOKEN']);
        self::assertSame('database', $config['STORAGE_TYPE']);
        self::assertSame('sqlite:verify.db', $config['DB_DSN']);
        self::assertSame('', $config['DB_USERNAME']);
        self::assertSame('json/verify.json', $config['JSON_PATH']);
    }

    public function testRealEnvironmentOverridesTheFile(): void
    {
        file_put_contents($this->directory . '/.env', "TOKEN=from-file\n");
        putenv('TOKEN=from-environment');
        self::assertSame('from-environment', PersistentState::loadEnvConfig($this->directory)['TOKEN']);
    }

    public function testDefaultTokenOnAPublicAddressIsRefused(): void
    {
        file_put_contents($this->directory . '/.env', "HOST_ADDR=0.0.0.0\nTOKEN=changeme\n");
        $this->expectException(Exception::class);
        PersistentState::loadEnvConfig($this->directory);
    }
}
