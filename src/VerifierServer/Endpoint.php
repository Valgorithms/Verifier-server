<?php declare(strict_types=1);

namespace VerifierServer;

use React\Http\Message\Response;
use VerifierServer\Endpoints\Interfaces\EndpointInterface;
use VerifierServer\Endpoints\Traits\RequestParserTrait;

class Endpoint implements EndpointInterface
{
    //use RequestTrait, MessageTrait, ServerRequestTrait;
    use RequestParserTrait;

    public function handle(
        string $method,
        $request,
        int|string &$response,
        array &$headers,
        string &$body
    ): void
    {
        $response = Response::STATUS_OK; // HTTP status code
        $headers = ['Content-Type' => 'application/json']; // Response headers
        $body = json_encode(['message' => 'Hello, World!']); // Response body
    }
}