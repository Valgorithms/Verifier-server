<?php declare(strict_types=1);

namespace VerifierServer\Endpoints\Interfaces;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\PromiseInterface;

/**
 * An endpoint whose work may involve waiting on another service.
 *
 * The ReactPHP server hands the request straight to handleAsync() and
 * answers when the returned promise settles, so the event loop keeps
 * serving other requests meanwhile. The synchronous handle() from
 * EndpointInterface stays available for in-process callers.
 */
interface AsyncEndpointInterface extends EndpointInterface
{
    /**
     * @return PromiseInterface<ResponseInterface>
     */
    public function handleAsync(string $method, ServerRequestInterface $request): PromiseInterface;
}
