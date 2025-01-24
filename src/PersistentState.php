<?php
namespace VerifierServer;

use Dotenv\Dotenv;

class PersistentState {
    private $verifyList;
    private $civToken;
    private $storageType;
    private $pdo;

    public function __construct($verifyFile, $civToken, $storageType = 'filesystem') {
        $this->verifyList = $verifyFile;
        $this->civToken = $civToken;
        $this->storageType = $storageType;

        if ($this->storageType === 'sql') {
            $env = self::loadEnvConfig();
            $dsn = $env['DB_DSN'];
            $username = $env['DB_USERNAME'];
            $password = $env['DB_PASSWORD'];
            $options = isset($env['DB_OPTIONS']) ? json_decode($env['DB_OPTIONS'], true) : [];
            $this->pdo = new \PDO($dsn, $username, $password, $options);
            $this->initializeDatabase();
        }
    }

    private function initializeDatabase() {
        if (strpos($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME), 'mysql') !== false) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS verify_list (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ss13 VARCHAR(255),
                discord VARCHAR(255),
                create_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS verify_list (
                id INTEGER PRIMARY KEY,
                ss13 TEXT,
                discord TEXT,
                create_time TEXT
            )");
        }
    }

    public function getVerifyList() {
        if ($this->storageType === 'sql') {
            $stmt = $this->pdo->query("SELECT * FROM verify_list");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $this->verifyList;
    }

    public function setVerifyList($list) {
        if ($this->storageType === 'sql') {
            $this->pdo->exec("DELETE FROM verify_list");
            $stmt = $this->pdo->prepare("INSERT INTO verify_list (ss13, discord, create_time) VALUES (:ss13, :discord, :create_time)");
            foreach ($list as $item) {
                $stmt->execute($item);
            }
        } else {
            $this->verifyList = $list;
        }
    }

    public function getToken() {
        return $this->civToken;
    }

    public static function loadVerifyFile() {
        if (! file_exists("verify.json")) {
            file_put_contents("verify.json", "[]");
        }
        $data = file_get_contents("verify.json");
        return json_decode($data, true) ?: [];
    }

    public static function loadEnvConfig() {
        if (! file_exists(".env")) {
            file_put_contents(".env", 
                "HOST_ADDR=127.0.0.1" . PHP_EOL . 
                "HOST_PORT=8080" . PHP_EOL . 
                "TOKEN=changeme" . PHP_EOL . 
                "STORAGE_TYPE=filesystem" . PHP_EOL . 
                "# SQLite configuration" . PHP_EOL . 
                "#DB_DSN=sqlite:verify.db" . PHP_EOL . 
                "#DB_USERNAME=" . PHP_EOL . 
                "#DB_PASSWORD=" . PHP_EOL . 
                "#DB_OPTIONS=" . PHP_EOL . 
                "# MySQL configuration" . PHP_EOL . 
                "DB_DSN=mysql:host=127.0.0.1;port=3307;dbname=verify_list" . PHP_EOL . 
                "DB_PORT=3306" . PHP_EOL . 
                "DB_USERNAME=your_username" . PHP_EOL . 
                "DB_PASSWORD=your_password" . PHP_EOL . 
                "#DB_OPTIONS={\"option1\":\"value1\",\"option2\":\"value2\"}"
            );
            echo "No .env file found. Creating one with default values." . PHP_EOL;
        }

        $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
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

    public static function writeJson($file, $data) {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }
}
