<?php declare(strict_types=1);

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace VerifierServer\Endpoints;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use VerifierServer\Endpoint;
use VerifierServer\Endpoints\Interfaces\AsyncEndpointInterface;
use VerifierServer\OAuth2Authenticator;
use VerifierServer\SessionCookie;

use function React\Promise\resolve;

/**
 * Browser-facing OAuth2 login endpoint (`/dwa`, `/ss14wa`).
 *
 * Sessions are keyed by the `verifier_session` cookie, which every OAuth2
 * endpoint on the server shares, and are stored as
 * `$sessions[<endpoint name>][<session id>]`. Provider calls are
 * asynchronous; the synchronous handle() only serves requests that need no
 * provider round trip.
 */
class OAuth2Endpoint extends Endpoint implements AsyncEndpointInterface
{
    protected string $auth = OAuth2Authenticator::class;
    protected array $allowed_methods = ['GET', 'POST'];
    protected ?OAuth2Authenticator $authenticator = null;
    /** @var callable|null */
    protected $http;

    public function __construct(
        protected array &$sessions,
        protected string $resolved_ip,
        protected string $web_address,
        protected int $http_port,
        protected string $client_id,
        protected string $client_secret,
        ?callable $http = null,
        protected ?LoopInterface $loop = null
    ) {
        $this->http = $http;
    }

    protected function authenticator(): OAuth2Authenticator
    {
        return $this->authenticator ??= new ($this->auth)(
            $this->http ?? $this->browserClient(),
            $this->resolved_ip,
            $this->web_address,
            $this->http_port,
            $this->client_id,
            $this->client_secret
        );
    }

    /** Default provider client: a bounded ReactPHP Browser on the server's loop. */
    protected function browserClient(): callable
    {
        $browser = (new Browser(null, $this->loop ?? Loop::get()))
            ->withTimeout(10)
            ->withFollowRedirects(false)
            ->withRejectErrorResponse(false);
        return static fn(string $method, string $url, array $headers, string $body): PromiseInterface
            => $browser->request($method, $url, $headers, $body);
    }

    public function handleAsync(string $method, ServerRequestInterface $request): PromiseInterface
    {
        if (!in_array($method, $this->allowed_methods, true)) {
            return resolve(new Response(Response::STATUS_METHOD_NOT_ALLOWED, ['Content-Type' => 'text/plain'], 'Method Not Allowed'));
        }
        $params = $request->getQueryParams();
        if (!$params) {
            return resolve(new Response(Response::STATUS_BAD_REQUEST, ['Content-Type' => 'text/plain'], 'Bad Request'));
        }

        $auth = $this->authenticator();
        $name = $auth->getEndpointName();
        [$id, $isNew] = SessionCookie::resolve($request);
        $this->sessions[$name] ??= [];
        SessionCookie::prune($this->sessions[$name]);
        $session = &$this->sessions[$name][$id];
        $session ??= [];
        $session['touched'] = time();

        if (isset($params['code'], $params['state']) && is_string($params['code']) && is_string($params['state'])) {
            $result = $auth->exchangeCode($request, $session, $params['code'], $params['state']);
        } elseif (isset($params['login'])) {
            $result = resolve($auth->login($request, $session));
        } elseif (isset($params['logout'])) {
            $result = resolve($auth->logout($session));
            $session['touched'] = time();
        } elseif (isset($params['remove'])) {
            $result = $auth->isAuthed($session)
                ? $auth->revoke($session)
                : resolve(new Response(Response::STATUS_UNAUTHORIZED, ['Content-Type' => 'text/plain'], 'Unauthorized'));
        } elseif (isset($params['user'])) {
            $result = $auth->fetchUser($session)->then(static fn(?object $user): ResponseInterface
                => new Response(Response::STATUS_OK, ['Content-Type' => 'application/json'], json_encode($user ?? [])));
        } else {
            $result = resolve(new Response(Response::STATUS_BAD_REQUEST, ['Content-Type' => 'text/plain'], 'Bad Request'));
        }

        return $result->then(static fn(ResponseInterface $response): ResponseInterface
            => $isNew ? SessionCookie::attach($response, $id) : $response);
    }

    /**
     * Synchronous adapter for in-process callers. Requests that need a
     * provider round trip cannot complete here and answer 503.
     */
    public function handle(
        string $method,
        $request,
        int|string &$response,
        array &$headers,
        string &$body
    ): void
    {
        if (!$request instanceof ServerRequestInterface) {
            $response = Response::STATUS_METHOD_NOT_ALLOWED;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Method Not Allowed';
            return;
        }
        $settled = null;
        $this->handleAsync($method, $request)->then(
            static function (ResponseInterface $result) use (&$settled): void { $settled = $result; },
            static function (\Throwable $e) use (&$settled): void { $settled = $e; }
        );
        if ($settled instanceof \Throwable) {
            throw $settled;
        }
        if (!$settled instanceof ResponseInterface) {
            $response = Response::STATUS_SERVICE_UNAVAILABLE;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'This request needs the asynchronous server.';
            return;
        }
        $response = $settled->getStatusCode();
        $headers = [];
        foreach ($settled->getHeaders() as $key => $values) $headers[$key] = implode(', ', $values);
        $body = (string) $settled->getBody();
    }

    public function __serialize(): array
    {
        $data = get_object_vars($this);
        unset($data['client_id'], $data['client_secret'], $data['http'], $data['loop'], $data['authenticator']);
        return $data;
    }

    public function __unserialize(array $data): void
    {
        foreach ($data as $key => $value) $this->$key = $value;
        $this->client_id = $_ENV['SS14_OAUTH2_CLIENT_ID'] ?? getenv('SS14_OAUTH2_CLIENT_ID') ?: '';
        $this->client_secret = $_ENV['SS14_OAUTH2_CLIENT_SECRET'] ?? getenv('SS14_OAUTH2_CLIENT_SECRET') ?: '';
    }

    public function __debugInfo(): array
    {
        $debugInfo = get_object_vars($this);
        unset($debugInfo['client_id'], $debugInfo['client_secret'], $debugInfo['http']);
        return $debugInfo;
    }
}
