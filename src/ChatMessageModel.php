<?php
namespace MyApp;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class ChatMessageModel {
    protected $dbconn;

    public function __construct() {
        $this->initDBforCB();
        $this->qiInit = true;
        $this->getCachedQuickInboxMessages();
    }

    public function initDBforCB() {
        $host = "localhost";
        $usr = "root";
        $pwd = "senslope";
        $dbname = "newdb";
        $this->dbconn = new \mysqli($host, $usr, $pwd, $dbname);
        if ($this->dbconn->connect_error) {
            die("Connection failed: " . $this->dbconn->connect_error);
        } else {
            echo "Connection Established... \n";
        }
    }

    public function utf8_encode_recursive ($array) {
        $result = array();
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->utf8_encode_recursive($value);
            } else if (is_string($value)) {
                $result[$key] = utf8_encode($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    public function filterSpecialCharacters($message) {
        $filteredMsg = str_replace("\\", "\\\\", $message);
        $filteredMsg = str_replace("'", "\'", $filteredMsg);
        return $filteredMsg;
    }

    public function checkConnectionDB($sql = "Nothing") {
        if (!mysqli_ping($this->dbconn)) {
            echo 'Lost connection, exiting after query #1';
            $logFile = fopen("../logs/mysqlRunAwayLogs.txt", "a+");
            $t = time();
            fwrite($logFile, date("Y-m-d H:i:s") . "\n" . $sql . "\n\n");
            fclose($logFile);
            $this->initDBforCB();
        }
    }

    public function identifyMobileNetwork($contactNumber) {
        try {
            $countNum = strlen($contactNumber);
            if ($countNum == 11) {
                $curSimPrefix = substr($contactNumber, 2, 2);
            } elseif ($countNum == 12) {
                $curSimPrefix = substr($contactNumber, 3, 2);
            }

            echo "simprefix: 09$curSimPrefix\n";
            $networkSmart = "00,07,08,09,10,11,12,14,18,19,20,21,22,23,24,25,28,29,30,31,
            32,33,34,38,39,40,42,43,44,46,47,48,49,50,89,98,99";
            $networkGlobe = "05,06,15,16,17,25,26,27,35,36,37,45,55,56,75,77,78,79,94,95,96,97";
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

    public function insertSMSInboxEntry($timestamp, $sender, $message) {
        $message = $this->filterSpecialCharacters($message);

        $sql = "INSERT INTO smsinbox (timestamp, sim_num, sms_msg, read_status, web_flag)
        VALUES ('$timestamp', '$sender', '$message', 'READ-FAIL', 'WS')";

        var_dump($sql);
        $this->checkConnectionDB($sql);

        if ($this->dbconn->query($sql) === TRUE) {
            echo "New record created successfully!\n";
        } else {
            echo "Error: " . $sql . "<br>" . $this->dbconn->error;
        }
    }

    public function insertSMSOutboxEntry($recipients, $message, $sentTS = null, $ewi_tag = false) {
        $ewi_tag_id = [];
        $message = $this->filterSpecialCharacters($message);

        if ($sentTS) {
            $curTime = $sentTS;
        } else {
            $curTime = date("Y-m-d H:i:s", time());
        }
        
        foreach ($recipients as $recipient) {
            $mobileNetwork = $this->identifyMobileNetwork($recipient);

            if (strlen($recipient) > 11){
                $recipient = substr($recipient, 2);
                $recipient = "0".$recipient;
            }

            echo "$curTime Message recipient: $recipient\n";

            $sql = "INSERT INTO smsoutbox (timestamp_written, recepients, sms_msg, send_status, gsm_id)
            VALUES ('$curTime', '$recipient', '$message', 'PENDING', '$mobileNetwork')";
            $this->checkConnectionDB($sql);
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

    public function updateSMSOutboxEntry($recipient=null, $writtenTS=null, $sendStatus=null, $sentTS=null) {
        $recipient = str_replace("[", "", $recipient);
        $recipient = str_replace("]", "", $recipient);
        $recipient = str_replace("u", "", $recipient);
        $recipient = str_replace("'", "", $recipient);
        $recipient = $this->normalizeContactNumber($recipient);

        if ($this->isSenderValid($recipient) == false) {
            echo "Error: recipient '$recipient' is invalid.\n";
            return -1;
        }
        if ($writtenTS == null) {
            echo "Error: no input for written_timestamp.\n";
            return -1;
        }

        $setCtr = 0;
        $updateQuery = "UPDATE smsoutbox ";
        $whereClause = " WHERE timestamp_written = '$writtenTS' AND recepients like '%$recipient'";
        if ( ($sendStatus == "PENDING") || ($sendStatus == "SENT-PI") || 
            ($sendStatus == "SENT") || ($sendStatus == "SENT-WSS") ||
            ($sendStatus == "FAIL") || ($sendStatus == "FAIL-WSS") ) {
            $setClause = " SET send_status = '$sendStatus'";
        $setCtr++;
    }
    elseif ($sendStatus == null) {
    }
    else {
        echo "Error: invalid send_status.\n";
        return -1;
    }
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
        $converted = utf8_decode($name);
        return str_replace("?", "ñ", $converted);
    }

    public function getCachedQuickInboxMessages($isForceLoad=false) {

        $start = microtime(true);

        $os = PHP_OS;
        $qiResults;

        if (strpos($os,'WIN') !== false) {
            $qiResults = $this->getQuickInboxMessages();
        } elseif ((strpos($os,'Ubuntu') !== false) || (strpos($os,'Linux') !== false)) {

            $mem = new \Memcached();
            $mem->addServer("127.0.0.1", 11211);
            $qiCached = $mem->get("cachedQI");
            if ( ($this->qiInit == true) || $isForceLoad ) {
                echo "Initialize the Quick Inbox Messages \n";

                $qiResults = $this->getQuickInboxMessages();
                $mem->set("cachedQI", $qiResults) or die("couldn't save quick inbox results");
            } 
            else {
                $qiResults = $mem->get("cachedQI");
            }
        }
        else {
            $qiResults = $this->getQuickInboxMessages();
        }

        $execution_time = microtime(true) - $start;
        echo "\n\nExecution Time: $execution_time\n\n";

        return $qiResults;
    }

    public function addQuickInboxMessageToCache($receivedMsg) {
        $os = PHP_OS;

        if (strpos($os,'WIN') !== false) {
            return;
        }
        elseif ((strpos($os,'Ubuntu') !== false) || (strpos($os,'Linux') !== false)) {

            $mem = new \Memcached();
            $mem->addServer("127.0.0.1", 11211);
            $qiCached = $mem->get("cachedQI");
            if ($qiCached && ($this->qiInit == true) ) {
                echo "Initialize the Quick Inbox Messages \n";

                $qiResults = $this->getQuickInboxMessages();
                $mem->set("cachedQI", $qiResults) or die("couldn't save quick inbox results");
            } 
            else {
                array_pop($qiCached['data']);
                array_unshift($qiCached['data'], $receivedMsg);
                $mem->set("cachedQI", $qiCached) or die("couldn't save quick inbox results");
            }
        }
        else {
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
                $raw_data["name"] = $row["name"];
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

    public function getQuickInboxMessages($periodDays = 365) {
        $contact_lists = $this->getFullnamesAndNumbers();
        $get_all_sms_from_period = "SELECT smsinbox_users.inbox_id, smsinbox_users.ts_received, smsinbox_users.mobile_id, smsinbox_users.sms_msg, smsinbox_users.read_status, smsinbox_users.web_status,smsinbox_users.gsm_id,user_mobile.sim_num 
            FROM smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id
            WHERE smsinbox_users.ts_received > (now() - interval 1000 day)
            ORDER BY ts_received DESC";

        $this->checkConnectionDB($get_all_sms_from_period);
        $sms_result_from_period = $this->dbconn->query($get_all_sms_from_period);

        $fullData['type'] = 'smsloadquickinbox';
        $distinctNumbers = "";
        $allNumbers = [];
        $allMessages = [];
        $quickInboxMsgs = [];
        $ctr = 0;

        if ($sms_result_from_period->num_rows > 0) {
            while ($row = $sms_result_from_period->fetch_assoc()) {
                var_dump($row);
                // $normalizedNum = $this->normalizeContactNumber($row['sim_num']);

                // array_push($allNumbers, $normalizedNum);
                // $allMessages[$ctr]['user'] = $normalizedNum;
                // $allMessages[$ctr]['msg'] = $row['sms_msg'];
                // $allMessages[$ctr]['timestamp'] = $row['timestamp'];
                // $ctr++;
            }

            // $distinctNumbers = array_unique($allNumbers);

            // foreach ($distinctNumbers as $singleContact) {
            //     $msgDetails = $this->getRowFromMultidimensionalArray($allMessages, "user", $singleContact);
            //     $msgDetails['name'] = $this->convertNameToUTF8($this->findFullnameFromNumber($contactsList, $msgDetails['user']));
            //     array_push($quickInboxMsgs, $msgDetails);
            // }

            // $fullData['data'] = $quickInboxMsgs;
        } else {
            echo "0 results\n";
            $fullData['data'] = null;
        }

        // echo "JSON DATA: " . json_encode($fullData);
        // $this->qiInit = false;

        // return $fullData;
    }

    public function getFullnamesAndNumbers() {
        $get_full_names_query = "SELECT * FROM (SELECT UPPER(CONCAT(sites.site_code,' ',user_organization.org_name,' ',users.salutation,' ',users.firstname,' ',users.lastname)) as fullname,user_mobile.sim_num as number FROM users INNER JOIN user_organization ON user_organization.user_id = users.user_id LEFT JOIN user_mobile ON user_mobile.user_id = users.user_id LEFT JOIN sites ON user_organization.fk_site_id = sites.site_id) as fullcontact UNION SELECT * FROM (SELECT UPPER(CONCAT(dewsl_teams.team_code,' ',users.salutation,' ',users.firstname,' ',users.lastname)) as fullname,user_mobile.sim_num as number FROM users INNER JOIN user_mobile ON user_mobile.user_id = users.user_id LEFT JOIN dewsl_team_members ON dewsl_team_members.users_users_id = users.user_id LEFT JOIN dewsl_teams ON dewsl_teams.team_id = dewsl_team_members.dewsl_teams_team_id) as fullcontact;";
        // Make sure the connection is still alive, if not, try to reconnect 
        $this->checkConnectionDB($get_full_names_query);
        $result = $this->dbconn->query($get_full_names_query);
        $ctr = 0;
        $dbreturn = "";
        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['fullname'] = $row['fullname'];
                $dbreturn[$ctr]['number'] = $row['number'];
                $ctr++;
            }
            // echo json_encode($dbreturn);
            return $dbreturn;
        }
        else {
            echo "0 results\n";
        }
    }

    public function findFullnameFromNumber($contactsList, $normalizedNum) {
        for ($i=0; $i < count($contactsList); $i++) { 
            if (strpos($contactsList[$i]['numbers'], $normalizedNum)) {
                return $contactsList[$i]['fullname'];
            }
        }

        return "unknown";
    }

    public function getSearchedConversation($number = null,$type = null,$timestamp = null){
        if ($type == "smsLoadSearched") {

            $ctr = 0;
            $sql = '';
            $sqlTargetNumbers = "";
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

            $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, timestamp_written as timestamp, timestamp_sent as timestampsent,sms_id FROM smsoutbox WHERE " . $sqlTargetNumbersOutbox. "AND timestamp_written >= '$timestamp' ";

            $sqlInbox = "SELECT sim_num as user, sms_msg as msg, timestamp as timestamp, null as timestampsent,sms_id FROM smsinbox WHERE " . $sqlTargetNumbersInbox." AND timestamp >= '$timestamp' ";

            $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp asc LIMIT 20";
            $this->checkConnectionDB($sql);
            $result = $this->dbconn->query($sql);

            $ctr = 0;
            $presentConversation = "";
            $fullData['type'] = "smsLoadSearched";
            if ($result->num_rows > 0) {
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

            $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, timestamp_written as timestamp,timestamp_sent as timestampsent,sms_id FROM smsoutbox WHERE " . $sqlTargetNumbersOutbox. "AND timestamp_written < '$timestamp' ";

            $sqlInbox = "SELECT sim_num as user, sms_msg as msg, timestamp as timestamp,null as timestampsent,sms_id FROM smsinbox WHERE " . $sqlTargetNumbersInbox." AND timestamp < '$timestamp' ";

            $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp desc LIMIT 20";
            $this->checkConnectionDB($sql);
            $result = $this->dbconn->query($sql);

            $ctr = 0;
            $pastConversation = "";
            if ($result->num_rows > 0) {
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
        if ($user_number == "You") {
            $sql = "SELECT DISTINCT 'You' as user, recepients as recepients FROM smsoutbox WHERE sms_msg LIKE '%$msg%' AND timestamp_written = '$timestamp'";

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
    $sqlTargetIndividualAlias = "select firstname,lastname from dewslcontacts where numbers LIKE '%".$contactTrimmed."%'";
    $this->checkConnectionDB($sqlTargetIndividualAlias);
    $resultAlias = $this->dbconn->query($sqlTargetIndividualAlias);
    if ($resultAlias->num_rows > 0) {
        while ($rowIndiAlias = $resultAlias->fetch_assoc()) {
            $alias= $rowIndiAlias['firstname']." ".$rowIndiAlias['lastname'];
            break;
        }
    } else {
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

    $sqlOutbox = "SELECT DISTINCT 'You' as user, sms_msg as msg, timestamp_written as timestamp, timestamp_sent as timestampsent FROM smsoutbox WHERE recepients LIKE '%".$contactTrimmed."%' AND timestamp_written > '$timestamp' ";

    $sqlInbox = "SELECT DISTINCT sim_num as user, sms_msg as msg, timestamp as timestamp, null as timestampsent FROM smsinbox WHERE sim_num LIKE '%".$contactTrimmed."%' AND timestamp > '$timestamp' ";

    $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp asc LIMIT 20";

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
        $fullData['data'] = null;
    }

    $sqlOutbox = "SELECT DISTINCT 'You' as user, sms_msg as msg, timestamp_written as timestamp, timestamp_sent as timestampsent FROM smsoutbox WHERE recepients LIKE '%".$contactTrimmed."%' AND timestamp_written <= '$timestamp' ";

    $sqlInbox = "SELECT DISTINCT sim_num as user, sms_msg as msg, timestamp as timestamp, null as timestampsent FROM smsinbox WHERE sim_num LIKE '%".$contactTrimmed."%' AND timestamp <= '$timestamp' ";

    $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp desc LIMIT 20";
    $this->checkConnectionDB($sql);
    $result = $this->dbconn->query($sql);

    $ctr = 0;
    $pastConversation = "";
    if ($result->num_rows > 0) {
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
        $fullData['data'] = null;
    }

    $allArray = array_merge($presentConversation,$pastConversation);
    $fullData['data'] = $allArray;
    return $fullData;
    }

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
                    echo "target: $test\n";
                    $ctr++;
                }
            }
        }

        $sql = '';
        $sqlTargetNumbers = "";
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
            timestamp_written as timestamp, timestamp_sent as timestampsent,timestamp_written.sms_id FROM smsoutbox timestamp_written inner join (select sms_id from smsoutbox where timestamp_written = '$yourLastTimeStamp' order by sms_id limit 1) x on timestamp_written.sms_id < x.sms_id WHERE $sqlTargetNumbersOutbox";


            $sqlInbox = "SELECT sim_num as user, sms_msg as msg,
            timestamp as timestamp, null as timestampsent,timestamp.sms_id
            FROM smsinbox timestamp inner join (select sms_id from smsinbox where timestamp = '$indiLastTimeStamp' order by sms_id limit 1) x on timestamp.sms_id < x.sms_id WHERE $sqlTargetNumbersInbox ";

            $sql = $sqlOutbox."UNION ".$sqlInbox."ORDER BY timestamp desc LIMIT $limit";

        } else {
            if ($timestamp == null) {
                $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, 
                timestamp_written as timestamp, timestamp_sent as timestampsent,sms_id
                FROM smsoutbox WHERE " . $sqlTargetNumbersOutbox;

                $sqlInbox = "SELECT sim_num as user, sms_msg as msg,
                timestamp as timestamp, null as timestampsent,sms_id
                FROM smsinbox WHERE " . $sqlTargetNumbersInbox;

                $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp desc LIMIT $limit";
            } else {
                $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, 
                timestamp_written as timestamp, timestamp_sent as timestampsent,sms_id
                FROM smsoutbox WHERE " . $sqlTargetNumbersOutbox . "AND timestamp_written < '$timestamp' ";

                $sqlInbox = "SELECT sim_num as user, sms_msg as msg,
                timestamp as timestamp, null as timestampsent,sms_id
                FROM smsinbox WHERE " . $sqlTargetNumbersInbox . "AND timestamp < '$timestamp' ";

                $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp desc LIMIT $limit";
            }
        }


        $this->checkConnectionDB($sql);
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
                        $dbreturn[$ctr]['table_used'] = "smsinbox";
                    }
                }
                $dbreturn[$ctr]['sms_id'] = $row['sms_id'];
                $dbreturn[$ctr]['msg'] = $row['msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                $dbreturn[$ctr]['timestamp_sent'] = $row['timestampsent'];

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
        $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, timestamp_written as timestamp, timestamp_sent as timestampsent FROM smsoutbox WHERE " . $sqlTargetNumbersOutbox . "AND sms_msg LIKE '%".$searchKey."%'";

        $sqlInbox = "SELECT sim_num as user, sms_msg as msg,timestamp as timestamp, null as timestampsent FROM smsinbox WHERE " . $sqlTargetNumbersInbox . "AND sms_msg LIKE '%".$searchKey."%'";

        $sql = $sqlOutbox . " UNION " . $sqlInbox . "ORDER BY timestamp desc";
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'searchMessage';


        if ($result->num_rows > 0) {
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
        $sqlOutbox = "SELECT DISTINCT user,msg,timestamp,timestamp_sent FROM (SELECT 'You' as user,sms_msg as msg,timestamp_written as timestamp,timestamp_sent as timestamp_sent FROM smsoutbox WHERE ".$sqlTargetNumbersOutbox.") as outbox WHERE msg LIKE '%$searchKey%'";   

        $sqlInbox = "SELECT DISTINCT user,msg,timestamp,null as timestamp_sent FROM (SELECT sim_num as user,sms_msg as msg,timestamp as timestamp, null as timestamp_sent FROM smsinbox WHERE ".$sqlTargetNumbersInbox.") as inbox WHERE msg LIKE '%$searchKey%'";

        $sql = $sqlOutbox . " UNION " . $sqlInbox . " ORDER BY timestamp desc";
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $msgData['type'] = 'searchMessageGroup';

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['user'] = $row['user'];
                $dbreturn[$ctr]['msg'] = $row['msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                $dbreturn[$ctr]['timestamp_sent'] = $row['timestamp_sent'];
                $dbreturn[$ctr]['type'] = "searchMessageGroup";
                $normalized = $this->normalizeContactNumber($dbreturn[$ctr]['user']);
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

        return $msgData;
    }

    public function searchMessageGlobal($type,$searchKey){
        $sqlOutbox = "SELECT DISTINCT 'You' as user,sms_msg as msg, timestamp_written as timestamp,timestamp_sent as timestampsent FROM smsoutbox WHERE sms_msg LIKE '%$searchKey%'";

        $sqlInbox = "SELECT DISTINCT sim_num as user,sms_msg as msg, timestamp as timestamp, null as timestampsent FROM smsinbox WHERE sms_msg LIKE '%$searchKey%'";

        $sql = $sqlOutbox . " UNION " . $sqlInbox . "ORDER BY timestamp desc";
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'searchMessageGlobal';

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {

                if ($row['user'] == 'You') {
                    $dbreturn[$ctr]['user'] = $row['user'];
                } else {
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

    public function searchGintagMessage($type,$searchKey){
        if (strpos($searchKey, '#') !== 0) {
            $searchKey = "#".$searchKey;
        }

        $query = "SELECT table_element_id,table_used FROM gintags inner join gintags_reference ON gintags.tag_id_fk=gintags_reference.tag_id WHERE gintags_reference.tag_name LIKE'%".$searchKey."%';";
        $this->checkConnectionDB($query);
        $result = $this->dbconn->query($query);
        if ($result->num_rows > 0) {
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
                $query = $queryOutbox . "UNION " . $queryInbox . " ORDER BY timestamp desc";
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

    public function normalizeContactNumber($contactNumber) {
        $countNum = strlen($contactNumber);
        if ($countNum == 11) {
            $contactNumber = substr($contactNumber, 1);
        }
        elseif ($countNum == 12) {
            $contactNumber = substr($contactNumber, 2);
        }

        return $contactNumber;
    }

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
        $sql = "UPDATE communitycontacts SET ewirecipient = false WHERE sitename IN $filterSitenames";
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);
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

            $sqlOutbox = "SELECT DISTINCT 'You' as user, sms_msg as msg, timestamp_written as timestamp ,timestamp_sent as timestampsent,timestamp_written.sms_id FROM smsoutbox timestamp_written inner join (select sms_id from smsoutbox where timestamp_written = '$yourLastTimeStamp' order by sms_id limit 1) x on timestamp_written.sms_id < x.sms_id WHERE $sqlTargetNumbersOutbox GROUP BY (timestamp)";


            $sqlInbox = "SELECT DISTINCT sim_num as user, sms_msg as msg,timestamp as timestamp ,null as timestampsent,timestamp.sms_id FROM smsinbox timestamp inner join (select sms_id from smsinbox where timestamp = '$groupLastTimeStamp' order by sms_id limit 1) x on timestamp.sms_id < x.sms_id WHERE $sqlTargetNumbersInbox ";

            $sql = $sqlOutbox."UNION ".$sqlInbox."ORDER BY timestamp desc LIMIT $limit";

        } else {
            $sqlOutbox = "SELECT DISTINCT 'You' as user, sms_msg as msg, 
            timestamp_written as timestamp, timestamp_sent as timestampsent,sms_id
            FROM smsoutbox WHERE $sqlTargetNumbersOutbox AND timestamp_written IS NOT NULL GROUP BY (timestamp)";

            $sqlInbox = "SELECT DISTINCT sim_num as user, sms_msg as msg,
            timestamp as timestamp, null as timestamp_sent,sms_id
            FROM smsinbox WHERE $sqlTargetNumbersInbox AND timestamp IS NOT NULL ";

            $sql = $sqlOutbox . "UNION " . $sqlInbox . " ORDER BY timestamp desc LIMIT $limit";
        }
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        if ($type == "oldMessageGroup"){
            $msgData['type'] = 'oldMessageGroup';
        } else {
            $msgData['type'] = 'smsloadrequestgroup';
        }

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['sms_id'] = $row['sms_id'];
                if ($row['user'] == "You") {
                    $dbreturn[$ctr]['table_used'] = "smsoutbox";
                } else {
                    $dbreturn[$ctr]['table_used'] = "smsinbox";
                }
                $dbreturn[$ctr]['user'] = $row['user'];
                $dbreturn[$ctr]['msg'] = $row['msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];
                $dbreturn[$ctr]['timestamp_sent'] = $row['timestampsent'];
                $normalized = $this->normalizeContactNumber($dbreturn[$ctr]['user']);
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
    } else {
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
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadcommunitycontact';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";
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
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadcommunitycontact';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";
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
        return $fullData;
    }

    public function getNameFromNumber($contactNumber) {
        $normalized = $this->normalizeContactNumber($contactNumber);
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
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $dbreturn = [];
        if ($result->num_rows > 0) {
            $ctr = 0;
            while ($row = $result->fetch_assoc()) {
                if ($ctr == 0) {
                    $dbreturn['fullname'] = $row['fullname'];
                }
                else {
                    $dbreturn['fullname'] = $dbreturn['fullname'] . ', ' . $row['fullname'];
                }

                $ctr++;
            }

            echo "data size: " . $this->getArraySize($dbreturn);
        }
        else {
            echo "0 results\n";
            $dbreturn['fullname'] = "unknown";
        }

        echo json_encode($dbreturn);
        return $dbreturn;
    }

    public function getContactSuggestions($queryName) {
        $sql = "SELECT * FROM (SELECT UPPER(CONCAT(sites.site_code,' ',user_organization.org_name,' - ',users.salutation,' ',users.firstname,' ',users.lastname)) as fullname,users.user_id as id FROM users INNER JOIN user_organization ON users.user_id = user_organization.user_id RIGHT JOIN sites ON sites.site_id = user_organization.fk_site_id RIGHT JOIN user_mobile ON user_mobile.user_id = users.user_id UNION SELECT UPPER(CONCAT(dewsl_teams.team_name,' - ',users.salutation,' ',users.firstname,' ',users.lastname)) as fullname,users.user_id as id FROM users INNER JOIN dewsl_team_members ON users.user_id = dewsl_team_members.users_users_id RIGHT JOIN dewsl_teams ON dewsl_team_members.dewsl_teams_team_id = dewsl_teams.team_id RIGHT JOIN user_mobile ON user_mobile.user_id = users.user_id) as fullcontact WHERE fullname LIKE '%$queryName%' or id LIKE '%$queryName%'";

        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadnamesuggestions';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['fullname'] = $this->convertNameToUTF8($row['fullname']);
                $dbreturn[$ctr]['id'] = $row['id'];
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
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadnamesuggestions';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";
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
        return $fullData;
    }

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
        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadcontacts';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";
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
        return $fullData;
    }

    public function getAllOfficesAndSites() {
        $fullData['type'] = 'loadofficeandsites';
        $sqlOffices = "SELECT DISTINCT office FROM communitycontacts";
        $this->checkConnectionDB($sqlOffices);
        $result = $this->dbconn->query($sqlOffices);

        $ctr = 0;
        $returnOffices = "";

        if ($result->num_rows > 0) {
            $fullData['total_offices'] = $result->num_rows;
            echo $result->num_rows . " results\n";
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
        $sqlSitenames = "SELECT DISTINCT sitename FROM communitycontacts order by sitename asc";
        $this->checkConnectionDB($sqlSitenames);
        $result = $this->dbconn->query($sqlSitenames);

        $ctr = 0;
        $returnSitenames = "";

        if ($result->num_rows > 0) {
            $fullData['total_sites'] = $result->num_rows;
            echo $result->num_rows . " results\n";
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
        return $fullData;
    }

    public function getAllCmmtyContacts() {
        $this->checkConnectionDB();
        $returnCmmtyContacts = [];
        $returnData = [];
        $ctr = 0;
        $query = "SELECT * FROM users";
        $result = $this->dbconn->query($query);
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $returnCmmtyContacts[$ctr]['user_id'] = $row['user_id'];
                $returnCmmtyContacts[$ctr]['salutation'] = $row['salutation'];
                $returnCmmtyContacts[$ctr]['firstname'] = $row['firstname'];
                $returnCmmtyContacts[$ctr]['lastname'] = $row['lastname'];
                $returnCmmtyContacts[$ctr]['middlename'] = $row['middlename'];
                $returnCmmtyContacts[$ctr]['nickname'] = $row['nickname'];
                $returnCmmtyContacts[$ctr]['birthday'] = $row['birthday'];
                $returnCmmtyContacts[$ctr]['gender'] = $row['sex'];
                $returnCmmtyContacts[$ctr]['active_status'] = $row['status'];
                $ctr++;
            }
        } else {
            echo "No results..";
        }
        $returnData['type'] = 'fetchedCmmtyContacts';
        $returnData['data'] = $this->utf8_encode_recursive($returnCmmtyContacts);
        return $returnData;
    }

    public function getAllDwslContacts() {
        $this->checkConnectionDB();
        $returnDwslContacts = [];
        $finContact = [];
        $returnTeams = [];
        $returnData = [];
        $ctr = 0;
        $query = "SELECT * FROM users INNER JOIN dewsl_team_members ON users.user_id = dewsl_team_members.users_users_id INNER JOIN dewsl_teams ON dewsl_team_members.dewsl_teams_team_id = dewsl_teams.team_id";
        $result = $this->dbconn->query($query);
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $returnDwslContacts[$ctr]['user_id'] = $row['user_id'];
                $returnDwslContacts[$ctr]['salutation'] = $row['salutation'];
                $returnDwslContacts[$ctr]['firstname'] = $row['firstname'];
                $returnDwslContacts[$ctr]['lastname'] = $row['lastname'];
                $returnDwslContacts[$ctr]['middlename'] = $row['middlename'];
                $returnDwslContacts[$ctr]['nickname'] = $row['nickname'];
                $returnDwslContacts[$ctr]['birthday'] = $row['birthday'];
                $returnDwslContacts[$ctr]['gender'] = $row['sex'];
                $returnDwslContacts[$ctr]['active_status'] = $row['status'];
                $returnTeams[$ctr]['team'] = $row['team_name'];
                $ctr++;
            }
        } else {
            echo "No results..";
        }

        for ($x = 0; $x < $ctr; $x++) {
            if (!in_array($returnDwslContacts[$x],$finContact)) {
                array_push($finContact,$returnDwslContacts[$x]);

            }
        }

        for ($x = 0; $x < sizeof($finContact); $x++) {
            $finContact[$x]['team'] = "";
        }

        for ($x = 0; $x < $ctr; $x++) {
            for ($y = 0; $y < sizeof($finContact); $y++) {
                if ($finContact[$y]['user_id'] == $returnDwslContacts[$x]['user_id']) {
                    $finContact[$y]['team'] = ltrim($finContact[$y]['team'].",".$returnTeams[$x]['team'],',');
                }
            }
        }

        $returnData['type'] = 'fetchedDwslContacts';
        $returnData['data'] = $finContact;
        return $returnData;
    }

    public function getDwslContact($id) {
        $returnData = [];
        $returnContact = [];
        $returnMobileNumbers = [];
        $returnLandlineNumbers = [];
        $returnEmail = [];
        $returnTeam = [];
        $ctr = 0;
        $this->checkConnectionDB();
        $query = "SELECT users.user_id as id,users.salutation,users.firstname,users.middlename,users.lastname,users.nickname,users.birthday,users.sex,users.status,user_mobile.mobile_id,user_mobile.user_id,user_mobile.sim_num,user_mobile.priority,user_mobile.mobile_status,user_landlines.landline_id,user_landlines.landline_num,user_landlines.user_id,user_landlines.landline_num,user_landlines.remarks as landline_remarks,dewsl_team_members.members_id,dewsl_team_members.users_users_id,dewsl_team_members.dewsl_teams_team_id,dewsl_teams.team_id,dewsl_teams.team_name,dewsl_teams.remarks, user_emails.email_id,user_emails.email FROM users LEFT JOIN user_mobile ON users.user_id = user_mobile.user_id LEFT JOIN user_landlines ON users.user_id = user_landlines.user_id LEFT JOIN dewsl_team_members ON users.user_id = dewsl_team_members.users_users_id LEFT JOIN dewsl_teams ON dewsl_team_members.dewsl_teams_team_id = dewsl_teams.team_id LEFT JOIN user_emails ON users.user_id = user_emails.user_id WHERE users.user_id = '$id' order by lastname desc;";
        $result = $this->dbconn->query($query);
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                if (empty($returnContact)) {
                    $returnContact['id'] = $row['id'];
                    $returnContact['salutation'] = $row['salutation'];
                    $returnContact['firstname'] = $row['firstname'];
                    $returnContact['lastname'] = $row['lastname'];
                    $returnContact['middlename'] = $row['middlename'];
                    $returnContact['nickname'] = $row['nickname'];
                    $returnContact['gender'] = $row['sex'];
                    $returnContact['birthday'] = $row['birthday'];
                    $returnContact['contact_active_status'] = $row['status'];
                    $returnMobileNumbers[$ctr]['number_id'] = $row['mobile_id'];
                    $returnMobileNumbers[$ctr]['number'] = $row['sim_num'];
                    $returnMobileNumbers[$ctr]['priority'] = $row['priority'];
                    $returnMobileNumbers[$ctr]['number_status'] = $row['mobile_status'];
                    $returnLandlineNumbers[$ctr]['landline_id'] = $row['landline_id'];
                    $returnLandlineNumbers[$ctr]['landline_number'] = $row['landline_num'];
                    $returnLandlineNumbers[$ctr]['landline_remarks'] = $row['landline_remarks'];
                    $returnTeam[$ctr]['member_id'] = $row['members_id'];
                    $returnTeam[$ctr]['team_id'] = $row['team_id'];
                    $returnTeam[$ctr]['team_ref_id'] = $row['dewsl_teams_team_id'];
                    $returnTeam[$ctr]['team_name'] = $row['team_name'];
                    $returnEmail[$ctr]['email_id'] = $row['email_id'];
                    $returnEmail[$ctr]['email'] = $row['email'];
                    $ctr++;
                } else {
                    $returnMobileNumbers[$ctr]['number_id'] = $row['mobile_id'];
                    $returnMobileNumbers[$ctr]['number'] = $row['sim_num'];
                    $returnMobileNumbers[$ctr]['priority'] = $row['priority'];
                    $returnMobileNumbers[$ctr]['number_status'] = $row['mobile_status'];
                    $returnLandlineNumbers[$ctr]['landline_id'] = $row['landline_id'];
                    $returnLandlineNumbers[$ctr]['landline_number'] = $row['landline_num'];
                    $returnLandlineNumbers[$ctr]['landline_remarks'] = $row['landline_remarks'];
                    $returnTeam[$ctr]['member_id'] = $row['members_id'];
                    $returnTeam[$ctr]['team_id'] = $row['team_id'];
                    $returnTeam[$ctr]['team_ref_id'] = $row['dewsl_teams_team_id'];
                    $returnTeam[$ctr]['team_name'] = $row['team_name'];
                    $returnEmail[$ctr]['email_id'] = $row['email_id'];
                    $returnEmail[$ctr]['email'] = $row['email'];
                    $ctr++;
                }
            }
        } else {
            echo "No results..";
        }

        $finLandline = [];
        $finMobile = [];
        $finTeam = [];
        $finEmail = [];
        for ($x=0; $x < $ctr; $x++) {
            if (!in_array($returnMobileNumbers[$x],$finMobile)) {
                array_push($finMobile,$returnMobileNumbers[$x]);
            }

            if (!in_array($returnLandlineNumbers[$x], $finLandline)) {
                array_push($finLandline, $returnLandlineNumbers[$x]);
            }

            if (!in_array($returnTeam[$x], $finTeam)) {
                array_push($finTeam,$returnTeam[$x]);
            }

            if (!in_array($returnEmail[$x], $finEmail)) {
                array_push($finEmail,$returnEmail[$x]);
            }
        }

        $returnData['contact_info'] = $returnContact;
        $returnData['email_data'] = $finEmail;
        $returnData['mobile_data'] = $finMobile;
        $returnData['landline_data'] = $finLandline;
        $returnData['team_data'] = $finTeam;
        $returnObj['data'] = $returnData;
        $returnObj['type'] = "fetchedSelectedDwslContact";

        return $returnObj;
    }

    public function getCmmtyContact($id) {
        $returnData = [];
        $returnContact = [];
        $returnMobile = [];
        $returnLandline = [];
        $returnEwiStatus = [];
        $returnOrg = [];
        $ctr = 0;
        $this->checkConnectionDB();

        // EWI status checker.
        $if_ewi_updated = "SELECT * FROM user_ewi_status WHERE users_id = '$id'";
        $ewi_update = $this->dbconn->query($if_ewi_updated);
        if ($ewi_update->num_rows == 0) {
            $update_ewi_status = "INSERT INTO user_ewi_status VALUES(0,'Active','','$id')";
            $update = $this->dbconn->query($update_ewi_status);
        }

        // refactor this code
        $query = "SELECT users.user_id,users.salutation,users.firstname,users.middlename,users.lastname,users.nickname,users.birthday,users.sex,users.status as active_status,user_organization.org_id,user_organization.org_name,user_organization.scope,organization.org_id as organization_id,user_mobile.mobile_id,user_mobile.sim_num,user_mobile.priority,user_mobile.mobile_status,user_landlines.landline_id,user_landlines.landline_num,user_landlines.remarks,sites.site_id,sites.site_code,sites.psgc_source,user_ewi_status.mobile_id as ewi_mobile_id,user_ewi_status.status as ewi_status,user_ewi_status.remarks as ewi_remarks FROM users INNER JOIN user_organization ON users.user_id = user_organization.user_id LEFT JOIN user_ewi_status ON user_ewi_status.users_id = users.user_id LEFT JOIN organization ON user_organization.org_name = organization.org_name LEFT JOIN user_mobile ON user_mobile.user_id = users.user_id LEFT JOIN user_landlines ON user_landlines.user_id = users.user_id LEFT JOIN user_emails ON user_emails.user_id = users.user_id LEFT JOIN sites ON sites.site_id = user_organization.fk_site_id WHERE users.user_id = '$id' order by lastname desc;";
        $result = $this->dbconn->query($query);
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                if (empty($returnContact)) {
                    $returnContact['id'] = $row['user_id'];
                    $returnContact['salutation'] = $row['salutation'];
                    $returnContact['firstname'] = $row['firstname'];
                    $returnContact['lastname'] = $row['lastname'];
                    $returnContact['middlename'] = $row['middlename'];
                    $returnContact['nickname'] = $row['nickname'];
                    $returnContact['gender'] = $row['sex'];
                    $returnContact['birthday'] = $row['birthday'];
                    $returnContact['contact_active_status'] = $row['active_status'];
                    $returnMobile[$ctr]['number_id'] = $row['mobile_id'];
                    $returnMobile[$ctr]['number'] = $row['sim_num'];
                    $returnMobile[$ctr]['priority'] = $row['priority'];
                    $returnMobile[$ctr]['number_status'] = $row['mobile_status'];
                    $returnLandline[$ctr]['landline_id'] = $row['landline_id'];
                    $returnLandline[$ctr]['landline_number'] = $row['landline_num'];
                    $returnLandline[$ctr]['landline_remarks'] = $row['remarks'];
                    $returnEwiStatus[$ctr]['ewi_mobile_id'] = $row['ewi_mobile_id'];
                    $returnEwiStatus[$ctr]['ewi_status'] = $row['ewi_status'];
                    $returnEwiStatus[$ctr]['ewi_remarks'] = $row['ewi_remarks'];
                    $returnOrg[$ctr]['org_id'] = $row['org_id'];
                    $returnOrg[$ctr]['organization_id'] = $row['organization_id'];
                    $returnOrg[$ctr]['org_name'] = strtoupper($row['org_name']);
                    $returnOrg[$ctr]['org_scope'] = $row['scope'];
                    $returnOrg[$ctr]['site_code'] = strtoupper($row['site_code']);
                    $returnOrg[$ctr]['org_psgc_source'] = $row['psgc_source'];
                    $ctr++;
                } else {
                    $returnMobile[$ctr]['number_id'] = $row['number_id'];
                    $returnMobile[$ctr]['number'] = $row['sim_num'];
                    $returnMobile[$ctr]['priority'] = $row['priority'];
                    $returnMobile[$ctr]['number_status'] = $row['mobile_status'];
                    $returnLandline[$ctr]['landline_id'] = $row['landline_id'];
                    $returnLandline[$ctr]['landline_number'] = $row['landline_num'];
                    $returnLandline[$ctr]['landline_remarks'] = $row['remarks'];
                    $returnEwiStatus[$ctr]['ewi_mobile_id'] = $row['ewi_mobile_id'];
                    $returnEwiStatus[$ctr]['ewi_status'] = $row['ewi_status'];
                    $returnEwiStatus[$ctr]['ewi_remarks'] = $row['ewi_remarks'];
                    $returnOrg[$ctr]['org_users_id'] = $row['org_users_id'];
                    $returnOrg[$ctr]['org_id'] = $row['org_id'];
                    $returnOrg[$ctr]['org_name'] = strtoupper($row['org_name']);
                    $returnOrg[$ctr]['org_scope'] = $row['scope'];
                    $returnOrg[$ctr]['site_code'] = strtoupper($row['site_code']);
                    $returnOrg[$ctr]['org_psgc_source'] = $row['psgc_source'];
                    $ctr++;
                }
            }
        } else {
            echo "No results..";
        }

        $finMobile = [];
        $finLandline = [];
        $finOrg = [];
        $finEwi = [];

        for ($x = 0; $x < $ctr; $x++) {
            if (!in_array($returnMobile[$x],$finMobile)) {
                array_push($finMobile,$returnMobile[$x]);
            }

            if (!in_array($returnLandline[$x],$finLandline)) {
                array_push($finLandline,$returnLandline[$x]);
            }

            if (!in_array($returnOrg[$x],$finOrg)) {
                array_push($finOrg,$returnOrg[$x]);
            }

            if (!in_array($returnEwiStatus[$x],$finEwi)) {
                array_push($finEwi,$returnEwiStatus[$x]);
            }
        }
        $returnData['contact_info'] = $returnContact;
        $returnData['mobile_data'] = $finMobile;
        $returnData['landline_data'] = $finLandline;
        $returnData['ewi_data'] = $finEwi;
        $returnData['org_data'] = $finOrg;
        $returnData['list_of_sites'] = $this->getAllSites();
        $returnData['list_of_orgs'] = $this->getAllOrganization();
        $returnObj['data'] = $returnData;
        $returnObj['type'] = "fetchedSelectedCmmtyContact";
        return $returnObj;
    }

    public function updateDwslContact($data) {
        $query_contact_info = "UPDATE users SET firstname='$data->firstname',lastname='$data->lastname',middlename='$data->middlename',salutation='$data->salutation',birthday='$data->birthdate',sex='$data->gender',status=$data->contact_active_status WHERE user_id = $data->id;";
        $result = $this->dbconn->query($query_contact_info);
        if ($result == true) {
            $flag = true;
            $emails = explode(',',$data->email_address);
            $teams = explode(',',$data->teams);
            $remove_email = "DELETE FROM user_emails WHERE user_id='$data->id'";
            $result = $this->dbconn->query($remove_email);
            if ($emails[0] != "") {
                for ($counter = 0; $counter < sizeof($emails); $counter++) {
                    try {
                        $insert_new_emails = "INSERT INTO user_emails VALUES(0,'$data->id','$emails[$counter]')";
                        $result = $this->dbconn->query($insert_new_emails);
                    } catch (Exception $e) {
                        $flag = false;
                    }
                }
            }

            try {
                $remove_teams = "DELETE FROM dewsl_team_members WHERE users_users_id='$data->id'";
                $result = $this->dbconn->query($remove_teams);
            } catch (Exception $e) {
                $flag = false;
            }

            if ($teams[0] != "") {
                for ($counter = 0; $counter < sizeof($teams); $counter++) {
                    $check_if_existing = "SELECT * FROM dewsl_teams WHERE team_name ='$teams[$counter]'";
                    $result = $this->dbconn->query($check_if_existing);
                    if ($result->num_rows == 0) {
                        $insert_new_teams = "INSERT INTO dewsl_teams VALUES (0,'$teams[$counter]','')";
                        $result = $this->dbconn->query($insert_new_teams);
                        $newly_added_team = "SELECT * FROM dewsl_teams WHERE team_name ='$teams[$counter]'";
                        $result = $this->dbconn->query($newly_added_team);
                        $team_details = $result->fetch_assoc();
                        $insert_team_member = "INSERT INTO dewsl_team_members VALUES (0,'$data->id','".$team_details['team_id']."')";
                        $result = $this->dbconn->query($insert_team_member);
                    } else {
                        $team_details = $result->fetch_assoc();
                        $insert_team_member = "INSERT INTO dewsl_team_members VALUES (0,'$data->id','".$team_details['team_id']."')";
                        $result = $this->dbconn->query($insert_team_member);
                    }
                }
            }

            if (sizeof($data->numbers) == 0) {
                try {
                    $num_exist = "DELETE FROM user_mobile WHERE user_id='".$data->id."'";
                    $result = $this->dbconn->query($num_exist);
                } catch (Exception $e) {
                    $flag = false;
                }
            } else {
                for ($num_counter = 0; $num_counter < sizeof($data->numbers); $num_counter++) {
                    if ($data->numbers[$num_counter]->mobile_id != "" && $data->numbers[$num_counter]->mobile_number != "") {
                        try {
                            $num_exist = "UPDATE user_mobile SET sim_num = '".$data->numbers[$num_counter]->mobile_number."',priority = '".$data->numbers[$num_counter]->mobile_priority."',mobile_status = '".$data->numbers[$num_counter]->mobile_status."' WHERE mobile_id='".$data->numbers[$num_counter]->mobile_id."'";
                            $result = $this->dbconn->query($num_exist);
                        } catch (Exception $e) {
                            $flag = false;
                        }
                    } else if ($data->numbers[$num_counter]->mobile_number == "") {
                        try {
                            $num_exist = "DELETE FROM user_mobile WHERE mobile_id='".$data->numbers[$num_counter]->mobile_id."'";
                            $result = $this->dbconn->query($num_exist);
                        } catch (Exception $e) {
                            $flag = false;
                        }
                    } else {
                        try {
                            $new_num = "INSERT INTO user_mobile VALUES (0,'$data->id','".$data->numbers[$num_counter]->mobile_number."','".$data->numbers[$num_counter]->mobile_priority."','".$data->numbers[$num_counter]->mobile_status."')";
                            $result = $this->dbconn->query($new_num);
                        } catch (Exception $e) {
                            $flag = false;
                        }
                    }
                }
            }

            if (sizeof($data->landline) == 0) {
                try {
                    $landline_exist = "DELETE FROM user_landlines WHERE user_id='".$data->id."'";
                    $result = $this->dbconn->query($landline_exist);
                } catch (Exception $e) {
                    $flag = false;
                }
            } else {
                for ($landline_counter = 0; $landline_counter < sizeof($data->landline); $landline_counter++) {
                    if ($data->landline[$landline_counter]->landline_id != "" && $data->landline[$landline_counter]->landline_number != "") {
                        try {
                            $landline_exist = "UPDATE user_landlines SET landline_num = '".$data->landline[$landline_counter]->landline_number."', remarks = '".$data->landline[$landline_counter]->landline_remarks."' WHERE landline_id='".$data->landline[$landline_counter]->landline_id."'";
                            $result = $this->dbconn->query($landline_exist);
                        } catch (Exception $e) {
                            $flag = false;
                        }
                    } else if ($data->landline[$landline_counter]->landline_number == "") {
                        try {
                            $landline_exist = "DELETE FROM user_landlines WHERE landline_id='".$data->landline[$landline_counter]->landline_id."'";
                            $result = $this->dbconn->query($landline_exist);
                        } catch (Exception $e) {
                            $flag = false;
                        }
                    } else {
                        try {
                            $new_landline = "INSERT INTO user_landlines VALUES (0,'$data->id','".$data->landline[$landline_counter]->landline_number."','".$data->landline[$landline_counter]->landline_remarks."')";
                            $result = $this->dbconn->query($new_landline); 
                        } catch (Exception $e) {
                            $flag = false;
                        }
                    }
                }
            }

            if ($flag == false) {
                $return_data['return_msg'] = "Error occured, please refresh the page and try again.";
            } else {
                $return_data['return_msg'] = "Successfully updated contact.";
            }
            $return_data['status'] = $flag;
        } else {
            $return_data['status'] = $result;
            $return_data['return_msg'] = "Contact update failed, Please recheck inputs.";

        }
        $return_data['type'] = "updatedDwslContact";
        return $return_data;
    }

    public function updateCmmtyContact($data) {
        $flag = true;
        $query_contact_info = "UPDATE users SET firstname='$data->firstname',lastname='$data->lastname',middlename='$data->middlename',nickname='$data->nickname',salutation='$data->salutation',birthday='$data->birthdate',sex='$data->gender',status=$data->contact_active_status WHERE user_id = $data->id;";
        $result = $this->dbconn->query($query_contact_info);
        if ($result == true) {
            if (sizeof($data->numbers) == 0) {
                try {
                    $num_exist = "DELETE FROM user_mobile WHERE user_id='".$data->id."'";
                    $result = $this->dbconn->query($num_exist);
                } catch (Exception $e) {
                    $flag = false;
                    echo $e->getMessage();
                }
            } else {
                for ($num_counter = 0; $num_counter < sizeof($data->numbers); $num_counter++) {
                    if ($data->numbers[$num_counter]->mobile_id != "" && $data->numbers[$num_counter]->mobile_number != "") {
                        try {
                            $num_exist = "UPDATE user_mobile SET sim_num = '".$data->numbers[$num_counter]->mobile_number."',priority = '".$data->numbers[$num_counter]->mobile_priority."',mobile_status = '".$data->numbers[$num_counter]->mobile_status."' WHERE mobile_id='".$data->numbers[$num_counter]->mobile_id."'";
                            $result = $this->dbconn->query($num_exist);
                        } catch (Exception $e) {
                            $flag = false;
                            echo $e->getMessage();
                        }
                    } else if ($data->numbers[$num_counter]->mobile_number == "") {
                        try {
                            $num_exist = "DELETE FROM user_mobile WHERE mobile_id='".$data->numbers[$num_counter]->mobile_id."'";
                            $result = $this->dbconn->query($num_exist);
                        } catch (Exception $e) {
                            $flag = false;
                            echo $e->getMessage();
                        }
                    } else {
                        try {
                            $new_num = "INSERT INTO user_mobile VALUES (0,'$data->id','".$data->numbers[$num_counter]->mobile_number."','".$data->numbers[$num_counter]->mobile_priority."','".$data->numbers[$num_counter]->mobile_status."')";
                            $result = $this->dbconn->query($new_num);
                        } catch (Exception $e) {
                            $flag = false;
                            echo $e->getMessage();
                        }
                    }
                }
            }

            if (sizeof($data->landline) == 0) {
                try {
                    $landline_exist = "DELETE FROM user_landlines WHERE user_id='".$data->id."'";
                    $result = $this->dbconn->query($landline_exist);
                } catch (Exception $e) {
                    $flag = false;
                    echo $e->getMessage();
                }
            } else {
                for ($landline_counter = 0; $landline_counter < sizeof($data->landline); $landline_counter++) {
                    if ($data->landline[$landline_counter]->landline_id != "" && $data->landline[$landline_counter]->landline_number != "") {
                        try {
                            $landline_exist = "UPDATE user_landlines SET landline_num = '".$data->landline[$landline_counter]->landline_number."', remarks = '".$data->landline[$landline_counter]->landline_remarks."' WHERE landline_id='".$data->landline[$landline_counter]->landline_id."'";
                            $result = $this->dbconn->query($landline_exist);
                        } catch (Exception $e) {
                            $flag = false;
                            echo $e->getMessage();
                        }
                    } else if ($data->landline[$landline_counter]->landline_number == "") {
                        try {
                            $landline_exist = "DELETE FROM user_landlines WHERE landline_id='".$data->landline[$landline_counter]->landline_id."'";
                            $result = $this->dbconn->query($landline_exist);
                        } catch (Exception $e) {
                            $flag = false;
                            echo $e->getMessage();
                        }
                    } else {
                        try {
                            $new_landline = "INSERT INTO user_landlines VALUES (0,'$data->id','".$data->landline[$landline_counter]->landline_number."','".$data->landline[$landline_counter]->landline_remarks."')";
                            $result = $this->dbconn->query($new_landline); 
                        } catch (Exception $e) {
                            $flag = false;
                            echo $e->getMessage();
                        }
                    }
                }
            }

            if ($data->ewi_recipient == "") {
                try {
                    $check_if_existing = "SELECT * FROM user_ewi_status WHERE users_id = '".$data->id."'";
                    $result = $this->dbconn->query($check_if_existing);
                    if ($result->num_rows == 0) {
                        try {
                            $insert_ewi_status = "INSERT INTO user_ewi_status VALUES (0,'Inactive','','".$data->id."')";
                            $result = $this->dbconn->query($insert_ewi_status);
                        } catch (Exception $e) {
                            $flag = false;
                            echo $e->getMessage();
                        }
                    } else {
                        try {
                            $update_existing = "UPDATE user_ewi_status SET status='".$data->ewi_recipient."', remarks='' WHERE users_id = '".$data->id."'";
                            $result = $this->dbconn->query($update_existing);
                        } catch (Exception $e) {
                            $flag = false;
                            echo $e->getMessage();
                        }
                    }
                } catch (Exception $e) {
                    $flag = false;
                    echo $e->getMessage();
                }
            } else {
                try {
                    $check_if_existing = "SELECT * FROM user_ewi_status WHERE users_id = '".$data->id."'";
                    $result = $this->dbconn->query($check_if_existing);
                    if ($result->num_rows == 0) {
                        try {
                            $insert_ewi_status = "INSERT INTO user_ewi_status VALUES (0,'".$data->ewi_recipient."','','".$data->id."')";
                            $result = $this->dbconn->query($insert_ewi_status);
                        } catch (Exception $e) {
                            $flag = false;
                            echo $e->getMessage();
                        }
                    } else {
                        try {
                            $update_existing = "UPDATE user_ewi_status SET status='".$data->ewi_recipient."', remarks='' WHERE users_id = '".$data->id."'";
                            $result = $this->dbconn->query($update_existing);
                        } catch (Exception $e) {
                            $flag = false;
                            echo $e->getMessage();
                        }
                    }
                } catch (Exception $e) {
                    $flag = false;
                    echo $e->getMessage();
                }
            }

            $scope_query = "";
            for ($counter = 0; $counter < sizeof($data->organizations); $counter++) {
                if ($counter == 0) {
                    $scope_query = "org_name = '".$data->organizations[$counter]."'";
                } else {
                    $scope_query = $scope_query." OR org_name = '".$data->organizations[$counter]."'";
                }
            }

            $psgc_query = "";
            for ($counter = 0; $counter < sizeof($data->sites); $counter++) {
                if ($counter == 0) {
                    $psgc_query = "site_code = '".$data->sites[$counter]."'";
                } else {
                    $psgc_query = $psgc_query." OR site_code = '".$data->sites[$counter]."'";
                }   
            }

            try {
                $get_scope = "SELECT org_scope FROM organization WHERE ".$scope_query.";";
                $scope_result = $this->dbconn->query($get_scope);
                $ctr = 0;
                $scope = [];
                if ($scope_result->num_rows != 0) {
                    while ($row = $scope_result->fetch_assoc()) {
                        array_push($scope,$row['org_scope']);
                    }
                }
            } catch (Exception $e) {
                $flag = false;
                echo $e->getMessage();
            }

            try {
                $get_psgc = "SELECT site_id FROM sites WHERE ".$psgc_query.";";
                $psgc_result = $this->dbconn->query($get_psgc);
                $ctr = 0;
                $psgc = [];
                if ($psgc_result->num_rows != 0) {
                    while ($row = $psgc_result->fetch_assoc()) {
                        array_push($psgc,$row['site_id']);
                    }
                }
            } catch (Exception $e) {
                $flag = false;
                echo $e->getMessage();
            }

            try {
                $delete_orgs = "DELETE FROM user_organization WHERE user_id = '".$data->id."'";
                var_dump($delete_orgs);
                $result = $this->dbconn->query($delete_orgs);
            } catch (Exception $e) {
                $flag = false;
                echo $e->getMessage();
            }

            for ($counter = 0; $counter < sizeof($data->sites); $counter++) {
                for ($sub_counter = 0; $sub_counter < sizeof($data->organizations); $sub_counter++) {
                    try {
                        $insert_org = "INSERT INTO user_organization VALUES (0,'".$data->id."','".$psgc[$counter]."','".$data->organizations[$sub_counter]."','".$scope[$sub_counter]."')";
                        $result_org = $this->dbconn->query($insert_org);
                    } catch (Exception $e) {
                        $flag = false;
                        echo $e->getMessage();
                    }
                }
            }
            if ($flag == false) {
                $return_data['return_msg'] = "Error occured, please refresh the page and try again.";
            } else {
                $return_data['return_msg'] = "Successfully updated contact.";
            }
            $return_data['status'] = $flag;
        } else {
            $return_data['status'] = $result;
            $return_data['return_msg'] = "Contact update failed, Please recheck inputs.";
        }
        $return_data['type'] = "updatedCmmtyContact";
        return $return_data;
    }

    public function getAllSites() {
        $sites = [];
        $ctr = 0;
        $all_sites_query = "SELECT * FROM sites;";
        $result = $this->dbconn->query($all_sites_query);
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $sites[$ctr]["site_id"] = $row["site_id"];
                $sites[$ctr]["site_code"] = $row["site_code"];
                $sites[$ctr]["purok"] = $row["purok"];
                $sites[$ctr]["sitio"] = $row["sitio"];
                $sites[$ctr]["barangay"] = $row["barangay"];
                $sites[$ctr]["municipality"] = $row["municipality"];
                $sites[$ctr]["province"] = $row["province"];
                $sites[$ctr]["region"] = $row["region"];
                $sites[$ctr]["psgc_source"] = $row["psgc_source"];
                $ctr++;
            }
        }
        return $sites;
    }

