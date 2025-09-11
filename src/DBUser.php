<?php

namespace Common\DB;

use PDO;
use Throwable;

class DBUser extends DBBase
{
    protected $table = 'user';
    protected $emailField = 'username'; // User table uses 'username' field for email

    public function __construct($pdo = null, $logger = null)
    {
        parent::__construct($pdo, $logger);
    }

    /**
     * Get field names for this table
     * @return array
     */
    public function getFieldNames(): array
    {
        return ['id', 'username', 'password', 'active', 'tenant', 'app', 'created', 'modified'];
    }

    /**
     * Get user by email address (overrides parent to use username field)
     * @param string $email
     * @param string $fromdate Unused for user lookup
     * @return array User record or empty array if not found
     */
    public function getByEmail($email, $fromdate = ''): array
    {
        try {
            $this->debuglog("DBUser->getByEmail(): ($email)");
            
            $sql = "SELECT * FROM {$this->table} WHERE {$this->emailField} = :email LIMIT 1";
            $stmt = $this->executeStatement($sql, [':email' => $email]);
            if (!$stmt) {
                return [];
            }
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->debuglog("DBUser->getByEmail(): " . ($result ? "1 record found" : "no records found"));
            
            return $result ?: [];
        } catch (Throwable $e) {
            $this->debuglog("DBUser->getByEmail() error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get user by ID (overrides parent for specific user handling)
     * @param mixed $id
     * @return array User record or empty array if not found
     */
    public function getById($id): array
    {
        try {
            $this->debuglog("DBUser->getById(): ($id)");
            
            $sql = "SELECT * FROM {$this->table} WHERE id = :id LIMIT 1";
            $stmt = $this->executeStatement($sql, [':id' => $id]);
            if (!$stmt) {
                return [];
            }
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->debuglog("DBUser->getById(): " . ($result ? "1 record found" : "no records found"));
            
            return $result ?: [];
        } catch (Throwable $e) {
            $this->debuglog("DBUser->getById() error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Verify user password
     * @param string $email
     * @param string $password
     * @return bool True if password is valid
     */
    public function verifyPassword(string $email, string $password): bool
    {
        try {
            $user = $this->getByEmail($email);
            if (!$user || !isset($user['password'])) {
                return false;
            }

            $stored = $user['password'];
            
            // Check if password is hashed (bcrypt, argon2, etc.)
            if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2')) {
                return password_verify($password, $stored);
            } else {
                // Fallback for plain text passwords (not recommended)
                return hash_equals($stored, $password);
            }
        } catch (Throwable $e) {
            $this->debuglog("DBUser->verifyPassword() error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if user is active
     * @param string $email
     * @return bool True if user is active
     */
    public function isActive(string $email): bool
    {
        try {
            $user = $this->getByEmail($email);
            if (!$user) {
                return false;
            }
            
            $active = isset($user['active']) ? (int)$user['active'] : 1;
            return $active === 1;
        } catch (Throwable $e) {
            $this->debuglog("DBUser->isActive() error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process password hashing before update
     * @param mixed $params User data
     * @return mixed Result from parent update
     */
    public function update($params)
    {
        try {
            $this->debuglog("DBUser->update(): " . json_encode(array_keys($params)));
            
            // Hash password if provided and not already hashed
            if (isset($params['password']) && !str_starts_with($params['password'], '$')) {
                $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            }
            
            return parent::update($params);
        } catch (Throwable $e) {
            $this->debuglog("DBUser->update() error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create user with default active status and hashed password
     * @param array $params User data
     * @return mixed Result from parent insertOneDBRecord
     */
    public function create(array $params)
    {
        try {
            $this->debuglog("DBUser->create(): " . json_encode(array_keys($params)));
            
            // Set default active status
            if (!isset($params['active'])) {
                $params['active'] = 1;
            }
            
            // Hash password if provided and not already hashed
            if (isset($params['password']) && !str_starts_with($params['password'], '$')) {
                $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            }
            
            $potential = $this->getFieldNames();
            return $this->insertOneDBRecord($potential, $params, $this->table, $this->prefix, $this->idName);
        } catch (Throwable $e) {
            $this->debuglog("DBUser->create() error: " . $e->getMessage());
            return false;
        }
    }
}