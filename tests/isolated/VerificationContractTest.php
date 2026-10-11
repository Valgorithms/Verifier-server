<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;
use VerifierServer\PersistentState;
use VerifierServer\SS14PersistentState;
use VerifierServer\Endpoints\VerifiedEndpoint;
use VerifierServer\Endpoints\SS14VerifiedEndpoint;

final class OfflineVerificationState extends PersistentState
{
    public static function writeJson(string $file, array $data): void {}
}
final class OfflineSS14VerificationState extends SS14PersistentState
{
    public static function writeJson(string $file, array $data): void {}
}
final class VerificationContractTest extends TestCase
{
    /** @dataProvider platforms */
    public function testRegistrationAndDuplicateContracts(bool $ss14): void
    {
        $state = $ss14 ? new OfflineSS14VerificationState('test-token') : new OfflineVerificationState('test-token');
        $state->setVerifyList([], false);
        $endpoint = $ss14 ? new SS14VerifiedEndpoint($state) : new VerifiedEndpoint($state);
        $field = $ss14 ? 'ss14' : 'ckey';
        $request = new ServerRequest('POST', '/verified', ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'], http_build_query(['token' => 'test-token', 'discord' => '123', $field => 'alice']));
        $status = 404; $headers = []; $body = '';
        $endpoint->handle('POST', $request, $status, $headers, $body);
        self::assertSame(200, $status);
        self::assertCount(1, json_decode($body, true));
        self::assertSame(0, $endpoint->getIndex('123'));
        $endpoint->handle('POST', $request, $status, $headers, $body);
        self::assertSame(403, $status);
        self::assertCount(1, $state->getVerifyList());
        $request = new ServerRequest('POST', '/verified', ['Content-Type' => 'application/x-www-form-urlencoded'], 'token=wrong&discord=456&'.$field.'=bob');
        $endpoint->handle('POST', $request, $status, $headers, $body);
        self::assertSame(401, $status);
        self::assertCount(1, $state->getVerifyList());
    }
    public function testDuplicateCkeyCannotLinkAnotherDiscord(): void
    {
        $state = new OfflineVerificationState('test-token');
        $state->setVerifyList([['discord' => '123', 'ss13' => 'alice']], false);
        $endpoint = new VerifiedEndpoint($state);
        $status = 404; $headers = []; $body = '';
        $request = new ServerRequest('POST', '/verified', ['Token' => 'test-token', 'Discord' => '456', 'Ckey' => 'alice']);
        $endpoint->handle('POST', $request, $status, $headers, $body);
        self::assertSame(403, $status);
        self::assertCount(1, $state->getVerifyList());
        $request = new ServerRequest('POST', '/verified', ['Token' => 'wrong', 'Content-Type' => 'application/x-www-form-urlencoded'], 'token=test-token&discord=456&ckey=bob');
        $endpoint->handle('POST', $request, $status, $headers, $body);
        self::assertSame(200, $status);
        self::assertCount(2, $state->getVerifyList());
    }

    public function platforms(): array { return [[false], [true]]; }

    public function testLegacyHeadersAndDiscordOnlyDeletion(): void
    {
        $state = new OfflineVerificationState('test-token');
        $state->setVerifyList([], false);
        $endpoint = new VerifiedEndpoint($state);
        $status = 404; $headers = []; $body = '';
        $endpoint->handle('POST', "token: test-token".PHP_EOL."discord: 123".PHP_EOL."ckey: alice", $status, $headers, $body);
        self::assertSame(200, $status);
        $endpoint->handle('POST', "token: test-token".PHP_EOL."discord: 123".PHP_EOL."method: delete", $status, $headers, $body);
        self::assertSame(200, $status);
        self::assertSame([], $state->getVerifyList());
    }

    public function testSparseKeysAndLookupPrecedence(): void
    {
        $state = new OfflineVerificationState('test-token');
        $state->setVerifyList([7 => ['discord' => '123', 'ss13' => 'alice'], 12 => ['discord' => '456', 'ss13' => 'bob']], false);
        $endpoint = new VerifiedEndpoint($state);
        self::assertSame(12, $endpoint->getIndex('123', 'bob'));
        self::assertSame(7, $endpoint->getIndex('123', 'absent'));
        self::assertFalse($endpoint->getIndex('', ''));
        self::assertFalse($endpoint->getIndex('absent'));
        $ss14 = new OfflineSS14VerificationState('test-token');
        $ss14->setVerifyList([7 => ['discord' => '123', 'ss14' => 'alice'], 12 => ['discord' => '456', 'ss14' => 'bob']], false);
        $endpoint = new SS14VerifiedEndpoint($ss14);
        self::assertSame(7, $endpoint->getIndex('123', 'bob'));
        self::assertSame(['discord' => '456', 'ss14' => 'bob'], $endpoint->fetch('ss14', 'bob'));
        self::assertNull($endpoint->fetch('ss14', 'absent'));
        $live = &$ss14->getVerifyList();
        $live[7]['discord'] = '999';
        self::assertFalse($endpoint->getIndex('123'));
        self::assertSame(7, $endpoint->getIndex('999'));
        $ss14->setVerifyList([], false);
        self::assertFalse($endpoint->getIndex('123'));
        self::assertNull($endpoint->fetch('ss14', 'bob'));
    }
}
