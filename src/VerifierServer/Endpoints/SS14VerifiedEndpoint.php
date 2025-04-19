<?php declare(strict_types=1);

namespace VerifierServer\Endpoints;

use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use VerifierServer\SS14PersistentState;

/**
 * Class SS14VerifiedEndpoint
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
 * @property SS14PersistentState $state
 * @package VerifierServer\Endpoints
 */
class SS14VerifiedEndpoint extends VerifiedEndpoint
{
    public function __construct(protected &$state)
    {}

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
     * 3. Retrieves the method type, ss14, discord, and token from the parsed data.
     * 4. Checks if the provided token matches the expected token. If not, sets the response to 401 Unauthorized.
     * 5. Retrieves the verification list from the state.
     * 6. Based on the method type, either deletes an entry from the list or handles the default case.
     */
    protected function post(ServerRequestInterface|string $request, int|string &$response, array &$headers, string &$body, bool $bypass_token = false): void
    {
        $formData = $request instanceof ServerRequestInterface
            ? $request->getHeaders()
            : (is_string($request)
                ? self::parseHeaders($request)
                : []);

        $methodType = isset($formData['method']) 
            ? strtolower(trim(is_array($formData['method']) ? $formData['method'][0] : $formData['method']))
            : null;
        $discord = isset($formData['discord'])
            ? trim(is_array($formData['discord']) ? $formData['discord'][0] : $formData['discord'])
            : '';
        $ss14 = isset($formData['ss14'])
            ? trim(is_array($formData['ss14']) ? $formData['ss14'][0] : $formData['ss14'])
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
                $this->delete(
                    $this->getIndex($discord, $ss14),
                    $list,
                    $response,
                    $headers,
                    $body
                );
                break;
            default:
                $this->__post(
                    $list,
                    $ss14,
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
     * This method checks if the provided `ss14` or `discord` already exists in the list.
     * If either exists, it sets the response to a 403 Forbidden status.
     * If neither exists, it adds the new entry to the list, writes the updated list to a JSON file,
     * updates the state, and sets the response to a 200 OK status.
     *
     * @param array  $list          The list of existing entries.
     * @param string $ss14          The ss14 to be verified.
     * @param string $discord       The discord identifier to be verified.
     * @param string &$response     The response message to be set based on the verification result.
     * @param array  &$headers      The variable to store the headers of the response.
     * @param string &$body         The variable to store the body of the response.
     */
    protected function __post(array &$list, string $ss14, string $discord, int|string &$response, array &$headers, string &$body): void
    {
        $existingDiscordIndex = array_search($discord, array_column($list, 'discord'));
        $existingSS14Index = array_search($ss14, array_column($list, 'ss14'));
        if ($existingDiscordIndex !== false || $existingSS14Index !== false) {
            $response = Response::STATUS_FORBIDDEN;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Forbidden';
            return;
        }

        $this->add($discord, $ss14);
        
        $headers = ['Content-Type' => 'application/json'];
        $headers['Content-Length'] = ($body = $this->__content())
            ? strlen($body)
            : 0;
    }

    public function add(string $discord, string $ss14): void
    {
        $list = $this->state->getVerifyList();
        $list[] = [
            'discord' => $discord,
            'ss14' => $ss14,
            'create_time' => date('Y-m-d H:i:s')
        ];
        $this->state::writeJson($this->state->getJsonPath(), $list);
        $this->state->setVerifyList($list);
    }

    public function remove(string $discord, string $ss14 = ''): ?array
    {
        $existingIndex = $this->getIndex($discord, $ss14);
        if ($existingIndex === false) return null;
        return $this->removeIndex($existingIndex);
    }

    public function getIndex(string $discord, string $ss14 = ''): int|string|false
    {
        $list = $this->state->getVerifyList();
        $existingIndex = array_search($discord, array_column($list, 'discord'));
        if ($ss14 && $existingIndex === false) $existingIndex = array_search($ss14, array_column($list, 'ss14'));
        return $existingIndex;
    }
}
