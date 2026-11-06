<?php declare(strict_types=1);

namespace VerifierServer;

use Dotenv\Dotenv;
use VerifierServer\Traits\JsonStorageTrait;

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
class PersistentState
{
    use JsonStorageTrait;
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
    protected array $verifyList;

    public function __construct(
        protected string $civToken,
        protected string $storageType = 'filesystem',
        protected string $json_path = 'json/verify.json',
        protected string $table_name = 'ss14_verify_list'
    ) {
        $this->__wakeup();
    }

    /**
     * Initializes the database by creating the `{$this->table_name}` table if it does not already exist.
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
    protected function initializeDatabase(): void
    {
        $this->pdo = $this->connect();
        if (strpos($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'mysql') !== false) {
            if ($this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table_name} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ss13 VARCHAR(255),
                discord VARCHAR(255),
                create_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )") === false) {
                $errorInfo = $this->pdo->errorInfo();
                throw new PDOException("Failed to create table: " . implode(", ", $errorInfo));
            }
        } else {
            if ($this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table_name} (
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
     * Opens the database connection from the environment configuration.
     * Overridden by tests to inject an in-memory connection.
     */
    protected function connect(): PDO
    {
        $env = self::loadEnvConfig();
        return new PDO(
            $env['DB_DSN'],
            $env['DB_USERNAME'],
            $env['DB_PASSWORD'],
            isset($env['DB_OPTIONS']) && $env['DB_OPTIONS'] !== '' ? json_decode($env['DB_OPTIONS'], true) : null
        );
    }

    /**
     * Columns persisted for each record. Rows read back from a database also carry `id`,
     * which must not be fed back into the insert statement.
     */
    protected function recordColumns(): array
    {
        return ['ss13', 'discord', 'create_time'];
    }

