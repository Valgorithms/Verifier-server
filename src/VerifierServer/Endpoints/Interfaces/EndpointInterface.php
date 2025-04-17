<?php declare(strict_types=1);

namespace VerifierServer\Endpoints\Interfaces;

//use Psr\Http\Message\MessageInterface;
//use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Interface EndpointInterface
 *
 * Defines the contract for handling incoming HTTP requests and generating appropriate responses.
 *
 * @package VerifierServer\Endpoints
 */
interface EndpointInterface
{
    /**
     * Handles the incoming HTTP request and generates the appropriate response.
     *
     * @param string                        $method         The HTTP method of the request (e.g., 'GET', 'POST').
     * @param ServerRequestInterface|string $request        The request payload, typically used for 'POST' requests.
     * @param int|string                    &$response      The variable to store the generated response.
     * @param array                         &$headers       The variable to store the headers of the response.
     * @param string                        &$body          The variable to store the body of the response.
     */
    public function handle(
        string $method,
        $request,
        int|string &$response,
        array &$headers,
        string &$body
    ): void;
    public function getAllowedMethods(): array;
}
