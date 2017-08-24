<?php

    $servername = "localhost";
    $username = "root";
    $password = "senslope";
    $dbname = "senslopedb";

    class WebsocketClient {

        private $_Socket = null;

        public function __construct($host, $port) {
            $this->_connect($host, $port);
        }

        public function __destruct() {
            $this->_disconnect();
        }

        public function sendData($data) {
            fwrite($this->_Socket, "\x00" . $data . "\xff") or die('Error:' . $errno . ':' . $errstr);
            $wsData = fread($this->_Socket, 2000);
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
            $response = fread($this->_Socket, 2000);

            return true;
        }

        private function _disconnect() {
            fclose($this->_Socket);
        }

        private function _generateRandomString($length = 10, $addSpaces = true, $addNumbers = true) {
            $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}';
            $useChars = array();
            // select some random chars:    
            for ($i = 0; $i < $length; $i++) {
                $useChars[] = $characters[mt_rand(0, strlen($characters) - 1)];
            }
            // add spaces and numbers:
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

    }


    date_default_timezone_set("Singapore");
    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }  else {
        echo "Connection established.\n\n";
    }

    // -------------------------
    // $sql = "SELECT * FROM smsoutbox WHERE timestamp_written BETWEEN '".date('Y-m-d H:i:s',strtotime('-4 hours'))."' AND '".date('Y-m-d H:i:s',strtotime('-15 minutes'))."' AND send_status = 'fail'";

    $sql = "SELECT timestamp_written,recepients,sms_msg,send_status FROM smsoutbox WHERE timestamp_written BETWEEN '2017-08-24 03:42:37' AND '".date('Y-m-d H:i:s',strtotime('-15 minutes'))."' AND send_status = 'fail' ORDER BY timestamp_written DESC LIMIT 10";
    $result = $conn->query($sql);

    $ctr = 0;
    $msg_collection = [];
    $status_collection = [];
    $retry_collection = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {

            $msg_collection[$ctr]['numbers'] = [$row['recepients']];
            $msg_collection[$ctr]['msg'] = $row['sms_msg'];
            $msg_collection[$ctr]['timestamp'] = $row['timestamp_written'];
            $status_collection[$ctr]['send_status'] = $row['send_status'];
            $ctr++; 
            // $toBeSent = (object) array(
            //     "type"=>"smssend",
            //     "user"=>"You",
            //     "numbers"=>[$row['recepients']],
            //     "msg"=>$row['sms_msg'],
            //     "timestamp"=>$row['timestamp_written'],
            //     "ewi_tag"=>"false",
            //     );
            
            // $WebSocketClient = new WebsocketClient('localhost', 5050);
            // $WebSocketClient->sendData(json_encode($toBeSent));
            // unset($WebSocketClient);
        }

        for ($i=0; $i < sizeof($msg_collection); $i++) { 
            var_dump($msg_collection[$i]);
            var_dump($status_collection[$i]);
        }
        
    } else {
        echo "0 results";
        return;
    }
?>