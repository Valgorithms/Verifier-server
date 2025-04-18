<?php declare(strict_types=1);

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Discord\Endpoints;

use Discord\OAuth2Authenticator;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use VerifierServer\Endpoint;

class OAuth2Endpoint extends Endpoint
{
    protected array $cache = [];

    public function __construct(
        protected array &$sessions,
        protected string $resolved_ip,
        protected string $web_address,
        protected int $http_port,
        protected string $client_id,
        protected string $client_secret,
    ){}

    /**
     * @param string                        $method
     * @param ServerRequestInterface        $request
     * @param int|string                    &$response
     * @param array                         &$headers
     * @param string                        &$body
     */
    public function handle(
        string $method,
        $request,
        int|string &$response, 
        array &$headers,
        string &$body
    ): void
    {
        switch ($method) {
            case 'GET':
                $this->get($request, $response, $headers, $body);
                break;
            case 'POST':
                $this->post($request, $response, $headers, $body);
                break;
            case 'HEAD':
            case 'PUT':
            case 'DELETE':
            case 'PATCH':
            case 'OPTIONS':
            case 'CONNECT':
            default:
                $response = Response::STATUS_METHOD_NOT_ALLOWED;
                $headers = ['Content-Type' => 'text/plain'];
                $body = 'Method Not Allowed';
                break;
        }
    }

    /**
     * @param ServerRequestInterface|string $request
     * @param int|string                    &$response
     * @param array                         &$headers
     * @param string                        &$body
     */
    private function get(
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
        if (!$params = $request->getQueryParams()) {
            $response = Response::STATUS_BAD_REQUEST;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Bad Request';
            return;
        }

        $requesting_ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1'; // For session management, will be deprecated in favor of a more robust solution
        $OAA =
            &$this->cache[$requesting_ip]['OAuth2Authenticator'] ??
            $this->cache[$requesting_ip]['OAuth2Authenticator'] = new OAuth2Authenticator(
                $request,
                $this->sessions,
                $this->resolved_ip,
                $this->web_address,
                $this->http_port,
                $this->client_id,
                $this->client_secret
            );
        /** @var OAuth2Authenticator $OAA */

        if (isset($params['code'], $params['state'])) {
            /*$token =*/ $OAA->getToken($response, $headers, $body, $params['code'], $params['state']);
            return;
        }
        if (isset($params['login'])) {
            $OAA->login($response, $headers, $body);
            return;
        }
        if (isset($params['logout'])) {
            $OAA->logout($response, $headers, $body);
            return;
        }
        if (isset($params['remove']) && $OAA->isAuthed()) {
            $OAA->removeToken($response, $headers, $body);
            return;
        }
        if (isset($params['user']) && $OAA->isAuthed()) {
            $response = Response::STATUS_OK;
            $headers = ['Content-Type' => 'application/json'];
            $body = json_encode($OAA->getUser());
            return;
        }
    }

    /**
     * @param ServerRequestInterface|string $request
     * @param string|int                    &$response
     * @param array                         &$headers
     * @param string                        &$body
     */
    private function post(
        $request,
        int|string &$response,
        array &$headers,
        string &$body
    ): void
    {
        $this->get($request, $response, $headers, $body);
    }

    public function __serialize(): array
    {
        $data = get_object_vars($this);
        unset($data['client_id'], $data['client_secret']);
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
        unset($debugInfo['client_id'], $debugInfo['client_secret']);
        return $debugInfo;
    }
}
