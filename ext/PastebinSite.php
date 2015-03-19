<?php
class PastebinSite {
	public $pattern;
	public $rawURL;
	
	public function __construct($url, $pattern) {
		$this->pattern = $pattern;
		$this->rawURL = $url;
	}
	
	public function matchLine($line) {
		if(preg_match($this->pattern, $line, $match)) {
			return $match[1];
		}
	}
	
	public function getPaste($key) {
		return file_get_contents(sprintf($this->rawURL, $key));
	}
}
