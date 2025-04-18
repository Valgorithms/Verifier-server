<?php declare(strict_types=1);

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace VerifierServer;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class OAuth2Authenticator
{
    protected string $oidc_config;
    protected string $issuer;
    protected string $authorization_endpoint;
    protected string $token_endpoint;
    protected string $userinfo_endpoint;
    //protected string $end_session_endpoint // NYI
    //protected string $check_session_iframe // NYI
    protected string $revocation_endpoint;
    //protected string $introspection_endpoint // NYI
    //protected string $device_authorization_endpoint // NYI

    protected string $default_redirect;
    protected string $redirect_home;
    protected array $allowed_uri = [];
    
    protected string $state;
    protected ?string $access_token = null;
    protected ?object $user = null;

    protected string $requesting_ip; // For session management, will be deprecated in favor of a more robust solution
    

    /**
     * OAuth2Authenticator constructor.
     *
     * Initializes the OAuth2 authentication process by setting up session data, 
     * request parameters, allowed URIs, and other necessary configurations.
     *
     * @param ServerRequestInterface $request
     * @param array &$sessions
     * @param string $resolved_ip
     * @param string $web_address
     * @param int $http_port
     * @param string $client_id
     * @param string $client_secret
     * @param string $endpoint_name
     * @param string $scope
     *
     * @throws \RuntimeException If the provided request is not an instance of ServerRequestInterface.
     */
    public function __construct(
        $request,
        protected array &$sessions,
        string $resolved_ip,
        string $web_address,
        int $http_port,
        protected string $client_id,
        protected string $client_secret,
        protected string $endpoint_name,
        protected string $scope
    ) {
        if (! $request instanceof ServerRequestInterface) {
            throw new \RuntimeException('String requests are not supported.');
        }
        $this->requesting_ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
        $scheme = 'http'; //$request->getUri()->getScheme(); // TLS not supported
        if (($host = $request->getUri()->getHost()) === '127.0.0.1') $host = $resolved_ip; // Should only happen when testing on localhost
        $this->default_redirect = "$scheme://$host:$http_port" . explode('?', $request->getUri()->getPath())[0];

        $this->redirect_home = "$scheme://$web_address:$http_port";
        $this->allowed_uri[] = $this->redirect_home . "/{$this->endpoint_name}"; // must be first in array for redirect_uri check
        $this->allowed_uri[] = $this->redirect_home;
        if ($resolved_ip) {
            $this->allowed_uri[] = "$scheme://$resolved_ip:$http_port/";
            $this->allowed_uri[] = "$scheme://$resolved_ip:$http_port/{$this->endpoint_name}";
        }
        
        $this->state = isset($this->sessions[$this->endpoint_name][$this->requesting_ip], $this->sessions[$this->endpoint_name][$this->requesting_ip]['state'])
            ? $this->sessions[$this->endpoint_name][$this->requesting_ip]['state']
            : $this->sessions[$this->endpoint_name][$this->requesting_ip]['state'] = uniqid();

        if (isset($this->sessions[$this->endpoint_name][$this->requesting_ip]['access_token'])) {
            $this->access_token = $this->sessions[$this->endpoint_name][$this->requesting_ip]['access_token'];
            $this->getUser();
        }
    }

    protected function apiRequest(
        string $url,
        array $post = [],
        bool $associative = false
    ): object
    {
        $headers = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];
        if (isset($this->access_token)) $headers[] = 'Authorization: Bearer ' . $this->access_token;

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
        if (! isset($this->issuer, $this->authorization_endpoint)) {
            $response = Response::STATUS_BAD_REQUEST;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Method not supported.';
            return;
        }

        if (!in_array($redirect_uri = $redirect_uri ?? $this->default_redirect, $this->allowed_uri)) {
            $response = Response::STATUS_FOUND;
            $headers = ['Location' => "{$this->endpoint_name}/{$this->allowed_uri[0]}?login"];
            $body = '';
            var_dump($headers);
            return;
        }

        $response = Response::STATUS_FOUND;
        $headers = ['Location' => "{$this->issuer}{$this->authorization_endpoint}?"
            . http_build_query([
                'client_id' => $this->client_id,
                'response_type' => 'code',
                'scope' => $scope ?? $this->scope,
                'state' => $this->state,
                'redirect_uri' => $redirect_uri ?? $this->default_redirect,
            ])];
        $body = '';
    }

    public function logout(
        int|string &$response,
        array &$headers,
        string &$body
    ): void
    {
        unset($this->sessions[$this->endpoint_name][$this->requesting_ip]);
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
        if (! isset($this->issuer, $this->revocation_endpoint)) {
            $response = Response::STATUS_BAD_REQUEST;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Method not supported.';
            return;
        }

        if (isset($this->access_token)) $this->apiRequest(
            $this->issuer . $this->revocation_endpoint,
            [
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'access_token'  => $this->access_token
            ]
        );
        unset($this->sessions[$this->endpoint_name][$this->requesting_ip]['access_token']);
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
        if (!empty($this->access_token)) {
            $response = Response::STATUS_FOUND;
            $headers = ['Location' => $this->redirect_home];
            $body = '';
            return $this->access_token;
        }
        if (! isset($this->issuer, $this->token_endpoint)) {
            $response = Response::STATUS_BAD_REQUEST;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Method not supported.';
            return null;
        }

        if ($state !== $this->state) {
            $response = Response::STATUS_BAD_REQUEST;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Invalid state.';
            return null;
        }

        $api_response = $this->apiRequest(
            "{$this->issuer}{$this->token_endpoint}",
            [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirect_uri ?: $this->default_redirect,
            ]
        );

        if (isset($api_response->error)) {
            $response = Response::STATUS_BAD_REQUEST;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Error: ' . $api_response->error;
            return null;
        }
        
        $response = Response::STATUS_FOUND;
        $headers = ['Location' => $this->redirect_home];
        $body = '';
        return $this->sessions[$this->endpoint_name][$this->requesting_ip]['access_token'] = $this->access_token = $api_response->access_token;
    }

    public function getUser(): ?object
    {
        if (!empty($this->user)) {
            return $this->sessions[$this->endpoint_name][$this->requesting_ip]['user'] = $this->user;
        }
        if (! isset($this->issuer, $this->userinfo_endpoint, $this->access_token)) {
            return null;
        }
        return $this->sessions[$this->endpoint_name][$this->requesting_ip]['user'] = $this->user = $this->apiRequest("{$this->issuer}{$this->userinfo_endpoint}");
    }

    public function isAuthed(): bool
    {
        return isset($this->user);
    }

    public function __debugInfo(): array
    {
        $debugInfo = get_object_vars($this);
        unset($debugInfo['client_id'], $debugInfo['client_secret']);
        return $debugInfo;
    }
}