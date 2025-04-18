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
        return json_decode($data, true) ?: [];
    }

    /**
     * Writes the given data to a file in JSON format.
     *
     * @param string $file The path to the file where the JSON data will be written.
     * @param mixed  $data The data to be encoded as JSON and written to the file.
     */
    public static function writeJson(string $file, array $data): void
    {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }
}