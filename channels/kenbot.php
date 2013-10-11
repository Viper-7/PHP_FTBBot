<?php
class kenbot extends IRCServerChannel {
	protected $dbFile = '/var/ftb_triggers.sqlite';
	protected $db;
	
	protected $authList = array(
		'Viper-7' => 'viper7@*',
		'niel' => 'niel@*',
	);
	
	protected function handleTriggerResponse($message, $who) {
		if($this->isAuthed($who)) {
			if($message == '!bounce')
				die();
		}
		
		if(preg_match('/^(?:([^,]+)[,:]\s*)?\!\+(\w+)(.*?)$/', $message, $matches)) {
			list($match, $target, $trigger, $rest) = $matches;
			
			if($trigger == 'add' && $this->isAuthed($who)) {
				$params = array_map('trim', explode('=', $rest, 2));
				$this->query('INSERT INTO Commands SET Trigger=?, Response=?', $params);
			} else {
				$commands = $this->query('SELECT ID, Response FROM Commands WHERE Trigger=?', $trigger);
				
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
	
	protected function isAuthed($who) {
		foreach($this->authList as $nick => $auth) {
			list($nick, $mode, $ident, $host) = IRCServerUser::decodeHostmask("{$nick}!{$auth}");
			
			if(
				   $nick == $who->nick
				&& ($ident == '*' || $ident == $who->ident)
				&& ($host == '*' || $host == $who->host)
			) {
				return true;
			}
		}
	}
	
	public function event_msg($who, $message) {
		$this->handleTriggerResponse($message, $who);
	}
	
	public function event_joined() {
		$this->db = new PDO("sqlite://$dbFile");
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		
		$db->query('
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
