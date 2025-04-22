<?php declare(strict_types=1);

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace VerifierServer\Endpoints;

use React\Http\Message\Response;
use VerifierServer\Endpoint;

class FaviconEndpoint extends Endpoint
{
    public function handle(
        string $method,
        $request,
        int|string &$response,
        array &$headers,
        string &$body
    ): void {
        $response = Response::STATUS_OK;
        $headers = ['Content-Type' => 'image/x-icon'];
        $body = is_file($faviconPath = __DIR__ . '/../../assets/favicon.ico')
            ? file_get_contents($faviconPath)
            : '';
    }
}