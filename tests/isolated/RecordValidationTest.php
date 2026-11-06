<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;
use VerifierServer\Endpoints\SS14VerifiedEndpoint;
use VerifierServer\Endpoints\VerifiedEndpoint;
use VerifierServer\PersistentState;
use VerifierServer\SS14PersistentState;

final class ValidationMemoryState extends PersistentState
{
    public static function writeJson(string $file, array $data): void {}
}
final class ValidationMemorySS14State extends SS14PersistentState
{
    public static function writeJson(string $file, array $data): void {}
}

final class RecordValidationTest extends TestCase
{
    private function post(VerifiedEndpoint $endpoint, array $fields): array
    {
        $request = new ServerRequest('POST', '/verified', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields + ['token' => 'test-token']));
        $status = 404; $headers = []; $body = '';
        $endpoint->handle('POST', $request, $status, $headers, $body);
        return [$status, $body];
    }

    private function endpoint(array $list = []): VerifiedEndpoint
    {
        $state = new ValidationMemoryState('test-token');
        $state->setVerifyList($list, false);
        return new VerifiedEndpoint($state);
    }

    /** @dataProvider malformedRecords */
    public function testMalformedRecordsAreRejectedWith400(array $fields, string $problem): void
    {
        $endpoint = $this->endpoint();
        [$status, $body] = $this->post($endpoint, $fields);
        self::assertSame(400, $status);
        self::assertStringContainsString($problem, $body);
        self::assertSame([], $endpoint->getState()->getVerifyList());
    }

    public function malformedRecords(): array
    {
        return [
            'no fields' => [[], 'discord'],
            'short discord' => [['discord' => '123', 'ckey' => 'alice'], 'discord'],
            'non-numeric discord' => [['discord' => '12345678901234567x', 'ckey' => 'alice'], 'discord'],
            'mention' => [['discord' => '<@123456789012345678>', 'ckey' => 'alice'], 'discord'],
            'empty ckey' => [['discord' => '123456789012345678', 'ckey' => ''], 'ckey'],
            'ckey of separators only' => [['discord' => '123456789012345678', 'ckey' => '_ -.'], 'ckey'],
            'control characters' => [['discord' => '123456789012345678', 'ckey' => "al\x01ice"], 'ckey'],
            'unicode' => [['discord' => '123456789012345678', 'ckey' => 'alíce'], 'ckey'],
        ];
    }

    public function testKeysAreStoredAsNormalisedCkeys(): void
    {
        $endpoint = $this->endpoint();
        [$status, $body] = $this->post($endpoint, ['discord' => '123456789012345678', 'ckey' => ' Al_ice-Smith.Jr ']);
        self::assertSame(200, $status);
        self::assertSame('alicesmithjr', json_decode($body, true)[0]['ss13']);
        self::assertSame('alicesmithjr', $endpoint->getState()->getVerifyList()[0]['ss13']);
        self::assertSame(0, $endpoint->getIndex('', 'alicesmithjr'));
    }

    public function testAnotherSpellingOfARegisteredKeyIsADuplicate(): void
    {
        $endpoint = $this->endpoint([['discord' => '123456789012345678', 'ss13' => 'alice']]);
        [$status] = $this->post($endpoint, ['discord' => '234567890123456789', 'ckey' => 'Al_ice']);
        self::assertSame(403, $status);
        $endpoint = $this->endpoint([['discord' => '123456789012345678', 'ss13' => 'Al Ice']]);
        [$status] = $this->post($endpoint, ['discord' => '234567890123456789', 'ckey' => 'alice']);
        self::assertSame(403, $status, 'legacy records are compared after normalisation too');
        self::assertCount(1, $endpoint->getState()->getVerifyList());
        [$status] = $this->post($endpoint, ['discord' => '234567890123456789', 'ckey' => 'bob']);
        self::assertSame(200, $status);
        self::assertCount(2, $endpoint->getState()->getVerifyList());
    }

    public function testLegacyHeaderRequestsAreValidatedToo(): void
    {
        $endpoint = $this->endpoint();
        $status = 404; $headers = []; $body = '';
        $endpoint->handle('POST', "token: test-token".PHP_EOL."discord: 42".PHP_EOL."ckey: alice", $status, $headers, $body);
        self::assertSame(400, $status);
        $endpoint->handle('POST', "token: test-token".PHP_EOL."discord: 123456789012345678".PHP_EOL."ckey: Alice", $status, $headers, $body);
        self::assertSame(200, $status);
        self::assertSame('alice', $endpoint->getState()->getVerifyList()[0]['ss13']);
    }

    public function testDeletionDoesNotRequireAValidRecord(): void
    {
        $endpoint = $this->endpoint([['discord' => '123456789012345678', 'ss13' => 'alice']]);
        [$status] = $this->post($endpoint, ['method' => 'delete', 'discord' => 'alice', 'ckey' => 'alice']);
        self::assertSame(200, $status);
        self::assertSame([], $endpoint->getState()->getVerifyList());
    }

    public function testSs14NamesKeepTheirSpellingButMustBePrintable(): void
    {
        $state = new ValidationMemorySS14State('test-token');
        $state->setVerifyList([], false);
        $endpoint = new SS14VerifiedEndpoint($state);
        [$status] = $this->post($endpoint, ['discord' => '123456789012345678', 'ss14' => 'Space Captain_42']);
        self::assertSame(200, $status);
        self::assertSame('Space Captain_42', $state->getVerifyList()[0]['ss14']);
        [$status] = $this->post($endpoint, ['discord' => '234567890123456789', 'ss14' => "bad\x00name"]);
        self::assertSame(400, $status);
        [$status] = $this->post($endpoint, ['discord' => '123', 'ss14' => 'Someone']);
        self::assertSame(400, $status);
        [$status] = $this->post($endpoint, ['discord' => '234567890123456789', 'ss14' => 'Space Captain_42']);
        self::assertSame(403, $status);
    }
}
