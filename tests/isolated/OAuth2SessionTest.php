<?php declare(strict_types=1);

use Discord\Endpoints\OAuth2Endpoint as DiscordOAuth2Endpoint;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SS14\Endpoints\OAuth2Endpoint as SS14OAuth2Endpoint;
use VerifierServer\Server;
use VerifierServer\SessionCookie;

use function React\Promise\resolve;

/** Canned provider: records every call, answers from a URL-keyed table, never touches the network. */
final class FakeProvider
{
    public array $calls = [];
    public array $deferred = [];
    public function __construct(private array $answers, private bool $defer = false) {}
    public function __invoke(string $method, string $url, array $headers, string $body): PromiseInterface
    {
        $this->calls[] = [$method, $url, $headers, $body];
        $path = parse_url($url, PHP_URL_PATH);
        $response = new Response(200, ['Content-Type' => 'application/json'], json_encode($this->answers[$path] ?? ['error' => "no answer for $path"]));
        if (!$this->defer) return resolve($response);
        $deferred = new Deferred();
        $this->deferred[] = [$deferred, $response];
        return $deferred->promise();
    }
    public function settleAll(): void
    {
        while ($pair = array_shift($this->deferred)) $pair[0]->resolve($pair[1]);
    }
}

final class OAuth2SessionTest extends TestCase
{
    private array $sessions = [];

    private function discord(FakeProvider $provider): DiscordOAuth2Endpoint
    {
        return new DiscordOAuth2Endpoint($this->sessions, '203.0.113.5', 'verifier.example', 8080, 'client-id', 'client-secret', $provider);
    }

    private function request(string $query, ?string $cookie = null, string $method = 'GET'): ServerRequest
    {
        $request = new ServerRequest($method, "http://verifier.example:8080/dwa?$query");
        return $cookie === null ? $request : $request->withCookieParams([SessionCookie::NAME => $cookie]);
    }

    private function settle(PromiseInterface $promise): ResponseInterface
    {
        $result = null;
        $promise->then(function (ResponseInterface $r) use (&$result): void { $result = $r; }, function (Throwable $e): void { throw $e; });
        self::assertInstanceOf(ResponseInterface::class, $result, 'promise did not settle synchronously');
        return $result;
    }

    private function cookieOf(ResponseInterface $response): string
    {
        self::assertMatchesRegularExpression('/^verifier_session=[0-9a-f]{32}; Path=\/; Max-Age=86400; HttpOnly; SameSite=Lax$/', $response->getHeaderLine('Set-Cookie'));
        return explode('=', explode(';', $response->getHeaderLine('Set-Cookie'))[0], 2)[1];
    }

