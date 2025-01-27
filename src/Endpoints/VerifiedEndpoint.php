<?php
namespace VerifierServer\Endpoints;

use VerifierServer\PersistentState;

class VerifiedEndpoint {
    public function __construct(private PersistentState $state)
    {}

    /**
     * Handles the incoming HTTP request and generates the appropriate response.
     *
     * @param string $method The HTTP method of the request (e.g., 'GET', 'POST').
     * @param string $request The request payload, typically used for 'POST' requests.
     * @param string &$response The variable to store the generated response.
     */
    public function handleRequest(string $method, string $request, string &$response): void
    {
        switch ($method) {
            case 'GET':
                $this->handleGet($response);
                break;
            case 'POST':
                $this->handlePost($request, $response);
                break;
            default:
                $response = "HTTP/1.1 405 Method Not Allowed" . PHP_EOL . "Content-Type: text/plain" . PHP_EOL . PHP_EOL;
                break;
        }
    }

    /**
     * Handles the GET request and prepares the response.
     *
     * @param string &$response The response string to be sent back to the client.
     *
     * This method sets the HTTP status code to 200 OK and the Content-Type to application/json.
     * It then appends the JSON-encoded verification list to the response.
     */
    private function handleGet(string &$response): void
    {
        $response = "HTTP/1.1 200 OK" . PHP_EOL . "Content-Type: application/json" . PHP_EOL . PHP_EOL;
        $response .= json_encode($this->state->getVerifyList());
    }

    /**
     * Handles POST requests by parsing the request data and performing actions based on the method type.
     *
     * @param string $request The raw HTTP request string.
     * @param string &$response The response string to be modified based on the request handling.
     *
     * The function performs the following steps:
     * 1. Extracts the raw data from the request.
     * 2. Parses the raw data into an associative array.
     * 3. Retrieves the method type, ckey, discord, and token from the parsed data.
     * 4. Checks if the provided token matches the expected token. If not, sets the response to 401 Unauthorized.
     * 5. Retrieves the verification list from the state.
     * 6. Based on the method type, either deletes an entry from the list or handles the default case.
     */
    private function handlePost(string $request, string &$response): void
    {
        $rawData = explode(PHP_EOL . PHP_EOL, $request, 2)[1];
        parse_str($rawData, $formData);

        $methodType = isset($formData['method']) ? strtolower(trim($formData['method'])) : null;
        $ckey = $formData['ckey'] ?? '';
        $discord = $formData['discord'] ?? '';
        $token = $formData['token'] ?? '';

        if ($this->state->getToken() !== 'changeme' && $token !== $this->state->getToken()) {
            $response = "HTTP/1.1 401 Unauthorized" . PHP_EOL . "Content-Type: text/plain" . PHP_EOL . PHP_EOL;
            return;
        }

        $list = $this->state->getVerifyList();

        switch ($methodType) {
            case 'delete':
                $this->handleDelete(array_search($discord, array_column($list, 'discord')), $list, $response);
                break;
            default:
                $this->handleDefault($list, $ckey, $discord, $response);
                break;
        }
    }

    /**
     * Handles the deletion of an item from the list.
     *
     * @param int|string|false $existingIndex The index of the item to delete, or false if the item does not exist.
     * @param array &$list The list from which the item will be deleted.
     * @param string &$response The HTTP response message to be returned.
     */
    private function handleDelete(int|string|false $existingIndex, array &$list, string &$response): void
    {
        if ($existingIndex !== false) {
            array_splice($list, $existingIndex, 1);
            PersistentState::writeJson("verify.json", $list);
            $this->state->setVerifyList($list);
            $response = "HTTP/1.1 200 OK" . PHP_EOL . "Content-Type: text/plain" . PHP_EOL . PHP_EOL;
        } else {
            $response = "HTTP/1.1 403 Forbidden" . PHP_EOL . "Content-Type: text/plain" . PHP_EOL . PHP_EOL;
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
     * @param array $list The list of existing entries.
     * @param string $ckey The ckey to be verified.
     * @param string $discord The discord identifier to be verified.
     * @param string &$response The response message to be set based on the verification result.
     */
    public function handleDefault(array &$list, string $ckey, string $discord, string &$response): void
    {
        $existingCkeyIndex = array_search($ckey, array_column($list, 'ss13'));
        $existingDiscordIndex = array_search($discord, array_column($list, 'discord'));

        if ($existingCkeyIndex !== false || $existingDiscordIndex !== false) {
            $response = "HTTP/1.1 403 Forbidden" . PHP_EOL . "Content-Type: text/plain" . PHP_EOL . PHP_EOL;
        } else {
            $list[] = [
                'ss13' => $ckey,
                'discord' => $discord,
                'create_time' => date('Y-m-d H:i:s')
            ];
            PersistentState::writeJson("verify.json", $list);
            $this->state->setVerifyList($list);
            $response = "HTTP/1.1 200 OK" . PHP_EOL . "Content-Type: text/plain" . PHP_EOL . PHP_EOL;
        }
    }
}
