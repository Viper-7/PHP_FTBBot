<?php
class kenbot extends IRCServerChannel {
	public static $db_file = '/var/ftb_triggers.sqlite';
	protected $db;
	
	protected $authList = array(
		'Viper-7' => '~viper7@*',
		'niel' => 'niel@*',
	);
	
	protected function handleTriggerResponse($message, $who) {
		if($this->isAuthed($who)) {
			if($message == '!bounce')
				die();
				
			if($message == '!reload') {
				chdir(dirname(__FILE__) . '/..');
				shell_exec('git pull');
				die();
			}
		}
		
		if(preg_match('/^(?:([^,]+)[,:]\s*)?\!\+(\w+)(.*?)$/', $message, $matches)) {
			list($match, $target, $trigger, $rest) = $matches;
			
			if($trigger == 'add' && $this->isAuthed($who)) {
				$params = array_map('trim', explode('=', $rest, 2));
				$this->query('DELETE FROM Commands WHERE Trigger=?', $params[0]);
				$res = $this->query('INSERT INTO Commands (Trigger, Response) VALUES (?,?)', $params);
				if($res) {
					$this->send_msg("Added trigger {$params[0]}.");
				}
			} else {
				$commands = $this->query('SELECT Response FROM Commands WHERE Trigger=?', $trigger);
				
				if($commands) {
					$response = $commands[0][0];
					
					if($target)
						$this->send_msg("{$target}, {$response}");
					else
						$this->send_msg($response);
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
				
			}
		}		
	}
	
	protected function isAuthed($who) {
		foreach($this->authList as $nick => $auth) {
			$user = IRCServerUser::decodeHostmask("{$nick}!{$auth}");
			
			if(
				   $user['nick'] == $who->nick
				&& ($user['ident'] == '*' || $user['ident'] == $who->ident)
				&& ($user['host'] == '*' || $user['host'] == $who->host)
			) {
				return true;
			}
		}
	}
	
	public function event_msg($who, $message) {
		$this->handleTriggerResponse($message, $who);
		$this->handleBashTrigger($message, $who);
	}
	
	public function event_joined() {
		$this->db = new PDO('sqlite://' . self::$db_file);
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		
		$this->db->query('
			CREATE TABLE IF NOT EXISTS Commands
			(
				ID INTEGER PRIMARY KEY AUTOINCREMENT,
				Trigger VARCHAR(255),
				Response VARCHAR(4096)
			)
		');
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
