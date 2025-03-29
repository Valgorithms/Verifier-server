<?php declare(strict_types=1);

namespace VerifierServer\Endpoints;

/**
 * Trait HeaderParserTrait
 *
 * Provides functionality to parse HTTP request headers from a raw HTTP request string.
 *
 * @package VerifierServer\Endpoints
 */
trait HeaderParserTrait
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
    static function parseHeaders(string $request): array
    {
        return array_reduce(
            explode(PHP_EOL, $request), fn($carry, $line) =>
                (strpos($line, ':') !== false)
                    ? $carry += [trim(strtok($line, ':')) => trim(substr($line, strpos($line, ':') + 1))]
                    : $carry,
            []);
    }
}