    public function getAllOrganization() {
        $orgs = [];
        $ctr = 0;
        $all_organization_query = "SELECT * FROM organization;";
        $result = $this->dbconn->query($all_organization_query);
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $orgs[$ctr]["org_id"] = $row["org_id"];
                $orgs[$ctr]["org_name"] = $row["org_name"];
                $ctr++;
            }
        }
        return $orgs;
    }

    public function createDwlsContact($data) {
        $flag = true;
        $emails = explode(',',$data->email_address);
        $teams = explode(',',$data->teams);
        try {
            $query_contact_info = "INSERT INTO users VALUES (0,'$data->salutation','$data->firstname','$data->middlename','$data->lastname','$data->nickname','$data->birthdate','$data->gender','$data->contact_active_status');";
            $result = $this->dbconn->query($query_contact_info);
        } catch (Exception $e) {
            $flag = false;
        }

        try {
            $get_last_id = "SELECT LAST_INSERT_ID();";
            $result = $this->dbconn->query($get_last_id);
            $data->id = $result->fetch_assoc()["LAST_INSERT_ID()"];
        } catch (Exception $e) {
            $flag = false;
        }

        if ($emails[0] != "") {
            for ($counter = 0; $counter < sizeof($emails); $counter++) {
                try {
                    $insert_new_emails = "INSERT INTO user_emails VALUES(0,'$data->id','$emails[$counter]')";
                    $result = $this->dbconn->query($insert_new_emails);
                } catch (Exception $e) {
                    $flag = false;
                }
            }
        }

        if ($teams[0] != "") {
            for ($counter = 0; $counter < sizeof($teams); $counter++) {
                $check_if_existing = "SELECT * FROM dewsl_teams WHERE team_name ='$teams[$counter]'";
                $result = $this->dbconn->query($check_if_existing);
                if ($result->num_rows == 0) {
                    $insert_new_teams = "INSERT INTO dewsl_teams VALUES (0,'$teams[$counter]','')";
                    $result = $this->dbconn->query($insert_new_teams);
                    $newly_added_team = "SELECT * FROM dewsl_teams WHERE team_name ='$teams[$counter]'";
                    $result = $this->dbconn->query($newly_added_team);
                    $team_details = $result->fetch_assoc();
                    $insert_team_member = "INSERT INTO dewsl_team_members VALUES (0,'$data->id','".$team_details['team_id']."')";
                    $result = $this->dbconn->query($insert_team_member);
                } else {
                    $team_details = $result->fetch_assoc();
                    $insert_team_member = "INSERT INTO dewsl_team_members VALUES (0,'$data->id','".$team_details['team_id']."')";
                    $result = $this->dbconn->query($insert_team_member);
                }
            }
        }

        for ($num_counter = 0; $num_counter < sizeof($data->numbers); $num_counter++) {
            try {
                $new_num = "INSERT INTO user_mobile VALUES (0,'$data->id','".$data->numbers[$num_counter]->mobile_number."','".$data->numbers[$num_counter]->mobile_priority."','".$data->numbers[$num_counter]->mobile_status."')";
                $result = $this->dbconn->query($new_num);
            } catch (Exception $e) {
                $flag = false;
            }
        }

        for ($landline_counter = 0; $landline_counter < sizeof($data->landline); $landline_counter++) {
            try {
                $new_landline = "INSERT INTO user_landlines VALUES (0,'$data->id','".$data->landline[$landline_counter]->landline_number."','".$data->landline[$landline_counter]->landline_remarks."')";
                $result = $this->dbconn->query($new_landline); 
            } catch (Exception $e) {
                $flag = false;
            }
        }

        if ($flag == false) {
            $return_data['return_msg'] = "Error occured, please refresh the page and try again.";
        } else {
            $return_data['return_msg'] = "Successfully added new contact.";
        }
        $return_data['status'] = $flag;

        $return_data['type'] = "newAddedDwslContact";
        return $return_data;
    }

    public function createCommContact($data) {
        $flag = true;
        try {
            $query_contact_info = "INSERT INTO users VALUES (0,'$data->salutation','$data->firstname','$data->middlename','$data->lastname','$data->nickname','$data->birthdate','$data->gender','$data->contact_active_status');";
            $result = $this->dbconn->query($query_contact_info);
        } catch (Exception $e) {
            $flag = false;
        }

        try {
            $get_last_id = "SELECT LAST_INSERT_ID();";
            $result = $this->dbconn->query($get_last_id);
            $data->id = $result->fetch_assoc()["LAST_INSERT_ID()"];
        } catch (Exception $e) {
            $flag = false;
        }

        for ($num_counter = 0; $num_counter < sizeof($data->numbers); $num_counter++) {
            try {
                $new_num = "INSERT INTO user_mobile VALUES (0,'$data->id','".$data->numbers[$num_counter]->mobile_number."','".$data->numbers[$num_counter]->mobile_priority."','".$data->numbers[$num_counter]->mobile_status."')";
                $result = $this->dbconn->query($new_num);
            } catch (Exception $e) {
                $flag = false;
            }
        }

        for ($landline_counter = 0; $landline_counter < sizeof($data->landline); $landline_counter++) {
            try {
                $new_landline = "INSERT INTO user_landlines VALUES (0,'$data->id','".$data->landline[$landline_counter]->landline_number."','".$data->landline[$landline_counter]->landline_remarks."')";
                $result = $this->dbconn->query($new_landline); 
            } catch (Exception $e) {
                $flag = false;
            }
        }

        $site_query = "";
        for ($counter = 0; $counter < sizeof($data->sites); $counter++) {
            if ($counter == 0) {
                $site_query = "site_code = '".$data->sites[$counter]."'";
            } else {
                $site_query = $site_query." OR site_code = '".$data->sites[$counter]."'";
            }
        }

        $psgc = [];
        $ctr = 0;
        try {
            $get_psgc = "SELECT psgc FROM sites WHERE ".$site_query;
            $psgc_collection = $this->dbconn->query($get_psgc);
            while ($row = $psgc_collection->fetch_assoc()) {
                $psgc[$ctr] = $row['psgc'];
                $ctr++;
            }
        } catch (Exception $e) {
            $flag = false;
        }

        $org_scope_query = "";
        for ($counter = 0; $counter < sizeof($data->organizations); $counter++) {
            if ($counter == 0) {
                $org_scope_query = "org_name = '".$data->organizations[$counter]."'";
            } else {
                $org_scope_query = $org_scope_query." OR org_name = '".$data->organizations[$counter]."'";
            }
        }

        $scopes = [];
        $ctr = 0;
        try {
            $get_org_scope = "SELECT org_scope FROM organization WHERE ".$org_scope_query;
            $scope_collection = $this->dbconn->query($get_org_scope);
            while ($row = $scope_collection->fetch_assoc()) {
                $scopes[$ctr] = $row['org_scope'];
                $ctr++;
            }
        } catch (Exception $e) {
            $flag = false;
        }

        try {
            $delete_orgs = "DELETE FROM user_organization WHERE users_id = '".$data->id."'";
            $result = $this->dbconn->query($delete_orgs);
        } catch (Exception $e) {
            $flag = false;
        }

        for ($counter = 0; $counter < sizeof($psgc); $counter++) {
            for ($sub_counter = 0; $sub_counter < sizeof($scopes); $sub_counter++) {
                try {
                    $insert_org = "INSERT INTO user_organization VALUES (0,'".$data->id."','".$scopes[$sub_counter]."','".$psgc[$counter]."','')";
                    $result_org = $this->dbconn->query($insert_org);
                } catch (Exception $e) {
                    $flag = false;
                }
            }
        }

        try {
            $insert_ewi_status = "INSERT INTO user_ewi_status VALUES (0,'".$data->ewi_recipient."','','".$data->id."')";
            $result = $this->dbconn->query($insert_ewi_status);
        } catch (Exception $e) {
            $flag = false;
        }

        if ($flag == false) {
            $return_data['return_msg'] = "Error occured, please refresh the page and try again.";
        } else {
            $return_data['return_msg'] = "Successfully added new contact.";
        }
        $return_data['status'] = $flag;

        $return_data['type'] = "newAddedCommContact";
        return $return_data;
    }

    public function getSmsForGroups($organizations,$sitenames) {
        $mobile_data = [];
        $mobile_numbers = [];
        $recipient_ids = [];
        $mobile_ids = [];
        $ctr = 0;
        $site_query = "";
        $org_query = "";
        try {
            for ($sub_counter = 0; $sub_counter < sizeof($sitenames); $sub_counter++) {
                if ($sub_counter == 0) {
                    $site_query = "sites.site_code ='".strtoupper($sitenames[$sub_counter])."'";
                } else {
                    $site_query = $site_query." OR sites.site_code ='".strtoupper($sitenames[$sub_counter])."'";
                }
            }

            for ($sub_counter = 0; $sub_counter < sizeof($organizations); $sub_counter++) {
                if ($sub_counter == 0) {
                    $org_query = "user_organization.org_name = '".strtoupper($organizations[$sub_counter])."'";
                } else {
                    $org_query = $org_query." OR user_organization.org_name = '".strtoupper($organizations[$sub_counter])."'";
                }
            }

            $get_mobile_ids_query = "SELECT * FROM users INNER JOIN user_mobile ON users.user_id = user_mobile.user_id LEFT JOIN user_organization ON users.user_id = user_organization.user_id LEFT JOIN sites ON sites.site_id = user_organization.fk_site_id WHERE (".$site_query.") AND (".$org_query.")";
            var_dump($get_mobile_ids_query);
            $mobile_ids_raw = $this->dbconn->query($get_mobile_ids_query);
            if ($mobile_ids_raw->num_rows != 0) {
                while ($row = $mobile_ids_raw->fetch_assoc()) {
                    if (!in_array($row['user_id'],$recipient_ids)) {
                        array_push($recipient_ids,$row['user_id']);    
                        $mobile_numbers[$ctr]['mobile_id'] = $row['mobile_id'];
                        $mobile_numbers[$ctr]['user_id'] = $row['user_id'];
                        $mobile_numbers[$ctr]['sim_num'] = $row['sim_num'];
                        $mobile_numbers[$ctr]['number_priority'] = $row['priority'];
                        $mobile_numbers[$ctr]['mobile_active_status'] = $row['mobile_status'];
                        $mobile_data[$ctr]['user_id'] = $row['user_id'];
                        $mobile_data[$ctr]['salutation'] = $row['salutation'];
                        $mobile_data[$ctr]['firstname'] = $row['firstname'];
                        $mobile_data[$ctr]['middlename'] = $row['middlename'];
                        $mobile_data[$ctr]['lastname'] = $row['lastname'];
                        $mobile_data[$ctr]['site_code'] = $row['site_code'];
                        $mobile_data[$ctr]['site_id'] = $row['site_id'];
                        $mobile_data[$ctr]['site_code'] = $row['site_code'];
                        $mobile_data[$ctr]['purok'] = $row['purok'];
                        $mobile_data[$ctr]['sitio'] = $row['sitio'];
                        $mobile_data[$ctr]['barangay'] = $row['barangay'];
                        $mobile_data[$ctr]['municipality'] = $row['municipality'];
                        $mobile_data[$ctr]['province'] = $row['province'];
                        $mobile_data[$ctr]['region'] = $row['region'];
                        $mobile_data[$ctr]['psgc'] = $row['psgc'];
                        $ctr++;
                    } else {
                        $mobile_numbers[$ctr]['user_id'] = $row['user_id'];
                        $mobile_numbers[$ctr]['mobile_id'] = $row['mobile_id'];
                        $mobile_numbers[$ctr]['sim_num'] = $row['sim_num'];
                        $mobile_numbers[$ctr]['number_priority'] = $row['priority'];
                        $mobile_numbers[$ctr]['mobile_active_status'] = $row['mobile_status'];
                        $ctr++;
                    }
                }
            } else {
                $return_data['status'] = 'success';
                $return_data['data'] = [];
                $return_data['result_msg'] = 'No message fetched.';
                $return_data['type'] = 'fetchGroupSms';
                return $return_data;
            }

            for ($counter = 0; $counter < sizeof($mobile_data); $counter++) {
                $mobile_data[$counter]['mobile_numbers'] = [];
                for ($sub_counter = 0; $sub_counter < sizeof($mobile_numbers); $sub_counter++) {
                    var_dump($mobile_data[$counter]);
                    var_dump($mobile_numbers[$sub_counter]);
                    if ($mobile_data[$counter]['user_id'] == $mobile_numbers[$sub_counter]['user_id']) {
                        array_push($mobile_data[$counter]['mobile_numbers'],$mobile_numbers[$sub_counter]);
                    }
                }
            }

            for ($counter = 0; $counter < sizeof($mobile_data); $counter++) {
                for ($sub_counter = 0; $sub_counter < sizeof($mobile_data[$counter]['mobile_numbers']); $sub_counter++) {
                    array_push($mobile_ids,$mobile_data[$counter]['mobile_numbers'][$sub_counter]['mobile_id']);

                }
            }
            
            $mobile_id_sub_query = "";
            for ($counter = 0; $counter < sizeof($mobile_ids); $counter++) {
                if ($counter == 0 ) {
                    $mobile_id_sub_query = "mobile_id = '".$mobile_ids[$counter]."'";
                } else {
                    $mobile_id_sub_query = $mobile_id_sub_query." OR mobile_id = '".$mobile_ids[$counter]."'";
                }
            }

            $inbox_outbox_collection = [];
            try {
                $inbox_query = "SELECT * FROM newdb.smsinbox_users WHERE ".$mobile_id_sub_query." LIMIT 70";
                $test_fetch_inboxes = $this->dbconn->query($inbox_query);
                if ($test_fetch_inboxes->num_rows != 0) {
                    while ($row = $test_fetch_inboxes->fetch_assoc()) {
                        array_push($inbox_outbox_collection,$row);
                    }
                }

                $outbox_query = "SELECT * FROM newdb.smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE ".$mobile_id_sub_query." LIMIT 70";
                $test_fetch_outboxes = $this->dbconn->query($outbox_query);
                if ($test_fetch_outboxes->num_rows != 0) {
                    while ($row = $test_fetch_outboxes->fetch_assoc()) {
                        array_push($inbox_outbox_collection,$row);
                    }
                }

                $data = [];
                $ctr = 0;
                $inbox_outbox_collection = $this->sort_msgs($inbox_outbox_collection);
                for ($sms_counter = 0; $sms_counter < sizeof($inbox_outbox_collection); $sms_counter++) {
                    if (isset($inbox_outbox_collection[$sms_counter]['outbox_id'])) {
                        for ($contact_counter = 0; $contact_counter < sizeof($mobile_data); $contact_counter++) {
                            for ($number_counter = 0; $number_counter < sizeof($mobile_data[$contact_counter]['mobile_numbers']); $number_counter++) {
                                if ($mobile_data[$contact_counter]['mobile_numbers'][$number_counter]['mobile_id'] == $inbox_outbox_collection[$sms_counter]['mobile_id']) {
                                    $data[$ctr]['sms_id'] = $inbox_outbox_collection[$sms_counter]['outbox_id'];
                                    $data[$ctr]['msg'] = $inbox_outbox_collection[$sms_counter]['sms_msg'];
                                    $data[$ctr]['timestamp'] = $inbox_outbox_collection[$sms_counter]['ts_written'];
                                    $data[$ctr]['timestamp_sent'] = $inbox_outbox_collection[$sms_counter]['ts_sent'];
                                    $data[$ctr]['name'] = 'You';
                                    $data[$ctr]['recipient_user_id'] = $mobile_data[$contact_counter]['user_id'];
                                    $data[$ctr]['recipient_name'] = $mobile_data[$contact_counter]['salutation']." ".$mobile_data[$contact_counter]['firstname']." ".$mobile_data[$contact_counter]['lastname'];
                                    $data[$ctr]['recipient_mobile_id'] = $mobile_data[$contact_counter]['mobile_numbers'][$number_counter]['mobile_id'];
                                    $data[$ctr]['recipient_sim_num'] = $mobile_data[$contact_counter]['mobile_numbers'][$number_counter]['sim_num'];
                                    $data[$ctr]['recipient_site_code'] = $mobile_data[$contact_counter]['site_code'];
                                    $ctr++;
                                }
                            }
                        }
                    } else {  
                        for ($contact_counter = 0; $contact_counter < sizeof($mobile_data); $contact_counter++) {
                            for ($number_counter = 0; $number_counter < sizeof($mobile_data[$contact_counter]['mobile_numbers']); $number_counter++) {
                                if ($mobile_data[$contact_counter]['mobile_numbers'][$number_counter]['mobile_id'] == $inbox_outbox_collection[$sms_counter]['mobile_id']) {
                                    $data[$ctr]['sms_id'] = $inbox_outbox_collection[$sms_counter]['inbox_id'];
                                    $data[$ctr]['msg'] = $inbox_outbox_collection[$sms_counter]['sms_msg'];
                                    $data[$ctr]['timestamp'] = $inbox_outbox_collection[$sms_counter]['ts_received'];
                                    $data[$ctr]['user_id'] = $mobile_data[$contact_counter]['user_id'];
                                    $data[$ctr]['name'] = $mobile_data[$contact_counter]['salutation']." ".$mobile_data[$contact_counter]['firstname']." ".$mobile_data[$contact_counter]['lastname'];
                                    $data[$ctr]['mobile_id'] = $mobile_data[$contact_counter]['mobile_numbers'][$number_counter]['mobile_id'];
                                    $data[$ctr]['sim_num'] = $mobile_data[$contact_counter]['mobile_numbers'][$number_counter]['sim_num'];
                                    $data[$ctr]['site_code'] = $mobile_data[$contact_counter]['site_code'];
                                    $ctr++;
                                }
                            }
                        }
                    }
                }
                $return_data['status'] = 'success';
                $return_data['data'] = $data;
                $return_data['result_msg'] = 'Messages fetched.';
                
            } catch (Exception $e) {
                $return_data['result_msg'] = 'Message fetch failed, please contact SWAT for more details';
                $return_data['status'] = 'failed';
            }
        } catch (Exception $e) {
            $return_data['status'] = 'failed';
            $return_data['result_msg'] = 'Message fetch failed, please contact SWAT for more details';
        }
        $return_data['type'] = 'fetchGroupSms';
        return $return_data;
    }

    public function getSmsPerContact($fullname,$timestamp,$limit=20) {
        $contact_details_raw = explode(" ",$fullname);
        $contact_details = [];
        $inbox_outbox_collection = [];
        $number_query = "";
        $data = [];
        $mobile_ids = [];
        $return_data = [];
        $ctr = 0;

        $where_query = "";
        for ($counter = 0; $counter < sizeof($contact_details_raw); $counter++) {
            if ($contact_details_raw[$counter] != "" && $contact_details_raw[$counter] != "-") {
                array_push($contact_details,$contact_details_raw[$counter]);
            }
        }
        $org_team_checker_query = "SELECT * FROM dewsl_teams WHERE team_name LIKE '%".$contact_details[0]."%'";
        $is_org = $this->dbconn->query($org_team_checker_query);

        if ($is_org->num_rows != 0) {
            for ($counter = 2; $counter < sizeof($contact_details); $counter++) {
                $where_query = $where_query."AND (users.firstname LIKE '%".trim($contact_details[$counter],";")."%' OR users.lastname LIKE '%".trim($contact_details[$counter],";")."%') ";
            }
            $get_numbers_query = "SELECT * FROM user_mobile INNER JOIN users ON user_mobile.user_id = users.user_id RIGHT JOIN dewsl_team_members ON dewsl_team_members.users_users_id = users.user_id RIGHT JOIN dewsl_teams ON dewsl_teams.team_id = dewsl_team_members.dewsl_teams_team_id WHERE dewsl_teams.team_name LIKE '%".$contact_details[0]."%' ".$where_query.";";
        } else {
            for ($counter = 3; $counter < sizeof($contact_details); $counter++) {
                $where_query = $where_query."AND (users.firstname LIKE '%".trim($contact_details[$counter],";")."%' OR users.lastname LIKE '%".trim($contact_details[$counter],";")."%') ";
            }
            $get_numbers_query = "SELECT * FROM user_mobile INNER JOIN users ON user_mobile.user_id = users.user_id RIGHT JOIN user_organization ON user_organization.user_id = users.user_id RIGHT JOIN organization ON user_organization.org_name = organization.org_name RIGHT JOIN sites ON user_organization.fk_site_id = sites.site_id WHERE organization.org_name LIKE '%".$contact_details[1]."%' AND sites.site_code LIKE '%".$contact_details[0]."%' AND users.salutation = '".$contact_details[2]."' ".$where_query.";";
        }

        $numbers = $this->dbconn->query($get_numbers_query);
        if ($numbers->num_rows != 0) {
            while ($row = $numbers->fetch_assoc()) {
                if ($ctr == 0) {
                    $number_query = "user_mobile.sim_num LIKE '%".$row['sim_num']."%' ";
                    $ctr++;
                } else {
                    $number_query = $number_query."OR user_mobile.sim_num LIKE '%".$row['sim_num']."%' ";
                    $ctr++;
                }
            }
        } else {
            echo "No number fetched!";
        }

        try {
            $smsinbox_query = "SELECT * FROM smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id RIGHT JOIN users ON user_mobile.user_id = users.user_id WHERE ".$number_query." LIMIT $limit;";

            $fetch_inbox = $this->dbconn->query($smsinbox_query);
            if ($fetch_inbox->num_rows != 0) {
                while($row = $fetch_inbox->fetch_assoc()) {
                    array_push($inbox_outbox_collection,$row);
                }
            } else {
                echo "No message fetched!";
            }

            $smsoutbox_query = "SELECT * FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id RIGHT JOIN user_mobile ON user_mobile.mobile_id = smsoutbox_user_status.mobile_id RIGHT JOIN users ON users.user_id = user_mobile.user_id WHERE ".$number_query." LIMIT $limit";
            $fetch_outbox = $this->dbconn->query($smsoutbox_query);
            
            if ($fetch_outbox->num_rows != 0) {
                while($row = $fetch_outbox->fetch_assoc()) {
                    array_push($inbox_outbox_collection,$row);
                }
            } else {
                echo "No message fetched!";
            }

            $inbox_outbox_collection = $this->sort_msgs($inbox_outbox_collection);
            var_dump($inbox_outbox_collection);
            exit;

            $ctr = 0;
            for ($sms_counter = 0; $sms_counter < sizeof($inbox_outbox_collection); $sms_counter++) {
                if (isset($inbox_outbox_collection[$sms_counter]['outbox_id'])) {
                    $data[$ctr]['id'] = $inbox_outbox_collection[$sms_counter]['outbox_id'];
                    $data[$ctr]['ts_written'] = $inbox_outbox_collection[$sms_counter]['ts_written'];
                    $data[$ctr]['msg'] = $inbox_outbox_collection[$sms_counter]['sms_msg'];
                    $data[$ctr]['stat_id'] = $inbox_outbox_collection[$sms_counter]['stat_id'];
                    $data[$ctr]['mobile_id'] = $inbox_outbox_collection[$sms_counter]['mobile_id'];
                    $data[$ctr]['ts_sent'] = $inbox_outbox_collection[$sms_counter]['ts_sent'];
                    $data[$ctr]['sent_status'] = $inbox_outbox_collection[$sms_counter]['send_status'];
                    $data[$ctr]['web_status'] = $inbox_outbox_collection[$sms_counter]['web_status'];
                    $data[$ctr]['gsm_id'] = $inbox_outbox_collection[$sms_counter]['gsm_id'];
                    $data[$ctr]['user_id'] = $inbox_outbox_collection[$sms_counter]['user_id'];
                    $data[$ctr]['sim_num'] = $inbox_outbox_collection[$sms_counter]['sim_num'];
                    $data[$ctr]['firstname'] = $inbox_outbox_collection[$sms_counter]['firstname'];
                    $data[$ctr]['lastname'] = $inbox_outbox_collection[$sms_counter]['lastname'];
                    $data[$ctr]['active_status'] = $inbox_outbox_collection[$sms_counter]['status'];
                    $ctr++;
                } else {
                    $data[$ctr]['id'] = $inbox_outbox_collection[$sms_counter]['inbox_id'];
                    $data[$ctr]['ts_received'] = $inbox_outbox_collection[$sms_counter]['ts_received'];
                    $data[$ctr]['mobile_id'] = $inbox_outbox_collection[$sms_counter]['mobile_id'];
                    $data[$ctr]['msg'] = $inbox_outbox_collection[$sms_counter]['sms_msg'];
                    $data[$ctr]['read_status'] = $inbox_outbox_collection[$sms_counter]['read_status'];
                    $data[$ctr]['web_status'] = $inbox_outbox_collection[$sms_counter]['web_status'];
                    $data[$ctr]['gsm_id'] = $inbox_outbox_collection[$sms_counter]['gsm_id'];
                    $data[$ctr]['user_id'] = $inbox_outbox_collection[$sms_counter]['user_id'];
                    $data[$ctr]['sim_num'] = $inbox_outbox_collection[$sms_counter]['sim_num'];
                    $data[$ctr]['firstname'] = $inbox_outbox_collection[$sms_counter]['firstname'];
                    $data[$ctr]['lastname'] = $inbox_outbox_collection[$sms_counter]['lastname'];
                    $data[$ctr]['active_status'] = $inbox_outbox_collection[$sms_counter]['status'];
                    $ctr++;
                }
            }
            $return_data['status'] = 'success';
            $return_data['data'] = $data;
            $return_data['result_msg'] = 'Messages fetched.';
        } catch (Exception $e) {
            $return_data['result_msg'] = 'Message fetch failed, please contact SWAT for more details';
            $return_data['status'] = 'failed';
        }

        $return_data['type'] = 'fetchSms';
        return $return_data;
    }

    // UTILITIES

    function sort_msgs($arr) {
        var_dump($arr);
    }

    // function sort_msgs($arr) {
    //     $size = count($arr);
    //     for ($i=0; $i<$size; $i++) {
    //         for ($j=0; $j<$size-1-$i; $j++) {
    //             if (isset($arr[$j]['outbox_id'])) {
    //                 if (isset($arr[$j+1]['ts_written'])) {
    //                     if (strtotime($arr[$j+1]['ts_written']) < strtotime($arr[$j]['ts_written'])) {
    //                         $this->swap($arr, $j, $j+1);
    //                     }
    //                 } else {
    //                     if (strtotime($arr[$j+1]['ts_received']) < strtotime($arr[$j]['ts_written'])) {
    //                         $this->swap($arr, $j, $j+1);
    //                     }
    //                 }
    //             } else {
    //                 if (isset($arr[$j+1]['ts_received'])) {
    //                     if (strtotime($arr[$j+1]['ts_received']) < strtotime($arr[$j]['ts_received'])) {
    //                         $this->swap($arr, $j, $j+1);
    //                     }
    //                 } else {
    //                     if (strtotime($arr[$j+1]['ts_written']) < strtotime($arr[$j]['ts_received'])) {
    //                         $this->swap($arr, $j, $j+1);
    //                     }
    //                 }
    //             }

    //         }
    //     }
    //     return $arr;
    // }
     
    function swap(&$arr, $a, $b) {
        $tmp = $arr[$a];
        $arr[$a] = $arr[$b];
        $arr[$b] = $tmp;
    }
}
