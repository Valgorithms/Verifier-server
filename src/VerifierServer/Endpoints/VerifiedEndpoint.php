<?php declare(strict_types=1);

namespace VerifierServer\Endpoints;

use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use VerifierServer\PersistentState;

/**
 * Class VerifiedEndpoint
 *
 * This class is responsible for handling the verification process for the VerifierServer.
 * It implements the EndpointInterface and provides methods to handle HTTP GET, POST, and DELETE requests.
 *
 * Key Responsibilities:
 * - Handles GET requests to retrieve the list of verified users in JSON format.
 * - Handles POST requests to add a new verification entry or DELETE requests to remove an existing entry.
 * - Validates authorization tokens to ensure secure access to the endpoint.
 * - Manages the persistent state of the verification list by reading from and writing to a JSON file.
 *
 * Methods:
 * - __construct: Initializes the VerifiedEndpoint with a reference to the PersistentState object.
 * - handle: Routes incoming HTTP requests to the appropriate handler based on the HTTP method.
 * - get: Handles GET requests to retrieve the verification list.
 * - post: Handles POST requests to modify the verification list.
 * - __post: Adds a new entry to the verification list if its fields/columns are unique.
 * - delete: Handles DELETE request to remove an existing entry from the verification list.
 *
 * Authorization:
 * - The class checks the provided token against the expected token stored in the PersistentState.
 * - If the token is invalid, the response is set to 401 Unauthorized.
 * - If the token is valid, the requested action is performed.
 *
 * Error Handling:
 * - Returns 401 Unauthorized if the provided token does not match the expected token.
 * - Returns 403 Forbidden if a duplicate entry is detected during a POST request.
 * - Returns 404 Not Found if an entry to be deleted does not exist.
 * - Returns 405 Method Not Allowed for unsupported HTTP methods.
 *
 * Response Structure:
 * - Sets appropriate HTTP status codes.
 * - Sets the Content-Type header based on the response type (e.g., application/json, text/plain).
 * - Encodes the response body as JSON for successful requests or as plain text for error responses.
 *
 * @package VerifierServer\Endpoints
 */
class VerifiedEndpoint extends Endpoint
{
    public function __construct(private PersistentState &$state)
    {}

    /**
     * Handles the incoming HTTP request and generates the appropriate response.
     *
     * @param string                        $method        The HTTP method of the request (e.g., 'GET', 'POST').
     * @param ServerRequestInterface|string $request       The request payload, typically used for 'POST' requests.
     * @param int|string                    &$response     The variable to store the generated response.
     * @param array                         &$headers      The variable to store the headers of the response.
     * @param string                        &$body         The variable to store the body of the response.
     * @param bool                          $bypass_token  Whether to bypass the token check. Default is false.
     */
    public function handle(
        string $method,
        $request,
        int|string &$response, 
        array &$headers,
        string &$body,
        bool $bypass_token = false
    ): void
    {
        switch ($method) {
            case 'GET':
                $this->get($response, $headers, $body);
                break;
            case 'HEAD':
                $this->head($response, $headers);
                break;
            case 'POST':
            case 'PUT':
            case 'DELETE':
                $this->post($request, $response, $headers, $body, $bypass_token);
                break;
            case 'PATCH':
            case 'OPTIONS':
            case 'CONNECT':
            case 'TRACE':
            default:
                $response = Response::STATUS_METHOD_NOT_ALLOWED;
                $headers = ['Content-Type' => 'text/plain'];
                $body = 'Method Not Allowed';
                break;
        }
    }

    /**
     * Handles the GET request and prepares the response.
     *
     * @param int|string &$response     The response string to be sent back to the client.
     * @param array      &$headers      The variable to store the headers of the response.
     * @param string     &$body         The variable to store the body of the response.
     *
     * It appends the JSON-encoded verification list to the body of the response.
     */
    private function get(int|string &$response, array &$headers, string &$body): void
    {
        $body = $this->head($response, $headers);
    }

    /**
     * Sets the HTTP response status and content type for the HEAD request.
     *
     * @param int|string &$response     The response string to be sent back to the client.
     * @param array      &$headers      The variable to store the headers of the response.
     * 
     * @return string The content to be sent in the response body.
     * 
     * This method sets the HTTP status code and headers.
     */
    private function head(int|string &$response, array &$headers): string
    {
        $response = Response::STATUS_OK;
        $headers = ['Content-Type' => 'application/json'];
        $headers['Content-Length'] = ($content = $this->__content())
            ? strlen($content)
            : 0;
        return $content;
    }

    /**
     * Encodes the verification list retrieved from the state into a JSON string.
     *
     * @return string|false Returns the JSON-encoded string on success, or false on failure.
     */
    private function __content(): string|false
    {
        return json_encode($this->state->getVerifyList());
    }

