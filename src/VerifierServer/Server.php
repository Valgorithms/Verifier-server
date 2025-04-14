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
use SS14\Endpoints\OAuth2Endpoint as SS14OAuth2Endpoint;
use VerifierServer\Endpoints\Interfaces\EndpointInterface;
//use VerifierServer\Endpoints\USPSEndpoint;
use VerifierServer\Endpoints\VerifiedEndpoint;

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
     */
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
     */
    protected $server;

    /**
     * The socket server.
     * 
     * @var SocketServer Socket server.
    */
    protected SocketServer $socket;

    /**
     * The persistent state.
     * 
     * @var PersistentState Persistent state.
     */
    protected PersistentState $state;

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
     */
    protected array $endpoints = [];

    protected string $resolved_ip;

    /**
     * The IP sessions.
     * 
     * @var array IP sessions.
     */
    protected array $ip_sessions;
    
    public function __construct(
        protected string $addr,
        protected int $port = 16261
    ) {
        if (empty($port) && !str_contains($addr, ':')) {
            throw new Exception("Invalid address: $addr. Port is required.");
        }
        if (str_contains($this->addr, ':')) {
            $array = explode(':', $this->addr);
            $this->addr = $array[0];
            $this->port = (int) $array[1];
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
            ? $this->__initStreamSocketServer()
            : $this->__initReactHttpServer($loop);
    }

    /**
     * Initializes a stream socket server.
     *
     * This method creates a stream socket server using the specified host address.
     * If the server cannot be created, an exception is thrown with the error details.
     *
     * @throws Exception If the stream socket server fails to initialize.
     */
    private function __initStreamSocketServer(): void
    {
        if (! is_resource($this->server = stream_socket_server("{$this->addr}:{$this->port}", $errno, $errstr))) {
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
     */
    private function __initReactHttpServer(?LoopInterface $loop = null): void
    {
        $this->server = new HttpServer(
            $this->loop = $loop instanceof LoopInterface
                ? $loop
                : Loop::get(),
            fn($request) => $this->handleReact($request)
        )->on('error', fn(Throwable $e) => $this->logError($e, true));
        $this->socket = new SocketServer("{$this->addr}:{$this->port}", [], $this->loop);
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
                ? $this->__startReact($start_loop)
                : $this->__startResource();
        }
    }

    /**
     * Starts the ReactPHP server by binding it to the specified socket.
     *
     * @param bool $start_loop Determines whether to start the event loop.
     *                          - If true, the event loop will be started.
     *                          - If false, the event loop will not be started.
     */
    private function __startReact(bool $start_loop = false): void
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
     */
    private function __startResource(): void
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
        $this->running = false;
        if (isset($this->socket)) $this->socket->close();
        if (isset($this->loop) && $stop_loop) $this->loop->stop();
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

        $response = "$protocol 404 Not Found" . PHP_EOL . "Content-Type: text/plain" . PHP_EOL . PHP_EOL;
        $headers = ['Content-Type' => 'text/plain'];
        $body = "Not Found";
        
        $this->handleEndpoint($uri, $method, $request, $response, $headers, $body);

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
     * @param bool      $fatal Optional. Indicates whether the error is fatal. Defaults to false.
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
        return $this->logger ?? null;
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
     * @return PersistentState|null The current state of the server.
     */
    public function getState(): ?PersistentState
    {
        return $this->state ?? null;
    }

    public function getResolvedIp(): string
    {
        if (!filter_var($addr = $this->resolved_ip ?? $this->resolved_ip = gethostbyname($this->getWebAddress()), FILTER_VALIDATE_IP)) {
            throw new Exception("Resolved address is not a valid IP: $addr");
        }

        return $addr;
    }
    public function getWebAddress(): string
    {
        return $_ENV['SS14_WEB_ADDRESS']
            ?? getenv('SS14_WEB_ADDRESS')
            ?: $this->addr;
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
     * Sets the state for the server instance.
     *
     * This method ensures that the state is initialized only once. If the state
     * is provided as an array, it will be converted into a PersistentState object.
     * The state is then assigned to the server instance and used to set up the
     * verified endpoint.
     *
     * @param array|PersistentState $state The state to set, either as an 
     *                                     array of options or a PersistentState object.
     */
    public function setState(array|PersistentState $state): void
    {
        if (isset($this->state)) return;
        if (is_array($state)) $state = new PersistentState(...$state);
        $this->state = $state;
        $this->__setVerifiedEndpoint($state);
        //$this->endpoints['/usps'] = new USPSEndpoint($_ENV['USPS_USERID'] ?? getenv('USPS_USERID'));
    }

    /**
     * Sets the state of the server and initializes the endpoints.
     *
     * This method checks if the `$state` property is set. If it is, it initializes
     * the `$endpoints` property with a default endpoint and a reference to the
     * verified endpoint.
     */
    private function __setVerifiedEndpoint(PersistentState &$state): void
    {
        $this->endpoints['/verified'] = new VerifiedEndpoint($state);
        $this->endpoints['/'] = &$this->endpoints['/verified'];
    }

    public function setOAUth2Endpoint(
        string $SS14_OAUTH2_CLIENT_ID,
        string $SS14_OAUTH2_CLIENT_SECRET
    ): void
    {
        $this->ip_sessions = $this->ip_sessions ?? [];
        $this->endpoints['/ss14wa'] = new SS14OAuth2Endpoint(
            $this->ip_sessions,
            $this->getResolvedIp(),
            $this->getWebAddress(),
            $this->port,
            $SS14_OAUTH2_CLIENT_ID,
            $SS14_OAUTH2_CLIENT_SECRET,
        );
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
