<?php declare(strict_types=1);

namespace VerifierServer\Endpoints;

use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;

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