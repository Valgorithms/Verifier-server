<?php declare(strict_types=1);

namespace VerifierServer;

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use VerifierServer\Endpoints\VerifiedEndpoint;
use VerifierServer\Endpoints\EndpointInterface;

use Exception;
use Throwable;
use function pcntl_signal_dispatch;

/**
 * Class Server
 *
 * This class represents a server implementation that can handle HTTP requests
 * using ReactPHP's HttpServer or a stream socket server. It provides methods
 * for initializing, starting, stopping, and handling client requests. The server
 * supports endpoints and logging functionality.
 *
 * @package VerifierServer
 */
class Server {
    /**
    * The ReactPHP event loop.
     * 
     * @var LoopInterface Event loop.
     * */
    protected LoopInterface $loop;

    /**
     * The logger.
     *
     * @var LoggerInterface Logger.
     */
    protected LoggerInterface $logger;

    /**
     * The server instance.
     * 
     * @var HttpServer|resource|null HTTP server.
     * */
    protected $server;

    /**
     * The socket server instance.
     * 
     * @var SocketServer Socket server.
    */
    protected SocketServer $socket;

    /**
     * Whether the server has been initialized.
     *
     * @var bool Initialized.
     *
     */
    protected bool $initialized = false;

    /**
     * Whether the server is running.
     * 
     * @var bool Running.
     */
    protected bool $running = false;

    /**
     * The server's endpoints.
     * 
     * @var EndpointInterface[] HTTP server endpoints.
     * */
    private array $endpoints = [];
    
    public function __construct(
        private string $hostAddr,
        private ?PersistentState $state = null
    ) {
        if ($state) {
            $this->endpoints = ['/' => new VerifiedEndpoint($state), '/verified' => &$this->endpoints['/']];
        }
    }

    /**
     * Initializes the server by creating a stream socket server and setting it to non-blocking mode.
     *
     * @throws Exception If the server fails to be created.
     */
    public function init(?LoopInterface $loop = null, bool $stream_socket_server = false): void
    {
        if ($this->running) return;
        $this->initialized = true;
        ($stream_socket_server)
            ? $this->initStreamSocketServer()
            : $this->initReactHttpServer($loop);
    }

    /**
     * Initializes a stream socket server.
     *
     * This method creates a stream socket server using the specified host address.
     * If the server cannot be created, an exception is thrown with the error details.
     *
     * @throws Exception If the stream socket server fails to initialize.
     */
    private function initStreamSocketServer(): void
    {
        if (! is_resource($this->server = stream_socket_server($this->hostAddr, $errno, $errstr))) {
            throw new Exception("Failed to create server: $errstr ($errno)");
        }
    }

