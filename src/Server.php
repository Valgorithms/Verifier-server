<?php declare(strict_types=1);

namespace VerifierServer;

use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use VerifierServer\Endpoints\VerifiedEndpoint;

class Server {
    private LoopInterface $loop;
    private $server;
    private SocketServer $socket;
    private bool $initialized = false;
    private bool $running = false;
    
    public function __construct(
        private PersistentState $state,
        private string $hostAddr,
        private Logger|false $logger = false
    ) {}

    /**
     * Logs an error message with details about the exception.
     *
     * @param \Exception $e The exception to log.
     * @param bool $fatal Optional. Indicates whether the error is fatal. Defaults to false.
     *                     If true, the server will stop after logging the error.
     *
     * @return void
     */
    public function logError($e, bool $fatal = false) {
        echo 'Error: ' . $e->getMessage() . PHP_EOL .
        'Line ' . $e->getLine() . ' in ' . $e->getFile() . PHP_EOL .
        $e->getTraceAsString();
        if ($fatal) $this->stop();
    }

    /**
     * Initializes the server by creating a stream socket server and setting it to non-blocking mode.
     *
     * @throws \Exception If the server fails to be created.
     */
    public function init(?LoopInterface $loop = null, bool $stream_socket_server = false): void
    {
        if ($this->running) return;
        if ($stream_socket_server) {
            $this->server = stream_socket_server("{$this->hostAddr}", $errno, $errstr);
            if (! is_resource($this->server)) {
                throw new \Exception("Failed to create server: $errstr ($errno)");
            }
        } else {
            $this->server = new HttpServer(
                $this->loop = $loop instanceof LoopInterface
                    ? $loop
                    : Loop::get(),
                fn($request) => $this->handle($request)
            );
            $this->server->on('error', fn(\Throwable $e) => $this->logError($e, true));
            $this->socket = new SocketServer($this->hostAddr, [], $this->loop);
        }
        $this->initialized = true;
    }

    /**
     * Starts the server and listens for incoming connections using ReactPHP's HttpServer.
     * 
     * This method sets up an event-driven server to handle incoming client connections.
     */
    public function start(bool $start_loop = false): void
    {
        if (! $this->initialized) {
            $this->init();
        }
        if (! $this->running) {
            if ($this->server instanceof HttpServer) {
                $this->server->listen($this->socket);
                if ($start_loop) $this->loop->run();
            } elseif (is_resource($this->server)) {
                $this->running = true;
                while ($this->running) {
                    if (stripos(PHP_OS, 'WIN') === false && extension_loaded('pcntl')) {
                        \pcntl_signal_dispatch();
                    }
                    if ($client = @stream_socket_accept($this->server, 0)) {
                        $this->handleResource($client);
                    }
                }
            }
        }
    }

    /**
     * Stops the server by closing the server resource if it is open.
     * 
     * This method checks if the server resource is valid and open,
     * and if so, it closes the resource to stop the server.
     */
    public function stop(bool $stop_loop = false): void
    {
        if ($this->running) {
            $this->socket->close();
            if ($stop_loop) $this->loop->stop();
        }
    }

    /**
     * Retrieves the event loop instance.
     *
     * @return LoopInterface|null The event loop instance if set, or null if not set.
     */
    public function getLoop(): ?LoopInterface
    {
        return $this->loop ?? null;
    }
    
    /**
     * Retrieves the server instance.
     *
     * @return HttpServer|resource|null The server instance.
     */
    public function getServer()
    {
        return $this->server ?? null;
    }

    /**
     * Retrieves the current state of the server.
     *
     * @return PersistentState The current state of the server.
     */
    public function getState(): PersistentState
    {
        return $this->state;
    }

    /**
     * Set the verbosity level of the server.
     *
     * @param Logger|bool $bool True to enable logging, false to disable it.
     */
    public function setLogger(Logger|bool $logger = false): void
    {
        if ($logger === true) {
            $logger = new Logger('Verifier Server');
            $logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout', Level::Debug));
        }
        $this->logger = $logger;
    }

