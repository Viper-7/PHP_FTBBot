<?php
/**
* Authentication Levels
*
* 80 = reload the service from git
* 70 = restart the service
* 40 = add a trigger
**/
class kenbot extends IRCServerChannel {
	public static $db_file = '/var/ftb_triggers.sqlite';
	protected $db;
	
	protected function handleTriggerResponse($message, $who) {
		if(preg_match('/^(?:([^,]+)[,:]\s*)?\!\+(\w+)(.*?)$/', $message, $matches)) {
			list($match, $target, $trigger, $rest) = $matches;
			
			if($trigger == 'add' && $this->isAuthed($who, 40)) {
				$params = array_map('trim', explode('=', $rest, 2));
				$this->query('DELETE FROM Commands WHERE Trigger=?', $params[0]);
				$res = $this->query('INSERT INTO Commands (Trigger, Response) VALUES (?,?)', $params);
				if($res) {
					$this->send_msg("Added trigger {$params[0]}.");
					return true;
				}
			} else {
				$commands = $this->query('SELECT Response FROM Commands WHERE Trigger=?', $trigger);
				
				if($commands) {
					$response = $commands[0][0];
					
					if($target)
						$this->send_msg("{$target}, {$response}");
					else
						$this->send_msg($response);
					
					return true;
				}
			}
		}
	}

	protected function handleBashTrigger($message, $who) {
		$path = realpath(dirname(__FILE__) . '/../bash');
		$nick = $who->nick;
		
		if(preg_match('/^(?:([^,]+)[,:]\s*)?\!\+(\w+)(.*?)$/', $message, $matches)) {
			list($match, $target, $trigger, $rest) = $matches;

			if($match = glob("{$path}/{$trigger}{,.sh,.bash}", GLOB_BRACE)) {
				$response = shell_exec("{$match[0]} $nick $rest");

				if($target)
					$this->send_msg("{$target}, {$response}");
				else
					$this->send_msg($response);
				
				return true;
			}
		}		
	}
	
	protected function isAuthed($who, $level = 50) {
		$result = $this->query("SELECT Host, Access FROM Users WHERE Nick=? AND Ident=?", array($who->nick, $who->ident));
		
		if($result) {
			list($host, $access) = $result[0];
			
			return $access >= $level && fnmatch($host, $who->host);
		}
	}
	
	public function event_msg($who, $message) {
		$this->handleTriggerResponse($message, $who);
		$this->handleBashTrigger($message, $who);
	}
	
	public function event_privmsg($who, $message) {
		if($message == '!bounce') {
			if($this->isAuthed($who, 70)) {
				die();
			} else {
				return $who->send_msg("You do not have permission to use this command");
			}
		}
		
		if($message == '!reload') {
			if($this->isAuthed($who, 80)) {
				chdir(dirname(__FILE__) . '/..');
				shell_exec('git pull');
				die();
			} else {
				return $who->send_msg("You do not have permission to use this command");
			}
		}
		
		if(!$this->handleTriggerResponse($message, $who) &&
		   !$this->handleBashTrigger($message, $who)) {
		   $who->send_msg("Unknown command");
		}
	}
	
	public function event_joined() {
		$this->db = new PDO('sqlite://' . self::$db_file);
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->createDB();
	}
	
	protected function createDB() {
		$this->db->query('
			CREATE TABLE IF NOT EXISTS Commands
			(
				ID INTEGER PRIMARY KEY AUTOINCREMENT,
				Trigger VARCHAR(255),
				Response VARCHAR(4096)
			)
		');
		
		$this->db->query('
			CREATE TABLE IF NOT EXISTS Users
			(
				ID INTEGER PRIMARY KEY AUTOINCREMENT,
				Nick VARCHAR(255),
				Ident VARCHAR(255),
				Host VARCHAR(4096),
				Access INTEGER
			)
		');
		
		$users = $this->query('SELECT ID FROM Users');
		
		if(!$user) {
			$stmt = $this->db->prepare('INSERT INTO Users (Nick, Ident, Host, Access) VALUES (?,?,?,?)');
			
			$stmt->execute(array('niel', 'niel', '2600:3c01:e000:*', 100));
			$stmt->execute(array('Viper-7', '~viper7', '*.syd?.internode.on.net', 80));
		}
	}
	
	protected function query($sql, $params = array()) {
		try {
			if(!is_array($params))
				$params = array($params);
				
			$stmt = $this->db->prepare($sql);
			$stmt->execute($params);
			return $stmt->fetchAll();
		} catch (PDOException $e) {
			$this->send_msg("DB Error! " . $e->getMessage());
			return array(array());
		}
	}
}
