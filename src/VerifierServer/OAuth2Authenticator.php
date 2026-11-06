<?php declare(strict_types=1);

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace VerifierServer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Authorization-code flow against an OAuth2 provider.
 *
 * One instance serves every browser; the per-browser state (CSRF state,
 * access token, user profile) lives in the `$session` array each method
 * receives by reference. Provider requests go through an injected
 * asynchronous HTTP client, so nothing here blocks the event loop.
 */
class OAuth2Authenticator
{
    protected string $oidc_config;
    protected string $issuer;
    protected string $authorization_endpoint;
    protected string $token_endpoint;
    protected string $userinfo_endpoint;
    protected string $revocation_endpoint;

    /** @var callable(string $method, string $url, array $headers, string $body): PromiseInterface<ResponseInterface> */
    protected $http;

    /**
     * @param callable $http          Asynchronous HTTP client, see the property above.
     * @param string   $resolved_ip   The server's public IP, accepted as an alternative redirect host.
     * @param string   $web_address   The public host name the provider redirects back to.
     * @param int      $http_port     The public port.
     * @param string   $endpoint_name Path of the endpoint, without slash; also the session namespace.
     */
    public function __construct(
        callable $http,
        protected string $resolved_ip,
        protected string $web_address,
        protected int $http_port,
        protected string $client_id,
        protected string $client_secret,
        protected string $endpoint_name,
        protected string $scope
    ) {
        $this->http = $http;
    }

    public function getEndpointName(): string
    {
        return $this->endpoint_name;
    }

    /** Where the browser lands after login or logout. TLS is terminated elsewhere, if at all. */
    protected function redirectHome(): string
    {
        return "http://{$this->web_address}:{$this->http_port}";
    }

    /** Redirect URIs the provider may send the browser back to; the first is canonical. */
    protected function allowedRedirects(): array
    {
        $allowed = [$this->redirectHome() . "/{$this->endpoint_name}", $this->redirectHome()];
        if ($this->resolved_ip !== '') {
            $allowed[] = "http://{$this->resolved_ip}:{$this->http_port}/";
            $allowed[] = "http://{$this->resolved_ip}:{$this->http_port}/{$this->endpoint_name}";
        }
        return $allowed;
    }

    /** The redirect URI matching the host the browser used, or the canonical one. */
    protected function redirectFor(ServerRequestInterface $request): string
    {
        $host = $request->getUri()->getHost();
        if ($host === '127.0.0.1' || $host === 'localhost') $host = $this->resolved_ip ?: $host;
        $candidate = "http://$host:{$this->http_port}" . explode('?', $request->getUri()->getPath())[0];
        return in_array($candidate, $this->allowedRedirects(), true) ? $candidate : $this->allowedRedirects()[0];
    }

    private function text(int $status, string $body): Response
    {
        return new Response($status, ['Content-Type' => 'text/plain'], $body);
    }

    private function redirect(string $location): Response
    {
        return new Response(Response::STATUS_FOUND, ['Location' => $location], '');
    }

    /** Sends the browser to the provider's consent page with a fresh, unguessable state. */
    public function login(ServerRequestInterface $request, array &$session, ?string $scope = null): ResponseInterface
    {
        if (!isset($this->issuer, $this->authorization_endpoint)) {
            return $this->text(Response::STATUS_BAD_REQUEST, 'Method not supported.');
        }
        $session['state'] = bin2hex(random_bytes(16));
        return $this->redirect("{$this->issuer}{$this->authorization_endpoint}?" . http_build_query([
            'client_id' => $this->client_id,
            'response_type' => 'code',
            'scope' => $scope ?? $this->scope,
            'state' => $session['state'],
            'redirect_uri' => $this->redirectFor($request),
        ]));
    }

    /** Forgets everything about this browser. */
    public function logout(array &$session): ResponseInterface
    {
        $session = [];
        return $this->redirect($this->redirectHome());
    }

