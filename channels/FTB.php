<?php
class FTB extends IRCServerChannel {
	protected $dbFile = '/var/ftb_triggers.sqlite';
	protected $db;
	
	protected function handleTriggerResponse($message, $who) {
		if(preg_match('/^(?:([^,]+)[,:]\s*)?\!\+(\w+)(.*?)$/', $message, $matches)) {
			list($match, $target, $trigger, $rest) = $matches;
			
			if($trigger == 'add') {
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
	
	public function event_msg($who, $message) {
		$this->handleTriggerResponse($message, $who);
	}
	
	public function event_joined() {
		$this->db = new PDO("sqlite://$dbFile");
		$this->db->setAttribute(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);
	}
	
	protected function query($sql, $params) {
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