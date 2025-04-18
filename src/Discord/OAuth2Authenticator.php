<?php declare(strict_types=1);

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Discord;

use VerifierServer\OAuth2Authenticator as __OAuth2Authenticator;

class OAuth2Authenticator extends __OAuth2Authenticator
{
    protected string $oidc_config                   = 'https://discord.com/.well-known/openid-configuration';
    protected string $issuer                        = 'https://discord.com/api/v10';
    protected string $authorization_endpoint        = '/oauth2/authorize';
    protected string $token_endpoint                = '/oauth2/token';
    protected string $userinfo_endpoint             = '/users/@me';
    protected string $revocation_endpoint           = '/oauth2/token/remove';

    protected string $connections_endpoint          = '/users/@me/connections';

    public function __construct(
        $request,
        protected array &$sessions,
        string $resolved_ip,
        string $web_address,
        int $http_port,
        protected string $client_id,
        protected string $client_secret,
        protected string $endpoint_name = 'dwa',
        protected string $scope = 'identify guilds connections'
    ) {
        parent::__construct($request, $sessions, $resolved_ip, $web_address, $http_port, $client_id, $client_secret, $endpoint_name, $scope);
    }

    /**
     * Processes a connection object and stores relevant OAuth data in the session.
     * 
     * Properties of $connection:
     * - id (string): The ID of the connection account.
     * - name (string): The username of the connection account.
     * - type (string): The service of the connection (e.g., "twitch", "youtube", "steam").
     * - revoked (boolean, optional): Whether the connection is revoked.
     * - integrations (array, optional): An array of partial server integrations.
     * - verified (boolean): Whether the connection is verified.
     * - friend_sync (boolean): Whether friend sync is enabled for this connection.
     * - show_activity (boolean): Whether activities related to this connection will be shown in presence updates.
     * - visibility (integer): The visibility of this connection.
     *
     * Session keys set:
     * - oauth_<type>_id: The ID of the connection account.
     * - oauth_<type>_name: The username of the connection account.
     * - oauth_steam_url (if type is "steam"): The Steam profile URL for the connection account.
     */
    protected function getConnections(): ?object
    {
        if (!$connections = $this->apiRequest("{$this->issuer}{$this->connections_endpoint}")) {
            return null;
        }
        foreach($connections as $__ => $connection)
        {
            if (isset($connection->type)) {
                $this->sessions[$this->requesting_ip]["oauth_{$connection->type}_id"] = $connection->id;
                $this->sessions[$this->requesting_ip]["oauth_{$connection->type}_name"] = $connection->name;
                if ($connection->type == 'steam') $this->sessions[$this->requesting_ip]['oauth_steam_url'] = "https://steamcommunity.com/profiles/{$connection->id}/";
            }
        }
        return $connections;
    }

    protected function getGuild($id): ?object
    {
        if (empty($this->user) || empty($this->user->guilds)) {
            return null;
        }
        return $this->user->guilds[array_search($id, array_column($this->user->guilds, 'id'))] ?? null;
    }
}