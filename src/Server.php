<?php
namespace VerifierServer;

use VerifierServer\Endpoints\VerifiedEndpoint;

class Server {
    public function __construct(
        private $state,
        private $hostAddr,
        private $verbose = false
    ) {}

    public function start(bool $test = false)
    {
        echo PHP_EOL . "Listening on {$this->hostAddr}..." . PHP_EOL;
        return $this->runServer($test);
    }

    private function runServer(bool $test = false)
    {
        $server = stream_socket_server("{$this->hostAddr}", $errno, $errstr);

        if (! $server) {
            die("Error: $errstr ($errno)" . PHP_EOL);
        }

        if ($test) {
            return $server;
        }

        while (true) {
            if ($client = @stream_socket_accept($server, 30)) {
                $this->handleClient($client);
            }
        }

        return $server;
    }

    private function handleClient($client) {
        $request = fread($client, 1024);
        $lines = explode(PHP_EOL, $request);
        $firstLine = explode(' ', $lines[0]);
        $method = $firstLine[0] ?? '';
        $uri = $firstLine[1] ?? '/';

        if ($this->verbose) {
            echo "$request" . PHP_EOL;
        }

        $response = "HTTP/1.1 200 OK" . PHP_EOL . "Content-Type: application/json" . PHP_EOL . PHP_EOL;
        $body = "";

        switch ($uri) {
            case '/':
                $response = "HTTP/1.1 301 Moved Permanently" . PHP_EOL . "Location: /verified" . PHP_EOL . PHP_EOL;
                break;

            case '/verified':
                $endpoint = new VerifiedEndpoint($this->state);
                $body = $endpoint->handleRequest($method, $request, $response);
                break;

            default:
                $response = "HTTP/1.1 404 Not Found" . PHP_EOL . "Content-Type: text/html" . PHP_EOL . PHP_EOL;
                $body = "<h1>Not Found</h1>";
                break;
        }

        fwrite($client, $response . $body);
        fclose($client);
        if ($this->verbose) {
            echo $response . $body . PHP_EOL;
        }
    }
}
