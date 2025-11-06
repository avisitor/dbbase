<?php
namespace Common\DB;

use PDO;
use PDOException;
use ReflectionClass;
use Exception;
use RuntimeException;
use Throwable;

if (!function_exists(__NAMESPACE__.'\\uniqidReal')) {
	function uniqidReal($prefix = '', $len = 13) {
		if (function_exists('random_bytes')) {
			$bytes = random_bytes(ceil($len / 2));
		} elseif (function_exists('openssl_random_pseudo_bytes')) {
			$bytes = openssl_random_pseudo_bytes(ceil($len / 2));
		} else {
			throw new Exception('no cryptographically secure random function available');
		}
		return $prefix . substr(bin2hex($bytes), 0, $len);
	}
}

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
	
	public function setConnection(PDO $pdo) { $this->conn = $pdo; }
	public function getConnection() { return $this->conn; }
	public static function setDefaultConnection(PDO $pdo) { self::$defaultConn = $pdo; }
	public static function getDefaultConnection() { return self::$defaultConn; }
	public static function setDefaultLogger($logger) { self::$defaultLogger = $logger; }
	
	/**
	 * Configure database connection parameters
	 * @param array $config Configuration array with keys: host, dbname, username, password, port?, socket?, charset?
	 */
	public static function setDefaultConfig(array $config) {
		self::$defaultConfig = $config;
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
		
		$host = $config['host'] ?? 'localhost';
		$dbname = $config['dbname'] ?? '';
		$username = $config['username'] ?? '';
		$password = $config['password'] ?? '';
		$port = $config['port'] ?? null;
		$socket = $config['socket'] ?? null;
		$charset = $config['charset'] ?? 'utf8mb4';
		
		if (!$dbname) {
			throw new Exception('Database name is required');
		}
		
		$dsn = "mysql:dbname={$dbname};charset={$charset}";
		
		if ($socket) {
			$dsn = "mysql:unix_socket={$socket};dbname={$dbname};charset={$charset}";
		} else {
			$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
			if ($port) {
				$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
			}
		}
		
		$options = [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		];
		
		try {
			return new PDO($dsn, $username, $password, $options);
		} catch (PDOException $e) {
			throw new Exception('Database connection failed: ' . $e->getMessage());
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

	public function getFieldNames() { return []; }
	public function getClassName() {
		if(!$this->className){ $this->className = preg_replace('/^DB/','',(new ReflectionClass($this))->getShortName()); }
		return $this->className;
	}
	public function getIDName() { return $this->idName; }
	public function getModuleName() { return $this->moduleName; }
	protected function log($message, $title='') {
		if ($this->logger) { ($this->logger)($message, $title); }
	}

	// Optional convenience logger compatible with legacy debuglog()
	public function debuglog($message, $title = '') {
		if (function_exists('debuglog')) {
			debuglog($message, $title);
		} else {
			$this->log(is_string($message) ? $message : var_export($message, true), $title);
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

	protected function executeStatement($sql, $values=null) {
		$this->log($sql . ', ' . var_export($values, true));
		$this->ensureConn();
		try {
			$stmt = $this->conn->prepare($sql);
			if($values) $stmt->execute($values); else $stmt->execute();
			return $stmt;
		} catch (PDOException $e) {
			$this->log($e->getMessage(), 'error');
			return null;
		}
	}

	public function query($sql, $values=null) {
		$stmt = $this->executeStatement($sql,$values);
		if(!$stmt) return [];
		$stmt->setFetchMode(PDO::FETCH_ASSOC);
		return $stmt->fetchAll() ?: [];
	}

	public function execute($sql, $values=null) {
		$stmt = $this->executeStatement($sql,$values);
		return $stmt?0:1;
	}

	// --- Convenience helpers preserved from legacy DBBase ---
	protected function getOne($sql, $values=null) {
		$rows = $this->query($sql, $values);
		return ($rows && isset($rows[0])) ? $rows[0] : [];
	}
	protected function firstValue($sql, $values = [], $key = null, $default = 0) {
		$rows = $this->query($sql, $values);
		if (!$rows || count($rows) === 0) return $default;
		if ($key !== null) return isset($rows[0][$key]) ? $rows[0][$key] : $default;
		foreach ($rows[0] as $k => $v) { return $v; }
		return $default;
	}
	protected function buildDateRangeClause($from = '', $to = '', $field = 'date', $fromParam = ':__from', $toParam = ':__to') {
		$parts = []; $values = [];
		if ($from) { $parts[] = $field . ' >= ' . $fromParam; $values[$fromParam] = $from; }
		if ($to)   { $parts[] = $field . ' <= ' . $toParam;   $values[$toParam] = $to; }
		$clause = count($parts) ? (' where ' . implode(' and ', $parts)) : '';
		return [$clause, $values];
	}
	public function getAllByDateRange($from = '', $to = '', $dateField = 'date', $orderBy = '') {
		list($where, $values) = $this->buildDateRangeClause($from, $to, $dateField);
		$sql = "select * from {$this->table}{$where}"; if ($orderBy) { $sql .= " order by $orderBy"; }
		return $this->query($sql, $values);
	}
	public function appendDateRangeToSql($sql, $from = '', $to = '', $dateField = 'date', $fromParam = ':__from', $toParam = ':__to') {
		list($clause, $values) = $this->buildDateRangeClause($from, $to, $dateField, $fromParam, $toParam);
		if (!$clause) return [$sql, []];
		$hasWhere = stripos($sql, ' where ') !== false;
		if ($hasWhere) { $clause = preg_replace('/^\s*where\s+/i', ' and ', $clause); return [$sql . $clause, $values]; }
		return [$sql . $clause, $values];
	}
	protected function buildInClause($field, $list, $paramBase = 'in') {
		if (!is_array($list) || count($list) === 0) { return ['', []]; }
		$placeholders = []; $values = [];
		foreach (array_values($list) as $i => $val) { $ph = ':' . $paramBase . $i; $placeholders[] = $ph; $values[$ph] = $val; }
		$clause = $field . ' in (' . implode(',', $placeholders) . ')';
		return [$clause, $values];
	}
	public function processRecord($record) { return $record; }
	public function processRecords($records) { for($i=0;$i<count($records);$i++){ $records[$i]=$this->processRecord($records[$i]); } return $records; }

	// CRUD helpers
	public function updateOneDBRecord($potential, $params, $table, $prefix='', $idName='id', $criteria='') {
		$answers=[]; foreach($potential as $k){ if(array_key_exists($k,$params)) $answers[$k]=$params[$k]; }
		if(count($answers)<1) return $answers;
		$id = $params[$idName] ?? '';
		if(!$id){
			if($prefix){ $id=uniqidReal($prefix); $params[$idName]=$id; $answers[$idName]=$id; }
			$keys=implode(',',array_keys($answers));
			$vals=':'.implode(',:',array_keys($answers));
			$sql="replace into $table ($keys) values ($vals)";
		} else {
			$updates=[]; foreach(array_keys($answers) as $k){ if($k!==$idName) $updates[]="$k=:$k"; }
			if(!$criteria) $criteria="$idName=:$idName";
			$sql="update $table set ".implode(',', $updates)." where $criteria";
			$answers[$idName]=$id;
		}
		$this->executeStatement($sql,$answers);
		return $answers;
	}
	public function insertOneDBRecord($potential, $params, $table, $prefix='id', $idName='id') {
		$answers=array_intersect_key($params,array_flip($potential));
		if(count($answers)<1) return $answers;
		$id=$answers[$idName]??uniqidReal($prefix); $answers[$idName]=$params[$idName]=$id;
		$keys=implode(',',array_keys($answers)); $vals=':'.implode(',:',array_keys($answers));
		$sql="replace into $table ($keys) values ($vals)"; $this->executeStatement($sql,$answers); return $params;
	}

	// Common getters
	public function getBy($key, $value, $multiple=true) {
		$sql="select * from {$this->table} where $key = :value"; $rows=$this->query($sql,[':value'=>$value]);
		if(!$multiple) return ($rows && isset($rows[0]))?$rows[0]:[]; return $rows;
	}
	public function getById($id){ return $this->getBy($this->idName,$id,false); }
	public function getByEmail($email,$fromdate=''){ return $this->getBy('email',$email,false); }
	/**
	 * Flexible getAll supporting optional params:
	 * - ['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD', 'dateField' => 'date', 'orderBy' => '...']
	 */
	public function getAll($params = null){
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
	public function delete($id){ return $this->deleteBy($this->idName,$id); }
	public function deleteBy($key,$value){ $sql="delete from {$this->table} where $key = :v"; return $this->execute($sql,[':v'=>$value]); }

	// Convenient update method: create if no ID provided or no existing record found, otherwise update
	public function update($params) {
		$this->debuglog($params);
		$potential = $this->getFieldNames();
		$record = [];
		$idName = $this->getIDName();
		$id = $params[$idName] ?? null;
		// If no id provided, but we have an email field, try to resolve existing record by email
		if (!$id && isset($params['email']) && $params['email']) {
			try {
				$existing = $this->getByEmail($params['email']);
				if (is_array($existing) && isset($existing[$idName]) && $existing[$idName]) {
					$id = $existing[$idName];
					$params[$idName] = $id; // promote to params for update path
				}
			} catch (Throwable $e) {
				// ignore – not all tables support getByEmail
			}
		}
		if ($id) {
			$record = $this->getById($id);
		}
		if (is_array($record) && isset($record[$idName]) && $record[$idName]) {
			$results = $this->updateOneDBRecord($potential, $params, $this->table, $this->prefix, $idName);
		} else {
			// Use expandName if available globally, otherwise just use params as-is
			$processedParams = function_exists('expandName') ? expandName($params) : $params;
			$results = $this->insertOneDBRecord($potential, $processedParams, $this->table, $this->prefix, $idName);
		}
		return $results;
	}
}

