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
        private PersistentState $state,
        private string $hostAddr,
        private ?LoggerInterface $logger = null
    ) {
        $this->endpoints['/'] = new VerifiedEndpoint($state);
        $this->endpoints['/verified'] = &$this->endpoints['/'];
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
    public function logError($e, bool $fatal = false): void
    {
        if ($fatal) $this->close();
        $error = 'Error: ' . $e->getMessage() . PHP_EOL .
            'Line ' . $e->getLine() . ' in ' . $e->getFile() . PHP_EOL .
            $e->getTraceAsString();
        if (isset($this->logger)) $this->logger->warning($error);
    }

    /**
     * Initializes the server by creating a stream socket server and setting it to non-blocking mode.
     *
     * @throws Exception If the server fails to be created.
     */
    public function init(?LoopInterface $loop = null, bool $stream_socket_server = false): void
    {
        if ($this->running) return;
        if ($stream_socket_server) {
            $this->server = stream_socket_server("{$this->hostAddr}", $errno, $errstr);
            if (! is_resource($this->server)) {
                throw new Exception("Failed to create server: $errstr ($errno)");
            }
        } else {
            $this->server = new HttpServer(
                $this->loop = $loop instanceof LoopInterface
                    ? $loop
                    : Loop::get(),
                fn($request) => $this->handleReact($request)
            );
            $this->server->on('error', fn(Throwable $e) => $this->logError($e, true));
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
                $this->running = true;
                if ($start_loop) $this->loop->run();
            } elseif (is_resource($this->server)) {
                $this->running = true;
                while ($this->running) {
                    if (stripos(PHP_OS, 'WIN') === false && extension_loaded('pcntl')) {
                        pcntl_signal_dispatch();
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
     * @param array                         &$content_type  The variable to store the content type of the response.
     * @param string                        &$body          The variable to store the body of the response.
     * @param bool                          $bypass_token   Whether to bypass the token check.
     */
    public function handleEndpoint(
        string $uri,
        string $method,
        ServerRequestInterface|string $request,
        int|string &$response,
        array &$content_type,
        string &$body,
        bool $bypass_token = false
    ): void
    {
        if (isset($this->endpoints[$uri]) && $this->endpoints[$uri] instanceof EndpointInterface) {
            $this->endpoints[$uri]->handle($method, $request, $response, $content_type, $body, $bypass_token);
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
        $content_type = ['Content-Type' => 'text/plain'];
        $body = "Not Found";

        $this->handleEndpoint($uri, $method, $client, $response, $content_type, $body);

        return new Response(
            $response,
            $content_type,
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
        $headers = [];
        $lines = explode(PHP_EOL, trim($request));
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[trim($key)] = [trim($value)];
            }
        }

        if ($request === false) {
            throw new Exception("Failed to read from client");
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
                $endpoint->handle($method, $request, $response, $content_type, $body);
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
            throw new Exception("Failed to write to client");
        }
        fclose($client);
        return null;
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
