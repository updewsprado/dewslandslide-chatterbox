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


    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }  else {
        echo "Connection established.\n\n";
    }

    // get ongoing events------
    $sql = "SELECT name from site INNER JOIN public_alert_event ON site.id=public_alert_event.site_id WHERE public_alert_event.status = 'extended'";
    $result = $conn->query($sql);
    $site_collection = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            array_push($site_collection,$row['name']);
        }
    } else {
        echo "0 results";
        return;
    }
    //-------------------------
    $msg = "Magandang umaga po. Inaasahan na magpadala ng ground data ang LEWC bago mag-11:30 AM para sa ating extend monitoring. Tiyakin ang kaligtasan kung pupunta sa site. Magsabi po lamang kung hindi makakapagsukat. Mag-reply po kayo kung natanggap ang mensaheng ito. Salamat at ingat kayo. - PHIVOLCS-DYNASLOPE";

    date_default_timezone_set("Singapore");
    foreach ($site_collection as $site) {
        $toBeSent = (object) array(
            "type"=>"smssendgroup",
            "user"=>"You",
            "offices"=>["LLMC"],
            "sitenames"=>[$site],
            "msg"=>$msg,
            "timestamp"=>date('Y-m-d H:i:s'),
            "ewi_filter"=>"true",
            "ewi_tag"=>"false",
            );
        $WebSocketClient = new WebsocketClient('localhost', 5050);
        $WebSocketClient->sendData(json_encode($toBeSent));
        unset($WebSocketClient);
    }
?>