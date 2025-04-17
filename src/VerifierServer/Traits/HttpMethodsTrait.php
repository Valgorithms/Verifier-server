<?php

namespace VerifierServer\Traits;

use React\Http\Message\Response;

trait HttpMethodsTrait
{
    CONST DEFAULT_METHODS = ['OPTIONS', 'TRACE'];

    protected function options(
        &$response,
        &$headers,
        &$body,
        $uri = '*',
        array $server_allowed_methods = [],
    ): array
    {
        $allowed_methods = array_unique(array_merge(self::DEFAULT_METHODS, $server_allowed_methods));
        $response = Response::STATUS_OK;
        $headers['Allow'] = implode(', ', $allowed_methods);
        unset($headers['Content-Type']);
        $headers['Content-Length'] = 0;
        $body = ($uri === '*')
            ? '' // Handle server-wide OPTIONS request
            : ''; // Optionally include resource-specific details (NYI)
        return $allowed_methods;
    }

    protected function trace(
        $request,
        &$response,
        &$headers,
        &$body
    ): void
    {
        $client_headers = $request->getHeaders();
        $response = Response::STATUS_OK;
        $headers = ['Content-Type' => 'message/http'];
        $body = sprintf(
            "%s %s HTTP/%s\r\n%s\r\n\r\n%s",
            $request->getMethod(),
            $request->getRequestTarget(),
            $request->getProtocolVersion(),
            implode(PHP_EOL, array_map(fn($key, $values) => $key . ': ' . implode(', ', $values), array_keys($client_headers), $client_headers)),
            (string) $request->getBody()
        );
    }
}