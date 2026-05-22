<?php

class Database
{
    private mysqli $connection;

    public function __construct(array $config)
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->connection = new mysqli(
            (string) $config['host'],
            (string) $config['username'],
            (string) $config['password'],
            (string) $config['dbname'],
            (int) $config['port']
        );
        $this->connection->set_charset((string) $config['charset']);
        $this->connection->query("SET time_zone = '+05:30'");
    }

    public function prepare(string $sql): DatabaseStatement
    {
        return new DatabaseStatement($this->connection->prepare($sql));
    }

    public function query(string $sql): DatabaseResult
    {
        return new DatabaseResult($this->connection->query($sql));
    }

    public function lastInsertId(): int
    {
        return $this->connection->insert_id;
    }
}

class DatabaseStatement
{
    private mysqli_stmt $statement;
    private ?mysqli_result $result = null;

    public function __construct(mysqli_stmt $statement)
    {
        $this->statement = $statement;
    }

    public function execute(array $params = []): void
    {
        if ($params !== []) {
            $types = str_repeat('s', count($params));
            $values = array_map(static function ($param) {
                return is_bool($param) ? (int) $param : (string) $param;
            }, $params);
            $this->statement->bind_param($types, ...$values);
        }

        $this->statement->execute();
        $result = $this->statement->get_result();
        $this->result = $result !== false ? $result : null;
    }

    public function fetch(): array|false
    {
        if (!$this->result instanceof mysqli_result) {
            return false;
        }

        return $this->result->fetch_assoc();
    }

    public function fetchAll(): array
    {
        if (!$this->result instanceof mysqli_result) {
            return [];
        }

        return $this->result->fetch_all(MYSQLI_ASSOC);
    }

    public function fetchColumn(): mixed
    {
        $row = $this->fetch();

        if ($row === false) {
            return false;
        }

        return array_values($row)[0] ?? false;
    }
}

class DatabaseResult
{
    private mysqli_result|bool $result;

    public function __construct(mysqli_result|bool $result)
    {
        $this->result = $result;
    }

    public function fetchAll(): array
    {
        if (!$this->result instanceof mysqli_result) {
            return [];
        }

        return $this->result->fetch_all(MYSQLI_ASSOC);
    }

    public function fetchColumn(): mixed
    {
        if (!$this->result instanceof mysqli_result) {
            return false;
        }

        $row = $this->result->fetch_row();
        return $row[0] ?? false;
    }
}


function ensure_user_role_support(Database $database): void
{
    $roleColumn = $database->query("SHOW COLUMNS FROM users LIKE 'role'")->fetchAll();

    if ($roleColumn === []) {
        return;
    }

    $roleType = (string) ($roleColumn[0]['Type'] ?? '');
    if (str_contains($roleType, "'district_user'")) {
        return;
    }

    $database->query("ALTER TABLE users MODIFY COLUMN role ENUM('administrator', 'crm_member', 'district_user') NOT NULL");
}

function db(): Database
{
    static $database = null;

    if ($database instanceof Database) {
        return $database;
    }

    $cfg = require __DIR__ . '/../config/database.php';

    try {
        $database = new Database($cfg);
        ensure_user_role_support($database);
    } catch (mysqli_sql_exception $exception) {
        error_log(sprintf(
            'Database connection failed for user "%s" on host "%s:%s" (db: %s): %s',
            (string) $cfg['username'],
            (string) $cfg['host'],
            (string) $cfg['port'],
            (string) $cfg['dbname'],
            $exception->getMessage()
        ));

        http_response_code(500);
        exit('Database connection failed. Please verify DB credentials in config/database.php or environment variables (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS).');
    }

    return $database;
}
