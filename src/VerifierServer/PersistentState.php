<?php declare(strict_types=1);

namespace VerifierServer;

use Dotenv\Dotenv;

use Exception;
use PDO;
use PDOException;

/**
 * Class PersistentState
 *
 * This class provides a persistent state management system for verification data. It supports two storage types:
 * - Filesystem: Stores the verification data in a JSON file.
 * - Database: Stores the verification data in a database table.
 *
 * The class includes methods for initializing the storage, retrieving and updating the verification list, 
 * and managing environment configurations.
 * 
 * @package VerifierServer
 */
class PersistentState {
    /**
     * The PDO object for database operations.
     * 
     * @var PDO Database connection.
    */
    protected PDO $pdo;

    /**
     * The list of verification items.
     * 
     * @var array Verification list.
    */
    private array $verifyList;

    public function __construct(
        private string $civToken,
        private string $storageType = 'filesystem',
        private string $json_path = 'json/verify.json'
    ) {
        $this->__wakeup();
    }

    /**
     * Initializes the database by creating the `verify_list` table if it does not already exist.
     * The table schema differs based on the database driver (MySQL or others).
     *
     * For MySQL:
     * - id: INT, AUTO_INCREMENT, PRIMARY KEY
     * - ss13: VARCHAR(255)
     * - discord: VARCHAR(255)
     * - create_time: TIMESTAMP, DEFAULT CURRENT_TIMESTAMP
     *
     * For other databases:
     * - id: INTEGER, PRIMARY KEY
     * - ss13: TEXT
     * - discord: TEXT
     * - create_time: TEXT
     *
     * @throws PDOException if the table creation fails.
     */
    private function initializeDatabase(): void
    {
        $env = self::loadEnvConfig();
        $this->pdo = new PDO(
            $env['DB_DSN'],
            $env['DB_USERNAME'],
            $env['DB_PASSWORD'],
            isset($env['DB_OPTIONS']) ? json_decode($env['DB_OPTIONS'], true) : null
        );
        if (strpos($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'mysql') !== false) {
            if ($this->pdo->exec("CREATE TABLE IF NOT EXISTS verify_list (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ss13 VARCHAR(255),
                discord VARCHAR(255),
                create_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )") === false) {
                $errorInfo = $this->pdo->errorInfo();
                throw new PDOException("Failed to create table: " . implode(", ", $errorInfo));
            }
        } else {
            if ($this->pdo->exec("CREATE TABLE IF NOT EXISTS verify_list (
                id INTEGER PRIMARY KEY,
                ss13 TEXT,
                discord TEXT,
                create_time TEXT
            )") === false) {
                $errorInfo = $this->pdo->errorInfo();
                throw new PDOException("Failed to create table: " . implode(", ", $errorInfo));
            }
        }
    }

    /**
     * Retrieves the verification list.
     *
     * This method fetches the verification list from either the filesystem or the database,
     * depending on the configured storage type.
     *
     * @return array The verification list.
     *
     * @throws PDOException If there is an error executing the query or fetching the data from the database.
     */
    public function getVerifyList(bool $getLocalCache = false): array
    {
        if ($this->storageType === 'filesystem' || $getLocalCache) {
            return isset($this->verifyList)
                ? $this->verifyList
                : $this->verifyList = self::loadVerifyFile($this->getJsonPath());
        }
        $stmt = $this->pdo->query("SELECT * FROM verify_list");
        if ($stmt === false) {
            $errorInfo = $this->pdo->errorInfo();
            throw new PDOException("Failed to execute query: " . implode(", ", $errorInfo));
        }
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($result === false) {
            $errorInfo = $this->pdo->errorInfo();
            throw new PDOException("Failed to fetch data: " . implode(", ", $errorInfo));
        }
        return $this->verifyList = $result;
    }

    /**
     * Sets the verification list.
     *
     * This method updates the verification list based on the storage type. If the storage type is 'filesystem',
     * it simply assigns the provided list to the verifyList property. If the storage type is not 'filesystem',
     * it updates the verify_list table in the database.
     *
     * @param array $list  The list of verification items to be set. Each item in the list should be an associative array
     *                         with keys 'ss13', 'discord', and 'create_time'.
     * @param bool  $write Whether to write the list to the database. Default is true.
     * 
     * @throws PDOException If there is an error deleting from the verify_list table, preparing the insert statement,
     *                           or executing the insert statement.
     */
    public function setVerifyList(array $list, bool $write = true): void
    {
        if ($write && $this->storageType !== 'filesystem') {
            if ($this->pdo->exec("DELETE FROM verify_list") === false) {
                throw new PDOException("Failed to delete from verify_list: " . implode(", ", $this->pdo->errorInfo()));
            }
            if (($stmt = $this->pdo->prepare("INSERT INTO verify_list (ss13, discord, create_time) VALUES (:ss13, :discord, :create_time)")) === false) {
                throw new PDOException("Failed to prepare statement.");
            }
            foreach ($list as $item) if (!$stmt->execute($item)) {
                throw new PDOException("Failed to execute statement.");
            }
        }
        $this->verifyList = $list;
    }