    /**
     * Handles the incoming client request.
     *
     * @param ServerRequestInterface|resource $request The client resource to handle.
     *
     * @throws \Exception If the client resource is invalid, reading from the client fails, or writing to the client fails.
     * 
     * @return Response|null The response to send back to the client, or null if the client is a resource.
     *
     * This method reads the request from the client, parses the HTTP method and URI, and generates an appropriate response.
     * It supports the following URIs:
     * - `/`: Redirects to `/verified`.
     * - `/verified`: Processes the request using the VerifiedEndpoint class.
     * - Any other URI: Returns a 404 Not Found response.
     *
     * If the logger mode is enabled, the request and response are printed to the console.
     */
    public function handle($client): ?Response
    {
        if (! $client instanceof ServerRequestInterface && ! is_resource($client)) {
            throw new \Exception("Invalid client resource");
        }

        return $client instanceof ServerRequestInterface
            ? $this->handleReact($client)
            : $this->handleResource($client);
    }

    public function handleReact($client): Response
    {
        $request = $client;

        $method = $client->getMethod();
        
        $uri = $client->getUri()->getPath();


        $response = Response::STATUS_OK;
        $content_type = ['Content-Type' => 'application/json'];
        $body = "";

        switch ($uri) {
            case '/':
            case '/verified':
                $endpoint = new VerifiedEndpoint($this->state);
                $endpoint->handleRequest(
                    $method,
                    $request,
                    $response,
                    $content_type,
                    $body,
                    $this->state->getJsonPath()
                );
                break;

            default:
                $response = Response::STATUS_NOT_FOUND;
                $content_type = ['Content-Type' => 'text/plain'];
                $body = "Not Found";
                break;
        }

        if ($this->logger) $this->logger->debug((string) $response, $content_type);

        return new Response(
            $response,
            $content_type,
            $body
        );
    }

    // NYI
    public function handleResource($client): null
    {
        $request = fread($client, 1024);
        $headers = [];
        $lines = explode(PHP_EOL, trim($request));
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[trim($key)] = [trim($value)];
            }
        }

        if ($request === false) {
            throw new \Exception("Failed to read from client");
        }
        $lines = explode(PHP_EOL, trim($request));
        $firstLine = explode(' ', $lines[0]);
        $method = $firstLine[0] ?? '';
        $uri = $firstLine[1] ?? '/';

        $response = $firstLine[2] ?? "HTTP/1.1";
        $response .= " 200 OK";
        $content_type = ['Content-Type' => 'application/json'];
        $body = "";

        switch ($uri) {
            case '/':
            case '/verified':
                $endpoint = new VerifiedEndpoint($this->state);
                $endpoint->handleRequest($method, $request, $response, $content_type, $body);
                break;

            default:
                $response = "HTTP/1.1 404 Not Found" . PHP_EOL . "Content-Type: text/html" . PHP_EOL . PHP_EOL;
                $body = "<h1>Not Found</h1>";
                break;
        }

        if (is_int($response)) {
            $statusTexts = [
                200 => "OK",
                201 => "Created",
                204 => "No Content",
                400 => "Bad Request",
                401 => "Unauthorized",
                403 => "Forbidden",
                404 => "Not Found",
                500 => "Internal Server Error",
                502 => "Bad Gateway",
                503 => "Service Unavailable",
            ];
            $statusText = $statusTexts[$response] ?? "Unknown Status";
            $response = "HTTP/1.1 $response $statusText";
        }
        if (fwrite($client, $response . PHP_EOL . implode(PHP_EOL, array_map(fn($key, $value) => "$key: $value", array_keys($content_type), $content_type)) . PHP_EOL . PHP_EOL . $body) === false) {
            throw new \Exception("Failed to write to client");
        }
        fclose($client);
        var_dump($response);
        return null;
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
