<?php declare(strict_types=1);

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace SS14\Endpoints;

use VerifierServer\Endpoint;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use SS14\OAuth2Authenticator;

class OAuth2Endpoint extends Endpoint
{
    public function __construct(
        public array &$sessions,
        protected string $resolved_ip,
        protected string $web_address,
        protected int $http_port,
        protected string $SS14_OAUTH2_CLIENT_ID,
        protected string $SS14_OAUTH2_CLIENT_SECRET,
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
            case 'TRACE':
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
        if (! $request instanceof ServerRequestInterface) {
            $response = Response::STATUS_METHOD_NOT_ALLOWED;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Method Not Allowed';
            return;
        }
        $params = $request->getQueryParams();
        
        $OAA = new OAuth2Authenticator(
            $request,
            $this->sessions,
            $this->resolved_ip,
            $this->web_address,
            $this->http_port,
            $this->SS14_OAUTH2_CLIENT_ID,
            $this->SS14_OAUTH2_CLIENT_SECRET,
        );

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

    public function __debugInfo(): array
    {
        $debugInfo = get_object_vars($this);
        unset($debugInfo['SS14_OAUTH2_CLIENT_ID'], $debugInfo['SS14_OAUTH2_CLIENT_SECRET']);
        return $debugInfo;
    }
}
