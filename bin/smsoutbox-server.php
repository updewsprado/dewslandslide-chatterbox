<?php
	$credentials = include('../utils/config.php');
	class OutboxServer {
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

		public function checkStatusUpdate($conn) {
			$outbox_container = [];
			$outbox_query = "SELECT * FROM user_outbox_collection WHERE ws_status = 0;";
			$outbox_collection = $conn->query($outbox_query);
			if ($outbox_collection->num_rows > 0) {
				while ($row = $outbox_collection->fetch_assoc()) {
					array_push($outbox_container,$row['outbox_id']);
					$this->updateInboxStatus($conn,$row['outbox_id']);
				}
			}
			return $outbox_container;
		}

		public function formatWSSRequest($outbox_data) {
			$structure = [
				"type" => "smsoutboxStatusUpdate",
				"data" => $outbox_data
			];
			return json_encode($structure);
		}

		public function updateInboxStatus($conn, $inbox_id) {
			$update_status_query = "UPDATE user_outbox_collection SET ws_status = 1 WHERE outbox_id = '".$inbox_id."'";
			$update_status = $conn->query($update_status_query);
			if ($update_status != true) {
				echo "Failed to update read status.\n";
			}
		}

	}

	$server = new OutboxServer($credentials['wsscredentials']['host'],$credentials['wsscredentials']['port']);
    $conn = new mysqli($credentials['dbcredentials']['dbhost'], $credentials['dbcredentials']['dbuser'], 
    					$credentials['dbcredentials']['dbpass'], $credentials['dbcredentials']['dbname']);

    echo "Connecting to database. \n";
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }  else {
        echo "Connection established.\n\n";
    }

    while (true) {
    	echo "Listening for new SMS outbox update.. \n";
    	$oubox = $server->checkStatusUpdate($conn);
    	if (sizeOf($oubox) != 0) {
    		echo "New update received.\n";
    		$format_request = $server->formatWSSRequest($oubox);
	        $server->sendData($format_request);
	        echo "Data sent to WSS.\n";
    	}
    	// unset($server);
    	sleep(1);
    }
?>