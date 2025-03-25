<?php declare(strict_types=1);

namespace VerifierServer\Endpoints;

use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use VerifierServer\PersistentState;

class VerifiedEndpoint implements EndpointInterface
{
    public function __construct(private PersistentState &$state)
    {}

    /**
     * Handles the incoming HTTP request and generates the appropriate response.
     *
     * @param string                        $method        The HTTP method of the request (e.g., 'GET', 'POST').
     * @param ServerRequestInterface|string $request       The request payload, typically used for 'POST' requests.
     * @param string                        &$response     The variable to store the generated response.
     * @param array                         &$content_type The variable to store the content type of the response.
     * @param string                        &$body         The variable to store the body of the response.
     */
    public function handle(string $method, ServerRequestInterface|string $request, int|string &$response, array &$content_type, string &$body, bool $bypass_token = false): void
    {
        switch ($method) {
            case 'GET':
                $this->get($response, $content_type, $body);
                break;
            case 'POST':
            case 'DELETE':
                $this->post($request, $response, $content_type, $body, $bypass_token);
                break;
            default:
                $response = Response::STATUS_METHOD_NOT_ALLOWED;
                $content_type = ['Content-Type' => 'text/plain'];
                $body = 'Method Not Allowed';
                break;
        }
    }

    /**
     * Handles the GET request and prepares the response.
     *
     * @param string &$response     The response string to be sent back to the client.
     * @param array  &$content_type The variable to store the content type of the response.
     * @param string &$body         The variable to store the body of the response.
     *
     * This method sets the HTTP status code to 200 OK and the Content-Type to application/json.
     * It then appends the JSON-encoded verification list to the response.
     */
    private function get(int|string &$response, array &$content_type, string &$body): void
    {
        $response = Response::STATUS_OK;
        $content_type = ['Content-Type' => 'application/json'];
        $body = json_encode($this->state->getVerifyList());
    }

    /**
     * Handles POST requests by parsing the request data and performing actions based on the method type.
     *
     * @param ServerRequestInterface|string $request       The raw HTTP request string.
     * @param string                        &$response     The response string to be modified based on the request handling.
     * @param array                         &$content_type The variable to store the content type of the response.
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
    private function post(ServerRequestInterface|string $request, int|string &$response, array &$content_type, string &$body, bool $bypass_token = false): void
    {
        if ($request instanceof ServerRequestInterface) {
            $formData = $request->getHeaders();
        } elseif (is_string($request)) {
            $formData = [];
            $lines = explode(PHP_EOL, $request);
            foreach ($lines as $line) {
                if (strpos($line, ':') !== false) {
                    [$key, $value] = explode(':', $line, 2);
                    $formData[trim($key)] = trim($value);
                }
            }
        }
        
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
            $content_type = ['Content-Type' => 'text/plain'];
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
                    $content_type,
                    $body
                );
                break;
            default:
                $this->__post(
                    $list,
                    $ckey,
                    $discord,
                    $response,
                    $content_type,
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
     * @param array  &$content_type The variable to store the content type of the response.
     * @param string &$body         The variable to store the body of the response.
     */
    private function __post(array &$list, string $ckey, string $discord, int|string &$response, array &$content_type, string &$body): void
    {
        $existingCkeyIndex = array_search($ckey, array_column($list, 'ss13'));
        $existingDiscordIndex = array_search($discord, array_column($list, 'discord'));

        if ($existingCkeyIndex !== false || $existingDiscordIndex !== false) {
            $response = Response::STATUS_FORBIDDEN;
            $content_type = ['Content-Type' => 'text/plain'];
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
        $response = Response::STATUS_OK;
        $content_type = ['Content-Type' => 'application/json'];
        $body = json_encode($list);
    }

    /**
     * Handles the deletion of an item from the list.
     *
     * @param int|string|false $existingIndex The index of the item to delete, or false if the item does not exist.
     * @param array            &$list         The list from which the item will be deleted.
     * @param string           &$response     The HTTP response message to be returned.
     * @param array            &$content_type The variable to store the content type of the response.
     * @param string           &$body         The variable to store the body of the response.
     */
    private function delete(int|string|false $existingIndex, array &$list, int|string &$response, array &$content_type, string &$body): void
    {
        if ($existingIndex === false) {
            $response = Response::STATUS_NOT_FOUND;
            $content_type = ['Content-Type' => 'text/plain'];
            $body = 'Not Found';
            return;
        }
        $splice = array_splice($list, $existingIndex, 1);
        PersistentState::writeJson($this->state->getJsonPath(), $list);
        $this->state->setVerifyList($list);
        $response = Response::STATUS_OK;
        $content_type = ['Content-Type' => 'application/json'];
        $body = json_encode($splice);
    }
}
