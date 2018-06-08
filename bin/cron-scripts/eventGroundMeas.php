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

    // get Ground measurements for this day
    $current_date = date('Y-m-d H:m:i');
    $previous_date_raw = date_create(date('Y-m-d H:i'));
    $reconstruct_date = date_sub($previous_date_raw,date_interval_create_from_date_string("4 hours"));
    $previous_date = date_format($reconstruct_date,"Y-m-d H:i:s");
    $gndmeas_sent_sites = [];
    $sql = "SELECT * FROM senslopedb.gintags 
            INNER JOIN smsinbox ON smsinbox.sms_id = table_element_id 
            INNER JOIN gintags_reference ON gintags.tag_id_fk = gintags_reference.tag_id
            where (gintags_reference.tag_name = '#CantSendGndMeas' OR gintags_reference.tag_name = '#GroundMeas') AND smsinbox.timestamp < '".$current_date."' AND smsinbox.timestamp > '".$previous_date."' limit 100;";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        foreach ($result as $tagged) {
            $sql = "SELECT sitename FROM communitycontacts WHERE number like '%".substr($tagged['sim_num'],-10)."%'";
            $get_sites = $conn->query($sql);
            if ($get_sites->num_rows > 0) {
                foreach ($get_sites as $site) {
                    if (sizeOf($gndmeas_sent_sites) == 1) {
                        array_push($gndmeas_sent_sites, $site['sitename']);
                    } else {
                        if (!in_array($site['sitename'],$gndmeas_sent_sites)) {
                            array_push($gndmeas_sent_sites,$site);
                        }
                    }
                }
            } else {
                echo "No contacts fetched. \n\n";
            }
        }
    } else {
        echo "No Ground measurement received.\n\n";
    }

    // get ongoing events------
    $sql = "SELECT DISTINCT name,status from site INNER JOIN public_alert_event ON site.id=public_alert_event.site_id 
            INNER JOIN public_alert_release ON public_alert_event.event_id = public_alert_release.event_id WHERE public_alert_event.status <> 'routine' AND public_alert_event.status <> 'finished' AND public_alert_event.status <> 'invalid' AND public_alert_release.internal_alert_level NOT LIKE '%A3%'";
    $result = $conn->query($sql);
    $events = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            if ($row['name'] != "pug") {
                if (!in_array($row['name'],$gndmeas_sent_sites)) {
                    array_push($events,$row['name']);
                }
            }
        }
    } else {
        echo "0 results";
    }

    $current_hour = date_create(date('h:i A'));
    $reconstruct_hour = date_add($current_hour, date_interval_create_from_date_string("2 hours"));
    $cut_off_hour = date_format($reconstruct_hour,"h:i A");

    $msg = "Magandang hapon po. 

Inaasahan namin ang pagpapadala ng LEWC ng ground data bago mag-bago mag-".$cut_off_hour.". 
Tiyakin ang kaligtasan sa pagpunta sa site. 

Salamat. - PHIVOLCS-DYNASLOPE";
    
    var_dump($events);
    foreach ($events as $event) {
        $toBeSent = (object) array(
            "type" => "smssendgroup",
            "user" => "You",
            "offices" => ["LEWC"],
            "sitenames" => [$event],
            "msg" => $msg,
            "timestamp" => date('Y-m-d H:i:s'),
            "ewi_filter" => "true",
            "ewi_tag" => "false",
            );
        $WebSocketClient = new WebsocketClient('localhost', 5050);
        $WebSocketClient->sendData(json_encode($toBeSent));
        unset($WebSocketClient);
    }
?>