<?php
class PastebinWatcher {
	public $callback;
	public $testPatterns = array();
	public $pastebinURL = 'http://pastebin.com/raw.php?i=%s';
	
	public function __construct($response_func) {
		$this->callback = $response_func;
	}
	
	public function getPaste($key) {
		return file_get_contents(sprintf($this->pastebinURL, $key));
	}
	
	public function parseLine($line, $who = null) {
		if(preg_match('#https?://(?:www.)?pastebin.com/(\w+)#', $line, $match)) {
			echo "Parsing {$match[0]}";
			
			$key = $match[1];
			$content = $this->getPaste($key);
			
			if(!$content)
				return;
			
			foreach($this->testPatterns as $pattern => $resolution) {
				if(preg_match("§{$pattern}§si", $content, $match)) {
					$msg = '';
					if($who)
						$msg = "{$who->nick}: ";
					
					$msg .= $resolution;
					
					call_user_func($this->callback, $msg);
				}
			}
		}
	}
}