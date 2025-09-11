<?php
namespace Common\DB;

use PDO;use PDOException;use ReflectionClass;use Throwable;use Exception;use RuntimeException;

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
    protected string $table = '';
    protected string $prefix = '';
    protected string $idName = 'id';
    protected string $className = '';
    protected string $moduleName = '';
    protected ?PDO $conn = null;
    protected static ?PDO $defaultConn = null;
    /** @var callable|null */
    protected $logger = null; // fn(string $msg, string $title='')

    public function __construct(?PDO $pdo = null, ?callable $logger = null) {
        $this->conn = $pdo;
        $this->logger = $logger;
    }
    public function setConnection(PDO $pdo): void { $this->conn = $pdo; }
    public function getConnection(): ?PDO { return $this->conn; }
    public static function setDefaultConnection(PDO $pdo): void { self::$defaultConn = $pdo; }
    public static function getDefaultConnection(): ?PDO { return self::$defaultConn; }
    public static function setDefaultLogger(?callable $logger): void { self::$defaultLogger = $logger; }

    public function getFieldNames(): array { return []; }
    public function getClassName(): string {
        if(!$this->className){ $this->className = preg_replace('/^DB/','',(new ReflectionClass($this))->getShortName()); }
        return $this->className;
    }
    public function getIDName(): string { return $this->idName; }
    public function getModuleName(): string { return $this->moduleName; }
    protected function log($message, string $title=''): void {
        if ($this->logger) { ($this->logger)($message, $title); }
    }

    protected function ensureConn(): void {
        if(!$this->conn) {
            if (self::$defaultConn) { $this->conn = self::$defaultConn; }
            else { throw new RuntimeException('No PDO connection set'); }
        }
    }

    protected function executeStatement(string $sql, ?array $values=null): ?\PDOStatement {
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

    public function query(string $sql, ?array $values=null): array {
        $stmt = $this->executeStatement($sql,$values);
        if(!$stmt) return [];
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        return $stmt->fetchAll() ?: [];
    }

    public function execute(string $sql, ?array $values=null): int {
        $stmt = $this->executeStatement($sql,$values);
        return $stmt?0:1;
    }

    public function updateOneDBRecord(array $potential, array $params, string $table, string $prefix='', string $idName='id', string $criteria='') {
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
        return 0;
    }

    public function insertOneDBRecord(array $potential, array $params, string $table, string $prefix='id', string $idName='id') {
        $answers=array_intersect_key($params,array_flip($potential));
        if(count($answers)<1) return $answers;
        $id=$answers[$idName]??uniqidReal($prefix); $answers[$idName]=$params[$idName]=$id;
        $keys=implode(',',array_keys($answers)); $vals=':'.implode(',:',array_keys($answers));
        $sql="replace into $table ($keys) values ($vals)"; $this->executeStatement($sql,$answers); return 0;
    }

    public function getBy(string $key, $value, bool $multiple=true) {
        $sql="select * from {$this->table} where $key = :value"; $rows=$this->query($sql,[':value'=>$value]);
        if(!$multiple) return ($rows && isset($rows[0]))?$rows[0]:[]; return $rows;
    }
    public function getById($id){ return $this->getBy($this->idName,$id,false); }
    public function getByEmail($email,$fromdate=''){ return $this->getBy('email',$email,false); }
    public function getAll(): array { return $this->query("select * from {$this->table}"); }
    public function delete($id){ return $this->deleteBy($this->idName,$id); }
    public function deleteBy($key,$value){ $sql="delete from {$this->table} where $key = :v"; return $this->execute($sql,[':v'=>$value]); }
}
