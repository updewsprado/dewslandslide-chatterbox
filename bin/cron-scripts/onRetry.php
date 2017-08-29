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
    echo "\n\nResending messages from ".date('Y-m-d H:i:s',strtotime('-4 hours'))." TO ".date('Y-m-d H:i:s',strtotime('-15 minutes'));
    $sql = "SELECT * FROM smsoutbox WHERE timestamp_written BETWEEN '".date('Y-m-d H:i:s',strtotime('-4 hours'))."' AND '".date('Y-m-d H:i:s',strtotime('-5 minutes'))."' AND (send_status LIKE '%pending%' OR send_status LIKE '%fail%') ORDER BY timestamp_written";

    $result = $conn->query($sql);

    $ctr = 0;
    $msg_collection = [];
    $retry_collection = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $retry_count = explode("-",$row['send_status']);
            if (isset($retry_count[1]) == false) {
                array_push($retry_count,'0');
            }
            if ($retry_count[1] < 5) {
                $toBeSent = (object) array(
                    "type"=>"smssend",
                    "user"=>"You",
                    "numbers"=>[$row['recepients']],
                    "msg"=>$row['sms_msg'],
                    "timestamp"=>$row['timestamp_written'],
                    "ewi_tag"=>"false",
                    "retry"=>"true"
                    );
                $WebSocketClient = new WebsocketClient('localhost', 5050);
                $WebSocketClient->sendData(json_encode($toBeSent));
                unset($WebSocketClient);

                $retry_count[1] = $retry_count[1]+1;
                echo "SMS ID: ".$row['sms_id']." is being resend..\n";
                $update_retry_count = "UPDATE smsoutbox SET send_status='".$retry_count[0]."-".$retry_count[1]."' WHERE sms_id='".$row['sms_id']."'";
                $update_result = $conn->query($update_retry_count);         
            } else {
                echo "SMS ID: ".$row['sms_id']." has reached maximum number of retries. \n\n";
            }

        }
        
    } else {
        echo "\n\nNo message flagged for resending..";
        return;
    }
?>