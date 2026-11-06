<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use React\Http\Message\ServerRequest;
use VerifierServer\PersistentState;
use VerifierServer\SS14PersistentState;
use VerifierServer\Server;

final class FailingVerificationState extends PersistentState
{
    public function getVerifyList(bool $getLocalCache = false): array
    {
        throw new RuntimeException('storage unavailable');
    }
}
final class MemoryVerificationState extends PersistentState
{
    public static function writeJson(string $file, array $data): void {}
}
final class RecordingLogger extends AbstractLogger
{
    public array $records = [];
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message];
    }
}

final class RequestRobustnessTest extends TestCase
{
    private function server(PersistentState $state): Server
    {
        $server = new Server('127.0.0.1', 16261);
        $server->setState($state);
        return $server;
    }

    private function react(Server $server, ServerRequest $request): \Psr\Http\Message\ResponseInterface
    {
        return (new ReflectionMethod(Server::class, 'handleReact'))->invoke($server, $request);
    }

    public function testEndpointFailureIsA500AndDoesNotStopTheServer(): void
    {
        $server = $this->server(new FailingVerificationState('test-token'));
        $logger = new RecordingLogger();
        $server->setLogger($logger);
        $response = $this->react($server, new ServerRequest('GET', '/verified'));
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', (string) $response->getBody());
        self::assertNotEmpty($logger->records);
        self::assertStringContainsString('storage unavailable', $logger->records[0][1]);
        self::assertStringNotContainsString('[FATAL]', $logger->records[0][1]);
        $running = new ReflectionProperty(Server::class, 'running');
        $running->setValue($server, true);
        $server->logError(new RuntimeException('again'), false);
        self::assertTrue($running->getValue($server), 'a request error must not close the listener');
    }

    public function testTrailingSlashReachesTheVerifiedEndpoint(): void
    {
        $state = new MemoryVerificationState('test-token');
        $state->setVerifyList([['discord' => '123456789012345678', 'ss13' => 'alice']], false);
        $server = $this->server($state);
        foreach (['/verified', '/verified/', '/verified//', '/'] as $path) {
            $response = $this->react($server, new ServerRequest('GET', $path));
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'), $path);
        }
        self::assertSame(404, $this->react($server, new ServerRequest('GET', '/missing/'))->getStatusCode());
        self::assertNotNull($server->getEndpoint('/verified/'));

        $status = 404; $headers = []; $body = '';
        $server->handleEndpoint('/verified/', 'POST', "token: test-token".PHP_EOL."discord: 234567890123456789".PHP_EOL."ckey: bob", $status, $headers, $body);
        self::assertSame(200, $status);
        self::assertCount(2, $state->getVerifyList());
    }

    public function testTokenMismatchStillUnauthorized(): void
    {
        $state = new MemoryVerificationState('test-token');
        $state->setVerifyList([], false);
        $server = $this->server($state);
        $status = 404; $headers = []; $body = '';
        $server->handleEndpoint('/verified', 'POST', "token: test-tokem".PHP_EOL."discord: 234567890123456789".PHP_EOL."ckey: bob", $status, $headers, $body);
        self::assertSame(401, $status);
        self::assertSame([], $state->getVerifyList());
    }

    public function testSs14StateAccessor(): void
    {
        $server = new Server('127.0.0.1', 16261);
        self::assertNull($server->getSS14State());
        $ss14 = new SS14PersistentState('test-token');
        $server->setSS14State($ss14);
        self::assertSame($ss14, $server->getSS14State());
        self::assertNotNull($server->getEndpoint('/ss14verified/'));
    }
}
