<?php
namespace Common\DB;

use PDO;
use PDOException;
use ReflectionClass;
use Exception;
use RuntimeException;
use Throwable;

abstract class DBBase {
	protected $table = '';
	protected $prefix = '';
	protected $idName = 'id';
	protected $className = '';
	protected $moduleName = '';
	protected $conn = null; // ?PDO
	protected static $defaultConn = null; // ?PDO
	/** @var callable|null */
	protected $logger = null; // fn(string $msg, string $title='')
	/** @var callable|null */
	protected static $defaultLogger = null;
	/** @var array|null */
	protected static $defaultConfig = null;

	public function __construct($pdo = null, $logger = null) {
		$this->conn = $pdo ?: self::$defaultConn;
		$this->logger = $logger ?: self::$defaultLogger;
	}
	
	public function setConnection(PDO $pdo) {
        $this->conn = $pdo;
    }
	public function getConnection() {
        return $this->conn;
    }
	public static function setDefaultConnection(PDO $pdo) {
        self::$defaultConn = $pdo;
    }
	public static function getDefaultConnection() {
        return self::$defaultConn;
    }
	public static function setDefaultLogger($logger) {
        self::$defaultLogger = $logger;
    }
	
	/**
	 * Configure database connection parameters
	 * @param array $config Configuration array with keys: host, dbname, username, password, port?, socket?, charset?
	 */
	public static function setDefaultConfig(array $config) {
		self::$defaultConfig = $config;
	}
	
	public function uniqidReal($prefix = '', $len = 13) {
		if (function_exists('random_bytes')) {
			$bytes = random_bytes(ceil($len / 2));
		} elseif (function_exists('openssl_random_pseudo_bytes')) {
			$bytes = openssl_random_pseudo_bytes(ceil($len / 2));
		} else {
			throw new Exception('no cryptographically secure random function available');
		}
		return $prefix . substr(bin2hex($bytes), 0, $len);
	}

	/**
	 * Create a PDO connection from configuration
	 * @param array|null $config Configuration override, uses default if null
	 * @return PDO
	 * @throws Exception
	 */
	public static function createConnection(array $config = null): PDO {
		$config = $config ?: self::$defaultConfig;
		if (!$config) {
			throw new Exception('No database configuration provided');
		}

        $required = [ 'host', 'dbname', 'username', 'password', 'port' ];
        foreach( $required as $key ) {
            if( !isset( $config[$key] ) || !$config[$key] ) {
			    throw new Exception("DB config key $key is required by DBBase, normally passed in the environment");
            }
		}
        
		$socket = $config['socket'] ?? null;
		$charset = $config['charset'] ?? 'utf8mb4';
		
		$dsn = "mysql:dbname={$config['dbname']};charset={$charset}";
		
		if ($socket) {
			$dsn = "mysql:unix_socket={$socket};dbname={$config['dbname']};charset={$charset}";
		} else {
			$dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset={$charset}";
		}
		//echo "dsn: $dsn, {$config['username']}, {$config['password']}\n";
		
		$options = [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
			PDO::ATTR_CASE => PDO::CASE_LOWER,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
		];
		
		try {
			return new PDO($dsn, $config['username'], $config['password'], $options);
		} catch (PDOException $e) {
			throw new Exception('Database connection failed: ' . $e->getMessage() . $dsn);
		}
	}
	
	/**
	 * Initialize default connection from configuration
	 * @param array|null $config Configuration override, uses default if null
	 * @return PDO
	 */
	public static function initializeDefaultConnection(array $config = null): PDO {
		$pdo = self::createConnection($config);
		self::setDefaultConnection($pdo);
		return $pdo;
	}

	public function getFieldNames() {
        return [];
    }
    
	public function getClassName() {
		if(!$this->className) {
            $this->className = preg_replace('/^DB/','', (new ReflectionClass($this))->getShortName());
        }
		return $this->className;
	}
    
	public function getIDName() {
        return $this->idName;
    }
    
	public function getModuleName() {
        return $this->moduleName;
    }
    
    protected function get_caller_info() {
        $c = '';
        $file = '';
        $func = '';
        $class = '';
        $trace = debug_backtrace();
        if (isset($trace[2])) {
            $file = $trace[1]['file'];
            $func = $trace[2]['function'];
            if ((substr($func, 0, 7) == 'include') || (substr($func, 0, 7) == 'require')) {
                $func = '';
            }
        } else if (isset($trace[1])) {
            $file = $trace[1]['file'];
            $func = '';
        }
        if (isset($trace[3]['class'])) {
            $class = $trace[3]['class'];
            $func = $trace[3]['function'];
            $file = $trace[2]['file'];
        } else if (isset($trace[2]['class'])) {
            $class = $trace[2]['class'];
            $func = $trace[2]['function'];
            $file = $trace[1]['file'];
        }
        if ($file != '') $file = basename($file);
        $c = $file . ": ";
        $c .= ($class != '') ? ":" . $class . "->" : "";
        $c .= ($func != '') ? $func . "(): " : "";
        return($c);
    }