    public function testLoginMintsACookieAndAnUnguessableStatePerBrowser(): void
    {
        $endpoint = $this->discord(new FakeProvider([]));
        $first = $this->settle($endpoint->handleAsync('GET', $this->request('login')));
        $second = $this->settle($endpoint->handleAsync('GET', $this->request('login')));
        self::assertSame(302, $first->getStatusCode());
        $a = $this->cookieOf($first); $b = $this->cookieOf($second);
        self::assertNotSame($a, $b, 'two browsers without cookies get distinct sessions');
        parse_str(parse_url($first->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        self::assertSame('https://discord.com/api/v10/oauth2/authorize', explode('?', $first->getHeaderLine('Location'))[0]);
        self::assertSame('client-id', $query['client_id']);
        self::assertSame('http://verifier.example:8080/dwa', $query['redirect_uri']);
        self::assertSame($this->sessions['dwa'][$a]['state'], $query['state']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $query['state']);
        self::assertNotSame($this->sessions['dwa'][$a]['state'], $this->sessions['dwa'][$b]['state']);
        // A returning browser keeps its session and gets no new cookie.
        $again = $this->settle($endpoint->handleAsync('GET', $this->request('login', $a)));
        self::assertSame('', $again->getHeaderLine('Set-Cookie'));
        self::assertCount(2, $this->sessions['dwa']);
    }

    public function testCodeExchangeStoresTheProfileUnderTheCookieOnly(): void
    {
        $provider = new FakeProvider([
            '/api/v10/oauth2/token' => ['access_token' => 'tok-123', 'token_type' => 'Bearer'],
            '/api/v10/users/@me' => ['id' => '123456789012345678', 'username' => 'alice'],
        ]);
        $endpoint = $this->discord($provider);
        $cookie = $this->cookieOf($this->settle($endpoint->handleAsync('GET', $this->request('login'))));
        $state = $this->sessions['dwa'][$cookie]['state'];

        $wrong = $this->settle($endpoint->handleAsync('GET', $this->request("code=abc&state=nope", $cookie)));
        self::assertSame(400, $wrong->getStatusCode());
        self::assertSame([], $provider->calls, 'no provider call before the state is verified');

        $ok = $this->settle($endpoint->handleAsync('GET', $this->request("code=abc&state=$state", $cookie)));
        self::assertSame(302, $ok->getStatusCode());
        self::assertSame('http://verifier.example:8080', $ok->getHeaderLine('Location'));
        self::assertSame('POST', $provider->calls[0][0]);
        parse_str($provider->calls[0][3], $tokenRequest);
        self::assertSame(['client_id' => 'client-id', 'client_secret' => 'client-secret', 'grant_type' => 'authorization_code', 'code' => 'abc', 'redirect_uri' => 'http://verifier.example:8080/dwa'], $tokenRequest);
        self::assertSame('Bearer tok-123', $provider->calls[1][2]['Authorization']);
        self::assertSame('123456789012345678', $this->sessions['dwa'][$cookie]['user']->id);
        self::assertArrayNotHasKey('state', $this->sessions['dwa'][$cookie], 'a code is exchanged once per login');

        // The profile is served to the browser holding the cookie and to nobody else.
        $mine = $this->settle($endpoint->handleAsync('GET', $this->request('user', $cookie)));
        self::assertSame('alice', json_decode((string) $mine->getBody())->username);
        $stranger = $this->settle($endpoint->handleAsync('GET', $this->request('user')));
        self::assertSame('[]', (string) $stranger->getBody());
        self::assertCount(2, $provider->calls, 'cached profile is not fetched again');

        $this->settle($endpoint->handleAsync('GET', $this->request('logout', $cookie)));
        self::assertArrayNotHasKey('user', $this->sessions['dwa'][$cookie]);
        self::assertSame('[]', (string) $this->settle($endpoint->handleAsync('GET', $this->request('user', $cookie)))->getBody());
    }

    public function testTheSameCookieLinksDiscordAndSs14SessionsForTheBot(): void
    {
        $discord = $this->discord(new FakeProvider([
            '/api/v10/oauth2/token' => ['access_token' => 'd'], '/api/v10/users/@me' => ['id' => '123456789012345678'],
        ]));
        $ss14 = new SS14OAuth2Endpoint($this->sessions, '203.0.113.5', 'verifier.example', 8080, 'ss14-id', 'ss14-secret', new FakeProvider([
            '/connect/token' => ['access_token' => 's'], '/connect/userinfo' => ['name' => 'Alice'],
        ]));
        $cookie = $this->cookieOf($this->settle($discord->handleAsync('GET', $this->request('login'))));
        $this->settle($discord->handleAsync('GET', $this->request('code=x&state=' . $this->sessions['dwa'][$cookie]['state'], $cookie)));
        $this->settle($ss14->handleAsync('GET', (new ServerRequest('GET', 'http://verifier.example:8080/ss14wa?login'))->withCookieParams([SessionCookie::NAME => $cookie])));
        $this->settle($ss14->handleAsync('GET', (new ServerRequest('GET', 'http://verifier.example:8080/ss14wa?code=y&state=' . $this->sessions['ss14wa'][$cookie]['state']))->withCookieParams([SessionCookie::NAME => $cookie])));
        // Civilizationbot's Civ14 verifier walks $sessions['dwa'] for the Discord id and reads $sessions['ss14wa'][<same key>]['user']->name.
        $key = null;
        foreach ($this->sessions['dwa'] as $id => $session) if (($session['user']->id ?? null) === '123456789012345678') $key = $id;
        self::assertSame($cookie, $key);
        self::assertSame('Alice', $this->sessions['ss14wa'][$key]['user']->name);
    }

    public function testIdleSessionsExpireAndForgedCookiesAreReplaced(): void
    {
        $endpoint = $this->discord(new FakeProvider([]));
        $old = $this->cookieOf($this->settle($endpoint->handleAsync('GET', $this->request('login'))));
        $this->sessions['dwa'][$old]['touched'] = time() - SessionCookie::TTL - 1;
        $forged = $this->settle($endpoint->handleAsync('GET', $this->request('login', '../etc/passwd')));
        self::assertNotSame('', $forged->getHeaderLine('Set-Cookie'), 'an invalid cookie value gets a fresh id');
        self::assertArrayNotHasKey($old, $this->sessions['dwa']);
        self::assertArrayNotHasKey('../etc/passwd', $this->sessions['dwa']);
        self::assertCount(1, $this->sessions['dwa']);
    }

    public function testProviderCallsDoNotBlockTheServer(): void
    {
        $provider = new FakeProvider(['/api/v10/oauth2/token' => ['access_token' => 't'], '/api/v10/users/@me' => ['id' => '1']], true);
        $endpoint = $this->discord($provider);
        $cookie = $this->cookieOf($this->settle($endpoint->handleAsync('GET', $this->request('login'))));
        $state = $this->sessions['dwa'][$cookie]['state'];

        // Through the ReactPHP server the exchange returns a pending promise...
        $server = new Server('127.0.0.1', 8080);
        $server->setState(new VerifierServer\PersistentState('test-token'));
        (new ReflectionProperty(Server::class, 'endpoints'))->setValue($server, ['/dwa' => $endpoint]);
        $pending = (new ReflectionMethod(Server::class, 'handleReact'))->invoke($server, $this->request("code=abc&state=$state", $cookie));
        self::assertInstanceOf(PromiseInterface::class, $pending);
        $result = null;
        $pending->then(function (ResponseInterface $r) use (&$result): void { $result = $r; });
        self::assertNull($result);
        $provider->settleAll(); // token answer arrives
        $provider->settleAll(); // profile answer arrives
        self::assertSame(302, $result->getStatusCode());
        self::assertSame('1', $this->sessions['dwa'][$cookie]['user']->id);

        // ...while the synchronous in-process adapter cannot wait and says so.
        $status = 0; $headers = []; $body = '';
        $endpoint->handle('GET', $this->request('login', $cookie), $status, $headers, $body);
        self::assertSame(302, $status);
        $endpoint->handle('GET', $this->request("code=abc&state=" . $this->sessions['dwa'][$cookie]['state'], $cookie), $status, $headers, $body);
        self::assertSame(302, $status, 'already authenticated: no provider call needed');
        $this->sessions['dwa'][$cookie] = ['touched' => time(), 'state' => 'st'];
        $endpoint->handle('GET', $this->request('code=abc&state=st', $cookie), $status, $headers, $body);
        self::assertSame(503, $status);
    }

    public function testProviderFailureIsA500ThroughTheServer(): void
    {
        $failing = static fn(): PromiseInterface => \React\Promise\reject(new RuntimeException('provider down'));
        $endpoint = new DiscordOAuth2Endpoint($this->sessions, '203.0.113.5', 'verifier.example', 8080, 'id', 'secret', $failing);
        $cookie = $this->cookieOf($this->settle($endpoint->handleAsync('GET', $this->request('login'))));
        $server = new Server('127.0.0.1', 8080);
        (new ReflectionProperty(Server::class, 'endpoints'))->setValue($server, ['/dwa' => $endpoint]);
        $response = $this->settle((new ReflectionMethod(Server::class, 'handleReact'))->invoke($server, $this->request('code=abc&state=' . $this->sessions['dwa'][$cookie]['state'], $cookie)));
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(405, $this->settle($endpoint->handleAsync('DELETE', $this->request('login')))->getStatusCode());
        self::assertSame(400, $this->settle($endpoint->handleAsync('GET', $this->request('')))->getStatusCode());
    }
}
