<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;
use VerifierServer\PersistentState;
use VerifierServer\Server;

final class ServerLoadingTest extends TestCase
{
    public function testServerLoadsAndRoutesWithoutBindingSocket(): void
    {
        $server = new Server('127.0.0.1', 16261);
        $state = new PersistentState('test-only-token');
        $list = [['discord' => '123', 'ss13' => 'alice']];
        $state->setVerifyList($list, false);
        $server->setState($state);
        $handler = new ReflectionMethod(Server::class, 'handleReact');
        $response = $handler->invoke($server, new ServerRequest('GET', '/verified'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($list, json_decode((string) $response->getBody(), true));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertNull($server->getServer());
        self::assertNull($server->getSocketServer());
        $server->close();
    }
}
