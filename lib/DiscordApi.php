<?php
class DiscordApi extends DiscordApiChain {
	protected $options, $ch;
	protected static $token_types = array(
		'bot'		=> 'Bot', 
		'oauth'		=> 'Bearer', 
		'oauth2'	=> 'Bearer', 
		'bearer'	=> 'Bearer'
	);
	
	public function __construct($options) {
		$this->options = array_merge(array(
			'token'				=> "", 
			'token_type'		=> 'bot', 
			'api_base'			=> 'https://discordapp.com/api/', 
			'connect_timeout'	=> 60, 
			'timeout'			=> 60, 
			'exceptions'		=> true
		), $options);
		
		$token_type = strtolower($this->options['token_type']);
		
		if (!isset(self::$token_types[$token_type]))
			throw Exception("Invalid token type!");
		
		$this->options['token_type'] = self::$token_types[$token_type];
		
		$this->ch = curl_init();
		curl_setopt_array($this->ch, array(
			CURLOPT_RETURNTRANSFER		=> true, 
			CURLOPT_FOLLOWLOCATION		=> true, 
			CURLOPT_TIMEOUT				=> $this->options['timeout'], 
			CURLOPT_CONNECTTIMEOUT		=> $this->options['connect_timeout'], 
		));
		
		parent::__construct($this, array());
	}
	
	public function exec($method, $url, $data = "") {
		echo "$method $url (".json_encode($data).")\n";
		
		curl_setopt_array($this->ch, array(
			CURLOPT_URL					=> $this->options['api_base'].$url."?wait=true", 
			CURLOPT_CUSTOMREQUEST		=> $method, 
			CURLOPT_POST				=> $method != "GET", 
			CURLOPT_POSTFIELDS			=> $data, 
			CURLOPT_HTTPHEADER			=> array(
				"Content-Type: multipart/form-data", 
				"Authorization: ".$this->options['token_type']." ".$this->options['token']
			)
		));
		
		$res = curl_exec($this->ch);
		$code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		
		if ($code == 204) {
			$json = NULL;
		} else if ($code) {
			$json = @json_decode($res);
			
			if (json_last_error()) {
				$json = (object) array(
					'code'		=> 0, 
					'message'	=> "Can't decode json response: $res"
				);
			}
		} else {
			$json = (object) array(
				'code'		=> 0, 
				'message'	=> "Network error #".curl_errno($this->ch).": ".curl_error($this->ch)
			);
		}
		
		if (!$this->options['exceptions'] || in_array($code, array(200, 201, 204, 304)))
			return $json;
		
		throw new Exception($json->message, $json->code);
	}
	
	public static function snowflake($value) {
		return (object) array(
			'timestamp'		=> (($value >> 22) + 1420070400000) / 1000, 
			'worker'		=> ($value & 0x3E0000) >> 17, 
			'process'		=> ($value & 0x3E0000) >> 12, 
			'increment'		=> ($value & 0xFFF), 
		);
	}
}

class DiscordApiChain {
	private $api;
	private $parts;
	
	private static $api_methods = array(
		'GET'		=> true, 
		'PUT'		=> true, 
		'POST'		=> true, 
		'PATCH'		=> true, 
		'DELETE'	=> true, 
	);
	
	public function __construct($api, $parts) {
		$this->api = $api;
		$this->parts = $parts;
	}
	
	public function __get($method) {
		return $this->__call($method, array());
	}
	
	public function __call($method, $args) {
		$method = preg_replace_callback("/([a-z])([A-Z])/", function ($m) {
			return $m[1]."-".strtolower($m[2]);
		}, $method);
		
		$uc_method = strtoupper($method);
		if (isset(self::$api_methods[$uc_method])) {
			return $this->api->exec($uc_method, implode("/", $this->parts), isset($args[0]) ? $args[0] : "");
		} else {
			$parts = $this->parts;
			$parts[] = $method;
			if (count($args))
				$parts[] = $args[0];
			return new DiscordApiChain($this->api, $parts);
		}
	}
}

