<?php declare(strict_types=1);

namespace VerifierServer;

use React\Http\Message\Response;
use VerifierServer\Endpoints\Interfaces\EndpointInterface;
use VerifierServer\Endpoints\Traits\RequestParserTrait;
use VerifierServer\Traits\HttpMethodsTrait;

class Endpoint implements EndpointInterface
{
    //use RequestTrait, MessageTrait, ServerRequestTrait;
    use RequestParserTrait;
    use HttpMethodsTrait;

    protected array $allowed_methods = []; // 'OPTIONS' and 'TRACE' are handled by the server unless otherwise specified here

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

    public function getAllowedMethods(): array
    {
        return $this->allowed_methods;
    }
}