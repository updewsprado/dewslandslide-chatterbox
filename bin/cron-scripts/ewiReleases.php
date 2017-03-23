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
    $sql = "SELECT id,name,sitio,barangay,municipality,province,public_alert_event.event_id,public_alert_release.internal_alert_level,public_alert_release.release_time,public_alert_trigger.timestamp as data_timestamp from site INNER JOIN public_alert_event ON site.id=public_alert_event.site_id INNER JOIN public_alert_release ON public_alert_event.event_id=public_alert_release.event_id INNER JOIN public_alert_trigger ON public_alert_event.event_id=public_alert_trigger.event_id WHERE public_alert_event.status = 'on-going'";
    $result = $conn->query($sql);
    $site_collection['name'] = [];
    $site_collection['event_id'] = [];
    $site_collection['internal_alert_level'] = [];
    $site_collection['release_time'] = [];
    $site_collection['sbmp'] = [];
    $site_collection['site_id'] = [];
    $site_collection['data_timestamp'] = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            array_push($site_collection['site_id'],$row['id']);
            array_push($site_collection['name'],$row['name']);
            array_push($site_collection['event_id'],$row['event_id']);
            array_push($site_collection['internal_alert_level'],$row['internal_alert_level']);
            array_push($site_collection['release_time'],$row['release_time']);
            array_push($site_collection['data_timestamp'],$row['data_timestamp']);
            array_push($site_collection['sbmp'],$row['sitio'].", ".$row['barangay'].", ".$row['municipality'].", ".$row['province']);
        }
    } else {
        echo "0 results";
        return;
    }
    //-------------------------
    date_default_timezone_set("Singapore");

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_URL => 'http://localhost/chatterbox/getewi',
        CURLOPT_USERAGENT => 'Curl request for ewi composition'
    ));

    $resp = curl_exec($curl);
    curl_close($curl);
    $response = (array) json_decode($resp);

    // get current alert ------
    $current_timestamp = date('Y-m-d H:i:s');
   
    for ($counter=0; $counter < sizeof($site_collection['name']); $counter++) {
        $gintag_collection = [];
        if (strlen($site_collection['internal_alert_level'][$counter]) > 4) {
            $msg = $response[substr($site_collection['internal_alert_level'][$counter],0,2)];
        } else {
            $msg = $response[substr($site_collection['internal_alert_level'][$counter],0,4)];
        }

        if (date('g:i a',strtotime('+2 minutes')) == "12:00 pm"){
            $msg = str_replace("%%PANAHON%%", "tanghali", $msg);
        } else if (date('a',strtotime('+2 minutes')) == "am") {
            $msg = str_replace("%%PANAHON%%", "umaga", $msg);
        } else {
            $msg = str_replace("%%PANAHON%%", "hapon", $msg);
        }

        if ($site_collection['sbmp'][$counter][0] == ",") {
            $msg = str_replace("%%SBMP%%",ltrim($site_collection['sbmp'][$counter],","),$msg);
        } else {
            $msg = str_replace("%%SBMP%%",$site_collection['sbmp'][$counter],$msg);
        }

        $msg = str_replace("%%CURRENT_TIME%%",date("g:i a", strtotime('+2 minutes')),$msg);

        $msg = str_replace("%%DATE%%",date('d')." ".date('F'),$msg);
        
        $msg = str_replace("%%GROUND_DATA_TIME%%",date("g:i a",strtotime('+212 minutes')),$msg);

        $msg = str_replace("%%NEXT_EWI%%",date("g:i a",strtotime("+242 minutes")),$msg);

        if (date("a") == "pm") {
            $msg = str_replace("%%NOW_TOM%%","bukas ng",$msg);
        } else {
            $msg = str_replace("%%NOW_TOM%%","mamayang",$msg);
        }


        if (date("g:i a") == "23:58 pm") {
            $msg = str_replace("%%N_NOW_TOM%%","bukas ng",$msg);
        } else {
            $msg = str_replace("%%N_NOW_TOM%%","mamayang",$msg);
        }

        $msg = $msg."- Sonya Delp PHIVOLCS-DYNASLOPE";
        $toBeSent = (object) array(
            "type"=>"smssendgroup",
            "user"=>"You",
            "offices"=>["LLMC","BLGU","MLGU","PLGU","REG8"],
            "sitenames"=>[$site_collection['name'][$counter]],
            "msg"=> $msg,
            "timestamp"=>$current_timestamp,
            "ewi_filter"=>"true",
            "ewi_tag"=>"true",
            );
        $WebSocketClient = new WebsocketClient('localhost', 5050);
        $callbackData = (array) json_decode($WebSocketClient->sendData(json_encode($toBeSent)));
        unset($WebSocketClient);

        // Tag as #EwiMessage--
        foreach ($callbackData["data"] as $id) {
            $gintags = array(
                'tag_name'=> "#EwiMessage",
                'tag_description'=> "communications",
                'timestamp'=> $current_timestamp,
                'tagger'=> '56', // Do be change to Sonya Delp's ID
                'table_element_id'=>$id[0],
                'table_used'=> 'smsoutbox',
                'remarks'=> "" // Leave it blank for now
                );
            array_push($gintag_collection,$gintags);
        }
        //---------------------
        // use key 'http' even if you send the request to https://...
        $url = "http://localhost/gintagshelper/ginTagsEntry/";
        $data = array('gintags'=>$gintag_collection);
        $options = array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data)
            )
        );

        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        if ($result === FALSE) { echo "ERROR"; }

        $raw_time = date("g:i a",strtotime(trim(substr($site_collection['data_timestamp'][$counter], 10))));

        $data_timestamp = date("g:i a", strtotime($raw_time)+(30*60));

        $narrative_details = array(
            "event_id"=> $site_collection['event_id'][$counter],
            "site_id"=> $site_collection['site_id'][$counter],
            "ewi_sms_timestamp"=> $current_timestamp,
            "narrative_template"=> "Sent ".$data_timestamp." EWI SMS to LLMC, MLGU, BLGU, PLGU"
            );

        $url = "http://localhost/narrative_generator/insertEwiNarrative/";
        $data = array('narratives'=>$narrative_details);
        $options = array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data)
            )
        );

        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        if ($result === FALSE) { echo "ERROR"; }

        // ---------------------------------
    }
    // ------------------------
?>