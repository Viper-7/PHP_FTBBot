<?php
class GoogleFight
{
	public function fight($msg)
	{
		$words = explode(' vs ', trim($msg));
		if(count($words) < 2)
			$words = explode(' ', trim($msg));

		if (empty($words) || count($words) < 2) {
			return "Usage: !googlefight word1 word2\n";
		}
		$words = array_map('trim', $words);

		$results = array();
		foreach($words as $word)
		{
			$result = json_decode(file_get_contents("http://ajax.googleapis.com/ajax/services/search/web?v=1.0&q=" . urlencode($word)));
			if(!empty($result->responseData->cursor->estimatedResultCount))
				$results[$word] = $result->responseData->cursor->estimatedResultCount;
		}
if(empty($results)) $results['derp'] = 'rate limiters suck';
		$responses = array();
		foreach($results as $word => $result)
		{
			$responses[] = trim(urldecode($word)) . ' (' . number_format($result, 0) . ' hits)';
		}
		
		$message = implode(' vs ', $responses) . "\n";

		arsort($results);
		reset($results);
		
		return $message . '' . trim(urldecode(key($results))) . ' Wins!';
	}
}
