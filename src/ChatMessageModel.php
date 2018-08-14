<?php
namespace MyApp;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class ChatMessageModel {
    protected $dbconn;

    public function __construct() {
        //Initialize the database connection
        $this->initDBforCB();

        //Cache the initial inbox messages
        $this->qiInit = true;
        $this->getCachedQuickInboxMessages();
        date_default_timezone_set("Asia/Singapore");
    }

    public function helloWorld() {
        echo "ChatMessageModel: Hello World \n\n";
    }

    public function initDBforCB() {
        //Create a DB Connection
        $host = "localhost";
        $usr = "root";
        $pwd = "senslope";
        $dbname = "senslopedb";

        $this->dbconn = new \mysqli($host, $usr, $pwd);

        if ($this->dbconn->connect_error) {
            die("Connection failed: " . $this->dbconn->connect_error);
        }
        echo "Successfully connected to database!\n";

        $this->connectSenslopeDB();
        echo "Switched to schema: senslopedb!\n";

        $this->createSMSInboxTable();
        $this->createSMSOutboxTable();
    }

    //Connect to senslopedb
    public function connectSenslopeDB() {
        //$success = $this->dbconn->mysqli_select_db("senslopedb");
        $success = mysqli_select_db($this->dbconn, "senslopedb");

        if (!$success) {
            $this->createSenslopeDB();
        }
        return $success;
    }

    //Create database if it does not exist yet
    public function createSenslopeDB() {
        $sql = "CREATE DATABASE senslopedb";
        if ($this->dbconn->query($sql) === TRUE) {
            echo "Database created successfully\n";
        } else {
            die("Error creating database: " . $this->dbconn->error);
        }
    }

    public function utf8_encode_recursive ($array) {
        $result = array();
        foreach ($array as $key => $value)
        {
            if (is_array($value))
            {
                $result[$key] = $this->utf8_encode_recursive($value);
            }
            else if (is_string($value))
            {
                $result[$key] = utf8_encode($value);
            }
            else
            {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    //Create the smsinbox table if it does not exist yet
    public function createSMSInboxTable() {
        $sql = "CREATE TABLE IF NOT EXISTS `senslopedb`.`smsinbox` (
                  `sms_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                  `timestamp` DATETIME NULL,
                  `sim_num` VARCHAR(20) NULL,
                  `sms_msg` VARCHAR(1023) NULL,
                  `read_status` VARCHAR(20) NULL,
                  `web_flag` VARCHAR(2) NOT NULL DEFAULT 'WU',
                  PRIMARY KEY (`sms_id`))";

        if ($this->dbconn->query($sql) === TRUE) {
            echo "Table 'smsinbox' exists!\n";
        } else {
            die("Error creating table 'smsinbox': " . $this->dbconn->error);
        }
    }

    //Create the smsoutbox table if it does not exist yet
    public function createSMSOutboxTable() {
        $sql = "CREATE TABLE IF NOT EXISTS `senslopedb`.`smsoutbox` (
                  `sms_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                  `timestamp_written` DATETIME NULL,
                  `timestamp_sent` DATETIME NULL,
                  `recepients` VARCHAR(1023) NULL,
                  `sms_msg` VARCHAR(1023) NULL,
                  `send_status` VARCHAR(20) NOT NULL DEFAULT 'UNSENT',
                  PRIMARY KEY (`sms_id`))";

        if ($this->dbconn->query($sql) === TRUE) {
            echo "Table 'smsoutbox' exists!\n";
        } else {
            die("Error creating table 'smsoutbox': " . $this->dbconn->error);
        }
    }

    public function filterSpecialCharacters($message) {
        //Filter backslash (\)
        $filteredMsg = str_replace("\\", "\\\\", $message);
        //Filter single quote (')
        $filteredMsg = str_replace("'", "\'", $filteredMsg);

        return $filteredMsg;
    }

    //Check connection and catch SQL that might be clue for MySQL Runaway
    //This is the solution for the "MySQL Runaway Error"
    public function checkConnectionDB($sql = "Nothing") {
        // Make sure the connection is still alive, if not, try to reconnect 
        if (!mysqli_ping($this->dbconn)) {
            echo 'Lost connection, exiting after query #1';

            //Write the ff to the log file
            //  1. Timestamp when the problem occurred
            //  2. The Query to be written
            
            //Append the file
            $logFile = fopen("../logs/mysqlRunAwayLogs.txt", "a+");
            $t = time();
            fwrite($logFile, date("Y-m-d H:i:s") . "\n" . $sql . "\n\n");
            fclose($logFile);

            //Try to reconnect
            $this->initDBforCB();
        }
    }

    //Identify contact number's network
    public function identifyMobileNetwork($contactNumber) {
        try {
            $countNum = strlen($contactNumber);
            //echo "num count = $countNum\n";

            //ex. 09 '16' 8888888
            if ($countNum == 11) {
                $curSimPrefix = substr($contactNumber, 2, 2);
            }
            //ex. 639 '16' 8888888
            elseif ($countNum == 12) {
                $curSimPrefix = substr($contactNumber, 3, 2);
            }

            echo "simprefix: 09$curSimPrefix\n";
            //TODO: compare the prefix to the list of sim prefixes

            //Mix of Smart, Sun, Talk & Text
            $networkSmart = "00,07,08,09,10,11,12,14,18,19,20,21,22,23,24,25,28,29,30,31,
                    32,33,34,38,39,40,42,43,44,46,47,48,49,50,89,98,99";
            //Mix of Globe and TM
            $networkGlobe = "05,06,15,16,17,25,26,27,35,36,37,45,55,56,75,77,78,79,94,95,96,97";

            //Globe Number
            if (strpos($networkSmart, $curSimPrefix)) {
                echo "Smart Network!\n";
                return "SMART";
            } 
            elseif (strpos($networkGlobe, $curSimPrefix)) {
                echo "Globe Network!\n";
                return "GLOBE";
            }
            else {
                echo "Unkown Network!\n";
                return "UNKNOWN";
            }
        } catch (Exception $e) {
            echo "identifyMobileNetwork Exception: Unknown Network\n";
            return "UNKNOWN";
        }
    }

    //Insert data for smsinbox table
    public function insertSMSInboxEntry($timestamp, $sender, $message) {
        //filter or check special characters
        $message = $this->filterSpecialCharacters($message);

        $sql = "INSERT INTO smsinbox (timestamp, sim_num, sms_msg, read_status, web_flag)
                VALUES ('$timestamp', '$sender', '$message', 'READ-FAIL', 'WS')";

        // Make sure the connection is still alive, if not, try to reconnect 
        // $this->checkConnectionDB($sql);

        if ($this->dbconn->query($sql) === TRUE) {
            echo "New record created successfully!\n";
            $status = true;
        } else {
            echo "Error: " . $sql . "<br>" . $this->dbconn->error;
            $status = false;
        }
        return $status;
    }

    //Insert data for smsoutbox table
    public function insertSMSOutboxEntry($recipients, $message, $sentTS = null, $ewi_tag = false) {
        //ewi tag ids
        $ewi_tag_id = [];
        //filter or check special characters
        $message = $this->filterSpecialCharacters($message);

        if ($sentTS) {
            $curTime = $sentTS;
        } else {
            $curTime = date("Y-m-d H:i:s", time());
        }
        
        foreach ($recipients as $recipient) {
            // Identify the mobile network of the current number
            $mobileNetwork = $this->identifyMobileNetwork($recipient);

            if (strlen($recipient) > 11){
                $recipient = substr($recipient, 2);
                $recipient = "0".$recipient;
            }

            echo "$curTime Message recipient: $recipient\n";

            $sql = "INSERT INTO smsoutbox (timestamp_written, recepients, sms_msg, send_status, gsm_id)
                    VALUES ('$curTime', '$recipient', '$message', 'PENDING', '$mobileNetwork')";

            // Make sure the connection is still alive, if not, try to reconnect 
            // $this->checkConnectionDB($sql);
            $result = $this->dbconn->query($sql);
            if ($result === TRUE) {
                echo "New record created successfully!\n";
                $sql  = "SELECT LAST_INSERT_ID()";
                $res = $this->dbconn->query($sql);
                array_push($ewi_tag_id,$res->fetch_array()[0]);
            } else {
                echo "Error: " . $sql . "<br>" . $this->dbconn->error;
            }
        }
        return $ewi_tag_id;
    }

    //TODO: Update a smsoutbox entry
    public function updateSMSOutboxEntry($recipient=null, $writtenTS=null, $sendStatus=null, $sentTS=null) {
        // validate identifier: recipient
        //Remove non number characters
        $recipient = str_replace("[", "", $recipient);
        $recipient = str_replace("]", "", $recipient);
        $recipient = str_replace("u", "", $recipient);
        $recipient = str_replace("'", "", $recipient);
        $recipient = $this->normalizeContactNumber($recipient);

        if ($this->isSenderValid($recipient) == false) {
            echo "Error: recipient '$recipient' is invalid.\n";
            return -1;
        }

        // TODO: validate identifier: written timestamp
        if ($writtenTS == null) {
            echo "Error: no input for written_timestamp.\n";
            return -1;
        }

        $setCtr = 0;
        $updateQuery = "UPDATE smsoutbox ";
        $whereClause = " WHERE timestamp_written = '$writtenTS' AND recepients like '%$recipient'";

        // validate sendStatus
        if ( ($sendStatus == "PENDING") || ($sendStatus == "SENT-PI") || 
            ($sendStatus == "SENT") || ($sendStatus == "SENT-WSS") ||
            ($sendStatus == "FAIL") || ($sendStatus == "FAIL-WSS") ) {
            //compose send status set clause
            $setClause = " SET send_status = '$sendStatus'";
            $setCtr++;
        }
        elseif ($sendStatus == null) {
            // Do nothing
        }
        else {
            echo "Error: invalid send_status.\n";
            return -1;
        }

        // validate sentTS
        if ($sentTS) {
            if ($setCtr > 0) {
                $setClause = $setClause . ", timestamp_sent = '$sentTS'";
            }
            else {
                $setClause = " SET timestamp_sent = '$sentTS'";
            }

            $setCtr++;
        }

        if ($setCtr == 0) {
            echo "Error: No data for updating\n";
            return -1;
        }

        $updateQuery = $updateQuery . $setClause . $whereClause;
        //echo "updateQuery: $updateQuery\n";

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($updateQuery);

        if ($this->dbconn->query($updateQuery) === TRUE) {
            echo "record updated successfully!\n";
            return 0;
        } else {
            echo "Error: " . $updateQuery . "<br>" . $this->dbconn->error;
            return -1;
        }
    }

    public function isSenderValid($sender) {
        $patternNoNumbers = '/[^0-9+]/';

        $success = preg_match($patternNoNumbers, $sender, $match);
        if ($success) {
            $patternExceptions='/^LBC|^lbc/';

            if (preg_match($patternExceptions, $sender, $match)) {
                echo "Valid: $sender\n";
                return true;
            }
            else {
                echo "Filter out: $sender\n";
                return false;
            }
        }
        else {
            echo "Valid: All Numbers\n";
            return true;
        }
    }

    public function convertNameToUTF8($name) {
        //Convert the string to utf8 format
        $converted = utf8_decode($name);

        //Replace "?" character with "ñ"
        return str_replace("?", "ñ", $converted);
    }

    public function getCachedQuickInboxMessages($isForceLoad=false) {
        $start = microtime(true);

        $os = PHP_OS;
        $qiResults;

        if (strpos($os,'WIN') !== false) {
            //echo "Running on a windows server. Not using memcached </Br>";
            $qiResults = $this->getQuickInboxMessages();
        }
        elseif ((strpos($os,'Ubuntu') !== false) || (strpos($os,'Linux') !== false)) {
            //echo "Running on a Linux server. Will use memcached </Br>";

            $mem = new \Memcached();
            $mem->addServer("127.0.0.1", 11211);

            //cachedprall - Cached Public Release All
            $qiCached = $mem->get("cachedQI");

            //Load quick inbox results from DB on initialization
            if ( ($this->qiInit == true) || $isForceLoad ) {
                echo "Initialize the Quick Inbox Messages \n";

                $qiResults = $this->getQuickInboxMessages();
                $mem->set("cachedQI", $qiResults) or die("couldn't save quick inbox results");
            } 
            else {
                //Load from cache if no longer from initialization
                $qiResults = $mem->get("cachedQI");
            }
        }
        else {
            //echo "Unknown OS for execution... Script discontinued";
            $qiResults = $this->getQuickInboxMessages();
        }

        //echo json_encode($qiResults) . "\n\n";

        $execution_time = microtime(true) - $start;
        echo "\n\nExecution Time: $execution_time\n\n";

        return $qiResults;
    }

    public function addQuickInboxMessageToCache($receivedMsg) {
        //Get the cached results
        $os = PHP_OS;

        if (strpos($os,'WIN') !== false) {
            //do nothing if on windows
            return;
        }
        elseif ((strpos($os,'Ubuntu') !== false) || (strpos($os,'Linux') !== false)) {
            //echo "Running on a Linux server. Will use memcached </Br>";

            $mem = new \Memcached();
            $mem->addServer("127.0.0.1", 11211);

            //cachedprall - Cached Public Release All
            $qiCached = $mem->get("cachedQI");

            //Load quick inbox results from DB on initialization
            if ($qiCached && ($this->qiInit == true) ) {
                echo "Initialize the Quick Inbox Messages \n";

                $qiResults = $this->getQuickInboxMessages();
                $mem->set("cachedQI", $qiResults) or die("couldn't save quick inbox results");
            } 
            else {
                //delete the oldest message from array
                array_pop($qiCached['data']);  
                //insert latest message to array
                array_unshift($qiCached['data'], $receivedMsg);
                //update the cached quick inbox results
                $mem->set("cachedQI", $qiCached) or die("couldn't save quick inbox results");
            }
        }
        else {
            //do nothing if not on Linux
            return;
        }
    }

    public function getRowFromMultidimensionalArray($mdArray, $field, $value) {
       foreach($mdArray as $key => $row) {
          if ( $row[$field] === $value )
             return $row;
       }
       return -1;
    }

    public function getLatestAlerts(){
        $query = "SELECT * FROM site inner join public_alert_event alerts on site.id=alerts.site_id inner join public_alert_release releases on alerts.latest_release_id = releases.release_id WHERE alerts.status <> 'finished' AND alerts.status <> 'invalid' AND alerts.status <> 'routine'";
        $this->checkConnectionDB($query);
        $alerts = $this->dbconn->query($query);
        $fullData['type'] = 'latestAlerts';
        $raw_data = array();
        $ctr = 0;
        if ($alerts->num_rows > 0) {
            while ($row = $alerts->fetch_assoc()) {
                $raw_data["id"] = $row["id"];
                $raw_data["name"] = strtoupper($row["name"]);
                $raw_data["sitio"] = $row["sitio"];
                $raw_data["barangay"] = $row["barangay"];
                $raw_data["province"] = $row["province"];
                $raw_data["region"] = $row["region"];
                $raw_data["municipality"] = $row["municipality"];
                $raw_data["status"] = $row["status"];
                $raw_data["internal_alert_level"] = $row["internal_alert_level"];
                $fullData['data'][$ctr] = $raw_data;
                $ctr++;
            }
        }
        else {
            echo "0 results\n";
            $fullData['data'] = null;
        }
        return $fullData;
    }

    public function getSiteMembers($offices,$sites) {
        $ctr = 0;
        $officeSubQuery = "";
        $siteSubQuery = "";
        $contacts = [];
        $fullData = [];


        for ($counter = 0; $counter < sizeof($offices); $counter++) {
            if ($ctr == 0) {
                $officeSubQuery = "office = '$offices[$counter]'";
            } else {
                $officeSubQuery = $officeSubQuery." OR office = '$offices[$counter]'";
            }
            $ctr++;
        }

        $ctr = 0;
        for ($counter = 0; $counter < sizeof($sites); $counter++) {
            if ($ctr == 0) {
                $siteSubQuery = "sitename = '$sites[$counter]'";
            } else {
                $siteSubQuery = $siteSubQuery." OR sitename = '$sites[$counter]'";
            }
            $ctr++;
        }

        $fullData['type'] = "groupMessageQuickAcces";
        $ctr = 0;
        $sql = "SELECT CONCAT(sitename, ' ', office, ' ', prefix, ' ', firstname, ' ', lastname, ' - ',number) as fullname,sitename,sitio,province,barangay,municipality FROM communitycontacts INNER JOIN site ON site.name = communitycontacts.sitename WHERE (".$officeSubQuery.") AND (".$siteSubQuery.") order by sitename asc";
        $this->checkConnectionDB($sql);
        $contacts_raw = $this->dbconn->query($sql);
        if ($contacts_raw->num_rows > 0) {
            while($row = $contacts_raw->fetch_assoc()) {
                $contacts[$ctr]['site'] = $row['sitename'];
                $contacts[$ctr]['fullname'] = $row['fullname'];
                $contacts[$ctr]['sitio'] = $row['sitio'];
                $contacts[$ctr]['municipality'] = $row['municipality'];
                $contacts[$ctr]['barangay'] = $row['barangay'];
                $contacts[$ctr]['province'] = $row['province'];
                $ctr++;
            }
        } else {
            echo "\n\nNo result.";
            $fullData['data'] = [];
            return $fullData;
        }
        
        $fullData['data'] = $contacts;
        $fullData = $this->utf8_encode_recursive($fullData);
        return $fullData;
    }

    //Return the quick inbox messages needed for the initial display on chatterbox
    public function getQuickInboxMessages($periodDays = 7) {
        // $start = microtime(true);

        // Get the name of the senders
        $contactsList = $this->getFullnamesAndNumbers();
        $sitesOnEvent = $this->getLatestAlerts();

        // Create query to get all sim numbers for the past X days
        $sqlGetAllNumbersFromPeriod = "
            SELECT * FROM smsinbox
            WHERE timestamp > (now() - interval $periodDays day)
            ORDER BY timestamp DESC";

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sqlGetAllNumbersFromPeriod);
        $resultNumbersFromPeriod = $this->dbconn->query($sqlGetAllNumbersFromPeriod);

        $fullData['type'] = 'smsloadquickinbox';
        $distinctNumbers = "";
        $allNumbers = [];
        $allMessages = [];
        $quickInboxMsgs = [];
        $ctr = 0;
        if ($resultNumbersFromPeriod->num_rows > 0) {
            // output data of each row
            while ($row = $resultNumbersFromPeriod->fetch_assoc()) {
                $normalizedNum = $this->normalizeContactNumber($row['sim_num']);
                array_push($allNumbers, $normalizedNum);
                $allMessages[$ctr]['user'] = $normalizedNum;
                $allMessages[$ctr]['msg'] = $row['sms_msg'];
                $allMessages[$ctr]['timestamp'] = $row['timestamp'];
                $allMessages[$ctr]['network'] = $this->identifyMobileNetwork($row['sim_num']);
                $ctr++;
            }

            // Get distinct numbers
            $distinctNumbers = array_unique($allNumbers);
            // echo "getQuickInboxMessages() | JSON DATA: " . json_encode($distinctNumbers) . "\n";

            foreach ($distinctNumbers as $singleContact) {
                // echo "$singleContact \n";
                $msgDetails = $this->getRowFromMultidimensionalArray($allMessages, "user", $singleContact);
                $msgDetails['name'] = $this->convertNameToUTF8($this->findFullnameFromNumber($contactsList, $msgDetails['user']));
                $raw_name = explode(" ",$msgDetails['name']);
                foreach ($sitesOnEvent['data'] as $siteEvent) {
                    if (strtoupper($raw_name[0]) == strtoupper($siteEvent['name'])) {
                        $msgDetails['onevent'] = 1;
                        break;
                    } else {
                        $msgDetails['onevent'] = 0;
                    }
                }
                array_push($quickInboxMsgs, $msgDetails);
                // echo json_encode($msgDetails) . "\n";
            }

            $fullData['data'] = $quickInboxMsgs;
        } else {
            echo "0 results\n";
            $fullData['data'] = null;
        }
        echo "JSON DATA: " . json_encode($fullData);
        //echo json_encode($contactsList);

        // $execution_time = microtime(true) - $start;
        // echo "\n\nExecution Time: $execution_time\n\n";

        //Quick Inbox Messages have been reloaded
        $this->qiInit = false;
        return $fullData;
    }

    //Find the Fullname of a contact from a number
    public function findFullnameFromNumber($contactsList, $normalizedNum) {
        // foreach($contactsList as $contact) {
        //     if(strpos($contact['numbers'], $normalizedNum) >= 0) 
        //         return $contact['fullname'];
        // }
        for ($i=0; $i < count($contactsList); $i++) { 
            if (strpos($contactsList[$i]['numbers'], $normalizedNum)) {
                return $contactsList[$i]['fullname'];
            }
        }

        return "unknown";
    }

    //Get Fullnames and numbers in the database
    public function getFullnamesAndNumbers() {
        $sqlGetFullnamesAndNumbers = "
            SELECT
                CONCAT(sitename, ' ', office, ' ', prefix, ' ', firstname, ' ', lastname) as fullname,
                number as numbers
            FROM communitycontacts
            UNION
            SELECT 
                CONCAT(firstname, ' ', lastname) as fullname, 
                numbers
            FROM dewslcontacts
            ORDER BY fullname";

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sqlGetFullnamesAndNumbers);
        $result = $this->dbconn->query($sqlGetFullnamesAndNumbers);

        $ctr = 0;
        $dbreturn = "";

        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['fullname'] = $row['fullname'];
                $dbreturn[$ctr]['numbers'] = $row['numbers'];

                // echo "fullname: ". $row['fullname'] . ", numbers: " . $row['numbers'] . "\n";
                // echo "fullname: ". $dbreturn[$ctr]['fullname'] . ", numbers: " . $dbreturn[$ctr]['numbers'] . "\n";

                $ctr = $ctr + 1;
            }

