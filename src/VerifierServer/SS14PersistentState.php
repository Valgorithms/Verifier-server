<?php declare(strict_types=1);

namespace VerifierServer;

use Dotenv\Dotenv;

use Exception;
use PDO;
use PDOException;

/**
 * Class SS14PersistentState
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
class SS14PersistentState extends PersistentState
{
    public function __construct(
        protected string $civToken,
        protected string $storageType = 'filesystem',
        protected string $json_path = 'json/ss14verify.json',
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
        $env = self::loadEnvConfig();
        $this->pdo = new PDO(
            $env['DB_DSN'],
            $env['DB_USERNAME'],
            $env['DB_PASSWORD'],
            isset($env['DB_OPTIONS']) ? json_decode($env['DB_OPTIONS'], true) : null
        );
        if (strpos($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'mysql') !== false) {
            if ($this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table_name} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                discord VARCHAR(255),
                ss14 VARCHAR(255),
                create_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )") === false) throw new PDOException("Failed to create table: " . implode(", ", $this->pdo->errorInfo()));
        } else {
            if ($this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table_name} (
                id INTEGER PRIMARY KEY,
                discord TEXT,
                ss14 TEXT,
                create_time TEXT
            )") === false) throw new PDOException("Failed to create table: " . implode(", ", $this->pdo->errorInfo()));
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
    public function &getVerifyList(bool $getLocalCache = false): array
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
     *                         with keys 'discord', 'ss14', and 'create_time'.
     * @param bool  $write Whether to write the list to the database. Default is true.
     * 
     * @throws PDOException If there is an error deleting from the {$this->table_name} table, preparing the insert statement,
     *                           or executing the insert statement.
     */
    public function setVerifyList(array $list, bool $write = true): void
    {
        if ($write && $this->storageType !== 'filesystem') {
            if ($this->pdo->exec("DELETE FROM {$this->table_name}") === false) {
                throw new PDOException("Failed to delete from {$this->table_name}: " . implode(", ", $this->pdo->errorInfo()));
            }
            if (($stmt = $this->pdo->prepare("INSERT INTO {$this->table_name} (discord, ss14, create_time) VALUES (:discord, :ss14, :create_time)")) === false) {
                throw new PDOException("Failed to prepare statement.");
            }
            foreach ($list as $item) if (!$stmt->execute($item)) {
                throw new PDOException("Failed to execute statement.");
            }
        }
        $this->verifyList = $list;
    }
}