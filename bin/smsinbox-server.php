<?php
	$credentials = include('../utils/config.php');
	class InboxServer {
		private $_Socket = null;

	    public function __construct($host, $port) {
	    	echo "Connecting to WS server..\n";
	        $status = $this->_connect($host, $port);
	        if ($status == true) {
	        	echo "Connected to WS server..\n\n";
	        } else {
	        	echo "Failed to WS server..\n\n";
	        	return -1;
	        }
	    }

	    public function __destruct() {
	        $this->_disconnect();
	    }

	    public function sendData($data) {
	        fwrite($this->_Socket, "\x00" . $data . "\xff") or die('Error:' . $errno . ':' . $errstr);
	        $wsData = fread($this->_Socket, 1);
	        $retData = trim($wsData, "\x00\xff");
	        return $retData;
	    }

	    private function _connect($host, $port) {
	        $key1 = $this->_generateRandomString(32);
	        $key2 = $this->_generateRandomString(32);
	        $key3 = $this->_generateRandomString(8, false, true);

	        $header = "GET /echo HTTP/1.1\r\n";
	        $header.= "Upgrade: WebSocket\r\n";
	        $header.= "Connection: Upgrade\r\n";
	        $header.= "Host: " . $host . ":" . $port . "\r\n";
	        $header.= "Origin: http://localhost\r\n";
	        $header.= "Sec-WebSocket-Key1: " . $key1 . "\r\n";
	        $header.= "Sec-WebSocket-Key2: " . $key2 . "\r\n";
	        $header.= "\r\n";
	        $header.= $key3;


	        $this->_Socket = fsockopen($host, $port, $errno, $errstr, 2);
	        fwrite($this->_Socket, $header) or die('Error: ' . $errno . ':' . $errstr);
	        $response = fread($this->_Socket, 1);

	        return true;
	    }

	    private function _disconnect() {
	        fclose($this->_Socket);
	    }

	    private function _generateRandomString($length = 10, $addSpaces = true, $addNumbers = true) {
	        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}';
	        $useChars = array();
	        for ($i = 0; $i < $length; $i++) {
	            $useChars[] = $characters[mt_rand(0, strlen($characters) - 1)];
	        }
	        if ($addSpaces === true) {
	            array_push($useChars, ' ', ' ', ' ', ' ', ' ', ' ');
	        }
	        if ($addNumbers === true) {
	            array_push($useChars, rand(0, 9), rand(0, 9), rand(0, 9));
	        }
	        shuffle($useChars);
	        $randomString = trim(implode('', $useChars));
	        $randomString = substr($randomString, 0, $length);
	        return $randomString;
	    }

		public function checkIncommingSMS($conn) {
			$inbox_container = [];
			$inbox_query = "SELECT * FROM user_inbox_collection;";
			$inbox_collection = $conn->query($inbox_query);
			if ($inbox_collection->num_rows > 0) {
				while ($row = $inbox_collection->fetch_assoc()) {
					array_push($inbox_container,$row['inbox_id']);
				}
			}
			return $inbox_container;
		}

		public function formatWSSRequest($inbox_data) {
			$structure = [
				"type" => "newSmsInbox",
				"data" => $inbox_data
			];
			return json_encode($structure);
		}

		public function clearNewInboxStorage() {

		}

	}

	$server = new InboxServer($credentials['wsscredentials']['host'],$credentials['wsscredentials']['port']);
    $conn = new mysqli($credentials['dbcredentials']['dbhost'], $credentials['dbcredentials']['dbuser'], 
    					$credentials['dbcredentials']['dbpass'], $credentials['dbcredentials']['dbname']);

    echo "Connecting to database. \n";
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }  else {
        echo "Connection established.\n\n";
    }

    while (true) {
    	echo "Listening for new SMS.. \n";
    	$inbox = $server->checkIncommingSMS($conn);
    	if (sizeOf($inbox) != 0) {
    		echo "New SMS received.\n";
    		$format_request = $server->formatWSSRequest($inbox);
	        $server->sendData($format_request);
	        echo "Data sent to WSS.\n";
    	}
    	// unset($server);
    	sleep(1);
    }
?>