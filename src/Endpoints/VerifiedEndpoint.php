<?php
namespace VerifierServer\Endpoints;

use VerifierServer\PersistentState;

class VerifiedEndpoint {
    private PersistentState $state;

    public function __construct(PersistentState $state) {
        $this->state = $state;
    }

    public function handleRequest($method, $request, &$response) {
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

    private function handleGet(&$response) {
        $response = "HTTP/1.1 200 OK" . PHP_EOL . "Content-Type: application/json" . PHP_EOL . PHP_EOL;
        $response .= json_encode($this->state->getVerifyList());
    }

    private function handlePost($request, &$response) {
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
        $existingIndex = array_search($discord, array_column($list, 'discord'));

        switch ($methodType) {
            case 'delete':
                $this->handleDelete($existingIndex, $list, $response);
                break;
            default:
                $this->handleDefault($existingIndex, $list, $ckey, $discord, $response);
                break;
        }
    }

    private function handleDelete($existingIndex, &$list, &$response) {
        if ($existingIndex !== false) {
            array_splice($list, $existingIndex, 1);
            PersistentState::writeJson("verify.json", $list);
            $this->state->setVerifyList($list);
            $response = "HTTP/1.1 200 OK" . PHP_EOL . "Content-Type: text/plain" . PHP_EOL . PHP_EOL;
        } else {
            $response = "HTTP/1.1 403 Forbidden" . PHP_EOL . "Content-Type: text/plain" . PHP_EOL . PHP_EOL;
        }
    }

    public function handleDefault($existingIndex, &$list, $ckey, $discord, &$response) {
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
