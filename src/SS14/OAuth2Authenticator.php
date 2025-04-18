<?php declare(strict_types=1);

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace SS14;

use VerifierServer\OAuth2Authenticator as __OAuth2Authenticator;

class OAuth2Authenticator extends __OAuth2Authenticator
{
    protected string $oidc_config                   = 'https://account.spacestation14.com/.well-known/openid-configuration';
    protected string $issuer                        = 'https://account.spacestation14.com';
    protected string $authorization_endpoint        = '/connect/authorize';
    protected string $token_endpoint                = '/connect/token';
    protected string $userinfo_endpoint             = '/connect/userinfo';
    protected string $revocation_endpoint           = '/connect/revocation';

    public function __construct(
        $request,
        protected array &$sessions,
        string $resolved_ip,
        string $web_address,
        int $http_port,
        protected string $client_id,
        protected string $client_secret,
        protected string $endpoint_name = 'ss14wa',
        protected string $scope = 'openid profile email'
    ) {
        parent::__construct(
            $request,
            $sessions,
            $resolved_ip,
            $web_address,
            $http_port,
            $client_id,
            $client_secret,
            $endpoint_name,
            $scope
        );
    }
}