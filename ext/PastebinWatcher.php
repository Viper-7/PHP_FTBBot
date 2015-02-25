<?php
class PastebinWatcher {
	public $callback;
	public $testPatterns = array();
	public $patternNames = array();
	
	public $sites = array();
	
	public function __construct($response_func, $sites = array()) {
		$this->sites = $sites;
		$this->callback = $response_func;
	}
	
	public function addSite($site) {
		$this->sites[] = $site;
	}
	
	public function removeSite($url) {
		foreach($this->sites as $key => $site) {
			if($site->URL == $url) {
				unset($this->sites[$key]);
				return;
			}
		}
	}
	
	public function parseLine($line, $who = null) {
		$msg = '';
		$match = false;
		
		foreach($this->sites as $site) {
			if($key = $site->matchLine($line)) {
				$content = $site->getPaste($key);
				
				if(!$content)
					return;
				
				foreach($this->testPatterns as $pattern => $resolution) {
					if(preg_match("§{$pattern}§si", $content)) {
						$msg = $this->patternNames[$pattern];

						if(!$match) {
							call_user_func($this->callback, "Hi {$who->nick}, Good news! I've found a common problem in your log, and have some more information about it:");
							$match = true;
						}
						
						if($resolution) {
							$resolution = "Suggested Fix: {$resolution}";
							
							if(strlen($msg . $resolution) > 430) {
								call_user_func($this->callback, $msg);
								call_user_func($this->callback, $resolution);
							} else {
								call_user_func($this->callback, "{$msg} | {$resolution}");
							}
						} else {
							call_user_func($this->callback, $msg);
						}
					}
				}
			}
		}
	}
}