    public function debuglog( $msg, $prefix="" ) {
        if( is_object( $msg ) || is_array( $msg ) ) {
            $msg = var_export( $msg, true );
        }
        if( $prefix ) {
            $msg = "$prefix: $msg";
        }
        error_log( $_SERVER['SCRIPT_NAME'] . ": " . $this->get_caller_info() . ": $msg" );
    }
    
	public function log($message, $title='', $level=0) {
		if ($this->logger) {
            $message = is_string($message) ? $message : var_export($message, true);
            ($this->logger)($message, $title);
        } else {
			$this->debuglog($message, $title);
        }
	}

	protected function ensureConn() {
		if(!$this->conn) {
			if (self::$defaultConn) { 
				$this->conn = self::$defaultConn; 
			} elseif (self::$defaultConfig) {
				// Auto-initialize connection from config when first needed
				self::$defaultConn = self::createConnection(self::$defaultConfig);
				$this->conn = self::$defaultConn;
			} else { 
				throw new RuntimeException('No PDO connection set and no configuration provided'); 
			}
		}
	}

	public function executeStatement($sql, $values=null) {
		$this->debuglog($sql, "sql");
		if( $values ) {
			$this->debuglog($values, "values");
		}
		$this->ensureConn();
		try {
			$stmt = $this->conn->prepare($sql);
			if($values) {
                $stmt->execute($values);
            } else {
                $stmt->execute();
            }
			return $stmt;
		} catch (PDOException $e) {
			$this->log($e->getMessage(), 'error');
			return null;
		}
	}

	public function query($sql, $values=null) {
		$stmt = $this->executeStatement($sql,$values);
		if( !$stmt ) {
            return [];
        }
		$stmt->setFetchMode(PDO::FETCH_ASSOC);
		return $stmt->fetchAll() ?: [];
	}

	public function execute($sql, $values=null) {
		$stmt = $this->executeStatement($sql,$values);
		return $stmt ? 0 : 1;
	}

	// --- Convenience helpers preserved from legacy DBBase ---
	protected function getOne($sql, $values=null) {
		$rows = $this->query($sql, $values);
		return ($rows && isset($rows[0])) ? $rows[0] : [];
	}
    
	protected function firstValue($sql, $values = [], $key = null, $default = 0) {
		$rows = $this->query($sql, $values);
		if (!$rows || count($rows) === 0) {
            return $default;
        }
		if ($key !== null) {
            return isset($rows[0][$key]) ? $rows[0][$key] : $default;
        }
		foreach ($rows[0] as $k => $v) {
            return $v;
        }
		return $default;
	}
    
	protected function buildDateRangeClause($from = '', $to = '', $field = 'date', $fromParam = ':__from', $toParam = ':__to') {
		$parts = []; $values = [];
		if ($from) {
            $parts[] = $field . ' >= ' . $fromParam; $values[$fromParam] = $from;
        }
		if ($to) {
            $parts[] = $field . ' <= ' . $toParam;   $values[$toParam] = $to;
        }
		$clause = count($parts) ? (' where ' . implode(' and ', $parts)) : '';
		return [$clause, $values];
	}
    
	public function getAllByDateRange($from = '', $to = '', $dateField = 'date', $orderBy = '') {
		list($where, $values) = $this->buildDateRangeClause($from, $to, $dateField);
		$sql = "select * from {$this->table}{$where}";
        if ($orderBy) {
            $sql .= " order by $orderBy";
        }
		return $this->query($sql, $values);
	}
    
	public function appendDateRangeToSql($sql, $from = '', $to = '', $dateField = 'date', $fromParam = ':__from', $toParam = ':__to') {
		list($clause, $values) = $this->buildDateRangeClause($from, $to, $dateField, $fromParam, $toParam);
		if (!$clause) {
            return [$sql, []];
        }
		$hasWhere = stripos($sql, ' where ') !== false;
		if ($hasWhere) {
            $clause = preg_replace('/^\s*where\s+/i', ' and ', $clause); return [$sql . $clause, $values];
        }
		return [$sql . $clause, $values];
	}
    