    /**
     * Persists a replacement list through the configured storage and updates the cache.
     * Filesystem storage writes the JSON file; database storage rewrites the table.
     */
    public function saveVerifyList(array $list): void
    {
        if ($this->storageType === 'filesystem') {
            static::writeJson($this->getJsonPath(), $list);
            $this->setVerifyList($list, false);
            return;
        }
        $this->setVerifyList($list, true);
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
                : $this->verifyList = self::loadJsonFile($this->getJsonPath());
        }
        $stmt = $this->pdo->query("SELECT * FROM {$this->table_name}");
        if ($stmt === false) {
            throw new PDOException("Failed to execute query: " . implode(", ", $this->pdo->errorInfo()));
        }
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($result === false) {
            throw new PDOException("Failed to fetch data: " . implode(", ", $this->pdo->errorInfo()));
        }
        return $this->verifyList = $result;
    }

    /**
     * Sets the verification list.
     *
     * This method updates the verification list based on the storage type. If the storage type is 'filesystem',
     * it simply assigns the provided list to the verifyList property. If the storage type is not 'filesystem',
     * it updates the {$this->table_name} table in the database.
     *
     * @param array $list  The list of verification items to be set. Each item in the list should be an associative array
     *                         with keys 'ss13', 'discord', and 'create_time'.
     * @param bool  $write Whether to write the list to the database. Default is true.
     * 
     * @throws PDOException If there is an error deleting from the {$this->table_name} table, preparing the insert statement,
     *                           or executing the insert statement.
     */
    public function setVerifyList(array $list, bool $write = true): void
    {
        if ($write && $this->storageType !== 'filesystem') {
            $this->rewriteTable($list);
        }
        $this->verifyList = $list;
    }

    /**
     * Replaces every row of the table inside one transaction with only the record columns.
     *
     * @throws PDOException If any statement fails; the transaction is rolled back.
     */
    protected function rewriteTable(array $list): void
    {
        $columns = $this->recordColumns();
        $placeholders = implode(', ', array_map(fn($column) => ":$column", $columns));
        $this->pdo->beginTransaction();
        try {
            if ($this->pdo->exec("DELETE FROM {$this->table_name}") === false) {
                throw new PDOException("Failed to delete from {$this->table_name}: " . implode(", ", $this->pdo->errorInfo()));
            }
            if (($stmt = $this->pdo->prepare("INSERT INTO {$this->table_name} (" . implode(', ', $columns) . ") VALUES ($placeholders)")) === false) {
                throw new PDOException("Failed to prepare statement.");
            }
            foreach ($list as $item) {
                $row = [];
                foreach ($columns as $column) $row[$column] = isset($item[$column]) ? (string) $item[$column] : null;
                if (!$stmt->execute($row)) {
                    throw new PDOException("Failed to execute statement.");
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
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
        return self::isAbsolutePath($this->json_path)
            ? $this->json_path
            : getcwd() . '/' . $this->json_path;
    }

    /** True for `/path`, `C:\path`, `C:/path` and `\\server\share` paths. */
    public static function isAbsolutePath(string $path): bool
    {
        return $path !== ''
            && ($path[0] === '/' || $path[0] === '\\' || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1);
    }

    /** Keys returned by loadEnvConfig() with their defaults when absent from the environment. */
    public const ENV_DEFAULTS = [
        'HOST_ADDR' => '127.0.0.1',
        'HOST_PORT' => '8080',
        'TOKEN' => 'changeme',
        'STORAGE_TYPE' => 'filesystem',
        'JSON_PATH' => 'json/verify.json',
        'SS14_JSON_PATH' => 'json/ss14verify.json',
        'DB_DSN' => '',
        'DB_PORT' => '',
        'DB_USERNAME' => '',
        'DB_PASSWORD' => '',
        'DB_OPTIONS' => '',
    ];

    /**
     * Loads environment configuration from the `.env` file in `$directory`
     * (the repository root by default). If the file does not exist, it is
     * created with default values. Variables already present in the real
     * environment take precedence over the file.
     *
     * @return array The environment configuration as an associative array
     *               with every key of ENV_DEFAULTS present.
     * @throws Exception If the TOKEN is 'changeme' and the HOST_ADDR is not '127.0.0.1'.
     */
    public static function loadEnvConfig(?string $directory = null): array
    {
        $directory = $directory ?? dirname(__DIR__, 2);
        $file = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.env';
        if (! file_exists($file)) {
            file_put_contents($file,
                "HOST_ADDR=127.0.0.1" . PHP_EOL .
                "HOST_PORT=8080" . PHP_EOL .
                "TOKEN=changeme" . PHP_EOL .
                "STORAGE_TYPE=filesystem" . PHP_EOL .
                "JSON_PATH=json/verify.json" . PHP_EOL .
                "SS14_JSON_PATH=json/ss14verify.json" . PHP_EOL .
                "# SQLite configuration" . PHP_EOL .
                "#DB_DSN=sqlite:verify.db" . PHP_EOL .
                "#DB_USERNAME=" . PHP_EOL .
                "#DB_PASSWORD=" . PHP_EOL .
                "#DB_OPTIONS=" . PHP_EOL .
                "# MySQL configuration" . PHP_EOL .
                "#DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=verify_list" . PHP_EOL .
                "#DB_PORT=3306" . PHP_EOL .
                "#DB_USERNAME=your_username" . PHP_EOL .
                "#DB_PASSWORD=your_password" . PHP_EOL .
                "#DB_OPTIONS={\"option1\":\"value1\",\"option2\":\"value2\"}" . PHP_EOL
            );
        }

        $loaded = Dotenv::createImmutable($directory)->load();

        $env = [];
        foreach (self::ENV_DEFAULTS as $key => $default) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
            if ($value === null) $value = getenv($key);
            if ($value === false) $value = $loaded[$key] ?? null;
            $env[$key] = $value === null ? $default : (string) $value;
        }
        if ($env['TOKEN'] === 'changeme' && $env['HOST_ADDR'] !== '127.0.0.1') {
            throw new Exception('Cannot use default token with non-localhost address!');
        }
        return $env;
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
     */
    public function __wakeup(): void
    {
        if ($this->storageType !== 'filesystem') {
            $this->initializeDatabase();
        }
    }

    public function __debugInfo()
    {
        return [
            'storageType' => $this->storageType,
            'json_path' => $this->json_path,
            'verifyList' => $this->verifyList ?? [],
        ];
    }
}