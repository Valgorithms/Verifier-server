<?php
namespace VerifierServer;

use VerifierServer\Endpoints\VerifiedEndpoint;

class Server {
    private $server;
    public $listening = false;
    
    public function __construct(
        private PersistentState $state,
        private string $hostAddr,
        private bool $verbose = false
    ) {}

    /**
     * Initializes the server by creating a stream socket server and setting it to non-blocking mode.
     *
     * @throws \Exception If the server fails to be created.
     */
    public function init(): void
    {
        $this->server = stream_socket_server("{$this->hostAddr}", $errno, $errstr);

        if (! is_resource($this->server)) {
            throw new \Exception("Failed to create server: $errstr ($errno)");
        }

        echo PHP_EOL . "Ready to listen on {$this->hostAddr}." . PHP_EOL;
    }

    /**
     * Handles the incoming client request.
     *
     * @param resource $client The client resource to handle.
     *
     * @throws \Exception If the client resource is invalid, reading from the client fails, or writing to the client fails.
     *
     * This method reads the request from the client, parses the HTTP method and URI, and generates an appropriate response.
     * It supports the following URIs:
     * - `/`: Redirects to `/verified`.
     * - `/verified`: Processes the request using the VerifiedEndpoint class.
     * - Any other URI: Returns a 404 Not Found response.
     *
     * If the verbose mode is enabled, the request and response are printed to the console.
     */
    private function handleClient($client): void
    {
        if (! is_resource($client)) {
            throw new \Exception("Invalid client resource");
        }
        $request = fread($client, 1024);
        if ($this->verbose) {
            echo '---' . PHP_EOL;
            var_dump(trim($request));
        }
        if ($request === false) {
            throw new \Exception("Failed to read from client");
        }
        $lines = explode(PHP_EOL, trim($request));
        $firstLine = explode(' ', $lines[0]);
        $method = $firstLine[0] ?? '';
        $uri = $firstLine[1] ?? '/';

        $response = "HTTP/1.1 200 OK" . PHP_EOL . "Content-Type: application/json" . PHP_EOL . PHP_EOL;
        $body = "";

        switch ($uri) {
            case '/':
            case '/verified':
                $endpoint = new VerifiedEndpoint($this->state);
                $body = $endpoint->handleRequest($method, $request, $response);
                break;

            default:
                $response = "HTTP/1.1 404 Not Found" . PHP_EOL . "Content-Type: text/html" . PHP_EOL . PHP_EOL;
                $body = "<h1>Not Found</h1>";
                break;
        }

        if (fwrite($client, $response . $body) === false) {
            throw new \Exception("Failed to write to client");
        }
        fclose($client);
        if ($this->verbose) {
            echo $response . $body . PHP_EOL;
            echo '---' . PHP_EOL;
        }
    }

    /**
     * Retrieves the server instance.
     *
     * @return resource|false The server instance.
     */
    public function get()
    {
        return $this->server;
    }

    /**
     * Set the verbosity level of the server.
     *
     * @param bool $bool True to enable verbose mode, false to disable it.
     */
    public function setVerbose(bool $bool): void
    {
        $this->verbose = $bool;
    }

    /**
     * Stops the server by closing the server resource if it is open.
     * 
     * This method checks if the server resource is valid and open,
     * and if so, it closes the resource to stop the server.
     */
    public function stop(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }
    }

    /**
     * Starts the server and listens for incoming connections.
     * 
     * This method sets the server to a listening state and handles incoming client connections.
     * If the server is running on a non-Windows OS and the 'pcntl' extension is loaded, it sets up
     * signal handling for SIGTERM and SIGINT to gracefully stop the server.
     * 
     * The server will continue to listen for connections until the $this->listening property is set to false.
     */
    public function start(): void
    {
        echo "Listening for connections..." . PHP_EOL;
        $this->listening = true;
        if (stripos(PHP_OS, 'WIN') === false && extension_loaded('pcntl')) {
            \pcntl_async_signals(true);
            \pcntl_signal(SIGTERM, [$this, 'stop']);
            \pcntl_signal(SIGINT, [$this, 'stop']);
        }

        while ($this->listening) {
            if (stripos(PHP_OS, 'WIN') === false && extension_loaded('pcntl')) {
                \pcntl_signal_dispatch();
            }
            $client = @stream_socket_accept($this->server, 0);
            if ($client) {
                $this->handleClient($client);
            }
        }
    }

    /**
     * Destructor method that is automatically called when the object is destroyed.
     * It ensures that the server is properly stopped by calling the stop() method.
     */
    public function __destruct()
    {
        $this->stop();
    }
}