	protected function buildInClause($field, $list, $paramBase = 'in') {
		if (!is_array($list) || count($list) === 0) {
            return ['', []];
        }
		$placeholders = []; $values = [];
		foreach (array_values($list) as $i => $val) {
            $ph = ':' . $paramBase . $i; $placeholders[] = $ph; $values[$ph] = $val;
        }
		$clause = $field . ' in (' . implode(',', $placeholders) . ')';
		return [$clause, $values];
	}

    // Sometimes overridden by derived classes
	public function processRecord($record) {
        return $record;
    }
    
	public function processRecords($records) {
        for($i = 0; $i < count($records); $i++) {
            $records[$i] = $this->processRecord($records[$i]);
        }
        return $records;
    }

	// CRUD helpers
    // upsert - inserts unless there is an existing primary key or unique value, in which case it updates
	public function updateOneDBRecord($potential, $params, $table, $prefix='', $idName='id', $criteria='') {
		// Filter $params down to only the keys in $potential
		$answers = array_filter(
			$params,
			fn($k) => in_array($k, $potential, true),
			ARRAY_FILTER_USE_KEY
		);
		if( !isset($params[$idName]) || !$params[$idName] ) {
			// Generate an ID if necessary
			$answers[$idName] = $params[$idName] = $this->uniqidReal( $prefix );
		}
		if ($criteria) {
			$updates = array_map(fn($k) => "{$k} = :{$k}", array_keys($answers));
			$sql = "UPDATE {$table} SET " . implode(',', $updates) . " WHERE {$criteria}";
			$result = $this->executeStatement($sql, $answers);
			return $result === null ? [] : $answers;
		}

        $keys = implode(',', array_keys($answers));
        $values = ':' . implode(',:', array_keys($answers));
        
        // Base INSERT
        $sql = "INSERT INTO {$table} ({$keys}) VALUES ({$values})";

        // Optional WHERE clause
        if ($criteria) {
            $sql .= " WHERE {$criteria}";
        }

        // ON DUPLICATE KEY UPDATE
        $updates = array_map(fn($k) => "{$k} = :{$k}", array_keys($answers));
        $sql .= " ON DUPLICATE KEY UPDATE " . implode(',', $updates);

        $result = $this->executeStatement($sql, $answers);
        if( $result == NULL ) {
            $answers = [];
        }
        return $answers; // empty array on failure
    }

    // For legacy compatibiltiy
	public function insertOneDBRecord($potential, $params, $table, $prefix='id', $idName='id') {
        // updateOneDBRecord does an upsert
        return $this->updateOneDBRecord( $potential, $params, $table, $prefix, $idName );
	}

	// Common getters
	public function getBy($key, $value, $multiple=true) {
		$sql = "select * from {$this->table} where $key = :value";
        $rows = $this->query( $sql, [':value'=>$value] );
		if(!$multiple) {
            return ($rows && isset($rows[0])) ? $rows[0] : [];
        }
        return $rows;
	}
    
	public function getById($id){
        return $this->getBy($this->idName, $id, false);
    }
    
	public function getByEmail($email, $fromdate='') {
        return $this->getBy('email',$email,false);
    }
    
	/**
	 * Flexible getAll supporting optional params:
	 * - ['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD', 'dateField' => 'date', 'orderBy' => '...']
	 */
	public function getAll($params = []){
		if (is_array($params)) {
			$from = $params['from'] ?? '';
			$to = $params['to'] ?? '';
			$dateField = $params['dateField'] ?? 'date';
			$orderBy = $params['orderBy'] ?? '';
			if ($from || $to) {
				return $this->getAllByDateRange($from, $to, $dateField, $orderBy);
			}
		}
		return $this->query("select * from {$this->table}");
	}
    
	public function delete($id) {
        return $this->deleteBy($this->idName,$id);
    }
    
	public function deleteBy($key,$value) {
        $sql="delete from {$this->table} where $key = :v";
        return $this->execute($sql,[':v'=>$value]);
    }

	// upsert using class-defined table, field names and id prefix
	public function update($params) {
		$this->debuglog($params);
		// Use expandName if available globally, otherwise just use params as-is
		$processedParams = function_exists('expandName') ? expandName($params) : $params;
		return $this->updateOneDBRecord($this->getFieldNames(), $processedParams, $this->table, $this->prefix, $this->getIDName());
	}

    // Small helper used by scripts/tests; keeps legacy behavior
    public function getSomeIDs($count) {
        $count = (int)$count;
        if ($count <= 0) return [];
        $sql = "select {$this->idName} from {$this->table} limit $count";
        $records = $this->query($sql);
        return array_column($records, $this->idName);
    }
}

