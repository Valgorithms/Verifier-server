<?php declare(strict_types=1);

namespace VerifierServer\Endpoints\Traits;

/**
 * Trait RequestParserTrait
 *
 * Provides functionality to parse data from a raw HTTP request string.
 *
 * @package VerifierServer\Endpoints
 */
trait RequestParserTrait
{
    /**
     * Parses the headers from a raw HTTP request string.
     *
     * This method takes a raw HTTP request string, splits it into lines,
     * and extracts headers in the format "Header-Name: Header-Value".
     * It returns an associative array where the keys are header names
     * and the values are the corresponding header values.
     *
     * @param string $request The raw HTTP request string to parse.
     * @return array An associative array of headers, where the keys are
     *               header names and the values are header values.
     */
    public static function parseHeaders(string $request): array
    {
        return array_reduce(
            explode(PHP_EOL, $request), fn($carry, $line) =>
                (strpos($line, ':') !== false)
                    ? $carry += [trim(strtok($line, ':')) => trim(substr($line, strpos($line, ':') + 1))]
                    : $carry,
            []);
    }

    public function getQueryParams(string $request): array
    {
        return self::__getQueryParams($request);
    }
    
    /**
     * Parses the query parameters from a given request URL string.
     *
     * This method extracts the query string from the provided URL,
     * parses it into an associative array, and returns the result.
     *
     * @param string $request The full URL string containing the query parameters.
     * @return array An associative array of query parameters.
     */
    public static function __getQueryParams(string $request): array
    {
        parse_str(parse_url($request, PHP_URL_QUERY), $params);
        return $params;
    }
}