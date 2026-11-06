<?php declare(strict_types=1);

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Discord;

use React\Promise\PromiseInterface;
use VerifierServer\OAuth2Authenticator as __OAuth2Authenticator;

use function React\Promise\resolve;

class OAuth2Authenticator extends __OAuth2Authenticator
{
    protected string $oidc_config                   = 'https://discord.com/.well-known/openid-configuration';
    protected string $issuer                        = 'https://discord.com/api/v10';
    protected string $authorization_endpoint        = '/oauth2/authorize';
    protected string $token_endpoint                = '/oauth2/token';
    protected string $userinfo_endpoint             = '/users/@me';
    protected string $revocation_endpoint           = '/oauth2/token/revoke';

    protected string $connections_endpoint          = '/users/@me/connections';

    public function __construct(
        callable $http,
        string $resolved_ip,
        string $web_address,
        int $http_port,
        string $client_id,
        string $client_secret,
        string $endpoint_name = 'dwa',
        string $scope = 'identify guilds connections'
    ) {
        parent::__construct($http, $resolved_ip, $web_address, $http_port, $client_id, $client_secret, $endpoint_name, $scope);
    }

    /**
     * Loads the user's linked accounts and records them in the session as
     * `oauth_<type>_id` / `oauth_<type>_name` (plus `oauth_steam_url` for Steam).
     *
     * @return PromiseInterface<array|null>
     */
    protected function getConnections(array &$session): PromiseInterface
    {
        if (empty($session['access_token'])) {
            return resolve(null);
        }
        return $this->apiRequest("{$this->issuer}{$this->connections_endpoint}", [], $session['access_token'])
            ->then(static function (object $decoded) use (&$session): array {
                $connections = (array) $decoded;
                foreach ($connections as $connection) {
                    if (!is_object($connection) || !isset($connection->type, $connection->id)) continue;
                    $session["oauth_{$connection->type}_id"] = $connection->id;
                    $session["oauth_{$connection->type}_name"] = $connection->name ?? '';
                    if ($connection->type === 'steam') {
                        $session['oauth_steam_url'] = "https://steamcommunity.com/profiles/{$connection->id}/";
                    }
                }
                return $connections;
            });
    }

    protected function getGuild(array $session, $id): ?object
    {
        $user = $session['user'] ?? null;
        if (empty($user) || empty($user->guilds)) {
            return null;
        }
        $index = array_search($id, array_column($user->guilds, 'id'));
        return $index === false ? null : ($user->guilds[$index] ?? null);
    }
}
