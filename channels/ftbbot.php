<?php
class ftbbot extends IRCServerChannel {
	public static $db_file = '/var/ftb_triggers.sqlite';
	public static $log_file = '/var/ftb_log.sqlite';
	public static $backup_path = '/var/ftb_backup.sqlite';
	
	protected $db;
	protected $logdb;
	protected $pastebinWatcher;
	
	protected function handleTriggerResponse($message, $who, $private=false) {
		if(preg_match('/^(?:([^,\s]+)[,:]\s*)?\!(?:(\!?)|\+)(\w+)(.*?)$/', $message, $matches)) {
			list($match, $target, $public, $trigger, $rest) = $matches;
			
			if($trigger == 'add') {
				if($this->isAuthed($who, 30)) {
					$params = array_map('trim', explode('=', $rest, 2));
					$this->query('DELETE FROM Commands WHERE Trigger=?', $params[0]);
					$this->query('INSERT INTO Commands (Trigger, Response) VALUES (?,?)', $params);
					if($private)
						$who->send_msg("Added trigger {$params[0]}");
					else
						$this->send_msg("Added trigger {$params[0]}");
				} else {
					$who->send_msg("You do not have permission to use this command");
				}
				return true;
			} else {
				$commands = $this->query('SELECT Response FROM Commands WHERE Trigger=?', $trigger);
				
				if($commands) {
					$response = $commands[0][0];
					
					if($private) {
						if($public && $this->isAuthed($who, 20)) {
							if($target)
								$this->send_msg("{$target}, {$response}");
							else
								$this->send_msg($response);
						} else {
							$who->send_msg($response);
						}
					} else {
						if($target)
							$this->send_msg("{$target}, {$response}");
						else
							$this->send_msg($response);
					}
					
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
		$result = $this->query("SELECT Nick, Ident, Host, Access FROM Users WHERE Access >= ?", $level);
		
		foreach($result as $row) {
			list($nick, $ident, $host, $access) = $row;
			
			if(fnmatch($host, $who->host) 
			   && ($nick == '*' || fnmatch($nick, $who->nick))
			   && ($ident == '*' || fnmatch($ident, $who->ident))
			) {
				return $access;
			}
		}
	}
	
	public function event_msg($who, $message) {
		$this->handleTriggerResponse($message, $who);
		$this->handleBashTrigger($message, $who);
		$this->pastebinWatcher->parseLine($message, $who);
		
		$stmt = $this->logdb->prepare("INSERT INTO EventLog SET EventTime=?,EventName=?,EventContent=?");
		$stmt->execute(array(time(), $who->nick, $message));
	}
	
	public function event_privmsg($who, $message) {
		list($first, $rest) = explode(' ', $message, 2) + array('','');
		
		if($message == '!levels') {
			$who->send_msg('90 = make the bot say custom text in the channel');
			$who->send_msg('80 = reload the service from git');
			$who->send_msg('70 = restart the service');
			$who->send_msg('60 = add/edit users and access levels');
			$who->send_msg('50 = list current access levels');
			$who->send_msg('40 = add a pastebin auto-response');
			$who->send_msg('30 = add a trigger');
			$who->send_msg('20 = use a trigger from PM and have the result shown in public');
			$who->send_msg('10 = list available triggers');
			return true;
		}
		
		if($message == '!help') {
			$who->send_msg('!list - List available triggers');
			$who->send_msg('!search something - search for and list triggers that contain "something" (case sensitive)');
			$who->send_msg('!say whatever - Say "whatever" in the channel');
			$who->send_msg('!bounce - Restart the bot. !reload - Pull the latest bot code from git then restart');
			$who->send_msg('!levels - Shows what access level is needed for various tasks');
			$who->send_msg('!user help - Shows detailed help on the !user command');
			$who->send_msg('!pattern help - Shows detailed help on the pastebin watcher');
			return true;
		}
		
		if($message == '!pattern help') {
			$who->send_msg('!patterns - List patterns, their ID, and [R] if they have a resolution');
			$who->send_msg('!addpattern foo - Adds a pattern to match all pastes containing the word foo. Supports PCRE syntax');
			$who->send_msg('!resolve 2 foo - Adds a resolution of "foo" to pattern 2');
			$who->send_msg('!namepattern 2 Something went wrong with FML - Sets the display name for pattern 2');
			$who->send_msg('!getresolution 2 - Shows the current resolution for pattern 2');
			$who->send_msg('!delpattern 2 - Deletes pattern 2');
			return true;
		}
		
		if($message == '!user help') {
			$who->send_msg('!user - List all users and their access level');
			$who->send_msg('!user Foo - Display the access level of the user named Foo');
			$who->send_msg('!user Foo 60 - Set the access level of the user named Foo to 60');
			$who->send_msg('!user Foo Foo!foo@*.bar.com - Sets the hostmask for user named Foo to Foo!foo@*.bar.com');
			$who->send_msg('!user Foo Foo!foo@*.bar.com 60 - Creates a user named Foo with access 60 and hostmask Foo!foo@*.bar.com');
			$who->send_msg('? matches any one character, * is a wildcard. Format of a hostmask is nick!ident@host');
			return true;
		}
		
		// Backup the database
		if($message == '!backup') {
			if($this->isAuthed($who, 70)) {
				$backup = self::$backup_path . '.' . time();
				$res = @copy(self::$db_file, $backup);
				if($res)
					return $who->send_msg("Database backup created at {$backup}");
				else
					return $who->send_msg("Database backup failed. Write error, is the disk full?");
			} else {
				return $who->send_msg("You do not have permission to use this command");
			}
		}
		
		if($first == '!addpattern') {
			if($this->isAuthed($who, 40)) {
				$this->query('INSERT INTO PastebinWatcher (Pattern) VALUES (?)', trim($rest));
				$id = $this->db->lastInsertId();
				$this->rebuildPatterns();
				return $who->send_msg("Added {$rest} as pattern {$id}.");
			} else {
				return $who->send_msg("You do not have permission to use this command");
			}
		}
		
		if($message == '!patterns') {
			if($this->isAuthed($who, 40)) {
				$list = $this->query('SELECT ID, Name, Pattern, Resolution FROM PastebinWatcher');
				foreach($list as $row) {
					$w = $row['Resolution'] ? ' [R]' : '';
					return $who->send_msg("{$row['Name']} ({$row['ID']}): {$row['Pattern']}");
				}
			} else {
				return $who->send_msg("You do not have permission to use this command");
			}
		}
		
		if($first == '!delpattern') {
			if($this->isAuthed($who, 40)) {
				$stmt = $this->query('DELETE FROM PastebinWatcher WHERE ID=?', trim($rest));
				if($stmt->rowCount) {
					$this->rebuildPatterns();
					return $who->send_msg("Deleted {$stmt->rowCount} rows.");
				} else {
					return $who->send_msg("ID {$rest} not found");
				}
			} else {
				return $who->send_msg("You do not have permission to use this command");
			}
		}
		
		if($first == '!getresolution') {
			if($this->isAuthed($who, 40)) {
				$stmt = $this->query('SELECT Resolution FROM PastebinWatcher WHERE ID=?', trim($rest));
				if($row) {
					return $who->send_msg($row[0]['Resolution']);
				} else {
					return $who->send_msg("Pattern {$rest} not found");
				}
			} else {
				return $who->send_msg("You do not have permission to use this command");
			}
		}
		
		if($first == '!resolve') {
			if($this->isAuthed($who, 40)) {
				list($id, $resolution) = explode(' ', $rest, 2);
				$list = $this->query('SELECT Pattern, Resolution FROM PastebinWatcher WHERE ID=?', trim($id));
				if($list) {
					$this->query('UPDATE PastebinWatcher SET Resolution=? WHERE ID=?', array($resolution, $id));
					$this->rebuildPatterns();
					
					$who->send_msg("Added resolution for pattern {$list[0]['Pattern']}");
					
					if($list[0]['Resolution'])
						$who->send_msg("Overwrote resolution {$list[0]['Resolution']}");
					
					return true;
				}
			} else {
				return $who->send_msg("You do not have permission to use this command");
			}
		}
		
		if($first == '!namepattern') {
			if($this->isAuthed($who, 40)) {
				list($id, $resolution) = explode(' ', $rest, 2);
				$list = $this->query('SELECT Pattern, Name FROM PastebinWatcher WHERE ID=?', trim($id));
				if($list) {
					$this->query('UPDATE PastebinWatcher SET Name=? WHERE ID=?', array($resolution, $id));
					$this->rebuildPatterns();
					
					$who->send_msg("Added name for pattern {$list[0]['Pattern']}");
					
					if($list[0]['Name'])
						$who->send_msg("Overwrote old name {$list[0]['Name']}");
					
					return true;
				}
			} else {
				return $who->send_msg("You do not have permission to use this command");
			}
		}

		// Reload the bot
		if($message == '!bounce') {
			if($this->isAuthed($who, 70)) {
				die();
			} else {
				return $who->send_msg("You do not have permission to use this command");
			}
		}
		
		// Update from git then reload the bot
		if($message == '!reload') {
			if($this->isAuthed($who, 80)) {
				chdir(dirname(__FILE__) . '/..');
				shell_exec('git pull');
				die();
			} else {
				return $who->send_msg("You do not have permission to use this command");
			}
		}
		
		// List triggers
		if($message == '!list') {
			if($this->isAuthed($who, 10)) {
				$list = $this->query("SELECT DISTINCT Trigger FROM Commands ORDER BY Trigger");
				
				foreach(array_chunk($list, 6) as $rowset) {
					$set = array();
					foreach($rowset as $row) {
						$set[] = $row[0];
					}
					$who->send_msg(implode(', ', $set));
				}
				
				return true;
			}
		}
		
		// Search triggers
		if($first == '!search') {
			$list = $this->query("SELECT Trigger FROM Commands WHERE Trigger LIKE '%' || ? || '%' OR Response LIKE '%' || ? || '%'", array($rest, $rest));
			
			if($list) {
				foreach(array_chunk($list, 6) as $rowset) {
					$set = array();
					foreach($rowset as $row) {
						$set[] = $row[0];
					}
					$who->send_msg(implode(', ', $set));
				}
				
				return true;
			} else {
				return $who->send_msg("No matches found for '$rest'");
			}
		}
		
		// Detailed user info
		if($first == '!info') {
			if($this->isAuthed($who, 50)) {
				$result = $this->query("SELECT Name, Nick, Ident, Host, Access FROM Users WHERE Name=?", array($rest));
				
				if($result) {
					list($name, $nick, $ident, $host, $access) = $result[0];
					
					return $who->send_msg("User {$name} with nick '{$nick}', ident '{$ident}' and host '{$host}' has access {$access}");
				} else {
					return $who->send_msg("User {$rest} not found");
				}
			} else {
				return $who->send_msg("You do not have permission to use this command");
			}
		}
		
		// Set user info & access
		if($first == '!user') {
			if($who_access = $this->isAuthed($who, 60)) {
				list($name, $host, $access) = explode(' ', $rest, 3) + array('', '', '');
				
				if($name === '') {
					$list = $this->query("SELECT Access, Name FROM Users ORDER BY Access DESC");
					$set = array();
					foreach($list as $row) {
						$set[] = "{$row['Name']}:{$row['Access']}";
					}
					
					foreach(array_chunk($set, 6) as $line) {
						$who->send_msg(implode(', ', $line));
					}
					
					return true;
				}
				
				$current_access = 0;
				
				$res = $this->query('SELECT Access FROM Users WHERE Name=?', $name);
				if($res) {
					$current_access = $res[0][0];
				
					if($host === '')
						return $who->send_msg("Current access for {$name}: {$current_access}"); // 1 - !user Foo
					
					if($access === '') {
						$parts = IRCServerUser::decodeHostmask(trim($host));
						if(!$parts['nick'] || !$parts['ident'] || !$parts['host']) {
							return $who->send_msg("Bad host syntax. Expected 'nick!ident@hostname', got '{$host}'");
						}

						$this->query("UPDATE Users SET Nick=?, Ident=?, Host=? WHERE Name=?", array($parts['nick'], $parts['ident'], $parts['host'], $name));
						return $who->send_msg("Updated hostmask for {$name} to {$parts['nick']}!{$parts['ident']}@{$parts['host']}"); // 3 - !user Foo host
					} else {
						$user = $this->query("SELECT ID FROM Users WHERE Name=?", $name);
						if($current_access > $who_access)
							return $who->send_msg("You cannot edit details for a user with higher access than yourself");
						
						if($access > $who_access)
							return $who->send_msg("You cannot give users higher access than your own");
						
						$access = $host;
						$this->query("UPDATE Users SET Access=? WHERE Name=?", array($access, $name)); // 2 - !user Foo 60
						return $who->send_msg("Updated access for user {$name} to {$access}");
					}
				} else {
					if($access === '')
						return $who->send_msg("No user found called '{$name}'");
					
					$this->query('INSERT INTO Users (Name, Nick, Ident, Host, Access) VALUES (?,?,?,?)', array($name, $parts['nick'], $parts['ident'], $parts['host'], $access));
					return $who->send_msg("Created user {$name} with host '{$parts['nick']}!{$parts['ident']}@{$parts['host']}' and access {$access}"); // 4 - !user Foo host 60
				}
			} else {
				return $who->send_msg("You do not have permission to use this command");
			}
		}
		
		// Forced say
		if($first == '!say') {
			if($this->isAuthed($who, 90)) {
				return $this->send_msg($rest);
			}
		}
		
		// Check for a trigger to run in channel
		if(!$this->handleTriggerResponse($message, $who, true)) {
		   $who->send_msg("Unknown command");
		}
	}
	
	protected function rebuildPatterns() {
		$this->pastebinWatcher->testPatterns = array();
		foreach($this->query('SELECT Name, Pattern, Resolution FROM PastebinWatcher') as $row) {
			$this->pastebinWatcher->testPatterns[$row['Pattern']] = $row['Resolution'];
			$this->pastebinWatcher->patternNames[$row['Pattern']] = $row['Name'];
		}
	}
	
	public function event_joined() {
		$this->db = new PDO('sqlite://' . self::$db_file);
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		
		$this->logdb = new PDO('sqlite://' . self::$log_file);
		$this->logdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		
		$this->createDB();
		$this->pastebinWatcher = new PastebinWatcher(array($this,'send_msg'));
		$this->rebuildPatterns();
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
			CREATE TABLE IF NOT EXISTS PastebinWatcher
			(
				ID INTEGER PRIMARY KEY AUTOINCREMENT,
				Name VARCHAR(4096),
				Pattern VARCHAR(4096),
				Resolution VARCHAR(255)
			)
		');

		$this->db->query('
			CREATE TABLE IF NOT EXISTS Users
			(
				ID INTEGER PRIMARY KEY AUTOINCREMENT,
				Name VARCHAR(255),
				Nick VARCHAR(255),
				Ident VARCHAR(255),
				Host VARCHAR(4096),
				Access INTEGER
			)
		');
		
		$this->logdb->query('
			CREATE TABLE IF NOT EXISTS EventLog
			(
				ID INTEGER PRIMARY KEY AUTOINCREMENT,
				EventTime INTEGER,
				EventName VARCHAR(32),
				EventContent VARCHAR(1024),
				EventType VARCHAR(32)
			)
		');
		
		$users = $this->query('SELECT ID FROM Users');
		
		if(!$users) {
			$stmt = $this->db->prepare('INSERT INTO Users (Name, Nick, Ident, Host, Access) VALUES (?,?,?,?,?)');
			
			$stmt->execute(array('Viper-7', 'Viper-7', '~viper7', '*.syd?.internode.on.net', 80));
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