    /**
     * Initializes the ReactPHP HTTP server.
     *
     * This method sets up an HTTP server using ReactPHP's HttpServer and SocketServer.
     * It accepts an optional event loop instance. If no loop is provided, it defaults
     * to using the global loop instance. The HTTP server is configured to handle incoming
     * requests via the `handleReact` method and logs errors using the `logError` method.
     *
     * @param LoopInterface|null $loop Optional event loop instance. If null, the global loop is used.
     *
     * @return void
     */
    private function initReactHttpServer(?LoopInterface $loop = null): void
    {
        $this->server = new HttpServer(
            $this->loop = $loop instanceof LoopInterface
                ? $loop
                : Loop::get(),
            fn($request) => $this->handleReact($request)
        )->on('error', fn(Throwable $e) => $this->logError($e, true));
        $this->socket = new SocketServer($this->hostAddr, [], $this->loop);
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
            $this->running = true;
            ($this->server instanceof HttpServer)
                ? $this->startReact($start_loop)
                : $this->startResource();
        }
    }

    /**
     * Starts the ReactPHP server by binding it to the specified socket.
     *
     * @param bool $start_loop Determines whether to start the event loop.
     *                          - If true, the event loop will be started.
     *                          - If false, the event loop will not be started.
     *
     * @return void
     */
    private function startReact(bool $start_loop = false): void
    {
        $this->server->listen($this->socket);
        if ($start_loop) $this->loop->run();
    }

    /**
     * Starts the resource handling loop for the server.
     *
     * This method continuously listens for incoming client connections
     * while the server is running. If the operating system is not Windows
     * and the `pcntl` extension is loaded, it dispatches pending signals
     * using `pcntl_signal_dispatch`. When a client connection is accepted,
     * it delegates the handling of the connection to the `handleResource` method.
     *
     * @return void
     */
    private function startResource(): void
    {
        while ($this->running) {
            if (stripos(PHP_OS, 'WIN') === false && extension_loaded('pcntl')) {
                pcntl_signal_dispatch();
            }
            ($client = @stream_socket_accept($this->server, 0))
                ? $this->handleResource($client)
                : $this->logError(new Exception("Failed to accept client connection"), true);
        }
    }

    /**
     * Stops the server by closing the server resource if it is open.
     * 
     * This method checks if the server resource is valid and open,
     * and if so, it closes the resource to stop the server.
     */
    public function close(bool $stop_loop = false): void
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
     * Retrieves the logger instance.
     *
     * @return LoggerInterface|null Returns the logger instance if available, or null if the logger is disabled.
     */
    public function getLogger(): ?LoggerInterface
    {
        return isset($this->logger)
            ? $this->logger
            : null;
    }

    /**
     * Sets the logger instance.
     *
     * @param LoggerInterface|true|null $logger The logger instance to set.
     */
    public function setLogger(LoggerInterface|true|null $logger): void
    {
        $this->logger = $logger === true
            ? new Logger('VerifierServer', [new StreamHandler('php://stdout', Level::Info)])
            : $logger;
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
     * Retrieves the socket instance.
     *
     * @return SocketServer|null Returns the socket instance if set, or null if not set.
     */
    public function getSocketServer(): SocketServer|null
    {
        return $this->socket ?? null;
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
     * Converts an associative array into a request string format.
     * Useful for forging requests in tests or applications that require internal request generation.
     *
     * Each key-value pair in the array is transformed into a string
     * in the format "key: value" and concatenated with a newline character.
     *
     * @param array $formData The associative array to be converted.
     * 
     * @return string The resulting request string.
     */
    public static function arrayToRequestString(array $formData): string
    {
        return implode(PHP_EOL, array_map(fn($key, $value) => $key . ': ' . $value, array_keys($formData), $formData));
    }

    /**
     * Handles the incoming HTTP request and generates the appropriate response.
     *
     * @param string                        $uri            The URI of the request.
     * @param string                        $method         The HTTP method of the request (e.g., 'GET', 'POST').
     * @param ServerRequestInterface|string $request        The request payload, typically used for 'POST' requests.
     * @param int|string                    &$response      The variable to store the generated response.
     * @param array                         &$headers       The variable to store the content type of the response.
     * @param string                        &$body          The variable to store the body of the response.
     * @param bool                          $bypass_token   Whether to bypass the token check.
     */
    public function handleEndpoint(
        string $uri,
        string $method,
        ServerRequestInterface|string $request,
        int|string &$response,
        array &$headers,
        string &$body,
        bool $bypass_token = false
    ): void
    {
        if (isset($this->endpoints[$uri]) && $this->endpoints[$uri] instanceof EndpointInterface) {
            $this->endpoints[$uri]->handle($method, $request, $response, $headers, $body, $bypass_token);
        }
    }

    /**
     * Handles an incoming resource request from a client and generates appropriate responses.
     *
     * @param ServerRequestInterface $client The incoming client request.
     *
     * @return ResponseInterface The generated HTTP response.
     */
    private function handleReact(ServerRequestInterface $client): ResponseInterface
    {
        $method = $client->getMethod();
        $uri = $client->getUri()->getPath();

        // Defaults
        $response = Response::STATUS_NOT_FOUND;
        $headers = ['Content-Type' => 'text/plain'];
        $body = "Not Found";

        $this->handleEndpoint($uri, $method, $client, $response, $headers, $body);

        return new Response(
            $response,
            $headers,
            $body
        );
    }

    /**
     * Handles an incoming resource request from a client and generates appropriate responses.
     *
     * @param resource $client The client socket resource to handle the request from.
     *
     * @throws Exception If reading from or writing to the client fails.
     *
     * @return null Always returns null after processing the request.
     */
    private function handleResource($client): null
    {
        $request = fread($client, 1024);
        if ($request === false) {
            throw new Exception("Failed to read from client");
        }
        $client_headers = explode(PHP_EOL, trim($request));
        $firstLine = explode(' ', array_shift($client_headers));
        $method = $firstLine[0] ?? '';
        $uri = $firstLine[1] ?? '/';
        $protocol = $firstLine[2] ?? 'HTTP/1.1';

        $response = $protocol . " 200 OK";
        $headers = ['Content-Type' => 'application/json'];
        $body = "";

        switch ($uri) {
            case '/':
            case '/verified':
                $endpoint = new VerifiedEndpoint($this->state);
                $endpoint->handle($method, $request, $response, $headers, $body);
                break;

            default:
                $response = "$protocol 404 Not Found" . PHP_EOL . "Content-Type: text/plain" . PHP_EOL . PHP_EOL;
                $body = "Not Found";
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
            $response = "$protocol $response $statusText";
        }
        if (fwrite($client, $response . PHP_EOL . implode(PHP_EOL, array_map(fn($key, $value) => "$key: $value", array_keys($headers), $headers)) . PHP_EOL . PHP_EOL . $body) === false) {
            throw new Exception("Failed to write to client");
        }
        fclose($client);
        return null;
    }

    /**
     * Logs an error message with details about the exception.
     *
     * @param Exception $e     The exception to log.
     * @param bool       $fatal Optional. Indicates whether the error is fatal. Defaults to false.
     *                              If true, the server will stop after logging the error.
     *
     * @return string
     */
    public function logError(Exception $e, bool $fatal = false): void
    {
        if ($fatal) $this->close();
        if (isset($this->logger)) $this->logger->warning(sprintf(
            ($fatal ? '[FATAL] ' : '') . "Error: %s" . PHP_EOL . "Line %d in %s" . PHP_EOL . "%s",
            $e->getMessage(),
            $e->getLine(),
            $e->getFile(),
            $e->getTraceAsString()
        ));
    }

    /**
     * Destructor method that is automatically called when the object is destroyed.
     * It ensures that the server is properly stopped by calling the stop() method.
     */
    public function __destruct()
    {
        $this->close();
    }
}
