<?php declare(strict_types=1);

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace SS14\Endpoints;

use SS14\OAuth2Authenticator;
use VerifierServer\Endpoints\OAuth2Endpoint as __OAuth2Endpoint;

class OAuth2Endpoint extends __OAuth2Endpoint
{
    protected string $auth = OAuth2Authenticator::class;
}