    /**
     * Exchanges the provider's code for an access token, then loads the profile.
     *
     * @return PromiseInterface<ResponseInterface>
     */
    public function exchangeCode(ServerRequestInterface $request, array &$session, string $code, string $state): PromiseInterface
    {
        if (!empty($session['access_token'])) {
            return resolve($this->redirect($this->redirectHome()));
        }
        if (!isset($this->issuer, $this->token_endpoint)) {
            return resolve($this->text(Response::STATUS_BAD_REQUEST, 'Method not supported.'));
        }
        if (!isset($session['state']) || !hash_equals($session['state'], $state)) {
            return resolve($this->text(Response::STATUS_BAD_REQUEST, 'Invalid state.'));
        }
        unset($session['state']); // one exchange per login
        return $this->apiRequest("{$this->issuer}{$this->token_endpoint}", [
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectFor($request),
        ])->then(function (object $token) use (&$session): PromiseInterface {
            if (isset($token->error) || !isset($token->access_token) || !is_string($token->access_token)) {
                return resolve($this->text(Response::STATUS_BAD_REQUEST, 'Error: ' . ($token->error ?? 'no access token')));
            }
            $session['access_token'] = $token->access_token;
            return $this->fetchUser($session)->then(fn() => $this->redirect($this->redirectHome()));
        });
    }

    /**
     * The provider's profile for this session, fetched once and then cached in the session.
     *
     * @return PromiseInterface<object|null>
     */
    public function fetchUser(array &$session): PromiseInterface
    {
        if (!empty($session['user'])) {
            return resolve($session['user']);
        }
        if (!isset($this->issuer, $this->userinfo_endpoint) || empty($session['access_token'])) {
            return resolve(null);
        }
        return $this->apiRequest("{$this->issuer}{$this->userinfo_endpoint}", [], $session['access_token'])
            ->then(function (object $user) use (&$session): object {
                return $session['user'] = $user;
            });
    }

    /**
     * Revokes the access token at the provider and drops it from the session.
     *
     * @return PromiseInterface<ResponseInterface>
     */
    public function revoke(array &$session): PromiseInterface
    {
        if (!isset($this->issuer, $this->revocation_endpoint)) {
            return resolve($this->text(Response::STATUS_BAD_REQUEST, 'Method not supported.'));
        }
        if (empty($session['access_token'])) {
            return resolve($this->redirect($this->redirectHome()));
        }
        $token = $session['access_token'];
        unset($session['access_token'], $session['user']);
        return $this->apiRequest($this->issuer . $this->revocation_endpoint, [
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'access_token' => $token,
            'token' => $token,
        ])->then(fn() => $this->redirect($this->redirectHome()));
    }

    public function isAuthed(array $session): bool
    {
        return !empty($session['user']);
    }

    /**
     * One JSON request to the provider.
     *
     * @return PromiseInterface<object>
     */
    protected function apiRequest(string $url, array $post = [], ?string $bearer = null): PromiseInterface
    {
        $headers = ['Accept' => 'application/json'];
        if ($bearer !== null) $headers['Authorization'] = 'Bearer ' . $bearer;
        if ($post) $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        try {
            $promise = ($this->http)($post ? 'POST' : 'GET', $url, $headers, $post ? http_build_query($post) : '');
        } catch (\Throwable $e) {
            return reject($e);
        }
        return $promise->then(static function (ResponseInterface $response): object {
            $decoded = json_decode((string) $response->getBody(), false);
            if (!is_object($decoded)) {
                throw new \RuntimeException('Provider returned HTTP ' . $response->getStatusCode() . ' without a JSON object');
            }
            return $decoded;
        });
    }

    public function __debugInfo(): array
    {
        $debugInfo = get_object_vars($this);
        unset($debugInfo['client_id'], $debugInfo['client_secret'], $debugInfo['http']);
        return $debugInfo;
    }
}
