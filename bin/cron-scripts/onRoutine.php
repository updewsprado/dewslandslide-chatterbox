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
    $sql = "SELECT DISTINCT name,status from site INNER JOIN public_alert_event ON site.id=public_alert_event.site_id WHERE public_alert_event.status <> 'routine' AND public_alert_event.status <> 'finished' AND public_alert_event.status <> 'invalid'";
    $result = $conn->query($sql);
    $site_routine_collection['sitename'] = [];
    $site_routine_collection['status'] = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            array_push($site_routine_collection['sitename'],$row['name']);
            array_push($site_routine_collection['status'],$row['status']);
        }
    } else {
        echo "0 results";
    }
    //-------------------------

    // get all sites
    $sql = "SELECT name,season from site";
    $result = $conn->query($sql);
    $site_collection['sitename'] = [];
    $site_collection['season'] = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            array_push($site_collection['sitename'],$row['name']);
            array_push($site_collection['season'],$row['season']);
        }
    } else {
        echo "0 results";
    }

    $on_routine_raw = array_diff($site_collection['sitename'],$site_routine_collection['sitename']);
    
    $on_routine['sitename'] = [];
    $on_routine['season'] = [];
    foreach ($on_routine_raw as $sites) {
        array_push($on_routine['sitename'], $sites);
    }

    for ($allsite_counter = 0; $allsite_counter < sizeof($site_collection['sitename']);$allsite_counter++) {
        for ($raw_counter = 0; $raw_counter < sizeof($on_routine['sitename']);$raw_counter++) { 
            if ($on_routine['sitename'][$raw_counter] == $site_collection['sitename'][$allsite_counter]) {
                array_push($on_routine['season'],$site_collection['season'][$allsite_counter]);
            }
        }
    }

    // [[s1],[s2]];
    $wet = [[1,2,6,7,8,9,10,11,12], [5,6,7,8,9,10]];
    $dry = [[3,4,5], [1,2,3,4,11,12]];
    $month = (int) date("m"); // ex 3.
    $today = date("l");
    switch ($today) {
        case 'Wednesday':
            $msg = "Magandang umaga. Inaasahan na magpadala ng ground data ang LEWC bago mag-11:30AM para sa ating DRY SEASON routine monitoring. Para sa mga nakapagpadala na ng sukat, salamat po. Tiyakin ang kaligtasan kung pupunta sa site. Magsabi po lamang kung hindi makakapagsukat. Mag-reply po kayo kung natanggap ang mensaheng ito. Salamat at ingat kayo. - Sonya Delp PHIVOLCS-DYNASLOPE";
            for ($routine_counter = 0; $routine_counter < sizeof($on_routine['sitename']); $routine_counter++) {
                if (in_array($month,$dry[(int) ($on_routine['season'][$routine_counter]-1)])) {
                    echo $on_routine['sitename'][$routine_counter]." - ".$on_routine['season'][$routine_counter]." :";
                    echo "DRY\n\n";
                    $toBeSent = (object) array(
                        "type"=>"smssendgroup",
                        "user"=>"You",
                        "offices"=>["LLMC"],
                        "sitenames"=>[$on_routine['sitename'][$routine_counter]],
                        "msg"=>$msg,
                        "timestamp"=>date('Y-m-d H:i:s'),
                        "ewi_filter"=>"true",
                        "ewi_tag"=>"false",
                        );
                    $WebSocketClient = new WebsocketClient('localhost', 5050);
                    $WebSocketClient->sendData(json_encode($toBeSent));
                    unset($WebSocketClient);
                }
            }
            break;
        case 'Friday':
        case 'Tuesday':
            $msg = "Magandang umaga. Inaasahan na magpadala ng ground data ang LEWC bago mag-11:30AM para sa ating WET SEASON routine monitoring. Para sa mga nakapagpadala na ng sukat, salamat po. Tiyakin ang kaligtasan kung pupunta sa site. Magsabi po lamang kung hindi makakapagsukat. Mag-reply po kayo kung natanggap ang mensaheng ito. Salamat at ingat kayo. - Sonya Delp PHIVOLCS-DYNASLOPE";
            for ($routine_counter = 0; $routine_counter < sizeof($on_routine['sitename']); $routine_counter++) {
                if (in_array($month,$wet[(int) ($on_routine['season'][$routine_counter]-1)])){
                    echo $on_routine['sitename'][$routine_counter]." - ".$on_routine['season'][$routine_counter]." :";
                    echo "WET\n\n";
                    $toBeSent = (object) array(
                        "type"=>"smssendgroup",
                        "user"=>"You",
                        "offices"=>["LLMC"],
                        "sitenames"=>[$on_routine['sitename'][$routine_counter]],
                        "msg"=>$msg,
                        "timestamp"=>date('Y-m-d H:i:s'),
                        "ewi_filter"=>"true",
                        "ewi_tag"=>"false",
                        );
                    $WebSocketClient = new WebsocketClient('localhost', 5050);
                    $WebSocketClient->sendData(json_encode($toBeSent));
                    unset($WebSocketClient);
                }
            }
            break;
        
        default:
            echo "No routine for today.\n\n";
            break;
    }
?>