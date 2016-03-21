<?php

class MysqlException extends Exception {}

class MysqlDatabase
{
	public $last_affected_rows;
	public $last_insert_id;
	//
	private $link;
	private $max_allowed_packet;
	
	/*
		@params  - array or CSV string with database connection params
		@flags   - See MYSQLI_CLIENT_xxx on https://secure.php.net/manual/en/mysqli.real-connect.php
		@options - array with mysqli options. See https://secure.php.net/manual/en/mysqli.options.php
	*/
	public function __construct($params, $flags = 0, $options = array())
	{
		if (!is_int($flags)) throw new MysqlException('flags must be int');
		if (!is_array($options)) throw new MysqlException('options must be array');
		
		if (is_string($params))
		{
			$splits = explode(',', str_replace(array(';','|'), ',', $params));
			$params = array();
			
			foreach($splits as $arg)
			{
				if (count($arg = explode('=', $arg)) != 2) continue;
				if (!($arg[0] = trim($arg[0])) || !($arg[1] = trim($arg[1]))) continue;
				$params[$arg[0]] = $arg[1];
			}			
		} else {
			if (!is_array($params)) throw new MysqlException('params must be string or array', -1);
			foreach ($params as &$param) $param = trim($param);
		}

		if (empty($params['host'])) throw new MysqlException('params.host is required', -1);
		if (empty($params['user']) && empty($params['username'])) throw new MysqlException('params.user is required', -1);
		
		$host = $params['host'];
		$user = !empty($params['user']) ? $params['user'] : $params['username'];
		$pass = !empty($params['pass']) ? $params['pass'] : (!empty($params['password']) ? $params['password'] : '');
		$db = !empty($params['db']) ? $params['db'] : (!empty($params['dbname'] ? $params['dbname'] : ''));
		
		$port = isset($params['port']) ? intval($params['port']) : 3306;
		if ($port <= 0 || $port > 65535) throw new MysqlException('params.port out of range', -1);
		if (!($this->link = @mysqli_init())) throw new MysqlException('mysqli_init failed', -2);

		foreach ($options as $opt => $val) {
			if (!@mysqli_options($this->link, $opt, $val)) throw new MysqlException(mysqli_error($this->link), -2);
		}		
		if (!@mysqli_real_connect($this->link, $host, $user, $pass, $db, $port, '', $flags))
		{
			throw new MysqlException(mysqli_connect_error(), mysqli_connect_errno());
		}
		
		if (defined('MYSQL_MAX_ALLOWES_PACKET')) {
			$this->max_allowed_packet = MYSQL_MAX_ALLOWES_PACKET;
		} else {
			$this->max_allowed_packet = 512*1024; // safe default
		}
	}
	
	public function __destruct() {
		if ($this->link != null) @mysqli_close($this->link);
	}
	
	public function close() {
		if ($this->link != null) @mysqli_close($this->link);
		$this->link = null;
	}
	
	private function exec_statement($sql, &$params)
	{
		if (!($stmt = @mysqli_prepare($this->link, $sql)))
		{
			throw new MysqlException(mysqli_error($this->link), -2);
		}
		if (count($params) > 0)
		{
			$params_t = '';
			$params_a = array(&$stmt, &$params_t);
		
			foreach ($params as &$param)
			{
				if (is_string($param)) {
					$params_t .= strlen($param) >= $this->max_allowed_packet ? 'b' : 's';
				} else if (is_int($param) || is_bool($param)) {
					$params_t .= 'i';
				} else if (is_float($param)) {
					$params_t .= 'd';
				} else {
					throw new MysqlException('invalid param type', -1);
				}
				$params_a[] = &$param;
			}
			if (!call_user_func_array('mysqli_stmt_bind_param', $params_a))
			{
				throw new MysqlException(mysqli_stmt_error($stmt), -2);
			}
			for ($i = 0; $i < count($params); $i++) if ($params_t[$i] == 'b')
			{
				$param = &$params_a[$i + 2];

				for ($off = 0, $len = strlen($param); $len > 0; $off += $part, $len -= $part)
				{
					$part = min($len, $this->max_allowed_packet);
					$send = substr($param, $off, $part);

					if (!mysqli_stmt_send_long_data($stmt, $i, $send))
					{
						throw new MysqlException(mysqli_stmt_error($stmt), -2);
					}
				}
			}
		}
		if (!@mysqli_stmt_execute($stmt))
		{
			throw new MysqlException(mysqli_stmt_error($stmt), -2);
		}
		$this->last_affected_rows = mysqli_stmt_affected_rows($stmt);
		$this->last_insert_id = mysqli_stmt_insert_id($stmt);
		return $stmt;
	}
	