            // echo json_encode($dbreturn);

            return $dbreturn;
        }
        else {
            echo "0 results\n";
        }
    }

    //Return the message searched between Chatterbox and a number
    public function getSearchedConversation($number = null,$type = null,$timestamp = null){
        if ($type == "smsLoadSearched") {

            $ctr = 0;
            $sql = '';
            $sqlTargetNumbers = "";

            //Construct the query for loading messages from multiple numbers
            if ($ctr > 1) {
                for ($i = 0; $i < $ctr; $i++) { 
                    $targetNum = $number[$i];

                    if ($i == 0) {
                        $sqlTargetNumbersOutbox = "recepients LIKE '%$targetNum' ";
                        $sqlTargetNumbersInbox = "sim_num LIKE '%$targetNum' ";
                    } else {
                        $sqlTargetNumbersOutbox = $sqlTargetNumbersOutbox . "OR recepients LIKE '%$targetNum' ";
                        $sqlTargetNumbersInbox = $sqlTargetNumbersInbox . "OR sim_num LIKE '%$targetNum' ";
                    }
                }
            } else {
                $targetNum = $number[0];
                $sqlTargetNumbersOutbox = "recepients LIKE '%$targetNum' ";
                $sqlTargetNumbersInbox = "sim_num LIKE '%$targetNum' ";
            }

            //------------- First 20 latest messages

            $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, timestamp_written as timestamp, timestamp_sent as timestampsent,sms_id FROM smsoutbox WHERE " . $sqlTargetNumbersOutbox. "AND timestamp_written >= '$timestamp' ";

            $sqlInbox = "SELECT sim_num as user, sms_msg as msg, timestamp as timestamp, null as timestampsent,sms_id FROM smsinbox WHERE " . $sqlTargetNumbersInbox." AND timestamp >= '$timestamp' ";

            $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp asc LIMIT 20";
            

            // Make sure the connection is still alive, if not, try to reconnect 
            $this->checkConnectionDB($sql);
            $result = $this->dbconn->query($sql);

            $ctr = 0;
            $presentConversation = "";
            $fullData['type'] = "smsLoadSearched";
            if ($result->num_rows > 0) {
                // output data of each row
                while ($row = $result->fetch_assoc()) {
                    $presentConversation[$ctr]['user'] = $row['user'];
                    $presentConversation[$ctr]['msg'] = $row['msg'];
                    $presentConversation[$ctr]['timestamp'] = $row['timestamp'];
                    $presentConversation[$ctr]['timestamp_sent'] = $row['timestampsent'];
                    $presentConversation[$ctr]['type'] = 'smsLoadSearched';
                    $presentConversation[$ctr]['sms_id'] = 'sms_id';

                    $ctr = $ctr + 1;
                }
            }
            else {
                echo "0 results\n";
                $fullData['data'] = null;
            }

            //------------- END First 20 latest messages

            //------------- First 20 OLD messages

            $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, timestamp_written as timestamp,timestamp_sent as timestampsent,sms_id FROM smsoutbox WHERE " . $sqlTargetNumbersOutbox. "AND timestamp_written < '$timestamp' ";

            $sqlInbox = "SELECT sim_num as user, sms_msg as msg, timestamp as timestamp,null as timestampsent,sms_id FROM smsinbox WHERE " . $sqlTargetNumbersInbox." AND timestamp < '$timestamp' ";

            $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp desc LIMIT 20";

            // Make sure the connection is still alive, if not, try to reconnect 
            $this->checkConnectionDB($sql);
            $result = $this->dbconn->query($sql);

            $ctr = 0;
            $pastConversation = "";
            if ($result->num_rows > 0) {
                // output data of each row
                while ($row = $result->fetch_assoc()) {
                    $pastConversation[$ctr]['user'] = $row['user'];
                    $pastConversation[$ctr]['msg'] = $row['msg'];
                    $pastConversation[$ctr]['timestamp'] = $row['timestamp'];
                    $pastConversation[$ctr]['timestamp_sent'] = $row['timestampsent'];
                    $pastConversation[$ctr]['type'] = 'smsLoadSearched';
                    $pastConversation[$ctr]['sms_id'] = $row['sms_id'];

                    $ctr = $ctr + 1;
                }
            }
            else {
                echo "0 results\n";
                $fullData['data'] = null;
            }
            //------------- END First 20 OLD messages

            $allArray = array_merge($pastConversation,$presentConversation);
            $fullData['data'] = $allArray;
            return $fullData;

        } else {
            echo "Invalid Request/No request has been made.";
        }
    }

    public function getSearchedGroupConversation($offices = null,$sitenames=null,$type=null, $timestamp=null){
        if ($type == "smsLoadGroupSearched") {
            $ctr = 0;

            $tagsCombined = "";

            $ctrOffices = 0;
            $subQueryOffices;
            if ($offices == null) {
                echo "Error: no office selected.";
                return -1;
            } 
            else {
                foreach ($offices as $office) {
                    if ($ctrOffices == 0) {
                        $subQueryOffices = "('$office'";
                    } else {
                        $subQueryOffices = $subQueryOffices . ",'$office'";
                    }

                    $tagsCombined = "$tagsCombined $office";
                    $ctrOffices++;
                }

                $subQueryOffices = $subQueryOffices . ")";
            }
            echo "offices: $subQueryOffices \n";

            $ctrSites = 0;
            $subQuerySitenames;
            if ($sitenames == null) {
                echo "Error: no sitename selected.";
                return -1;
            } 
            else {
                foreach ($sitenames as $site) {
                    if ($ctrSites == 0) {
                        $subQuerySitenames = "('$site'";
                    } 
                    else {
                        $subQuerySitenames = $subQuerySitenames . ",'$site'";
                    }

                    $tagsCombined = "$tagsCombined $site";
                    $ctrSites++;
                }

                $subQuerySitenames = $subQuerySitenames . ")";
            }
            echo "sitenames: $subQuerySitenames \n";

            $sql = '';
            
            $sqlTargetNumbers = "SELECT office, sitename, lastname, number 
                                FROM communitycontacts 
                                WHERE office in $subQueryOffices 
                                AND sitename in $subQuerySitenames";

            $this->checkConnectionDB($sqlTargetNumbers);
            $result = $this->dbconn->query($sqlTargetNumbers);
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $numbers = explode(",", $row['number']);

                    foreach ($numbers as $number) {
                        $dbreturn[$ctr]['office'] = $row['office'];
                        $dbreturn[$ctr]['sitename'] = $row['sitename'];
                        $dbreturn[$ctr]['number'] = $number;

                        $ctr = $ctr + 1;
                    }
                }

                $contactInfoData['data'] = $dbreturn;
            }
            else {
                echo "0 numbers found\n";
                $contactInfoData['data'] = null;
            }

            $num_numbers = sizeof($contactInfoData['data']);
            if ($num_numbers >= 1) {
                for ($i = 0; $i < $num_numbers; $i++) { 
                    $targetNum = $this->normalizeContactNumber($contactInfoData['data'][$i]['number']);
                    $contactInfoData['data'][$i]['number'] = $targetNum;

                    if ($i == 0) {
                        $sqlTargetNumbersOutbox = "recepients LIKE '%$targetNum' ";
                        $sqlTargetNumbersInbox = "sim_num LIKE '%$targetNum' ";
                    } else {
                        $sqlTargetNumbersOutbox = $sqlTargetNumbersOutbox . "OR recepients LIKE '%$targetNum' ";
                        $sqlTargetNumbersInbox = $sqlTargetNumbersInbox . "OR sim_num LIKE '%$targetNum' ";
                    }
                }
            } else {
                $sqlTargetNumbersOutbox = " ";
                $sqlTargetNumbersInbox = " ";
            }   

            $timeStampArray = explode(',', $timestamp);
            $timestampYou = $timeStampArray[0];
            $timestampGroup = $timeStampArray[1];

            if ($timestampYou == "" || $timestampYou == null){
                $fetchTimeStamp = "select distinct timestamp_written from smsoutbox where $sqlTargetNumbersOutbox order by timestamp_written desc";
                $this->checkConnectionDB($fetchTimeStamp);
                $result = $this->dbconn->query($fetchTimeStamp);

                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        if ($row['timestamp_written'] < $timestampGroup){
                            $timestampYou = $row['timestamp_written'];
                            break;
                        }
                    }
                }

            }

            if ($timestampGroup == "" || $timestampGroup == null) {
                $fetchTimeStamp = "select distinct timestamp from smsinbox where $sqlTargetNumbersInbox order by timestamp desc";
                $this->checkConnectionDB($fetchTimeStamp);
                $result = $this->dbconn->query($fetchTimeStamp);
                $result->fetch_assoc()['timestamp'];

                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        if ($row['timestamp'] < $timestampYou){
                            $timestampGroup =  $row['timestamp'];
                            break;
                        }
                    }
                }
            }

            // FETCH THE LAST 20 MESSAGES

            $sqlOutbox = "SELECT DISTINCT 'You' as user, sms_msg as msg, timestamp_written as timestamp,timestamp_sent as timestamp_sent,timestamp_written.sms_id FROM smsoutbox timestamp_written inner join (select sms_id from smsoutbox where timestamp_written = '$timestampYou' order by sms_id limit 1) x on timestamp_written.sms_id < x.sms_id WHERE $sqlTargetNumbersOutbox GROUP BY (timestamp)";


            $sqlInbox = "SELECT DISTINCT sim_num as user, sms_msg as msg,timestamp as timestamp, null as timestamp_sent,timestamp.sms_id FROM smsinbox timestamp inner join (select sms_id from smsinbox where timestamp = '$timestampGroup' order by sms_id limit 1) x on timestamp.sms_id < x.sms_id WHERE $sqlTargetNumbersInbox ";

            $sql = $sqlOutbox."UNION ".$sqlInbox."ORDER BY timestamp desc LIMIT 20";

            $this->checkConnectionDB($sql);
            $result = $this->dbconn->query($sql);

            $ctr = 0;
            $dbreturn = "";
            $pastMessages = "";
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $dbreturn[$ctr]['sms_id'] = $row['sms_id'];
                    $dbreturn[$ctr]['user'] = $row['user'];
                    $dbreturn[$ctr]['msg'] = $row['msg'];
                    $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                    $dbreturn[$ctr]['timestamp_sent'] = $row['timestamp_sent'];
                    $normalized = $this->normalizeContactNumber($dbreturn[$ctr]['user']);
                    foreach ($contactInfoData['data'] as $singleContact) {
                        if ($singleContact['number'] == $normalized) {
                            $dbreturn[$ctr]['name'] = $singleContact['sitename'] . " " . $singleContact['office'];
                            $dbreturn[$ctr]['type'] = "smsLoadGroupSearched";
                        }
                    }

                    if ($dbreturn[$ctr]['user'] == "You") {
                        $dbreturn[$ctr]['name'] = $tagsCombined;
                    }

                    $ctr = $ctr + 1;
                }

                $pastMessages['data'] = $dbreturn;
            }
            else {
                echo "0 results\n";
                $pastMessages['data'] = null;
            }

            // END LAST 20

            // LATEST 20

            $sqlOutbox = "SELECT DISTINCT 'You' as user, sms_msg as msg, timestamp_written as timestamp, timestamp_sent as timestamp_sent,timestamp_written.sms_id FROM smsoutbox timestamp_written inner join (select sms_id from smsoutbox where timestamp_written = '$timestampYou' order by sms_id limit 1) x on timestamp_written.sms_id >= x.sms_id WHERE $sqlTargetNumbersOutbox GROUP BY (timestamp)";

            $sqlInbox = "SELECT DISTINCT sim_num as user, sms_msg as msg,timestamp as timestamp, null as timestamp_sent,timestamp.sms_id FROM smsinbox timestamp inner join (select sms_id from smsinbox where timestamp = '$timestampGroup' order by sms_id limit 1) x on timestamp.sms_id >= x.sms_id WHERE $sqlTargetNumbersInbox ";

            $sql = $sqlOutbox."UNION ".$sqlInbox."ORDER BY timestamp asc LIMIT 20";

            $this->checkConnectionDB($sql);
            $result = $this->dbconn->query($sql);

            $ctr = 0;
            $dbreturn = "";
            $latestMessages = "";
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $dbreturn[$ctr]['sms_id'] = $row['sms_id'];
                    $dbreturn[$ctr]['user'] = $row['user'];
                    $dbreturn[$ctr]['msg'] = $row['msg'];
                    $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                     $dbreturn[$ctr]['timestamp_sent'] = $row['timestamp_sent'];
                    $normalized = $this->normalizeContactNumber($dbreturn[$ctr]['user']);
                    foreach ($contactInfoData['data'] as $singleContact) {
                        if ($singleContact['number'] == $normalized) {
                            $dbreturn[$ctr]['name'] = $singleContact['sitename'] . " " . $singleContact['office'];
                            $dbreturn[$ctr]['type'] = "smsLoadGroupSearched";
                        }
                    }

                    if ($dbreturn[$ctr]['user'] == "You") {
                        $dbreturn[$ctr]['name'] = $tagsCombined;
                    }

                    $ctr = $ctr + 1;
                }

                $latestMessages['data'] = $dbreturn;
            }
            else {
                echo "0 results\n";
                $latestMessages['data'] = null;
            }

            // END LATEST 20
            $msgData = [];
            $msgData['data'] = array_merge(array_reverse($pastMessages['data']),$latestMessages['data']);
            $msgData['type'] = 'smsLoadGroupSearched';
            return $msgData;

        } else {
            echo "Invalid Request/No request has been made.";
        }
    }

    public function getSearchedGlobalConversation($user=null,$user_number=null,$timestamp=null,$msg){
        $ctr = 0;
        $sql = '';
        $sqlTargetNumbers = "";
        $simple_msg_catcher = explode("-",$msg);
        var_dump($simple_msg_catcher);
        //Get the recepient number and trim it.
        if ($user_number == "You") {

            $partition = 0;
            foreach ($simple_msg_catcher as $msg_partition) {
                if ($partition == 0 ) {
                    $sms_msg_sub = "sms_msg LIKE '%".$msg_partition."%'";
                    $partition++;
                } else {
                    $sms_msg_sub = $sms_msg_sub." AND sms_msg LIKE '%".$msg_partition."%'";
                }
            }
            $sql = "SELECT DISTINCT 'You' as user, recepients as recepients FROM smsoutbox WHERE ".$sms_msg_sub." AND timestamp_written = '$timestamp'";
            $this->checkConnectionDB($sql);
            $result = $this->dbconn->query($sql);

            $ctr = 0;
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {

                    if (strlen($row['recepients']) == 12){
                       $contactTrimmed = substr($row['recepients'], 2);
                    } else if (strlen($row['recepients']) == 11){
                        $contactTrimmed = substr($row['recepients'], 1);
                    } else {
                        $contactTrimmed = $row['recepients'];
                    }
                    $ctr = $ctr + 1;
                }
            }
            else {
                echo "0 results\n";
                $fullData['data'] = null;
            }


        } else {
            if (strlen($user_number) == 12){
               $contactTrimmed = substr($user_number, 2);
            } else if (strlen($user_number) == 11){
                $contactTrimmed = substr($user_number, 1);
            } else {
                $contactTrimmed = $user_number;
            }
        }

        //Get the Recepients name
        $sqlTargetIndividualAlias = "select firstname,lastname from dewslcontacts where numbers LIKE '%".$contactTrimmed."%'";
        $this->checkConnectionDB($sqlTargetIndividualAlias);
        $resultAlias = $this->dbconn->query($sqlTargetIndividualAlias);
        if ($resultAlias->num_rows > 0) {
            while ($rowIndiAlias = $resultAlias->fetch_assoc()) {
                $alias= $rowIndiAlias['firstname']." ".$rowIndiAlias['lastname'];
                break;
            }
        } else {
            // GET RECEPIENT ALIAS FOR Group
            $sqlTargetGroupAlias = "select * from communitycontacts where number LIKE '%".$contactTrimmed."%'";
            $this->checkConnectionDB($sqlTargetGroupAlias);
            $resultGroupAlias = $this->dbconn->query($sqlTargetGroupAlias);
            if ($resultGroupAlias->num_rows > 0) {
                while ($rowGroupAlias = $resultGroupAlias->fetch_assoc()) {
                  $alias = $rowGroupAlias['sitename']." ".$rowGroupAlias['office']." ".$rowGroupAlias['prefix']." ".$rowGroupAlias['firstname']." ".$rowGroupAlias['lastname'];
                   break;
                }
            } else {
                $alias = "You";
            }
        }

        //------------- First 20 latest messages

        $sqlOutbox = "SELECT DISTINCT 'You' as user, sms_msg as msg, timestamp_written as timestamp, timestamp_sent as timestampsent FROM smsoutbox WHERE recepients LIKE '%".$contactTrimmed."%' AND timestamp_written > '$timestamp' ";

        $sqlInbox = "SELECT DISTINCT sim_num as user, sms_msg as msg, timestamp as timestamp, null as timestampsent FROM smsinbox WHERE sim_num LIKE '%".$contactTrimmed."%' AND timestamp > '$timestamp' ";

        $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp asc LIMIT 20";

        var_dump($sql);
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $presentConversation = "";
        $fullData['type'] = "smsloadGlobalSearched";
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                if ($row['user'] == "You"){
                    $presentConversation[$ctr]['user'] = "You";
                } else {
                    $presentConversation[$ctr]['user'] = $alias;
                }
                $presentConversation[$ctr]['msg'] = $row['msg'];
                $presentConversation[$ctr]['timestamp'] = $row['timestamp'];
                $presentConversation[$ctr]['timestamp_sent'] = $row['timestampsent'];
                $presentConversation[$ctr]['type'] = 'smsloadGlobalSearched';

                $ctr = $ctr + 1;
            }
        }
        else {
            echo "0 results\n";
            $presentConversation = [];
        }

        //------------- END First 20 latest messages

        //------------- First 20 OLD messages

        $sqlOutbox = "SELECT DISTINCT 'You' as user, sms_msg as msg, timestamp_written as timestamp, timestamp_sent as timestampsent FROM smsoutbox WHERE recepients LIKE '%".$contactTrimmed."%' AND timestamp_written <= '$timestamp' ";

        $sqlInbox = "SELECT DISTINCT sim_num as user, sms_msg as msg, timestamp as timestamp, null as timestampsent FROM smsinbox WHERE sim_num LIKE '%".$contactTrimmed."%' AND timestamp <= '$timestamp' ";

        $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp desc LIMIT 20";

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $pastConversation = "";
        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                if ($row['user'] == "You"){
                    $pastConversation[$ctr]['user'] = "You";
                } else {
                    $pastConversation[$ctr]['user'] = $alias;
                }
                $pastConversation[$ctr]['msg'] = $row['msg'];
                $pastConversation[$ctr]['timestamp'] = $row['timestamp'];
                $pastConversation[$ctr]['timestamp_sent'] = $row['timestampsent'];
                $pastConversation[$ctr]['type'] = 'smsloadGlobalSearched';

                $ctr = $ctr + 1;
            }
        }
        else {
            echo "0 results\n";
           $pastConversation = [];
        }
        //------------- END First 20 OLD messages

        $allArray = array_merge($presentConversation,$pastConversation);
        $fullData['talking_to'] = $alias;
        $fullData['mobile_no'] = $contactTrimmed;
        $fullData['data'] = $allArray;
        return $fullData;
    }

    public function getSearchedConversationViaTimestampsent($data) {

        $ctr = 0;
        $pastConversation = "";
        $latestConversation = "";
        $conversation = [];
        if (strlen($data->user_number) == 12){
            $data->user_number = substr($data->user_number, 2);
        } else if (strlen($data->user_number) == 11){
            $data->user_number = substr($data->user_number, 1);
        } else {
            $data->user_number = $data->user_number;
        }

        //Get the Recepients name
        $sqlTargetIndividualAlias = "select firstname,lastname from dewslcontacts where numbers LIKE '%".$data->user_number."%'";
        $this->checkConnectionDB($sqlTargetIndividualAlias);
        $resultAlias = $this->dbconn->query($sqlTargetIndividualAlias);
        if ($resultAlias->num_rows > 0) {
            while ($rowIndiAlias = $resultAlias->fetch_assoc()) {
                $alias= $rowIndiAlias['firstname']." ".$rowIndiAlias['lastname'];
                break;
            }
        } else {
            // GET RECEPIENT ALIAS FOR Group
            $sqlTargetGroupAlias = "select * from communitycontacts where number LIKE '%".$data->user_number."%'";
            $this->checkConnectionDB($sqlTargetGroupAlias);
            $resultGroupAlias = $this->dbconn->query($sqlTargetGroupAlias);
            if ($resultGroupAlias->num_rows > 0) {
                while ($rowGroupAlias = $resultGroupAlias->fetch_assoc()) {
                  $alias = $rowGroupAlias['sitename']." ".$rowGroupAlias['office']." ".$rowGroupAlias['prefix']." ".$rowGroupAlias['firstname']." ".$rowGroupAlias['lastname'];
                   break;
                }
            } else {
                $alias = "You";
            }
        }

        $query_inbox_past_20 = "SELECT * FROM (SELECT smsinbox.sms_id as id,smsinbox.timestamp as timestamp_sent,null as timestamp_written,smsinbox.sms_msg as msg,sim_num as sender,'You' as recipient FROM smsinbox WHERE sim_num LIKE '%".$data->user_number."%' AND timestamp <= '".$data->timestamp."' UNION SELECT smsoutbox.sms_id as id,smsoutbox.timestamp_sent,smsoutbox.timestamp_written,smsoutbox.sms_msg as msg,'You' as sender,smsoutbox.recepients as recipient FROM senslopedb.smsoutbox WHERE recepients LIKE '%".$data->user_number."%' AND timestamp_sent <= '".$data->timestamp."') as sms order by timestamp_sent desc limit 21";
        $this->checkConnectionDB($query_inbox_past_20);
        $past_results = $this->dbconn->query($query_inbox_past_20);
        if ($past_results->num_rows > 0) {
            while ($row = $past_results->fetch_assoc()) {
                $pastConversation[$ctr]['id'] = $row['id'];
                $pastConversation[$ctr]['recipient'] = $row['recipient'];
                $pastConversation[$ctr]['sender'] = $row['sender'];
                $pastConversation[$ctr]['msg'] = $row['msg'];
                $pastConversation[$ctr]['timestamp'] = $row['timestamp_written'];
                $pastConversation[$ctr]['timestamp_sent'] = $row['timestamp_sent'];
                $pastConversation[$ctr]['type'] = 'smsloadTimestampsentSearched';
                $ctr++; 
           }
        } else {
            echo "0 results\n";
            $pastConversation = [null];
        }

        $query_inbox_latest_20 = "SELECT * FROM (SELECT smsinbox.sms_id as id,smsinbox.timestamp as timestamp_sent,null as timestamp_written,smsinbox.sms_msg as msg,sim_num as sender,'You' as recipient FROM smsinbox WHERE sim_num LIKE '%".$data->user_number."%' AND timestamp >= '".$data->timestamp."' UNION SELECT smsoutbox.sms_id as id,smsoutbox.timestamp_sent,smsoutbox.timestamp_written,smsoutbox.sms_msg as msg,'You' as sender,smsoutbox.recepients as recipient FROM senslopedb.smsoutbox WHERE recepients LIKE '%".$data->user_number."%' AND timestamp_sent >= '".$data->timestamp."') as sms order by timestamp_sent desc limit 21";
        $this->checkConnectionDB($query_inbox_latest_20);
        $latest_results = $this->dbconn->query($query_inbox_latest_20);

        $ctr = 0;
        if ($latest_results->num_rows > 0) {
            while ($row = $latest_results->fetch_assoc()) {
                $latestConversion[$ctr]['id'] = $row['id'];
                $latestConversion[$ctr]['recipient'] = $row['recipient'];
                $latestConversion[$ctr]['sender'] = $row['sender'];
                $latestConversion[$ctr]['msg'] = $row['msg'];
                $latestConversion[$ctr]['timestamp'] = $row['timestamp_written'];
                $latestConversion[$ctr]['timestamp_sent'] = $row['timestamp_sent'];
                $latestConversion[$ctr]['type'] = 'smsloadTimestampsentSearched';
                $ctr++; 
           }
        } else {
            echo "0 results\n";
            $latestConversion = [];
        }
        
        $conversation['talking_to'] = $alias;
        $conversation['mobile_no'] = $data->user_number;
        $conversation['data'] = array_merge($latestConversion,$pastConversation);
        $conversation['type'] = "smsloadTimestampsentSearched";
        return $conversation;
    }

    public function getSearchedConversationViaTimestampwritten($data) {
       
        $ctr = 0;
        $pastConversation = "";
        $latestConversation = "";
        $conversation = [];

        if (strlen($data->user_number) == 12){
            $data->user_number = substr($data->user_number, 2);
        } else if (strlen($data->user_number) == 11){
            $data->user_number = substr($data->user_number, 1);
        } else {
            $data->user_number = $data->user_number;
        }

        //Get the Recepients name
        $sqlTargetIndividualAlias = "select firstname,lastname from dewslcontacts where numbers LIKE '%".$data->user_number."%'";
        $this->checkConnectionDB($sqlTargetIndividualAlias);
        $resultAlias = $this->dbconn->query($sqlTargetIndividualAlias);
        if ($resultAlias->num_rows > 0) {
            while ($rowIndiAlias = $resultAlias->fetch_assoc()) {
                $alias= $rowIndiAlias['firstname']." ".$rowIndiAlias['lastname'];
                break;
            }
        } else {
            // GET RECEPIENT ALIAS FOR Group
            $sqlTargetGroupAlias = "select * from communitycontacts where number LIKE '%".$data->user_number."%'";
            $this->checkConnectionDB($sqlTargetGroupAlias);
            $resultGroupAlias = $this->dbconn->query($sqlTargetGroupAlias);
            if ($resultGroupAlias->num_rows > 0) {
                while ($rowGroupAlias = $resultGroupAlias->fetch_assoc()) {
                  $alias = $rowGroupAlias['sitename']." ".$rowGroupAlias['office']." ".$rowGroupAlias['prefix']." ".$rowGroupAlias['firstname']." ".$rowGroupAlias['lastname'];
                   break;
                }
            } else {
                $alias = "You";
            }
        }

        $query_inbox_past_20 = "SELECT * FROM (SELECT smsinbox.sms_id as id,smsinbox.timestamp as timestamp_sent,null as timestamp_written,smsinbox.sms_msg as msg,sim_num as sender,'You' as recipient FROM smsinbox WHERE sim_num LIKE '%".$data->user_number."%' AND timestamp <= '".$data->timestamp."' UNION SELECT smsoutbox.sms_id as id,smsoutbox.timestamp_written,smsoutbox.timestamp_sent,smsoutbox.recepients as recipient,'You' as sender,smsoutbox.sms_msg as msg FROM senslopedb.smsoutbox WHERE recepients LIKE '%".$data->user_number."%' AND timestamp_sent <= '".$data->timestamp."') as sms order by timestamp_sent desc limit 21";
        $this->checkConnectionDB($query_inbox_past_20);
        $past_results = $this->dbconn->query($query_inbox_past_20);

        if ($past_results->num_rows > 0) {
            while ($row = $past_results->fetch_assoc()) {
                $pastConversation[$ctr]['id'] = $row['id'];
                $pastConversation[$ctr]['recipient'] = $row['recipient'];
                $pastConversation[$ctr]['sender'] = $row['sender'];
                $pastConversation[$ctr]['msg'] = $row['msg'];
                $pastConversation[$ctr]['timestamp'] = $row['timestamp_written'];
                $pastConversation[$ctr]['timestamp_sent'] = $row['timestamp_sent'];
                $pastConversation[$ctr]['type'] = 'smsloadTimestampwrittenSearched';
                $ctr++; 
           }
        } else {
            echo "0 results\n";
            $pastConversation = [];
        }


        $query_inbox_latest_20 = "SELECT * FROM (SELECT smsinbox.sms_id as id,smsinbox.timestamp as timestamp_sent,null as timestamp_written,smsinbox.sms_msg as msg,sim_num as sender,'You' as recipient FROM smsinbox WHERE sim_num LIKE '%".$data->user_number."%' AND timestamp >= '".$data->timestamp."' UNION SELECT smsoutbox.sms_id as id,smsoutbox.timestamp_written,smsoutbox.timestamp_sent,smsoutbox.recepients as recipient,'You' as sender,smsoutbox.sms_msg as msg FROM senslopedb.smsoutbox WHERE recepients LIKE '%".$data->user_number."%' AND timestamp_sent >= '".$data->timestamp."') as sms order by timestamp_sent desc limit 21";
        $this->checkConnectionDB($query_inbox_latest_20);
        $latest_results = $this->dbconn->query($query_inbox_latest_20);

        $ctr = 0;
        if ($latest_results->num_rows > 0) {
            while ($row = $latest_results->fetch_assoc()) {
                $latestConversion[$ctr]['id'] = $row['id'];
                $latestConversion[$ctr]['recipient'] = $row['recipient'];
                $latestConversion[$ctr]['sender'] = $row['sender'];
                $latestConversion[$ctr]['msg'] = $row['msg'];
                $latestConversion[$ctr]['timestamp'] = $row['timestamp_written'];
                $latestConversion[$ctr]['timestamp_sent'] = $row['timestamp_sent'];
                $latestConversion[$ctr]['type'] = 'smsloadTimestampwrittenSearched';
                $ctr++; 
           }
        } else {
            echo "0 results\n";
            $latestConversion = [];
        }

        $conversation['talking_to'] = $alias;
        $conversation['mobile_no'] = $data->user_number;
        $conversation['data'] = array_merge($latestConversion,$pastConversation);
        $conversation['type'] = "smsloadTimestampwrittenSearched";
        return $conversation;
    }

    //Return the message exchanges between Chatterbox and a number
    public function getMessageExchanges($number = null,$type = null,$timestamp = null, $limit = 20,$tags = null) {
        $ctr = 0;
        $employeeTags = [];

        if ($type == "oldMessageGroupEmployee") {
            $number = $this->getEmpTagNumbers($number);
            for ($x = 0;$x < sizeof($number);$x++) {
                $ctr++;
            }
        } else {
            if ($number == null) {
                echo "Error: no number selected.";
                return -1;
            } else {
                foreach ($number as $test) {
                    //echo "target: $number\n";
                    echo "target: $test\n";
                    $ctr++;
                }
            }
        }

        $sql = '';
        $sqlTargetNumbers = "";

        //Construct the query for loading messages from multiple numbers
        if ($ctr > 1) {
            for ($i = 0; $i < $ctr; $i++) {
                if ($type == "oldMessageGroupEmployee") {
                    $targetNum = $number[$i]["number"];
                } else {
                    $targetNum = $number[$i];
                }

                if ($i == 0) {
                    $sqlTargetNumbersOutbox = "recepients LIKE '%$targetNum' ";
                    $sqlTargetNumbersInbox = "sim_num LIKE '%$targetNum' ";
                } else {
                    $sqlTargetNumbersOutbox = $sqlTargetNumbersOutbox . "OR recepients LIKE '%$targetNum' ";
                    $sqlTargetNumbersInbox = $sqlTargetNumbersInbox . "OR sim_num LIKE '%$targetNum' ";
                }
            }
        } else {
            $targetNum = $number[0];
            $sqlTargetNumbersOutbox = "recepients LIKE '%$targetNum' ";
            $sqlTargetNumbersInbox = "sim_num LIKE '%$targetNum' ";
        }

        //Construct the final query
        if ($type == "oldMessage" || $type == "oldMessageGroupEmployee") {

            $timeStampArray = explode(',', $timestamp);
            $yourLastTimeStamp = $timeStampArray[0];
            $indiLastTimeStamp = $timeStampArray[1];

            if ($yourLastTimeStamp == ""){
                $fetchTimeStamp = "select timestamp_written from smsoutbox where $sqlTargetNumbersOutbox order by timestamp_written desc";
                $this->checkConnectionDB($fetchTimeStamp);
                $result = $this->dbconn->query($fetchTimeStamp);
                $yourLastTimeStamp = $result->fetch_assoc()['timestamp']; 
            }

            if ($indiLastTimeStamp == "") {
                $fetchTimeStamp = "select timestamp from smsinbox where $sqlTargetNumbersInbox order by timestamp desc";
                $this->checkConnectionDB($fetchTimeStamp);
                $result = $this->dbconn->query($fetchTimeStamp);
                $indiLastTimeStamp = $result->fetch_assoc()['timestamp']; 
            }

            $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, 
                            timestamp_written as timestamp, timestamp_sent as timestampsent,timestamp_written.sms_id,send_status FROM smsoutbox timestamp_written inner join (select sms_id from smsoutbox where timestamp_written = '$yourLastTimeStamp' order by sms_id limit 1) x on timestamp_written.sms_id < x.sms_id WHERE $sqlTargetNumbersOutbox";


            $sqlInbox = "SELECT sim_num as user, sms_msg as msg,
                            timestamp as timestamp, null as timestampsent,timestamp.sms_id,'SUCCESS' as send_status
                        FROM smsinbox timestamp inner join (select sms_id from smsinbox where timestamp = '$indiLastTimeStamp' order by sms_id limit 1) x on timestamp.sms_id < x.sms_id WHERE $sqlTargetNumbersInbox ";

            $sql = $sqlOutbox."UNION ".$sqlInbox."ORDER BY timestamp desc LIMIT $limit";

        } else {
            if ($timestamp == null) {
                $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, 
                                timestamp_written as timestamp, timestamp_sent as timestampsent,sms_id,send_status
                            FROM smsoutbox WHERE " . $sqlTargetNumbersOutbox;

                $sqlInbox = "SELECT sim_num as user, sms_msg as msg,
                                timestamp as timestamp, null as timestampsent,sms_id,'SUCCESS' as send_status
                            FROM smsinbox WHERE " . $sqlTargetNumbersInbox;

                $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp desc LIMIT $limit";
            } else {
                $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, 
                                timestamp_written as timestamp, timestamp_sent as timestampsent,sms_id,send_status
                            FROM smsoutbox WHERE " . $sqlTargetNumbersOutbox . "AND timestamp_written < '$timestamp' ";

                $sqlInbox = "SELECT sim_num as user, sms_msg as msg,
                                timestamp as timestamp, null as timestampsent,sms_id,'SUCCESS' as send_status
                            FROM smsinbox WHERE " . $sqlTargetNumbersInbox . "AND timestamp < '$timestamp' ";

                $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp desc LIMIT $limit";
            }
        }

        // Make sure the connection is still alive, if not, try to reconnect 


        // $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        if ($type == "oldMessage"){
            $fullData['type'] = 'oldMessage';
        } else if ($type == "oldMessageGroupEmployee") {
            $fullData['type'] = 'oldMessageGroupEmployee';
        } else {
            $fullData['type'] = 'smsload';
        }

        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                if ($type == "oldMessageGroupEmployee") {
                    $employeeTags = $this->getEmpTagNumbers($tags);
                    for ($x = 0;$x < sizeof($employeeTags);$x++) {
                        if ($employeeTags[$x]['number'] == $row['user']) {
                            $dbreturn[$ctr]['user'] = strtoupper($employeeTags[$x]['tags']);
                        }
                    }
                } else {
                    $dbreturn[$ctr]['user'] = $row['user'];
                    if ($dbreturn[$ctr]['user'] == "You") {
                        $dbreturn[$ctr]['table_used'] = "smsoutbox";
                    } else {
                        $dbreturn[$ctr]['network'] = $this->identifyMobileNetwork($row['user']);
                        $dbreturn[$ctr]['table_used'] = "smsinbox";
                    }
                }
                $dbreturn[$ctr]['sms_id'] = $row['sms_id'];
                $dbreturn[$ctr]['msg'] = $row['msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                $dbreturn[$ctr]['timestamp_sent'] = $row['timestampsent'];
                $dbreturn[$ctr]['status'] = $row['send_status'];

                $ctr = $ctr + 1;
            }

            for ($x = 0; $x < sizeof($dbreturn);$x++) {
                if ($x == 0) {
                    $ids = "table_element_id = '".$dbreturn[$x]["sms_id"]."' ";
                } else {
                    $ids = $ids."OR table_element_id = '".$dbreturn[$x]["sms_id"]."' ";
                }
            }

            $query = "SELECT table_element_id FROM gintags WHERE ".$ids."";
            // Make sure the connection is still alive, if not, try to reconnect 
            $this->checkConnectionDB($query);
            $result = $this->dbconn->query($query);
            $idCollection = [];
            if ($result->num_rows > 0) {
                 while ($row = $result->fetch_assoc()) {
                    array_push($idCollection,$row["table_element_id"]);
                 }
            }

            for ($x = 0; $x < sizeof($dbreturn); $x++) {
                for ($y = 0; $y < sizeof($idCollection); $y++) {
                    if ($dbreturn[$x]["sms_id"] == $idCollection[$y]) {
                        $dbreturn[$x]["hasTag"] = 1;
                        break;
                    } else {
                        $dbreturn[$x]["hasTag"] = 0;
                    }
                }
            }

            $fullData['data'] = $dbreturn;
        }
        else {
            echo "0 results\n";
            $fullData['data'] = null;
        }

        return $fullData;
    }

    public function searchMessage($number,$timestamp,$searchKey){
        $ctr = 0;
        $sql = '';
        $sqlTargetNumbers = "";

        //Construct the query for loading messages from multiple numbers
        if ($ctr > 1) {
            for ($i = 0; $i < $ctr; $i++) { 
                $targetNum = $number[$i];

                if ($i == 0) {
                    $sqlTargetNumbersOutbox = "recepients LIKE '%$targetNum' ";
                    $sqlTargetNumbersInbox = "sim_num LIKE '%$targetNum' ";
                } else {
                    $sqlTargetNumbersOutbox = $sqlTargetNumbersOutbox . "OR recepients LIKE '%$targetNum' ";
                    $sqlTargetNumbersInbox = $sqlTargetNumbersInbox . "OR sim_num LIKE '%$targetNum' ";
                }
            }
        } else {
            $targetNum = $number[0];
            $sqlTargetNumbersOutbox = "recepients LIKE '%$targetNum' ";
            $sqlTargetNumbersInbox = "sim_num LIKE '%$targetNum' ";
        }

        //Construct the query for searching
        $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, timestamp_written as timestamp, timestamp_sent as timestampsent FROM smsoutbox WHERE " . $sqlTargetNumbersOutbox . "AND sms_msg LIKE '%".$searchKey."%'";

        $sqlInbox = "SELECT sim_num as user, sms_msg as msg,timestamp as timestamp, null as timestampsent FROM smsinbox WHERE " . $sqlTargetNumbersInbox . "AND sms_msg LIKE '%".$searchKey."%'";

        $sql = $sqlOutbox . " UNION " . $sqlInbox . "ORDER BY timestamp desc";

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'searchMessage';


        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['user'] = $row['user'];
                $dbreturn[$ctr]['msg'] = $row['msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                $dbreturn[$ctr]['timestamp_sent'] = $row['timestampsent'];
                $dbreturn[$ctr]['type'] = 'searchMessage';
                $ctr = $ctr + 1;
            }

            $fullData['data'] = $dbreturn;
        }
        else {
            echo "0 results\n";
            $fullData['data'] = null;
        }
        return $fullData;
    }

    public function searchMessageGroup($offices, $sitenames,$searchKey){
        $ctr = 0;

        $tagsCombined = "";

        $ctrOffices = 0;
        $subQueryOffices;
        if ($offices == null) {
            echo "Error: no office selected.";
            return -1;
        } 
        else {
            foreach ($offices as $office) {
                if ($ctrOffices == 0) {
                    $subQueryOffices = "('$office'";
                } else {
                    $subQueryOffices = $subQueryOffices . ",'$office'";
                }

                $tagsCombined = "$tagsCombined $office";
                $ctrOffices++;
            }

            $subQueryOffices = $subQueryOffices . ")";
        }
        echo "offices: $subQueryOffices \n";

        $ctrSites = 0;
        $subQuerySitenames;
        if ($sitenames == null) {
            echo "Error: no sitename selected.";
            return -1;
        } 
        else {
            foreach ($sitenames as $site) {
                if ($ctrSites == 0) {
                    $subQuerySitenames = "('$site'";
                } 
                else {
                    $subQuerySitenames = $subQuerySitenames . ",'$site'";
                }

                $tagsCombined = "$tagsCombined $site";
                $ctrSites++;
            }

            $subQuerySitenames = $subQuerySitenames . ")";
        }
        echo "sitenames: $subQuerySitenames \n";

        $sql = '';

        //TODO: construct query for loading the numbers from the tags selected
        //  by the user
        $sqlTargetNumbers = "SELECT office, sitename, lastname, number 
                            FROM communitycontacts 
                            WHERE office in $subQueryOffices 
                            AND sitename in $subQuerySitenames";

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sqlTargetNumbers);
        $result = $this->dbconn->query($sqlTargetNumbers);
        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $numbers = explode(",", $row['number']);

                foreach ($numbers as $number) {
                    $dbreturn[$ctr]['office'] = $row['office'];
                    $dbreturn[$ctr]['sitename'] = $row['sitename'];
                    $dbreturn[$ctr]['number'] = $number;

                    $ctr = $ctr + 1;
                }
            }

            $contactInfoData['data'] = $dbreturn;
        }
        else {
            echo "0 numbers found\n";
            $contactInfoData['data'] = null;
        }

        //echo "JSON output ($ctr): " . json_encode($contactInfoData);

        //Construct the query for loading messages from multiple numbers
        $num_numbers = sizeof($contactInfoData['data']);
        if ($num_numbers >= 1) {
            for ($i = 0; $i < $num_numbers; $i++) { 
                $targetNum = $this->normalizeContactNumber($contactInfoData['data'][$i]['number']);
                $contactInfoData['data'][$i]['number'] = $targetNum;

                if ($i == 0) {
                    $sqlTargetNumbersOutbox = "recepients LIKE '%$targetNum' ";
                    $sqlTargetNumbersInbox = "sim_num LIKE '%$targetNum' ";
                } else {
                    $sqlTargetNumbersOutbox = $sqlTargetNumbersOutbox . "OR recepients LIKE '%$targetNum' ";
                    $sqlTargetNumbersInbox = $sqlTargetNumbersInbox . "OR sim_num LIKE '%$targetNum' ";
                }
            }
        } else {
            $sqlTargetNumbersOutbox = " ";
            $sqlTargetNumbersInbox = " ";
        }

        //Construct the final query
        $sqlOutbox = "SELECT DISTINCT user,msg,timestamp,timestamp_sent FROM (SELECT 'You' as user,sms_msg as msg,timestamp_written as timestamp,timestamp_sent as timestamp_sent FROM smsoutbox WHERE ".$sqlTargetNumbersOutbox.") as outbox WHERE msg LIKE '%$searchKey%'";   

        $sqlInbox = "SELECT DISTINCT user,msg,timestamp,null as timestamp_sent FROM (SELECT sim_num as user,sms_msg as msg,timestamp as timestamp, null as timestamp_sent FROM smsinbox WHERE ".$sqlTargetNumbersInbox.") as inbox WHERE msg LIKE '%$searchKey%'";

        $sql = $sqlOutbox . " UNION " . $sqlInbox . " ORDER BY timestamp desc";

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $msgData['type'] = 'searchMessageGroup';

        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['user'] = $row['user'];
                $dbreturn[$ctr]['msg'] = $row['msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                $dbreturn[$ctr]['timestamp_sent'] = $row['timestamp_sent'];
                $dbreturn[$ctr]['type'] = "searchMessageGroup";
                //Normalize the user's number
                $normalized = $this->normalizeContactNumber($dbreturn[$ctr]['user']);

                //Add "office" and "sitename" data using the "contactInfoData" array
                foreach ($contactInfoData['data'] as $singleContact) {
                    if ($singleContact['number'] == $normalized) {
                        $dbreturn[$ctr]['name'] = $singleContact['sitename'] . " " . $singleContact['office'];
                    }
                }

                if ($dbreturn[$ctr]['user'] == "You") {
                    $dbreturn[$ctr]['name'] = $tagsCombined;
                }

                $ctr = $ctr + 1;
            }

            $msgData['data'] = $dbreturn;
        }
        else {
            echo "0 results\n";
            $msgData['data'] = null;
        }

        //echo json_encode($msgData);

        return $msgData;
    }

    public function searchMessageGlobal($type,$searchKey,$searchLimit = 100){
        //Construct the query for searching
        $sqlOutbox = "SELECT DISTINCT 'You' as user,sms_msg as msg, timestamp_written as timestamp,timestamp_sent as timestampsent FROM smsoutbox WHERE sms_msg LIKE '%$searchKey%'";

        $sqlInbox = "SELECT DISTINCT sim_num as user,sms_msg as msg, timestamp as timestamp, null as timestampsent FROM smsinbox WHERE sms_msg LIKE '%$searchKey%'";

        $sql = $sqlOutbox . " UNION " . $sqlInbox . "ORDER BY timestamp desc LIMIT ".$searchLimit;
        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'searchMessageGlobal';

        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {

                if ($row['user'] == 'You') {
                    $dbreturn[$ctr]['user'] = $row['user'];
                } else {
                    // GET RECEPIENT ALIAS FOR INDIVIDUAL
                    $sqlTrimmedContact = "SELECT DISTINCT sim_num from smsinbox where timestamp like '%".$row['timestamp']."%' AND sms_msg='".$row['msg']."' UNION SELECT 'You' from smsoutbox where timestamp_written like '%".$row['timestamp']."%' AND sms_msg='".$row['msg']."'";
                    $this->checkConnectionDB($sqlTrimmedContact);
                    $trimmedContact = $this->dbconn->query($sqlTrimmedContact);
                    if ($trimmedContact->num_rows > 0) {
                        while ($trimmed = $trimmedContact->fetch_assoc()) {
                            if (strlen($trimmed['sim_num']) == 12){
                               $contactTrimmed = substr($trimmed['sim_num'], 2);
                            } else if (strlen($trimmed['sim_num']) == 11){
                                $contactTrimmed = substr($trimmed['sim_num'], 1);
                            } else {
                                $contactTrimmed = $trimmed['sim_num'];
                            }
                        }
                    }


                    $sqlTargetIndividualAlias = "select firstname,lastname from dewslcontacts where numbers LIKE '%".$contactTrimmed."%'";
                    $this->checkConnectionDB($sqlTargetIndividualAlias);
                    $resultAlias = $this->dbconn->query($sqlTargetIndividualAlias);
                    if ($resultAlias->num_rows > 0) {
                        while ($rowIndiAlias = $resultAlias->fetch_assoc()) {
                            $dbreturn[$ctr]['user'] = $rowIndiAlias['firstname']." ".$rowIndiAlias['lastname'];
                            break;
                        }
                    } else {
                        // GET RECEPIENT ALIAS FOR Group
                        $sqlTargetGroupAlias = "select * from communitycontacts where number LIKE '%".$contactTrimmed."%'";
                        $this->checkConnectionDB($sqlTargetGroupAlias);
                        $resultGroupAlias = $this->dbconn->query($sqlTargetGroupAlias);
                        if ($resultGroupAlias->num_rows > 0) {
                            while ($rowGroupAlias = $resultGroupAlias->fetch_assoc()) {
                              $dbreturn[$ctr]['user'] = $rowGroupAlias['sitename']." ".$rowGroupAlias['office']." ".$rowGroupAlias['prefix']." ".$rowGroupAlias['firstname']." ".$rowGroupAlias['lastname'];
                               break;
                            }
                        } else {
                            $dbreturn[$ctr]['user'] = "Unknown - ".$row['user'];
                        }
                    }
                }

                $dbreturn[$ctr]['user_number'] = $row['user'];
                $dbreturn[$ctr]['msg'] = $row['msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                $dbreturn[$ctr]['timestamp_sent'] = $row['timestampsent'];
                $dbreturn[$ctr]['type'] = 'searchMessageGlobal';
                $ctr = $ctr + 1;
            }

            $fullData['data'] = $dbreturn;
        }
        else {
            echo "0 results\n";
            $fullData['data'] = null;
        }
        return $fullData;
    }

    public function searchGintagMessage($type,$searchKey = 100){
        if (strpos($searchKey, '#') !== 0) {
            $searchKey = "#".$searchKey;
        }

        $query = "SELECT table_element_id,table_used FROM gintags inner join gintags_reference ON gintags.tag_id_fk=gintags_reference.tag_id WHERE gintags_reference.tag_name LIKE'%".$searchKey."%'";
        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($query);
        $result = $this->dbconn->query($query);
        if ($result->num_rows > 0) {
            // output data of each row
            $sms_id_collection = [];
            $ctr = 0;

            while ($row = $result->fetch_assoc()) {
                $sms_id_collection[$ctr]["id"] = $row['table_element_id'];
                $sms_id_collection[$ctr]["table_used"] = $row['table_used'];
                $ctr++;
            }

            $inboxCtr = 0;
            $outboxCtr = 0;

            $sqlInbox = "";
            $sqlOutbox = "";

            for ($counter = 0; $counter < sizeof($sms_id_collection)-1; $counter++) {
                if ($sms_id_collection[$counter]['table_used'] == "smsoutbox") {
                    if ($outboxCtr == 0) {
                        $sqlOutbox  = "WHERE sms_id = '".$sms_id_collection[$counter]['id']."'";
                    } else {
                        $sqlOutbox = $sqlOutbox." OR sms_id = ".$sms_id_collection[$counter]['id']." ";
                    }
                    $outboxCtr++;
                } else {
                    if ($inboxCtr == 0) {
                        $sqlInbox  = "WHERE sms_id = '".$sms_id_collection[$counter]['id']."'";
                    } else {
                        $sqlInbox = $sqlInbox." OR sms_id = '".$sms_id_collection[$counter]['id']."' ";
                    }
                    $inboxCtr++;
                }
            }

            $queryOutbox = "SELECT DISTINCT 'You' as user,recepients as recipients, sms_msg as msg, timestamp_written as timestamp, timestamp_sent as timestamp_sent,sms_id FROM smsoutbox $sqlOutbox ";

            $queryInbox = "SELECT DISTINCT sim_num as user,null as recipients, sms_msg as msg,timestamp as timestamp, null as timestamp_sent,sms_id FROM smsinbox $sqlInbox";

           

            if ($outboxCtr == 0 && $inboxCtr == 0) {
                return;
            } else if ($outboxCtr !=0 && $inboxCtr == 0){
                $query = $queryOutbox;
            } else if ($outboxCtr ==0 && $inboxCtr != 0){
                $query = $queryInbox;
            } else {
                $query = $queryOutbox . "UNION " . $queryInbox . " ORDER BY timestamp desc LIMIT ".$searchLimit;
            }

            var_dump($query);
            $this->checkConnectionDB($query);
            $result = $this->dbconn->query($query);

            $ctr = 0;
            $dbreturn = "";
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {

                    if ($row['user'] === "You") {
                        $dbreturn[$ctr]['user'] = $row['user'];
                    } else {
                        // GET RECEPIENT ALIAS FOR INDIVIDUAL
                        $sqlTrimmedContact = "SELECT DISTINCT sim_num from smsinbox where timestamp like '%".$row['timestamp']."%' AND sms_msg='".$row['msg']."' UNION SELECT 'You' from smsoutbox where timestamp_written like '%".$row['timestamp']."%' AND sms_msg='".$row['msg']."'";
                        $this->checkConnectionDB($sqlTrimmedContact);
                        $trimmedContact = $this->dbconn->query($sqlTrimmedContact);
                        if ($trimmedContact->num_rows > 0) {
                            while ($trimmed = $trimmedContact->fetch_assoc()) {
                                if (strlen($trimmed['sim_num']) == 12){
                                   $contactTrimmed = substr($trimmed['sim_num'], 2);
                                } else if (strlen($trimmed['sim_num']) == 11){
                                    $contactTrimmed = substr($trimmed['sim_num'], 1);
                                } else {
                                    $contactTrimmed = $trimmed['sim_num'];
                                }
                            }
                        }


                        $sqlTargetIndividualAlias = "select firstname,lastname from dewslcontacts where numbers LIKE '%".$contactTrimmed."%'";
                        $this->checkConnectionDB($sqlTargetIndividualAlias);
                        $resultAlias = $this->dbconn->query($sqlTargetIndividualAlias);
                        if ($resultAlias->num_rows > 0) {
                            while ($rowIndiAlias = $resultAlias->fetch_assoc()) {
                                $dbreturn[$ctr]['user'] = $rowIndiAlias['firstname']." ".$rowIndiAlias['lastname'];
                                break;
                            }
                        } else {
                            // GET RECEPIENT ALIAS FOR Group
                            $sqlTargetGroupAlias = "select * from communitycontacts where number LIKE '%".$contactTrimmed."%'";
                            $this->checkConnectionDB($sqlTargetGroupAlias);
                            $resultGroupAlias = $this->dbconn->query($sqlTargetGroupAlias);
                            if ($resultGroupAlias->num_rows > 0) {
                                while ($rowGroupAlias = $resultGroupAlias->fetch_assoc()) {
                                  $dbreturn[$ctr]['user'] = $rowGroupAlias['sitename']." ".$rowGroupAlias['office']." ".$rowGroupAlias['prefix']." ".$rowGroupAlias['firstname']." ".$rowGroupAlias['lastname'];
                                   break;
                                }
                            } else {
                                $dbreturn[$ctr]['user'] = "Unknown - ".$row['user'];
                            }
                        }
                    }



                    $dbreturn[$ctr]['user_number'] = $row['user'];
                    $dbreturn[$ctr]['type'] = "searchGintags";
                    $dbreturn[$ctr]['recipients'] = $row['recipients'];
                    $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                    $dbreturn[$ctr]['timestamp_sent'] = $row['timestamp_sent'];
                    $dbreturn[$ctr]['msg'] = $row['msg'];
                    $dbreturn[$ctr]['sms_id'] = $row['sms_id'];
                    $ctr++;
                }
            } else {
                echo "NO RESULTS";
            }

            $msgData['data'] = $dbreturn;
            $msgData['type'] = "searchGintags";
            return $msgData;
        }
    }

    public function searchTimestampsent($fromDate,$toDate,$searchLimit = 100){
        $getSmsViaRange =  "SELECT sms_id,recepients as sim_num,sms_msg,timestamp_written as timestamp,timestamp_sent from smsoutbox WHERE timestamp_sent BETWEEN '$fromDate' AND '$toDate' ORDER by timestamp_sent DESC LIMIT ".$searchLimit;
        $this->checkConnectionDB($getSmsViaRange);
        $result = $this->dbconn->query($getSmsViaRange);
        $ctr = 0;
        $msgData = [];
        $dbreturn = [];
        $msgData['type'] = "searchedTimestampsent";

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['user'] = "You";
                $dbreturn[$ctr]['recipients'] = $this->getUserAlias($row['sim_num']);
                $dbreturn[$ctr]['sms_id'] = $row['sms_id'];
                $dbreturn[$ctr]['user_number'] = $row['sim_num'];
                $dbreturn[$ctr]['msg'] = $row['sms_msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                $dbreturn[$ctr]['timestamp_sent'] = $row['timestamp_sent'];
                $dbreturn[$ctr]['type'] = "searchedTimestampsent";
                $ctr++;
            }
        } else {
            echo "0 results\n";

        }
        $msgData['data'] = $dbreturn;
        $msgData['type'] = "searchedTimestampsent";
        return $msgData;
    }
    
    public function searchTimestampwritten($fromDate,$toDate,$searchLimit = 100){
        $getSmsViaRange =  "SELECT sms_id,recepients as sim_num,sms_msg,timestamp_written as timestamp,timestamp_sent from smsoutbox WHERE timestamp_written BETWEEN '$fromDate' AND '$toDate' ORDER by timestamp_written DESC LIMIT ".$searchLimit;
        $this->checkConnectionDB($getSmsViaRange);
        $result = $this->dbconn->query($getSmsViaRange);
        $ctr = 0;
        $msgData = [];
        $dbreturn = [];
        $msgData['type'] = "searchedTimestampwritten";

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['user'] = "You";
                $dbreturn[$ctr]['recipients'] = $this->getUserAlias($row['sim_num']);
                $dbreturn[$ctr]['sms_id'] = $row['sms_id'];
                $dbreturn[$ctr]['user_number'] = $row['sim_num'];
                $dbreturn[$ctr]['msg'] = $row['sms_msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                $dbreturn[$ctr]['timestamp_sent'] = $row['timestamp_sent'];
                $dbreturn[$ctr]['type'] = "searchedTimestampwritten";
                $ctr++;
            }
        } else {
            echo "0 results\n";

        }

        $msgData['data'] = $dbreturn;
        $msgData['type'] = "searchedTimestampwritten";
        return $msgData;
    }

    public function searchUnknownNumber($searchKey,$searchLimit = 100){
        $numberCollection = [];
        $searchedMessages = [];
        $dbreturn = [];
        // GET ALL KNOWN NUMBER
        $allNumbers = "SELECT DISTINCT number as number FROM communitycontacts UNION SELECT DISTINCT numbers as number FROM dewslcontacts;";
        $allNumbersRes = $this->dbconn->query($allNumbers);
        if ($allNumbersRes->num_rows > 0) {
            while ($row = $allNumbersRes->fetch_assoc()) {
                $cacheNumber = explode(",",$row['number']);
                foreach ($cacheNumber as $distinctNumber) {
                    if (trim($distinctNumber) != "") {
                        array_push($numberCollection,substr($distinctNumber,-10));
                    }
                }
            }
        } else {
            echo "No numbers found..";
            return $searchedMessages;
        }

        $ctr = 0;
        foreach ($numberCollection as $number) {
            if ($ctr == 0) {
                $inboxNumberQuery = "sim_num NOT LIKE '%$number%'";
                $ctr++;
            } else {
                $inboxNumberQuery = $inboxNumberQuery." OR sim_num NOT LIKE '%$number%'";
            }
        }

        $ctr = 0;
        foreach ($numberCollection as $number) {
            if ($ctr == 0) {
                $outboxNumberQuery = "recepients NOT LIKE '%$number%'";
                $ctr++;
            } else {
                $outboxNumberQuery = $outboxNumberQuery." OR recepients NOT LIKE '%$number%'";
            }
        }

        $getSearchedInbox = "SELECT sms_id as id, timestamp,null as timestamp_sent,sim_num as sender,'You' as recipient,sms_msg FROM smsinbox WHERE ($inboxNumberQuery) AND sms_msg LIKE '%$searchKey%'";
        $getSearchedOutbox = "SELECT sms_id as id,timestamp_written as timestamp,timestamp_sent,'You' as sender,recepients as recipient,sms_msg FROM smsoutbox WHERE ($outboxNumberQuery) AND sms_msg LIKE '%$searchKey%'";
        $getSearchedKey = $getSearchedInbox." UNION ".$getSearchedOutbox." ORDER BY timestamp desc LIMIT $searchLimit";
        $searchKeyRes = $this->dbconn->query($getSearchedKey);
        
        $ctr = 0;
        if ($searchKeyRes->num_rows > 0) {
            while ($row = $searchKeyRes->fetch_assoc()) {
                $dbreturn[$ctr]['user'] = $row['sender'];
                $dbreturn[$ctr]['recipients'] = $row['recipient'];
                $dbreturn[$ctr]['sms_id'] = $row['id'];
                $dbreturn[$ctr]['user_number'] = $row['recipient'];
                $dbreturn[$ctr]['msg'] = $row['sms_msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                $dbreturn[$ctr]['timestamp_sent'] = $row['timestamp_sent'];
                $dbreturn[$ctr]['type'] = "searchedUnknownNumber";
                $ctr++;
            }
        } else {
            echo "0 results\n";

        }

        $searchedMessages['data'] = $dbreturn;
        $searchedMessages['type'] = "searchedUnknownNumber";
        return $searchedMessages;
    }
    
    public function getUserAlias($sim_num) {
        $alias = "";
        if (strlen($sim_num) == 12){
           $contactTrimmed = substr($sim_num, 2);
        } else if (strlen($sim_num) == 11){
            $contactTrimmed = substr($sim_num, 1);
        } else {
            $contactTrimmed = $sim_num;
        }

        $getCommsUserAlias = "SELECT * FROM communitycontacts WHERE number LIKE '%".$contactTrimmed."%'";
        $commAliasResult = $this->dbconn->query($getCommsUserAlias);
        if ($commAliasResult->num_rows != 0) {
            while ($commRow = $commAliasResult->fetch_assoc()) {
                $alias = $commRow['sitename']." ".$commRow['office']." ".$commRow['prefix']." ".$commRow['firstname']." ".$commRow['lastname'];
            }
        } else {
            $getEmpUserAlias = "SELECT firstname,lastname FROM dewslcontacts WHERE numbers LIKE '%".$contactTrimmed."%'";
            $empAliasResult = $this->dbconn->query($getEmpUserAlias);
            if ($empAliasResult->num_rows == 0) {
                while ($empRow = $empAliasResult->fetch_assoc()) {
                   $alias = $empRow['firstname']." ".$empRow['lastname'];
                }
            } else {
                $alias = "Unknown - ".$sim_num;
            }
        }
        return $alias;
    }

    //Normalize a contact number
    public function normalizeContactNumber($contactNumber) {
        $countNum = strlen($contactNumber);
        //echo "num count = $countNum\n";

        //ex. 09168888888
        if ($countNum == 11) {
            $contactNumber = substr($contactNumber, 1);
        }
        //ex. 639168888888
        elseif ($countNum == 12) {
            $contactNumber = substr($contactNumber, 2);
        }

        return $contactNumber;
    }

    //Return the contact numbers from the group tags
    public function getContactNumbersFromGroupTags($offices = null, $sitenames = null,$ewi_filter = null) {
        $ctr = 0;

        $ctrOffices = 0;
        $subQueryOffices;
        if ($offices == null) {
            echo "Error: no office selected.";
            return -1;
        } else {
            foreach ($offices as $office) {
                if ($ctrOffices == 0) {
                    $subQueryOffices = "('$office'";
                } else {
                    $subQueryOffices = $subQueryOffices . ",'$office'";
                }
                $ctrOffices++;
            }

            $subQueryOffices = $subQueryOffices . ")";
        }
        echo "offices: $subQueryOffices \n";

        $ctrSites = 0;
        $subQuerySitenames;
        if ($sitenames == null) {
            echo "Error: no sitename selected.";
            return -1;
        } 
        else {
            foreach ($sitenames as $site) {
                if ($ctrSites == 0) {
                    $subQuerySitenames = "('$site'";
                } 
                else {
                    $subQuerySitenames = $subQuerySitenames . ",'$site'";
                }
                $ctrSites++;
            }

            $subQuerySitenames = $subQuerySitenames . ")";
        }
        echo "sitenames: $subQuerySitenames \n";

        $sql = '';
        

        //construct query for loading the numbers from the tags selected
        //  by the user
        if ($ewi_filter == "true") {
            $sqlTargetNumbers = "SELECT office, sitename, lastname, number 
                                FROM communitycontacts 
                                WHERE office in $subQueryOffices 
                                AND sitename in $subQuerySitenames AND ewirecipient = true";
        } else {
            $sqlTargetNumbers = "SELECT office, sitename, lastname, number 
                    FROM communitycontacts 
                    WHERE office in $subQueryOffices 
                    AND sitename in $subQuerySitenames";
        }

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sqlTargetNumbers);
        $result = $this->dbconn->query($sqlTargetNumbers);
        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $numbers = explode(",", $row['number']);
                foreach ($numbers as $number) {
                    $dbreturn[$ctr]['office'] = $row['office'];
                    $dbreturn[$ctr]['sitename'] = $row['sitename'];
                    $dbreturn[$ctr]['number'] = trim($number);

                    $ctr = $ctr + 1;
                }
            }

            $contactInfoData['data'] = $dbreturn;
        }
        else {
            echo "0 numbers found\n";
            $contactInfoData['data'] = null;
        }

        return $contactInfoData;
    }

    public function getEwiRecepients($offices,$sitenames){
        $ctr = 0;
        $dbreturn = "";
        $sqlTargetNumbers = "SELECT office, sitename, lastname,firstname, number, ewirecipient
                            FROM communitycontacts 
                            WHERE office in $offices
                            AND sitename in $sitenames";
        $this->checkConnectionDB($sqlTargetNumbers);
        $result = $this->dbconn->query($sqlTargetNumbers);
        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $numbers = explode(",", $row['number']);
                    if ($row['ewirecipient'] == NULL) {
                        foreach ($numbers as $number) {
                            $dbreturn[$ctr]['office'] = $row['office'];
                            $dbreturn[$ctr]['sitename'] = $row['sitename'];
                            $dbreturn[$ctr]['lastname'] = (string)$row['lastname'];
                            $dbreturn[$ctr]['firstname'] = (string)$row['firstname'];
                            $dbreturn[$ctr]['number'] = $number;
                            $dbreturn[$ctr]['ewirecipient'] = $row['ewirecipient'];
                            $ctr = $ctr + 1;
                            $resultData['type'] = "hasNullEWIRecipient";
                            $resultData['hasNull'] = true;
                        }
                    } else {
                            $resultData['hasNull'] = false;
                    }
            }
        } else {
            echo "0 numbers found\n";
        }
        $resultData['data'] = $dbreturn;
        return $resultData;
    }

    public function updateEwiRecipients($type,$data){
        $sql = "";
        $site = [];
        $office = [];
        $numbers = [];
        foreach ($data as $info) {

            if (count($site) == 0 && count($office) == 0 ) {
                array_push($site,$info->sitename);
                array_push($office,$info->office);
            }

            for ($i = 0; $i < sizeof($site);$i++){
                if ($site[$i] != $info->sitename) {
                    array_push($site,$info->sitename);
                }
            }

            for ($x = 0; $x < sizeof($office);$x++){
                if ($office[$x] !=  $info->office) {
                    array_push($office,$info->office);
                }
            }
        }

        $ctr = 0;
        $ctrSites = 0;
        $filterSitenames;

        foreach ($site as $site) {
            if ($ctrSites == 0) {
                $filterSitenames = "('$site'";
            } 
            else {
                $filterSitenames = $filterSitenames . ",'$site'";
            }
            $ctrSites++;
        }
        $filterSitenames = $filterSitenames . ")";

        // UPDATES ALL THE CONTACTS EWI RECIPIENTS TO FALSE.
        $sql = "UPDATE communitycontacts SET ewirecipient = false WHERE sitename IN $filterSitenames";
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        // UPDATES SELECTED CONTACTS EWI RECIPIENTS TO TRUE.
        foreach ($data as $info) {
            array_push($numbers,$info->number);
        }

        for ($i = 0; $i < sizeof($numbers); $i++) { 
            if ($i == 0) {
                $target = "number LIKE '%$numbers[$i]%' ";
            } else {
                $target = $target . "OR number LIKE '%$numbers[$i]%' ";
            }
        }

        $sql = "UPDATE communitycontacts SET ewirecipient = true WHERE $target";
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);
        $data['type'] = "resumeLoading";
        return $data;

    }

    //Return the message exchanges between Chatterbox and a group
    public function getMessageExchangesFromGroupTags($offices = null, $sitenames = null,$type = null,$lastTimeStamps = null, $limit = 70) {
        $ctr = 0;

        $tagsCombined = "";

        $ctrOffices = 0;
        $subQueryOffices;
        if ($offices == null) {
            echo "Error: no office selected.";
            return -1;
        } 
        else {
            foreach ($offices as $office) {
                if ($ctrOffices == 0) {
                    $subQueryOffices = "('$office'";
                } else {
                    $subQueryOffices = $subQueryOffices . ",'$office'";
                }

                $tagsCombined = "$tagsCombined $office";
                $ctrOffices++;
            }

            $subQueryOffices = $subQueryOffices . ")";
        }
        echo "offices: $subQueryOffices \n";

        $ctrSites = 0;
        $subQuerySitenames;
        if ($sitenames == null) {
            echo "Error: no sitename selected.";
            return -1;
        } 
        else {
            foreach ($sitenames as $site) {
                if ($ctrSites == 0) {
                    $subQuerySitenames = "('$site'";
                } 
                else {
                    $subQuerySitenames = $subQuerySitenames . ",'$site'";
                }

                $tagsCombined = "$tagsCombined $site";
                $ctrSites++;
            }

            $subQuerySitenames = $subQuerySitenames . ")";
        }
        echo "sitenames: $subQuerySitenames \n";

        $sql = '';

        $result = $this->getEwiRecepients($subQueryOffices,$subQuerySitenames);

        if ($result['hasNull'] == true) {
            return $result;
        }

        //TODO: construct query for loading the numbers from the tags selected
        //  by the user
        $sqlTargetNumbers = "SELECT office, sitename, lastname, number 
                            FROM communitycontacts 
                            WHERE office in $subQueryOffices 
                            AND sitename in $subQuerySitenames";

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sqlTargetNumbers);
        $result = $this->dbconn->query($sqlTargetNumbers);
        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $numbers = explode(",", $row['number']);

                foreach ($numbers as $number) {
                    $dbreturn[$ctr]['office'] = $row['office'];
                    $dbreturn[$ctr]['sitename'] = $row['sitename'];
                    $dbreturn[$ctr]['number'] = $number;

                    $ctr = $ctr + 1;
                }
            }

            $contactInfoData['data'] = $dbreturn;
        }
        else {
            echo "0 numbers found\n";
            $contactInfoData['data'] = null;
        }

        //echo "JSON output ($ctr): " . json_encode($contactInfoData);

        //Construct the query for loading messages from multiple numbers
        $num_numbers = sizeof($contactInfoData['data']);
        if ($num_numbers >= 1) {
            for ($i = 0; $i < $num_numbers; $i++) { 
                $targetNum = $this->normalizeContactNumber($contactInfoData['data'][$i]['number']);
                $contactInfoData['data'][$i]['number'] = $targetNum;

                if ($i == 0) {
                    $sqlTargetNumbersOutbox = "recepients LIKE '%$targetNum' ";
                    $sqlTargetNumbersInbox = "sim_num LIKE '%$targetNum' ";
                } else {
                    $sqlTargetNumbersOutbox = $sqlTargetNumbersOutbox . "OR recepients LIKE '%$targetNum' ";
                    $sqlTargetNumbersInbox = $sqlTargetNumbersInbox . "OR sim_num LIKE '%$targetNum' ";
                }
            }
        } else {
            $sqlTargetNumbersOutbox = " ";
            $sqlTargetNumbersInbox = " ";
        }

        if ($type == "oldMessageGroup"){

            $timeStampArray = explode(',', $lastTimeStamps);
            $yourLastTimeStamp = $timeStampArray[0];
            $groupLastTimeStamp = $timeStampArray[1];

            if ($yourLastTimeStamp == ""){
                $fetchTimeStamp = "select timestamp_written from smsoutbox where $sqlTargetNumbersOutbox order by timestamp_written desc";
                $this->checkConnectionDB($fetchTimeStamp);
                $result = $this->dbconn->query($fetchTimeStamp);
                $yourLastTimeStamp = $result->fetch_assoc()['timestamp']; 
            }

            if ($groupLastTimeStamp == "") {
                $fetchTimeStamp = "select timestamp from smsinbox where $sqlTargetNumbersInbox order by timestamp desc";
                $this->checkConnectionDB($fetchTimeStamp);
                $result = $this->dbconn->query($fetchTimeStamp);
                $groupLastTimeStamp = $result->fetch_assoc()['timestamp']; 
            }

            $sqlOutbox = "SELECT DISTINCT 'You' as user, sms_msg as msg, timestamp_written as timestamp ,timestamp_sent as timestampsent,timestamp_written.sms_id,send_status FROM smsoutbox timestamp_written inner join (select sms_id from smsoutbox where timestamp_written = '$yourLastTimeStamp' order by sms_id limit 1) x on timestamp_written.sms_id < x.sms_id WHERE $sqlTargetNumbersOutbox GROUP BY (timestamp)";


            $sqlInbox = "SELECT DISTINCT sim_num as user, sms_msg as msg,timestamp as timestamp ,null as timestampsent,timestamp.sms_id,'SUCCESS' as send_status FROM smsinbox timestamp inner join (select sms_id from smsinbox where timestamp = '$groupLastTimeStamp' order by sms_id limit 1) x on timestamp.sms_id < x.sms_id WHERE $sqlTargetNumbersInbox ";

            $sql = $sqlOutbox."UNION ".$sqlInbox."ORDER BY timestamp desc LIMIT $limit";

        } else {
            //Construct the final query
            $sqlOutbox = "SELECT DISTINCT 'You' as user, sms_msg as msg, 
                            timestamp_written as timestamp, timestamp_sent as timestampsent,sms_id,send_status
                        FROM smsoutbox WHERE $sqlTargetNumbersOutbox AND timestamp_written IS NOT NULL GROUP BY (timestamp)";
  
            $sqlInbox = "SELECT DISTINCT sim_num as user, sms_msg as msg,
                            timestamp as timestamp, 'SUCCESS' as timestamp_sent,sms_id,'SUCCESS' as send_status
                        FROM smsinbox WHERE $sqlTargetNumbersInbox AND timestamp IS NOT NULL ";

            $sql = $sqlOutbox . "UNION " . $sqlInbox . " ORDER BY timestamp desc LIMIT $limit";
        }

        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
            if ($type == "oldMessageGroup"){
            $msgData['type'] = 'oldMessageGroup';
        } else {
            $msgData['type'] = 'smsloadrequestgroup';
        }

        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['sms_id'] = $row['sms_id'];
                if ($row['user'] == "You") {
                    $dbreturn[$ctr]['table_used'] = "smsoutbox";
                } else {
                    $dbreturn[$ctr]['network'] = $this->identifyMobileNetwork($row['user']);
                    $dbreturn[$ctr]['table_used'] = "smsinbox";
                }
                $dbreturn[$ctr]['user'] = $row['user'];
                $dbreturn[$ctr]['msg'] = $row['msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                $dbreturn[$ctr]['timestamp_sent'] = $row['timestampsent'];
                $dbreturn[$ctr]['status'] = $row['send_status'];

                //Normalize the user's number
                $normalized = $this->normalizeContactNumber($dbreturn[$ctr]['user']);

                //Add "office" and "sitename" data using the "contactInfoData" array
                foreach ($contactInfoData['data'] as $singleContact) {
                    if ($singleContact['number'] == $normalized) {
                        $dbreturn[$ctr]['name'] = $singleContact['sitename'] . " " . $singleContact['office'];
                    }
                }

                if ($dbreturn[$ctr]['user'] == "You") {
                    $dbreturn[$ctr]['name'] = $tagsCombined;
                }

                $ctr = $ctr + 1;
            }

            for ($x = 0; $x < sizeof($dbreturn);$x++) {
                if ($x == 0) {
                    $ids = "table_element_id = '".$dbreturn[$x]["sms_id"]."' ";
                } else {
                    $ids = $ids."OR table_element_id = '".$dbreturn[$x]["sms_id"]."' ";
                }
            }

            $query = "SELECT table_element_id FROM gintags WHERE ".$ids."";
            // Make sure the connection is still alive, if not, try to reconnect 
            $this->checkConnectionDB($query);
            $result = $this->dbconn->query($query);
            $idCollection = [];
            if ($result->num_rows > 0) {
                 while ($row = $result->fetch_assoc()) {
                    array_push($idCollection,$row["table_element_id"]);
                 }
            }

            for ($x = 0; $x < sizeof($dbreturn); $x++) {
                for ($y = 0; $y < sizeof($idCollection); $y++) {
                    if ($dbreturn[$x]["sms_id"] == $idCollection[$y]) {
                        $dbreturn[$x]["hasTag"] = 1;
                        break;
                    } else {
                        $dbreturn[$x]["hasTag"] = 0;
                    }
                }
            }

            $msgData['data'] = $dbreturn;
        }
        else {
            echo "0 results\n";
            $msgData['data'] = null;
        }

        return $msgData;
    }

    public function getEmpTagNumbers($data){
        $e_ctr = 0;

        foreach ($data as $team_tag) {
            $ttag = "SELECT DISTINCT numbers,grouptags FROM dewslcontacts WHERE grouptags LIKE '%$team_tag%'";
            $this->checkConnectionDB($ttag);
            $res = $this->dbconn->query($ttag);

            if ($res->num_rows > 0) {
                while ($row = $res->fetch_assoc()){
                    $temp = "";
                    if (strlen($row['numbers']) == 9) {
                        $emptag[$e_ctr]['tags'] = $row['grouptags'];
                        $emptag[$e_ctr]['number'] = "63".$row['numbers'];
                    } else if (strlen($row['numbers']) == 11) {
                        $emptag[$e_ctr]['tags'] = $row['grouptags'];
                        $emptag[$e_ctr]['number'] = "63".substr($row['numbers'],1);
                    } else if (strlen($row['numbers']) > 12){
                        $numbers = explode(",", $row['numbers']);
                        $temp = $e_ctr;
                        foreach ($numbers as $number) {
                            $emptag[$temp]['tags'] = $row['grouptags'];
                            $emptag[$temp]['number'] = "63".substr($number,1);
                            $temp = $temp+1;
                        }

                    } else {
                         $emptag[$e_ctr]['number']  = $row['numbers'];
                    }
                    if ($temp != "" || $temp != NULL) {
                        $e_ctr = $temp;
                    } else {
                        $e_ctr = $e_ctr+1;
                    }
                    $employeeTags = $emptag;  
                }
            }
        }
        return $employeeTags;
    }

    public function getMessageExchangesFromEmployeeTags($type = null,$data = null,$limit = 70){
        $ctr = 0;
        $ctrTags = 0;
        $employeeTags = [];
        $employeeTargetNumber = [];

        $employeeTags = $this->getEmpTagNumbers($data);

        foreach ($data as $tag) {
            if ($ctrTags == 0) {
                $sqlTargetNumbersPerTag = "grouptags LIKE '%$tag%' ";
            } else {
                $sqlTargetNumbersPerTag = $sqlTargetNumbersPerTag . "OR grouptags LIKE '%$tag%' ";
            }
            $ctrTags++;
        }

        $sqlTargetNumbers = "SELECT DISTINCT numbers,grouptags FROM dewslcontacts WHERE ".$sqlTargetNumbersPerTag;
        $this->checkConnectionDB($sqlTargetNumbers);
        $result = $this->dbconn->query($sqlTargetNumbers);

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $targetEmpNumbers = "";
                if (strlen($row['numbers']) == 9) {
                    $targetEmpNumbers = "63".$row['numbers'];
                } else if (strlen($row['numbers']) == 11) {
                    $targetEmpNumbers = "63".substr($row['numbers'],1);
                } else if (strlen($row['numbers']) > 12){
                    $numbers = explode(",", $row['numbers']);
                    foreach ($numbers as $number) {
                        array_push($employeeTargetNumber, "63".substr($number,1));
                    }
                } else {
                    $targetEmpNumbers = $row['numbers'];
                }
                if ($targetEmpNumbers != "") {
                  array_push($employeeTargetNumber, $targetEmpNumbers);                  
                }
                    $dbreturn[$ctr]['tag'] = $row['grouptags'];
            }
            $contactInfoData['data'] = $dbreturn;
        }
        else {
            echo "0 numbers found\n";
            $contactInfoData['data'] = null;
        }

        $num_numbers = sizeof($employeeTargetNumber);
        if ($num_numbers >= 1) {
            for ($i = 0; $i < $num_numbers; $i++) { 
                if ($i == 0) {
                    $sqlTargetNumbersOutbox = "recepients LIKE '%$employeeTargetNumber[$i]' ";
                    $sqlTargetNumbersInbox = "sim_num LIKE '%$employeeTargetNumber[$i]' ";
                } else {
                    $sqlTargetNumbersOutbox = $sqlTargetNumbersOutbox . "OR recepients LIKE '%$employeeTargetNumber[$i]' ";
                    $sqlTargetNumbersInbox = $sqlTargetNumbersInbox . "OR sim_num LIKE '%$employeeTargetNumber[$i]' ";
                }
            }
        } else {
            $sqlTargetNumbersOutbox = " ";
            $sqlTargetNumbersInbox = " ";
        }

        //Construct the final query
        $sqlOutbox = "SELECT DISTINCT 'You' as user, sms_msg as msg, 
                        timestamp_written as timestamp, timestamp_sent as timestampsent,send_status
                    FROM smsoutbox WHERE $sqlTargetNumbersOutbox AND timestamp_written IS NOT NULL ";

        $sqlInbox = "SELECT DISTINCT sim_num as user, sms_msg as msg,
                        timestamp as timestamp, null as timestampsent,'SUCCESS' as send_status
                    FROM smsinbox WHERE $sqlTargetNumbersInbox AND timestamp IS NOT NULL ";

        $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp desc LIMIT $limit";

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);
        $msgData['type'] = 'loadEmployeeTag';

        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                if ($row['user'] == "You") {
                    $dbreturn[$ctr]['user'] = $row['user'];
                } else {
                    for ($x = 0;$x < sizeof($employeeTags);$x++) {
                        if ($employeeTags[$x]['number'] == $row['user']) {
                            $dbreturn[$ctr]['user'] = strtoupper($employeeTags[$x]['tags']);
                        }
                    }
                }

                $dbreturn[$ctr]['msg'] = $row['msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                $dbreturn[$ctr]['timestamp_sent'] = $row['timestampsent'];
                $dbreturn[$ctr]['status'] = $row['send_status'];
                $dbreturn[$ctr]['type'] = "loadEmployeeTag";

                $ctr = $ctr + 1;
            }

            $msgData['data'] = $dbreturn;
        }
        else {
            echo "0 results\n";
            $msgData['data'] = null;
        }

        return $msgData;
    }

    public function getArraySize($arr) {
        $tot = 0;
        foreach($arr as $a) {
            if (is_array($a)) {
                $tot += $this->getArraySize($a);
            }
            if (is_string($a)) {
                $tot += strlen($a);
            }
            if (is_int($a)) {
                $tot += PHP_INT_SIZE;
            }
        }
        return $tot;
    }

    public function getCommunityContact($sitename, $office) {
        echo "Come here\n\n";
        echo $sitename;
        echo $office;
        
        if ( ($office == "all") || ($office == null) ) {
            $sql = "SELECT
                        CONCAT(sitename, ' ', office, ' ', prefix, ' ', firstname, ' ', lastname) as fullname,
                        number as numbers
                    FROM communitycontacts
                    WHERE sitename like '%$sitename%'
                    ORDER BY fullname";
        } else {
            $sql = "SELECT
                        CONCAT(sitename, ' ', office, ' ', prefix, ' ', firstname, ' ', lastname) as fullname,
                        number as numbers
                    FROM communitycontacts
                    WHERE sitename like '%$sitename%' AND office = '$office'
                    ORDER BY fullname";
        }

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadcommunitycontact';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";

            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['fullname'] = $this->convertNameToUTF8($row['fullname']);
                $dbreturn[$ctr]['numbers'] = $row['numbers'];

                $ctr = $ctr + 1;
            }

            $fullData['data'] = $dbreturn;
            echo "data size: " . $this->getArraySize($dbreturn);
        }
        else {
            echo "0 results\n";
            $fullData['data'] = null;
        }

        //echo json_encode($fullData);
        return $fullData;
    }

    public function getContactsFromName($queryName) {
        $sql = "SELECT * FROM
                    (SELECT
                        CONCAT(sitename, ' ', office, ' ', prefix, ' ', firstname, ' ', lastname) as fullname,
                        number as numbers
                    FROM communitycontacts
                    UNION
                    SELECT 
                        CONCAT(firstname, ' ', lastname) as fullname, 
                        numbers
                    FROM dewslcontacts
                    ORDER BY fullname) as fullcontacts
                WHERE
                    fullname LIKE '%$queryName%'";

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadcommunitycontact';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";

            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['fullname'] = $this->convertNameToUTF8($row['fullname']);
                $dbreturn[$ctr]['numbers'] = $row['numbers'];

                $ctr = $ctr + 1;
            }

            $fullData['data'] = $dbreturn;
            echo "data size: " . $this->getArraySize($dbreturn);
        }
        else {
            echo "0 results\n";
            $fullData['data'] = null;
        }

        //echo json_encode($fullData);
        return $fullData;
    }

    //Get contact name from number
    public function getNameFromNumber($contactNumber) {
        //normalize the number
        $normalized = $this->normalizeContactNumber($contactNumber);
        $sitesOnEvent = $this->getLatestAlerts();

        //TODO: create query to get name from the contact number if it exists
        $sql = "SELECT * FROM 
                    (SELECT
                        CONCAT(sitename, ' ', office, ' ', prefix, ' ', firstname, ' ', lastname) as fullname,
                        number as numbers
                    FROM communitycontacts
                    UNION
                    SELECT
                        CONCAT(firstname, ' ', lastname) as fullname, 
                        numbers
                    FROM dewslcontacts) as contactNames
                WHERE
                    numbers LIKE '%$normalized%'";
        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);
        $temp_site = [];
        $temp_name = "";
        $dbreturn = [];
        if ($result->num_rows > 0) {
            $ctr = 0;
            // output data of each row
            while ($row = $result->fetch_assoc()) {

                if ($ctr == 0) {
                    $dbreturn['fullname'] = $row['fullname'];
                    $site_select = explode(" ",$row['fullname']);
                    array_push($temp_site,$site_select[0]);
                } else {
                    $sub_counter = 0;
                    $site_select = explode(" ",$row['fullname']);
                    array_push($temp_site,$site_select[0]);
                }
                $ctr++;
            }

            $raw_name = explode(" ",$dbreturn['fullname']);
            foreach ($sitesOnEvent['data'] as $siteEvent) {
                if (strtoupper($raw_name[0]) == strtoupper($siteEvent['name'])) {
                    $dbreturn['onevent'] = 1;
                    break;
                } else {
                    $dbreturn['onevent'] = 0;
                }
            }

            if (sizeof($temp_site) != 0) {
                $sites = "";
                for ($counter = 0; $counter < sizeof($temp_site); $counter++) {
                    if ($counter == 0) {
                        $sites = $temp_site[$counter];
                    } else {
                        $sites = $sites.",".$temp_site[$counter];
                    }
                }

                if ($temp_name == "") {
                    $raw_name = explode(" ",$dbreturn['fullname']);
                    for ($counter = 1; $counter < sizeof($raw_name); $counter++) {
                        $temp_name = $temp_name." ".$raw_name[$counter];
                    }
                }

                if (sizeof($temp_site) == 1) {
                    $dbreturn['fullname'] = $sites." ".trim($temp_name," ");
                } else {
                    $dbreturn['fullname'] = "[".$sites."] ".trim($temp_name," ");
                }
            }
            
            echo "data size: " . $this->getArraySize($dbreturn);
        } else {
            echo "0 results\n";
            $dbreturn['fullname'] = "unknown";
        }

        echo json_encode($dbreturn);
        return $dbreturn;
    }

    public function getContactSuggestions($queryName) {
        $sql = "SELECT * FROM
                    (SELECT
                        CONCAT(sitename, ' ', office, ' ', prefix, ' ', firstname, ' ', lastname) as fullname,
                        number as numbers
                    FROM communitycontacts
                    UNION
                    SELECT 
                        CONCAT(firstname, ' ', lastname) as fullname, 
                        numbers
                    FROM dewslcontacts
                    ORDER BY fullname) as fullcontacts
                WHERE
                    fullname LIKE '%$queryName%'
                    OR numbers LIKE '%$queryName%'";

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadnamesuggestions';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";

            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['fullname'] = $this->convertNameToUTF8($row['fullname']);
                $dbreturn[$ctr]['numbers'] = $row['numbers'];

                $ctr = $ctr + 1;
            }

            $dbreturn = $this->utf8_encode_recursive($dbreturn);

            $fullData['data'] = $dbreturn;
            echo "data size: " . $this->getArraySize($dbreturn);
        }
        else {
            echo "0 results\n";
            $fullData['data'] = null;
        }

        //echo json_encode($fullData);
        return $fullData;
    }

    public function getNameSuggestions($queryName) {
        $sql = "SELECT * FROM
                    (SELECT
                        CONCAT(sitename, ' ', office, ' ', prefix, ' ', firstname, ' ', lastname) as fullname
                    FROM communitycontacts
                    UNION
                    SELECT 
                        CONCAT(firstname, ' ', lastname) as fullname
                    FROM dewslcontacts
                    ORDER BY fullname) as fullcontacts
                WHERE
                    fullname LIKE '%$queryName%'
                    OR numbers LIKE '%$queryName%'";

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadnamesuggestions';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";

            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr] = $this->convertNameToUTF8($row['fullname']);

                $ctr = $ctr + 1;
            }

            $fullData['data'] = $dbreturn;
            echo "data size: " . $this->getArraySize($dbreturn);
        }
        else {
            echo "0 results\n";
            $fullData['data'] = null;
        }

        //echo json_encode($fullData);
        return $fullData;
    }

    //Return the normalized contact list for both DEWSL and Community
    //currently exceeds the web socket bandwidth (9115 bytes)
    public function getAllContactsList() {
        $sql = "SELECT
                    CONCAT(sitename, ' ', office, ' ', prefix, ' ', firstname, ' ', lastname) as fullname,
                    number as numbers
                FROM communitycontacts
                UNION
                SELECT 
                    CONCAT(firstname, ' ', lastname) as fullname, 
                    numbers
                FROM dewslcontacts
                ORDER BY fullname";

        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadcontacts';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";

            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['fullname'] = $row['fullname'];
                $dbreturn[$ctr]['numbers'] = $row['numbers'];

                $ctr = $ctr + 1;
            }

            $fullData['data'] = $dbreturn;
            echo "data size: " . $this->getArraySize($dbreturn);
        }
        else {
            echo "0 results\n";
            $fullData['data'] = null;
        }

        //echo json_encode($fullData);
        return $fullData;
    }

    //This will only be called only one time and only if the user clicks
    //  on the "advanced search option"
    public function getAllOfficesAndSites() {
        $fullData['type'] = 'loadofficeandsites';

        //Get the list of offices from the community contacts list
        $sqlOffices = "SELECT DISTINCT office FROM communitycontacts";
        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sqlOffices);
        $result = $this->dbconn->query($sqlOffices);

        $ctr = 0;
        $returnOffices = "";

        if ($result->num_rows > 0) {
            $fullData['total_offices'] = $result->num_rows;
            echo $result->num_rows . " results\n";

            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $returnOffices[$ctr] = $row['office'];
                $ctr = $ctr + 1;
            }

            $fullData['offices'] = $returnOffices;
            echo "Offices data size: " . $this->getArraySize($returnOffices) . "\n";
        }
        else {
            echo "0 results for offices\n";
            $fullData['offices'] = null;
        }

        //Get the list of sitenames from the community contacts list
        $sqlSitenames = "SELECT DISTINCT sitename FROM communitycontacts order by sitename asc";
        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($sqlSitenames);
        $result = $this->dbconn->query($sqlSitenames);

        $ctr = 0;
        $returnSitenames = "";

        if ($result->num_rows > 0) {
            $fullData['total_sites'] = $result->num_rows;
            echo $result->num_rows . " results\n";

            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $returnSitenames[$ctr] = $row['sitename'];

                $ctr = $ctr + 1;
            }

            $fullData['sitenames'] = $returnSitenames;
            echo "Sitenames data size: " . $this->getArraySize($returnSitenames) . "\n";
        }
        else {
            echo "0 results for sitenames\n";
            $fullData['sitenames'] = null;
        }

        // echo json_encode($fullData);
        return $fullData;
    }

    function getGroundMeasurementReminderTemplate() {
        $template_query = "SELECT template FROM ewi_backbone_template WHERE alert_status = 'GndMeasReminder'";
        $this->checkConnectionDB($template_query);
        $result = $this->dbconn->query($template_query);
        return $result->fetch_assoc();
    }

    function checkForGndMeasSettings($time) {
        $settings_container = [];
        $template_query = "SELECT * FROM ground_meas_reminder_automation WHERE status = 0 and timestamp = '".$time."' order by site";
        $this->checkConnectionDB($template_query);
        $result = $this->dbconn->query($template_query);
        while ($row = $result->fetch_assoc()) {
            array_push($settings_container, $row);
        }
        return $settings_container;
    }

    function flagGndMeasSettingsSentStatus() {
        $overwrite_query = "UPDATE ground_meas_reminder_automation SET status = 1 WHERE status = 0";
        $this->checkConnectionDB($template_query);
        $result = $this->dbconn->query($template_query);
    }

    function insertGndMeasReminderSettings($site, $type, $template, $altered, $modified_by) {
        if (strtotime(date('H:m:i A')) > strtotime('7:30 AM') && strtotime(date('H:m:i A')) < strtotime('11:30 AM')) {
            $ground_time = '11:30 AM';
        } else if (strtotime(date('H:m:i A')) > strtotime('11:30 AM') && strtotime(date('H:m:i A')) < strtotime('2:30 PM')) {
            $ground_time = '2:30 PM';
        } else {
            $ground_time = '7:30 AM';
        }
        $template_query = "INSERT INTO ground_meas_reminder_automation VALUES (0,'".$type."','".$template."', 'LEWC', '".$site."','".$altered."','".$ground_time."',0, '".$modified_by."')";
        $this->checkConnectionDB($template_query);
        $result = $this->dbconn->query($template_query);
        return $result; 
    }

    function routineSites() {
        $sites_cant_send_gndmeas = $this->getGroundMeasurementsForToday();
        $sql = "SELECT DISTINCT name,status from site INNER JOIN public_alert_event ON site.id=public_alert_event.site_id WHERE public_alert_event.status <> 'routine' AND public_alert_event.status <> 'finished' AND public_alert_event.status <> 'invalid' order by name";
        $result = $this->dbconn->query($sql);
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


        $sql = "SELECT name,season from site";
        $result = $this->dbconn->query($sql);
        $sites_on_routine = [];
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
                for ($routine_counter = 0; $routine_counter < sizeof($on_routine['sitename']); $routine_counter++) {
                    if (in_array($month,$dry[(int) ($on_routine['season'][$routine_counter]-1)])) {
                        array_push($sites_on_routine, $on_routine['sitename'][$routine_counter]);
                    }
                }
                break;
            case 'Tuesday':
            case 'Friday':
                for ($routine_counter = 0; $routine_counter < sizeof($on_routine['sitename']); $routine_counter++) {
                    if (in_array($month,$wet[(int) ($on_routine['season'][$routine_counter]-1)])){
                        array_push($sites_on_routine, $on_routine['sitename'][$routine_counter]);
                    }
                }
                break;
        }
        $final_sites = [];
        foreach ($sites_on_routine as $rtn_site) {
            if (sizeOf($sites_cant_send_gndmeas) > 0) {
                foreach ($sites_cant_send_gndmeas as $cant_send) {
                   if (strtoupper($rtn_site) != $cant_send) {
                        array_push($final_sites, $rtn_site);
                   }
                }
            } else {
                $final_sites = $sites_on_routine;
            }
        }
        $temp = [];

        foreach (array_unique($final_sites) as $site) {
            array_push($temp, $site);
        }

        return $temp;
    }

    function eventSites() {
        $sites_cant_send_gndmeas = $this->getGroundMeasurementsForToday();
        $event_sites_query = "SELECT DISTINCT name,status,public_alert_release.event_id from site INNER JOIN public_alert_event ON site.id=public_alert_event.site_id INNER JOIN public_alert_release ON public_alert_event.event_id = public_alert_release.event_id WHERE public_alert_event.status <> 'routine' AND public_alert_event.status <> 'finished' AND public_alert_event.status <> 'invalid' AND public_alert_event.status <> 'extended' and public_alert_release.internal_alert_level NOT LIKE 'A3%' order by name";

        $sites_with_stepup_alert = "SELECT DISTINCT name,status,public_alert_release.event_id from site INNER JOIN public_alert_event ON site.id=public_alert_event.site_id INNER JOIN public_alert_release ON public_alert_event.event_id = public_alert_release.event_id WHERE public_alert_event.status = 'on-going' and public_alert_release.internal_alert_level like '%A3%' order by name;";

        $alert_three = [];
        $result = $this->dbconn->query($sites_with_stepup_alert);
        while ($row = $result->fetch_assoc()) {
            array_push($alert_three, $row['name']);
        }

        $event_sites = [];
        $this->checkConnectionDB($event_sites_query);
        $result = $this->dbconn->query($event_sites_query);
        while ($row = $result->fetch_assoc()) {
            array_push($event_sites, $row);
        }

        $final_sites = [];
        foreach ($event_sites as $evt_site) {
            if (sizeOf($sites_cant_send_gndmeas) > 0) {
                foreach ($sites_cant_send_gndmeas as $cant_send) {
                   if (strtoupper($evt_site['name']) != strtoupper($cant_send)) {
                        array_push($final_sites, $evt_site);
                   }
                }
            } else {
                $final_sites = $event_sites;
            }
        }

        $temp_sites = [];
        foreach ($final_sites as $evt_site) {
            if (sizeOf($alert_three) > 0) {
                foreach ($alert_three as $cant_send) {
                   if (strtoupper($evt_site['name']) != strtoupper($cant_send)) {
                        array_push($temp_sites, $evt_site);
                   }
                }
            } else {
                $temp_sites = $event_sites;
            }
        }


        $temp = [];
        foreach (array_unique($temp_sites,SORT_REGULAR) as $site) {
            array_push($temp, $site);
        }
        return $temp;
    }

    function extendedSites() {
        $extended_sites = [];
        $sites_cant_send_gndmeas = $this->getGroundMeasurementsForToday();
        $extended_sites_query = "SELECT site.name,public_alert_event.validity from site INNER JOIN public_alert_event ON site.id=public_alert_event.site_id WHERE public_alert_event.status = 'extended' order by site.name";
        $this->checkConnectionDB($extended_sites_query);
        $result = $this->dbconn->query($extended_sites_query);
        $current_date = strtotime(date("Y/m/d"));
        while($row = $result->fetch_assoc()) {
            $secs = $current_date - strtotime($row['validity']);
            $days = $secs / 86400;
            if ($days > 0.6) {
                array_push($extended_sites, $row['name']); 
            }
        }
        $final_sites = [];
        foreach ($extended_sites as $extnd_site) {
            if (sizeOf($sites_cant_send_gndmeas) > 0) {
                foreach ($sites_cant_send_gndmeas as $cant_send) {
                   if (strtoupper($extnd_site) != $cant_send) {
                        array_push($final_sites, $extnd_site);
                   }
                }
            } else {
                $final_sites = $extended_sites;
            }
        }

        $temp = [];
        foreach (array_unique($final_sites) as $site) {
            array_push($temp, $site);
        }
        return $temp;
    }

    function getGroundMeasurementsForToday() {
        $current_date = date('Y-m-d H:i A').'11:30 AM';

        if (strtotime(date('H:m:i A')) > strtotime('7:30 AM') && strtotime(date('H:m:i A')) < strtotime('11:30 AM')) {
            $ground_time = '11:30 AM';
            $current_date = date_format(date_sub(date_create(date('Y-m-d ').$ground_time),date_interval_create_from_date_string("4 hours")),"Y-m-d H:i:s");
        } else if (strtotime(date('H:m:i A')) > strtotime('11:30 AM') && strtotime(date('H:m:i A')) < strtotime('2:30 PM')) {
            $ground_time = '2:30 PM';
            $current_date = date_format(date_sub(date_create(date('Y-m-d ').$ground_time),date_interval_create_from_date_string("4 hours")),"Y-m-d H:i:s");
        } else {
            $ground_time = '7:30 AM';
            $current_date = date_format(date_sub(date_create(date('Y-m-d ').$ground_time),date_interval_create_from_date_string("4 hours")),"Y-m-d H:i:s");
        }

        $gndmeas_sent_sites = [];
        $sql = "SELECT * FROM senslopedb.gintags 
                INNER JOIN smsinbox ON smsinbox.sms_id = table_element_id 
                INNER JOIN gintags_reference ON gintags.tag_id_fk = gintags_reference.tag_id
                where (gintags_reference.tag_name = '#CantSendGroundMeas' OR gintags_reference.tag_name = '#GroundMeas' OR gintags_reference.tag_name = '#GroundObs') AND smsinbox.timestamp < '".date('Y-m-d ').$ground_time."' AND smsinbox.timestamp > '".$current_date."' limit 100;";
        $result = $this->dbconn->query($sql);
        if ($result->num_rows > 0) {
            foreach ($result as $tagged) {
                $sql = "SELECT DISTINCT sitename FROM communitycontacts WHERE number like '%".substr($tagged['sim_num'],-10)."%'";
                $get_sites = $this->dbconn->query($sql);
                if ($get_sites->num_rows > 0) {
                    foreach ($get_sites as $site) {
                        if (sizeOf($gndmeas_sent_sites) == 1) {
                            array_push($gndmeas_sent_sites, $site['sitename']);
                        } else {
                            if (!in_array($site['sitename'],$gndmeas_sent_sites)) {
                                array_push($gndmeas_sent_sites,$site['sitename']);
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
        return array_unique($gndmeas_sent_sites);
    }

}