    /**
     * Retrieves the token.
     *
     * @return string The token.
     */
    public function getToken(): string
    {
        return $this->civToken;
    }

    /**
     * Retrieves the JSON path associated with the persistent state.
     *
     * @return string The file path to the JSON data.
     */
    public function getJsonPath(): string
    {
        return getcwd() . '/' . $this->json_path;
    }

    /**
     * Loads environment configuration from a .env file. 
     * If the .env file does not exist, it creates one with default values.
     * 
     * @return array The environment configuration as an associative array.
     * @throws Exception If the TOKEN is 'changeme' and the HOST_ADDR is not '127.0.0.1'.
     * 
     * The default values include:
     * - HOST_ADDR=127.0.0.1
     * - HOST_PORT=8080
     * - TOKEN=changeme
     * - STORAGE_TYPE=filesystem
     * - DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=verify_list
     * - DB_PORT=3306
     * - DB_USERNAME=your_username
     * - DB_PASSWORD=your_password
     * - DB_OPTIONS={"option1":"value1","option2":"value2"}
     * 
     * After loading the .env file, it checks if the TOKEN is 'changeme' and the HOST_ADDR is not '127.0.0.1'.
     * If this condition is met, the script terminates with an error message.
     * 
     */
    public static function loadEnvConfig(): array
    {
        if (! file_exists(".env")) {
            file_put_contents(".env", 
                "HOST_ADDR=127.0.0.1" . PHP_EOL . 
                "HOST_PORT=8080" . PHP_EOL . 
                "TOKEN=changeme" . PHP_EOL . 
                "STORAGE_TYPE=filesystem" . PHP_EOL . 
                "JSON_PATH=json/verify.json" . PHP_EOL .
                "# SQLite configuration" . PHP_EOL . 
                "#DB_DSN=sqlite:verify.db" . PHP_EOL . 
                "#DB_USERNAME=" . PHP_EOL . 
                "#DB_PASSWORD=" . PHP_EOL . 
                "#DB_OPTIONS=" . PHP_EOL . 
                "# MySQL configuration" . PHP_EOL . 
                "DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=verify_list" . PHP_EOL . 
                "DB_PORT=3306" . PHP_EOL . 
                "DB_USERNAME=your_username" . PHP_EOL . 
                "DB_PASSWORD=your_password" . PHP_EOL . 
                "#DB_OPTIONS={\"option1\":\"value1\",\"option2\":\"value2\"}"
            );
        }

        $dotenv = Dotenv::createImmutable(__DIR__ . '\\..\\..\\');
        $dotenv->load();

        $env = $_ENV;
        if ($env['TOKEN'] === 'changeme' && $env['HOST_ADDR'] !== '127.0.0.1') {
            die("Cannot use default token with non-localhost address! ");
        }
        return [
            'HOST_ADDR' => $env['HOST_ADDR'],
            'HOST_PORT' => $env['HOST_PORT'],
            'TOKEN' => $env['TOKEN'],
            'DB_DSN' => $env['DB_DSN'],
            'DB_PORT' => $env['DB_PORT'],
            'DB_USERNAME' => $env['DB_USERNAME'],
            'DB_PASSWORD' => $env['DB_PASSWORD'],
            'DB_OPTIONS' => $env['DB_OPTIONS'] ?? ''
        ];
    }

    /**
     * Loads the verification data from the "verify.json" file.
     * If the file does not exist, it creates an empty JSON array file.
     * If the file cannot be read, it throws an exception.
     *
     * @return array|null The decoded JSON data as an associative array, or an empty array if the file is empty or invalid.
     * @throws Exception If the file cannot be read.
     */
    public static function loadVerifyFile(string $json_path): ?array
    {
        $directory = dirname($json_path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        if (!file_exists($json_path)) {
            file_put_contents($json_path, "[]");
        }
        $data = file_get_contents($json_path);
        if ($data === false) {
            throw new Exception("Failed to read $json_path");
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

    /**
     * Prepares the object for serialization by excluding the PDO object.
     *
     * @return array The list of properties to serialize.
     */
    public function __sleep(): array
    {
        return ['civToken', 'storageType', 'json_path', 'verifyList'];
    }

    /**
     * Reinitializes the PDO object after deserialization.
     *
     * @return void
     */
    public function __wakeup(): void
    {
        if ($this->storageType !== 'filesystem') {
            $this->initializeDatabase();
        }
    }
}