	public function query($sql, ...$params)
	{
		if (!is_string($sql)) throw new MysqlException('sql must be string');
		$stmt = $this->exec_statement($sql, $params);
		$qres = mysqli_stmt_get_result($stmt);
		
		if (is_object($qres)) {
			$result = mysqli_fetch_all($qres, MYSQLI_ASSOC);
			mysqli_free_result($qres);
		} else {
			$result = false;
		}
		@mysqli_stmt_close($stmt);
		return $result;
	}
	
	public function query_single($sql, ...$params)
	{
		if (!is_string($sql)) throw new MysqlException('sql must be string');
		$stmt = $this->exec_statement($sql, $params);
		$qres = mysqli_stmt_get_result($stmt);
		
		if (is_object($qres)) {
			$result = mysqli_num_rows($qres) ? mysqli_fetch_assoc($qres) : false;
			mysqli_free_result($qres);
		} else {
			$result = false;
		}
		@mysqli_stmt_close($stmt);
		return $result;
	}
	
	public function exec($sql, ...$params)
	{
		if (!is_string($sql)) throw new MysqlException('sql must be string');
		@mysqli_stmt_close($this->exec_statement($sql, $params));
		return $this->last_affected_rows;
	}
	
	public function insert($table, $columns, $ignore = false)
	{
		if (!is_string($table)) throw new MysqlException('table must be string');
		if (!is_array($columns)) throw new MysqlException('columns must be array');
		if (!is_bool($ignore)) throw new MysqlException('ignore must be bool');

		$data  = ($ignore ? 'INSERT IGNORE ' : 'INSERT ').'INTO '.$table.' (';
		$data .= implode(',', array_keys($columns)).') VALUES ('.rtrim(str_repeat('?,', count($columns)), ',').')';
		@mysqli_stmt_close($this->exec_statement($data, $columns));
		return $this->last_insert_id;
	}
	
	public function begin($flags = 0, $name = '')
	{
		if (!is_int($flags)) throw new MysqlException('flags must be int');
		if (!is_string($name)) throw new MysqlException('name must be string');
		
		if ($name) {
			$res = @mysqli_begin_transaction($this->link, $flags, $name);
		} else {
			$res = @mysqli_begin_transaction($this->link, $flags);		
		}
		if (!$res) throw new MysqlException(mysqli_error($this->link), -2);
	}
	
	public function commit($flags = 0, $name = '')
	{
		if (!is_int($flags)) throw new MysqlException('flags must be int');
		if (!is_string($name)) throw new MysqlException('name must be string');

		if ($name) {
			$res = @mysqli_commit($this->link, $flags, $name);
		} else {
			$res = @mysqli_commit($this->link, $flags);
		}
		if (!$res) throw new MysqlException(mysqli_error($this->link), -2);
	}
	
	public function rollback($flags = 0, $name = '')
	{
		if (!is_int($flags)) throw new MysqlException('flags must be int');
		if (!is_string($name)) throw new MysqlException('name must be string');

		if ($name) {
			$res = @mysqli_rollback($this->link, $flags, $name);
		} else {
			$res = @mysqli_rollback($this->link, $flags);
		}
		if (!$res) throw new MysqlException(mysqli_error($this->link), -2);
	}
}

/*
CREATE TABLE session_handler_table (
	id			VARCHAR(128) NOT NULL,
	data		MEDIUMTEXT NOT NULL,
	timestamp	BIGINT UNSIGNED NOT NULL,
	--
	PRIMARY KEY (id)
);
*/
class MysqlSessionHandler implements SessionHandlerInterface
{
	private $database;
	private $table;
	
	public function __construct(MysqlDatabase $db, $table_name = 'session_handler_table') {
		if (!is_string($table_name)) throw new MysqlException('table_name must be string');
		$this->database = $db;
		$this->table = $table_name;
	}
	
	public function open($save_path, $name) {
		return true;
	}
	
	public function close() {
		return true;
	}
	
	public function read($id)
	{
		try {
			$data = $this->database->query_single('SELECT data FROM '.$this->table.' WHERE id=?', $id);
		}
		catch (MysqlException $e) {
			return false;
		}
		if (!$data || !isset($data['data'])) return false;
		return $data['data'];
	}
	
	public function write($id, $data)
	{
		try {
			$this->database->exec('REPLACE INTO '.$this->table.' VALUES (?,?,?)', $id, $data, time());
		}
		catch (MysqlException $e) {
			return false;
		}
		return $this->database->last_affected_rows > 0;
	}
	
	public function destroy($id)
	{
		try {
			$this->database->exec('DELETE FROM '.$this->table.' WHERE id=?', $id);
		}
		catch (MysqlException $e) {
			return false;
		}
		return true;
	}
	
	public function gc($maxlifetime)
	{
		try {
			$maxlifetime = time() - intval($maxlifetime);
			$this->database->exec('DELETE FROM '.$this->table.' WHERE timestamp < ?', $maxlifetime);
		}
		catch (MysqlException $e) {
			return false;
		}
		return true;
	}
}

?>