    /**
     * Handles POST requests by parsing the request data and performing actions based on the method type.
     *
     * @param ServerRequestInterface|string $request       The raw HTTP request string.
     * @param string                        &$response     The response string to be modified based on the request handling.
     * @param array                         &$headers      The variable to store the headers of the response.
     * @param string                        &$body         The variable to store the body of the response.
     *
     * The function performs the following steps:
     * 1. Extracts the raw data from the request.
     * 2. Parses the raw data into an associative array.
     * 3. Retrieves the method type, ckey, discord, and token from the parsed data.
     * 4. Checks if the provided token matches the expected token. If not, sets the response to 401 Unauthorized.
     * 5. Retrieves the verification list from the state.
     * 6. Based on the method type, either deletes an entry from the list or handles the default case.
     */
    private function post(ServerRequestInterface|string $request, int|string &$response, array &$headers, string &$body, bool $bypass_token = false): void
    {
        $formData = $request instanceof ServerRequestInterface
            ? $request->getHeaders()
            : (is_string($request)
                ? self::parseHeaders($request)
                : []);

        $methodType = isset($formData['method']) 
            ? strtolower(trim(is_array($formData['method']) ? $formData['method'][0] : $formData['method']))
            : null;
        $ckey = isset($formData['ckey'])
            ? trim(is_array($formData['ckey']) ? $formData['ckey'][0] : $formData['ckey'])
            : '';
        $discord = isset($formData['discord'])
            ? trim(is_array($formData['discord']) ? $formData['discord'][0] : $formData['discord'])
            : '';
        $token = isset($formData['token'])
            ? trim(is_array($formData['token']) ? $formData['token'][0] : $formData['token'])
            : '';
        
        if (!$bypass_token && ($this->state->getToken() === 'changeme' || $token !== $this->state->getToken())) {
            $response = Response::STATUS_UNAUTHORIZED;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Unauthorized';
            return;
        }

        $list = $this->state->getVerifyList();

        switch ($methodType) {
            case 'delete':
                $existingIndex = array_search($ckey, array_column($list, 'ss13'));
                if ($existingIndex === false) $existingIndex = array_search($discord, array_column($list, 'discord'));
                $this->delete(
                    $existingIndex,
                    $list,
                    $response,
                    $headers,
                    $body
                );
                break;
            default:
                $this->__post(
                    $list,
                    $ckey,
                    $discord,
                    $response,
                    $headers,
                    $body
                );
                break;
        }
    }

    /**
     * Handles the default verification process.
     *
     * This method checks if the provided `ckey` or `discord` already exists in the list.
     * If either exists, it sets the response to a 403 Forbidden status.
     * If neither exists, it adds the new entry to the list, writes the updated list to a JSON file,
     * updates the state, and sets the response to a 200 OK status.
     *
     * @param array  $list          The list of existing entries.
     * @param string $ckey          The ckey to be verified.
     * @param string $discord       The discord identifier to be verified.
     * @param string &$response     The response message to be set based on the verification result.
     * @param array  &$headers      The variable to store the headers of the response.
     * @param string &$body         The variable to store the body of the response.
     */
    private function __post(array &$list, string $ckey, string $discord, int|string &$response, array &$headers, string &$body): void
    {
        $existingCkeyIndex = array_search($ckey, array_column($list, 'ss13'));
        $existingDiscordIndex = array_search($discord, array_column($list, 'discord'));

        if ($existingCkeyIndex !== false || $existingDiscordIndex !== false) {
            $response = Response::STATUS_FORBIDDEN;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Forbidden';
            return;
        }
        $list[] = [
            'ss13' => $ckey,
            'discord' => $discord,
            'create_time' => date('Y-m-d H:i:s')
        ];
        PersistentState::writeJson($this->state->getJsonPath(), $list);
        $this->state->setVerifyList($list);
        $body = $this->head($response, $headers);
    }

    /**
     * Handles the deletion of an item from the list.
     *
     * @param int|string|false $existingIndex The index of the item to delete, or false if the item does not exist.
     * @param array            &$list         The list from which the item will be deleted.
     * @param string           &$response     The HTTP response message to be returned.
     * @param array            &$headers      The variable to store the headers of the response.
     * @param string           &$body         The variable to store the body of the response.
     */
    private function delete(int|string|false $existingIndex, array &$list, int|string &$response, array &$headers, string &$body): void
    {
        if ($existingIndex === false) {
            $response = Response::STATUS_NOT_FOUND;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Not Found';
            return;
        }
        $splice = array_splice($list, $existingIndex, 1);
        PersistentState::writeJson($this->state->getJsonPath(), $list);
        $this->state->setVerifyList($list);
        $response = Response::STATUS_OK;
        $headers = ['Content-Type' => 'application/json'];
        $headers['Content-Length'] = ($content = json_encode($splice))
            ? strlen($body = $content)
            : 0;
    }
}
