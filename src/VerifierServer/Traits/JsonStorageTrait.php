<?php declare(strict_types=1);

namespace VerifierServer\Traits;

use RuntimeException;

trait JsonStorageTrait
{
    /**
     * Loads the verification data from the "verify.json" file.
     * If the file does not exist, it creates an empty JSON array file.
     * If the file cannot be read, it throws an exception.
     *
     * @return array|null The decoded JSON data as an associative array, or an empty array if the file is empty or invalid.
     * @throws Exception If the file cannot be read.
     */
    public static function loadJsonFile(string $json_path): ?array
    {
        if (!is_dir($directory = dirname($json_path))) {
            mkdir($directory, 0777, true);
        }
        if (!file_exists($json_path)) {
            file_put_contents($json_path, "[]");
        }
        $data = file_get_contents($json_path);
        if ($data === false) {
            throw new RuntimeException("Failed to read $json_path");
        }
        if (trim($data) === '') {
            return [];
        }
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            // A damaged file must not silently become an empty list that the next write then persists.
            throw new RuntimeException("Invalid JSON in $json_path: " . json_last_error_msg());
        }
        return $decoded;
    }

    /**
     * Writes the given data to a file in JSON format.
     *
     * The data is written to a temporary file next to the target and renamed into
     * place, so a crash or full disk mid-write leaves the previous file intact.
     *
     * @param string $file The path to the file where the JSON data will be written.
     * @param mixed  $data The data to be encoded as JSON and written to the file.
     *
     * @throws RuntimeException If the data cannot be encoded or the file cannot be written.
     */
    public static function writeJson(string $file, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (!is_dir($directory = dirname($file))) {
            mkdir($directory, 0777, true);
        }
        $temporary = tempnam($directory, basename($file) . '.');
        if ($temporary === false) {
            throw new RuntimeException("Failed to create a temporary file beside $file");
        }
        if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException("Failed to write $file");
        }
    }
}