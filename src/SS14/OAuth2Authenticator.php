<?php declare(strict_types=1);

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace SS14;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class OAuth2Authenticator
{
    protected array $params;
    protected string $requesting_ip;

    protected string $oidc_config                   = 'https://account.spacestation14.com/.well-known/openid-configuration';
    protected string $issuer                        = 'https://account.spacestation14.com';
    protected string $authorization_endpoint        = '/connect/authorize';
    protected string $token_endpoint                = '/connect/token';
    protected string $userinfo_endpoint             = '/connect/userinfo';
    //protected string $end_session_endpoint          = '/connect/endsession'; // Unused
    //protected string $check_session_iframe          = '/connect/checksession'; // Unused
    protected string $revocation_endpoint           = '/connect/revocation'; // Unused
    //protected string $introspection_endpoint        = '/connect/introspect'; // Unused
    //protected string $device_authorization_endpoint = '/connect/deviceauthorization'; // Unused

    protected string $default_redirect;

    protected string $state;
    protected ?string $access_token = null;
    public ?object $user = null;

    protected string $redirect_home;
    protected array $allowed_uri = [];

    /**
     * OAuth2Authenticator constructor.
     *
     * Initializes the OAuth2 authentication process by setting up session data, 
     * request parameters, allowed URIs, and other necessary configurations.
     *
     * @param array &$sessions
     * @param string $resolved_ip
     * @param string $web_address
     * @param int $http_port
     * @param ServerRequestInterface $request
     * @param string $client_id
     * @param string $client_secret
     * @param string $endpoint_name
     * @param string $scope
     *
     * @throws \RuntimeException If the provided request is not an instance of ServerRequestInterface.
     */
    public function __construct(
        protected array &$sessions,
        string $resolved_ip,
        string $web_address,
        int $http_port,
        $request,
        protected string $client_id,
        protected string $client_secret,
        protected string $endpoint_name = 'ss14wa',
        protected string $scope = 'openid profile email'
    ) {
        if (! $request instanceof ServerRequestInterface) {
            throw new \RuntimeException('String requests are not supported.');
        }

        $this->params = $request->getQueryParams();
        $this->requesting_ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
        $scheme = 'http'; //$request->getUri()->getScheme();
        if ($host = $request->getUri()->getHost() === '127.0.0.1') $host = $resolved_ip; // Should only happen when testing on localhost
        $path = $request->getUri()->getPath();
        $this->default_redirect = "$scheme://$host:$http_port" . explode('?', $path)[0];

        $this->redirect_home = "$scheme://$web_address:$http_port";
        $this->allowed_uri[] = $this->redirect_home;
        $this->allowed_uri[] = $this->redirect_home . "/{$this->endpoint_name}";
        if ($resolved_ip) {
            $this->allowed_uri[] = "$scheme://$resolved_ip:$http_port/";
            $this->allowed_uri[] = "$scheme://$resolved_ip:$http_port/{$this->endpoint_name}";
        }
        
        $this->state = isset($this->sessions[$this->requesting_ip]['state'])
            ? $this->sessions[$this->requesting_ip]['state']
            : $this->sessions[$this->requesting_ip]['state'] = uniqid();

        if (isset($this->sessions[$this->requesting_ip]['access_token'])) {
            $this->access_token = $this->sessions[$this->requesting_ip]['access_token'];
            $this->user = $this->getUser();
        }
    }

    private function apiRequest(
        string $url,
        array $post = [],
        bool $associative = false
    ): object
    {
        $headers = ['Accept: application/json'];
        if ($this->access_token) $headers[] = 'Authorization: Bearer ' . $this->access_token;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $response = curl_exec($ch)
            ?: throw new \RuntimeException('cURL error: ' . curl_error($ch));
        
        $decoded = json_decode($response, $associative);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON decode error: ' . json_last_error_msg());
        }
        return $decoded;
    }

    public function login(
        int|string &$response,
        array &$headers,
        string &$body,
        ?string $redirect_uri = null,
        ?string $scope = null
    ): void
    {
        if (!in_array($redirect_uri = $redirect_uri ?? $this->default_redirect, $this->allowed_uri)) {
            $response = Response::STATUS_FOUND;
            $headers = ['Location' => $this->allowed_uri[0] . '?login'];
            $body = '';
            return;
        }

        $response = Response::STATUS_FOUND;
        $headers = ['Location' => "{$this->issuer}{$this->authorization_endpoint}?"
            . http_build_query([
                'client_id' => $this->client_id,
                'response_type' => 'code',
                'scope' => $scope ?? $this->scope,
                'state' => $this->state,
                'redirect_uri' => $redirect_uri,
            ])];
        $body = '';
    }

    public function logout(
        int|string &$response,
        array &$headers,
        string &$body
    ): void
    {
        unset($this->sessions[$this->requesting_ip]);
        $response = Response::STATUS_FOUND;
        $headers = ['Location' => ($this->redirect_home ?? $this->default_redirect)];
        $body = '';
    }

    public function removeToken(
        int|string &$response,
        array &$headers,
        string &$body
    ): void
    {
        if ($this->access_token)
        {
            $this->apiRequest(
                $this->issuer . $this->revocation_endpoint,
                [
                    'client_id'     => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'access_token'  => $this->access_token
                ]
            );
        }
        $this->logout($response, $headers, $body);
    }

    public function getToken(
        int|string &$response,
        array &$headers,
        string &$body,
        string $code,
        string $state,
        string $redirect_uri = ''
    ): ?string
    {
        if ($state === $this->state) {
            $token = $this->apiRequest(
                $this->issuer . $this->token_endpoint,
                [
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirect_uri ?: $this->default_redirect,
                ]
            );

            if (isset($token->error)) {
                $response = Response::STATUS_BAD_REQUEST;
                $headers = ['Content-Type' => 'text/plain'];
                $body = 'Error: ' . $token->error;
                return null;
            }
            
            $response = Response::STATUS_FOUND;
            $headers = ['Location' => $this->redirect_home];
            $body = '';
            return $this->sessions[$this->requesting_ip]['access_token'] = $token->access_token;
        }
        $response = Response::STATUS_BAD_REQUEST;
        $headers = ['Content-Type' => 'text/plain'];
        $body = 'Invalid state.';
        return null;
    }

    public function getUser(): ?object
    {
        return $this->apiRequest($this->issuer . $this->userinfo_endpoint);
    }

    public function isAuthed(): bool
    {
        return $this->user !== null;
    }
}