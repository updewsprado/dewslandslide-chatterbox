<?php
namespace MyApp;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class ChatMessageModel {
    protected $dbconn;

    public function __construct() {
        $this->initDBforCB();
        $this->switchDBforCB();
    }

    public function initDBforCB() {
        $host = "localhost";
        $usr = "root";
        $pwd = "senslope";

        // $host = "localhost";
        // $usr = "root";
        // $pwd = "senslope";
        $dbname = "comms_db";
        $this->dbconn = new \mysqli($host, $usr, $pwd, $dbname);
        if ($this->dbconn->connect_error) {
            die("Connection failed: " . $this->dbconn->connect_error);
        } else {
            echo "Connection Established for comms_db... \n";
            return true;
        }
    }

    function switchDBforCB() {
        $host = "localhost";
        $usr = "root";
        $pwd = "senslope";

        // $host = "localhost";
        // $usr = "root";
        // $pwd = "senslope";

        $analysis_db = "senslopedb";
        $this->senslope_dbconn = new \mysqli($host, $usr, $pwd, $analysis_db);
        if ($this->senslope_dbconn->connect_error) {
            die("Connection failed: " . $this->senslope_dbconn->connect_error);
        } else {
            echo "Connection Established for senslopedb... \n";
            return true;
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

    public function getQuickInboxMain($isForceLoad=false) {

        $start = microtime(true);
        $qiResults = $this->getQuickInboxMessages();
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

    public function getQuickInboxMessages($periodDays = 7) {
        $get_all_sms_from_period = "SELECT * FROM (
                SELECT max(inbox_id) as inbox_id FROM (
                    SELECT smsinbox_users.inbox_id, smsinbox_users.ts_sms, smsinbox_users.mobile_id, smsinbox_users.sms_msg, smsinbox_users.read_status, smsinbox_users.web_status,smsinbox_users.gsm_id,user_mobile.sim_num, CONCAT(sites.site_code,' ',user_organization.org_name, ' - ', users.lastname, ', ', users.firstname) as full_name
                    FROM smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id
                    INNER JOIN users ON user_mobile.user_id = users.user_id 
                    INNER JOIN user_organization ON users.user_id = user_organization.user_id 
                    INNER JOIN sites ON user_organization.fk_site_id = sites.site_id 
                    WHERE smsinbox_users.ts_sms > (now() - interval 7 day)
                    ) as smsinbox 
                GROUP BY full_name) as quickinbox 
            INNER JOIN (
                SELECT smsinbox_users.inbox_id, smsinbox_users.ts_sms, smsinbox_users.mobile_id, smsinbox_users.sms_msg, smsinbox_users.read_status, smsinbox_users.web_status,smsinbox_users.gsm_id,user_mobile.sim_num, CONCAT(sites.site_code,' ',user_organization.org_name, ' - ', users.lastname, ', ', users.firstname) as full_name
                FROM smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id
                INNER JOIN users ON user_mobile.user_id = users.user_id 
                INNER JOIN user_organization ON users.user_id = user_organization.user_id 
                INNER JOIN sites ON user_organization.fk_site_id = sites.site_id 
                WHERE smsinbox_users.ts_sms > (now() - interval 7 day) ORDER BY smsinbox_users.ts_sms desc) as smsinbox2 
            USING(inbox_id) ORDER BY ts_sms";

        $get_all_sms_from_period_employee = "SELECT * FROM (SELECT MAX(inbox_id) AS inbox_id FROM (SELECT smsinbox_users.inbox_id,smsinbox_users.ts_sms,smsinbox_users.mobile_id,smsinbox_users.sms_msg,smsinbox_users.read_status,smsinbox_users.web_status,smsinbox_users.gsm_id,user_mobile.sim_num,
            CONCAT(dewsl_teams.team_code,' - ', users.lastname, ', ', users.firstname) AS full_name
            FROM
                smsinbox_users
            INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id
            INNER JOIN users ON user_mobile.user_id = users.user_id
            INNER JOIN dewsl_team_members ON users.user_id = dewsl_team_members.users_users_id 
                                    INNER JOIN dewsl_teams ON dewsl_team_members.dewsl_teams_team_id = dewsl_teams.team_id
            WHERE
                smsinbox_users.ts_sms > (NOW() - INTERVAL 7 DAY)) AS smsinbox
            GROUP BY full_name) AS quickinbox INNER JOIN (SELECT smsinbox_users.inbox_id,smsinbox_users.ts_sms,smsinbox_users.mobile_id,smsinbox_users.sms_msg,smsinbox_users.read_status,smsinbox_users.web_status,smsinbox_users.gsm_id,user_mobile.sim_num,CONCAT(dewsl_teams.team_code,' - ', users.lastname, ', ', users.firstname) AS full_name
            FROM
                smsinbox_users
            INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id
            INNER JOIN users ON user_mobile.user_id = users.user_id
            INNER JOIN dewsl_team_members ON users.user_id = dewsl_team_members.users_users_id 
                                    INNER JOIN dewsl_teams ON dewsl_team_members.dewsl_teams_team_id = dewsl_teams.team_id
            WHERE
                smsinbox_users.ts_sms > (NOW() - INTERVAL 7 DAY)
            ORDER BY smsinbox_users.ts_sms DESC) AS smsinbox2 USING (inbox_id)
            ORDER BY ts_sms";

        $full_query = "SELECT * FROM (".$get_all_sms_from_period.") as community UNION SELECT * FROM (".$get_all_sms_from_period_employee.") as employee ORDER BY ts_sms";

        $this->checkConnectionDB($full_query);
        $sms_result_from_period = $this->dbconn->query($full_query);

        $full_data['type'] = 'smsloadquickinbox';
        $distinct_numbers = "";
        $all_numbers = [];
        $all_messages = [];
        $quick_inbox_messages = [];
        $ctr = 0;

        if ($sms_result_from_period->num_rows > 0) {
            while ($row = $sms_result_from_period->fetch_assoc()) {
                $normalized_number = substr($row["sim_num"], -10);
                $all_messages[$ctr]['sms_id'] = $row['inbox_id'];
                $all_messages[$ctr]['full_name'] = strtoupper($row['full_name']);
                $all_messages[$ctr]['user_number'] = $normalized_number;
                $all_messages[$ctr]['mobile_id'] = $row['mobile_id'];
                $all_messages[$ctr]['msg'] = $row['sms_msg'];
                $all_messages[$ctr]['ts_received'] = $row['ts_sms'];
                $ctr++;
            }

            $full_data['data'] = $all_messages;
        } else {
            echo "0 results\n";
            $full_data['data'] = null;
        }

        // echo "JSON DATA: " . json_encode($full_data);
        return $this->utf8_encode_recursive($full_data);
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
        $sqlOutbox = "SELECT DISTINCT 'You' as user,sms_msg as msg, ts_written as timestamp,timestamp_sent as timestampsent FROM smsoutbox_users WHERE sms_msg LIKE '%$searchKey%'";

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

    public function getContactSuggestions($queryName = "") {
        $sql = "SELECT * FROM (SELECT UPPER(CONCAT(sites.site_code,' ',user_organization.org_name,' - ',users.lastname,', ',users.firstname)) as fullname,users.user_id as id FROM users INNER JOIN user_organization ON users.user_id = user_organization.user_id RIGHT JOIN sites ON sites.site_id = user_organization.fk_site_id RIGHT JOIN user_mobile ON user_mobile.user_id = users.user_id UNION SELECT UPPER(CONCAT(dewsl_teams.team_name,' - ',users.salutation,' ',users.lastname,', ',users.firstname)) as fullname,users.user_id as id FROM users INNER JOIN dewsl_team_members ON users.user_id = dewsl_team_members.users_users_id RIGHT JOIN dewsl_teams ON dewsl_team_members.dewsl_teams_team_id = dewsl_teams.team_id RIGHT JOIN user_mobile ON user_mobile.user_id = users.user_id) as fullcontact WHERE fullname LIKE '%$queryName%' or id LIKE '%$queryName%'";

        $this->checkConnectionDB($sql);
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadnamesuggestions';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['fullname'] = $this->convertNameToUTF8($row['fullname']);
                $dbreturn[$ctr]['id'] = $row['id'];
                $ctr = $ctr + 1;
            }

            $dbreturn = $this->utf8_encode_recursive($dbreturn);

            $fullData['data'] = $dbreturn;
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
        $query = "SELECT DISTINCT users.user_id,users.firstname,users.lastname,users.middlename,users.salutation,users.status FROM users INNER JOIN user_organization ON users.user_id = user_organization.user_id;";
        $result = $this->dbconn->query($query);
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $returnCmmtyContacts[$ctr]['user_id'] = $row['user_id'];
                $returnCmmtyContacts[$ctr]['salutation'] = $row['salutation'];
                $returnCmmtyContacts[$ctr]['firstname'] = $row['firstname'];
                $returnCmmtyContacts[$ctr]['lastname'] = $row['lastname'];
                $returnCmmtyContacts[$ctr]['middlename'] = $row['middlename'];
                $returnCmmtyContacts[$ctr]['active_status'] = $row['status'];
                $ctr++;
            }
        } else {
            echo "No results..";
        }
        $returnData['type'] = 'fetchedCmmtyContacts';
        $returnData['data'] = $returnCmmtyContacts;
        return $this->utf8_encode_recursive($returnData);
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
        return $this->utf8_encode_recursive($returnData);
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
                    $returnOrg[$ctr]['org_users_id'] = $row['org_id'];
                    $returnOrg[$ctr]['org_id'] = $row['organization_id'];
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
        $finSite = [];
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

            // if (sizeof($data->landline) == 0) {
            //     try {
            //         $landline_exist = "DELETE FROM user_landlines WHERE user_id='".$data->id."'";
            //         $result = $this->dbconn->query($landline_exist);
            //     } catch (Exception $e) {
            //         $flag = false;
            //     }
            // } else {
            //     for ($landline_counter = 0; $landline_counter < sizeof($data->landline); $landline_counter++) {
            //         if ($data->landline[$landline_counter]->landline_id != "" && $data->landline[$landline_counter]->landline_number != "") {
            //             try {
            //                 $landline_exist = "UPDATE user_landlines SET landline_num = '".$data->landline[$landline_counter]->landline_number."', remarks = '".$data->landline[$landline_counter]->landline_remarks."' WHERE landline_id='".$data->landline[$landline_counter]->landline_id."'";
            //                 $result = $this->dbconn->query($landline_exist);
            //             } catch (Exception $e) {
            //                 $flag = false;
            //             }
            //         } else if ($data->landline[$landline_counter]->landline_number == "") {
            //             try {
            //                 $landline_exist = "DELETE FROM user_landlines WHERE landline_id='".$data->landline[$landline_counter]->landline_id."'";
            //                 $result = $this->dbconn->query($landline_exist);
            //             } catch (Exception $e) {
            //                 $flag = false;
            //             }
            //         } else {
            //             try {
            //                 $new_landline = "INSERT INTO user_landlines VALUES (0,'$data->id','".$data->landline[$landline_counter]->landline_number."','".$data->landline[$landline_counter]->landline_remarks."')";
            //                 $result = $this->dbconn->query($new_landline); 
            //             } catch (Exception $e) {
            //                 $flag = false;
            //             }
            //         }
            //     }
            // }

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
        $query_contact_info = "UPDATE users SET firstname='$data->firstname',lastname='$data->lastname',middlename='$data->middlename',nickname='$data->nickname',salutation='$data->salutation',birthday='$data->birthdate',sex='$data->gender',status=$data->contact_active_status WHERE user_id = $data->user_id;";
        $result = $this->dbconn->query($query_contact_info);
        if ($result == true) {
            if (sizeof($data->numbers) == 0) {
                try {
                    $num_exist = "DELETE FROM user_mobile WHERE user_id='".$data->user_id."'";
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
                    $landline_exist = "DELETE FROM user_landlines WHERE user_id='".$data->user_id."'";
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
                    $check_if_existing = "SELECT * FROM user_ewi_status WHERE users_id = '".$data->user_id."'";
                    $result = $this->dbconn->query($check_if_existing);
                    if ($result->num_rows == 0) {
                        try {
                            $insert_ewi_status = "INSERT INTO user_ewi_status VALUES (0,'".$data->ewi_recipient."','','".$data->user_id."')";
                            $result = $this->dbconn->query($insert_ewi_status);
                        } catch (Exception $e) {
                            $flag = false;
                            echo $e->getMessage();
                        }
                    } else {
                        try {
                            $update_existing = "UPDATE user_ewi_status SET status='".$data->ewi_recipient."', remarks='' WHERE users_id = '".$data->user_id."'";
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
                $delete_orgs = "DELETE FROM user_organization WHERE user_id = '".$data->user_id."'";
                $result = $this->dbconn->query($delete_orgs);
            } catch (Exception $e) {
                $flag = false;
                echo $e->getMessage();
            }

            for ($counter = 0; $counter < sizeof($data->sites); $counter++) {
                for ($sub_counter = 0; $sub_counter < sizeof($data->organizations); $sub_counter++) {
                    try {
                        $insert_org = "INSERT INTO user_organization VALUES (0,'".$data->user_id."','".$psgc[$counter]."','".$data->organizations[$sub_counter]."','".$scope[$sub_counter]."')";
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
                $new_num = "INSERT INTO user_mobile VALUES (0,'$data->id','".$data->numbers[$num_counter]->mobile_number."','".$data->numbers[$num_counter]->mobile_priority."','".$data->numbers[$num_counter]->mobile_status."','0')";
                $result = $this->dbconn->query($new_num);
            } catch (Exception $e) {
                $flag = false;
                echo "false";
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
    }

    // NEW CODE STARTS HERE

    function getMessageConversations($details, $limit = 20) {
        $inbox_outbox_collection = [];
        $temp_timestamp = [];
        $sorted_sms = [];

        if ($details['number'] == "N/A") {
            $mobile_number = $this->getMobileDetails($details);
            $details['number'] = substr($mobile_number[0]['sim_num'], -10);
        }

        $inbox_query = "SELECT smsinbox_users.inbox_id as convo_id, mobile_id, 
                        smsinbox_users.ts_sms as ts_received, null as ts_written, null as ts_sent, smsinbox_users.sms_msg,
                        smsinbox_users.read_status, smsinbox_users.web_status, smsinbox_users.gsm_id ,
                        null as send_status , ts_sms as timestamp , '".$details['full_name']."' as user from smsinbox_users WHERE mobile_id = (SELECT mobile_id FROM user_mobile where sim_num LIKE '%".$details['number']."%') ";
        $outbox_query = "SELECT smsoutbox_users.outbox_id as convo_id, mobile_id,
                        null as ts_received, ts_written, ts_sent, sms_msg , null as read_status,
                        web_status, gsm_id , send_status , ts_written as timestamp, 'You' as user FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE smsoutbox_user_status.mobile_id = 
                        (SELECT mobile_id FROM user_mobile where sim_num LIKE '%".$details['number']."%')";


        $full_query = "SELECT * FROM (".$inbox_query." UNION ".$outbox_query.") as full_contact group by sms_msg order by timestamp desc limit 20;";

        $fetch_convo = $this->dbconn->query($full_query);
        if ($fetch_convo->num_rows != 0) {
            while($row = $fetch_convo->fetch_assoc()) {
                array_push($inbox_outbox_collection,$row);
            }
        } else {
            echo "No message fetched!";
        }

        $full_data = [];
        $full_data['full_name'] = $details['full_name'];
        $full_data['recipients'] = $mobile_number;
        $full_data['type'] = "loadSmsConversation";
        $full_data['data'] = $inbox_outbox_collection;
        return $full_data;
    }

    function getMessageConversationsForMultipleContact($details) {
        $temp = [];
        $mobile_id_container = [];
        $counter = 0;
        $inbox_outbox_collection = [];
        foreach($details as $raw) {
            $temp['first_name'] =  $raw->firstname;
            $temp['last_name'] = $raw->lastname;
            $mobile_id = $this->getMobileDetails($temp);
            array_push($mobile_id_container,$mobile_id);
        }

        foreach ($mobile_id_container as $mobile_data) {
            if ($counter == 0) {
                $outbox_filter_query = "smsoutbox_user_status.mobile_id = ".$mobile_data[0]['mobile_id'];
                $inbox_filter_query = "smsinbox_users.mobile_id = ".$mobile_data[0]['mobile_id'];
                $counter++;
            } else {
                $outbox_filter_query = $outbox_filter_query." OR smsoutbox_user_status.mobile_id = ".$mobile_data[0]['mobile_id']." ";
                $inbox_filter_query = $inbox_filter_query." OR smsinbox_users.mobile_id = ".$mobile_data[0]['mobile_id']." ";
            }
        }

        $inbox_query = "SELECT smsinbox_users.inbox_id as convo_id, smsinbox_users.mobile_id, 
                        smsinbox_users.ts_sms as ts_received, null as ts_written, null as ts_sent, smsinbox_users.sms_msg,
                        smsinbox_users.read_status, smsinbox_users.web_status, smsinbox_users.gsm_id ,
                        null as send_status , ts_sms as timestamp, UPPER(CONCAT(sites.site_code,' ',user_organization.org_name, ' - ', users.lastname, ', ', users.firstname)) as user from smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id 
                        INNER JOIN users ON users.user_id = user_mobile.user_id INNER JOIN user_organization ON users.user_id = user_organization.user_id INNER JOIN sites ON user_organization.fk_site_id = sites.site_id WHERE ".$inbox_filter_query."";

        $outbox_query = "SELECT smsoutbox_users.outbox_id as convo_id, mobile_id,
                        null as ts_received, ts_written, ts_sent, sms_msg , null as read_status,
                        web_status, gsm_id , send_status , ts_written as timestamp, 'You' as user FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE ".$outbox_filter_query."";
        $full_query = "SELECT * FROM (".$inbox_query." UNION ".$outbox_query.") as full_contact group by sms_msg order by timestamp desc limit 70;";

        $fetch_convo = $this->dbconn->query($full_query);
        if ($fetch_convo->num_rows != 0) {
            while($row = $fetch_convo->fetch_assoc()) {
                array_push($inbox_outbox_collection,$row);
            }
        } else {
            echo "No message fetched!";
        }

        $full_data = [];
        $full_data['type'] = "loadSmsConversation";
        $full_data['data'] = $inbox_outbox_collection;
        $full_data['recipients'] = $mobile_id_container;
        return $full_data; 
    }

    function getMessageConversationsPerSites($offices, $sites) {
        $counter = 0;
        $inbox_filter_query = "";
        $outbox_filter_query = "";
        $inbox_outbox_collection = [];
        $convo_id_container = [];
        $contact_lists = $this->getMobileDetailsViaOfficeAndSitename($offices,$sites);

        foreach ($contact_lists as $mobile_data) {
            if ($counter == 0) {
                $outbox_filter_query = "smsoutbox_user_status.mobile_id = ".$mobile_data['mobile_id'];
                $inbox_filter_query = "smsinbox_users.mobile_id = ".$mobile_data['mobile_id'];
                $counter++;
            } else {
                $outbox_filter_query = $outbox_filter_query." OR smsoutbox_user_status.mobile_id = ".$mobile_data['mobile_id']." ";
                $inbox_filter_query = $inbox_filter_query." OR smsinbox_users.mobile_id = ".$mobile_data['mobile_id']." ";
            }
        }

        $inbox_query = "SELECT smsinbox_users.inbox_id as convo_id, smsinbox_users.mobile_id, 
                        smsinbox_users.ts_sms as ts_received, null as ts_written, null as ts_sent, smsinbox_users.sms_msg,
                        smsinbox_users.read_status, smsinbox_users.web_status, smsinbox_users.gsm_id ,
                        null as send_status , ts_sms as timestamp, UPPER(CONCAT(sites.site_code,' ',user_organization.org_name, ' - ', users.lastname, ', ', users.firstname)) as user from smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id 
                        INNER JOIN users ON users.user_id = user_mobile.user_id INNER JOIN user_organization ON users.user_id = user_organization.user_id INNER JOIN sites ON user_organization.fk_site_id = sites.site_id WHERE ".$inbox_filter_query."";

        $outbox_query = "SELECT smsoutbox_users.outbox_id as convo_id, mobile_id,
                        null as ts_received, ts_written, ts_sent, sms_msg , null as read_status,
                        web_status, gsm_id , send_status , ts_written as timestamp, 'You' as user FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE ".$outbox_filter_query."";
        $full_query = "SELECT * FROM (".$inbox_query." UNION ".$outbox_query.") as full_contact group by sms_msg order by timestamp desc limit 70;";
        $fetch_convo = $this->dbconn->query($full_query);
        if ($fetch_convo->num_rows != 0) {
            while($row = $fetch_convo->fetch_assoc()) {
                $tag = $this->fetchSmsTags($row['convo_id']);
                if (sizeOf($tag['data']) == 0) {
                    $row['hasTag'] = 0;
                } else {
                    $row['hasTag'] = 1;
                }
                array_push($inbox_outbox_collection,$row);
            }
        } else {
            echo "No message fetched!";
        }

        $title_collection = [];
        foreach ($inbox_outbox_collection as $raw) {
            if ($raw['user'] == 'You') {
                $titles = $this->getSentStatusForGroupConvos($raw['sms_msg'],$raw['timestamp'], $raw['mobile_id']);
                $constructed_title = "";
                foreach ($titles as $concat_title) {
                    if ($concat_title['status'] >= 5 ) {
                        $constructed_title = $constructed_title.$concat_title['full_name']." (SENT) <split>";
                    } else if ($concat_title['status'] < 5 && $concat_title >= 1) {
                        $constructed_title = $constructed_title.$concat_title['full_name']." (RESENDING) <split>";
                    } else {
                        $constructed_title = $constructed_title.$concat_title['full_name']." (FAIL) <split>";
                    }
                }
                array_push($title_collection, $constructed_title);
            } else {
                array_push($title_collection, $raw['user']);
            }
        }

        $full_data = [];
        $full_data['type'] = "loadSmsConversation";
        $full_data['data'] = $inbox_outbox_collection;
        $full_data['titles'] = array_reverse($title_collection);
        $full_data['recipients'] = $contact_lists;
        return $full_data;
    }

    function getSentStatusForGroupConvos($sms_msg, $timestamp, $mobile_id) {
        $status_container = [];
        $get_sent_status_query = "SELECT smsoutbox_users.outbox_id as sms_id, smsoutbox_user_status.send_status as status, CONCAT(users.lastname,', ',users.firstname) as full_name FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id INNER JOIN user_mobile ON smsoutbox_user_status.mobile_id = user_mobile.mobile_id INNER JOIN users ON user_mobile.user_id = users.user_id WHERE sms_msg = '".$sms_msg."' AND ts_written = '".$timestamp."';";
        $sent_status = $this->dbconn->query($get_sent_status_query);
        if ($sent_status->num_rows !=0) {
            while ($row = $sent_status->fetch_assoc()) {
                array_push($status_container, $row);
            }
        } else {
            echo "No sent status fetched.\n\n PMS FIRED!";
        }
        return $status_container;
    }

    function getMobileDetails($details) {
        $mobile_number_container = [];
        if (isset($details->mobile_id) == false ) {
            $mobile_number_query = "SELECT * FROM users NATURAL JOIN user_mobile WHERE users.firstname LIKE '%".$details['first_name']."%' AND users.lastname LIKE '%".$details['last_name']."%';";
        } else {
            $mobile_number_query = "SELECT * FROM users NATURAL JOIN user_mobile WHERE mobile_id = '".$details->mobile_id."';";
        }

        $mobile_number = $this->dbconn->query($mobile_number_query);
        if ($mobile_number->num_rows != 0) {
            while ($row = $mobile_number->fetch_assoc()) {
                array_push($mobile_number_container, $row);
            }
        } else {
            echo "No numbers fetched!";
        }
        return $mobile_number_container;
    }

    function getMobileDetailsViaOfficeAndSitename($offices,$sites) {
        $where = "";
        $counter = 0;
        $site_office_query = "";
        $mobile_data_container = [];
        foreach ($offices as $office) {
            foreach ($sites as $site) {
                if ($counter == 0) {
                    $site_office_query = "(org_name = '".$office."' AND fk_site_id = '".$site."')";
                } else {
                    $site_office_query = $site_office_query." OR (org_name = '".$office."' AND fk_site_id = '".$site."')";
                }
                $counter++;
            }
        }

        $mobile_data_query = "SELECT * FROM user_organization INNER JOIN users ON user_organization.user_id = users.user_id INNER JOIN user_mobile ON user_mobile.user_id = users.user_id INNER JOIN sites ON sites.site_id = '".$site."' WHERE ".$site_office_query.";";

        $mobile_number = $this->dbconn->query($mobile_data_query);
        while ($row = $mobile_number->fetch_assoc()) {
            array_push($mobile_data_container, $row);
        }
        return $mobile_data_container;
    }

    function sendSms($recipients, $message) {
        $sms_status_container = [];
        $current_ts = date("Y-m-d H:i:s", time());
        foreach ($recipients as $recipient) {
            $insert_smsoutbox_query = "INSERT INTO smsoutbox_users VALUES (0,'".$current_ts."','central','".$message."')";
            $smsoutbox = $this->dbconn->query($insert_smsoutbox_query);
            $convo_id = $this->dbconn->insert_id;
            if ($smsoutbox == true) {
                $insert_smsoutbox_status = "INSERT INTO smsoutbox_user_status VALUES (0,'".$this->dbconn->insert_id."','".$recipient."',null,0,0,'".$this->getGsmId($recipient)."')";
                $smsoutbox_status = $this->dbconn->query($insert_smsoutbox_status);
                if ($smsoutbox_status == true) {
                    $stats = [
                        "status" => $smsoutbox_status,
                        "mobile_id" => $recipient
                    ];
                    array_push($sms_status_container, $stats);
                } else {
                    return -1;
                }
            } else {
                return -1;
            }
        }

        $result = [
            "type" => "sendSms",
            "isYou" => 1,
            "mobile_id" => $sms_status_container[0]['mobile_id'],
            "read_status" => null,
            "send_status" => 0,
            "timestamp" => $current_ts,
            "ts_sent" => null,
            "ts_written" => $current_ts,
            "ts_received" => null,
            "user" => "You",
            "web_status" => null,
            "gsm_id" => 1,
            "convo_id" => $convo_id,
            "sms_msg" => $message,
            "data" => $sms_status_container
        ];
        return $result;
    }

    function getGsmId($mobile_id) {
        $gsm_id_query = "SELECT gsm_id FROM user_mobile WHERE mobile_id = '".$mobile_id."'";
        $gsm_container = $this->dbconn->query($gsm_id_query);
        $gsm_id = "";
        while ($row = $gsm_container->fetch_assoc()) {
            $gsm_id = $row['gsm_id'];
        }
        return $gsm_id;
    }

    function fetchSmsInboxData($inbox_id) {
        $inbox_data = "SELECT smsinbox_users.inbox_id, smsinbox_users.ts_sms, smsinbox_users.mobile_id, smsinbox_users.sms_msg, 
                        smsinbox_users.read_status, smsinbox_users.web_status,smsinbox_users.gsm_id,user_mobile.sim_num, CONCAT(sites.site_code,' ',user_organization.org_name, ' - ', users.lastname, ', ', users.firstname) as full_name, users.user_id 
                        FROM smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id 
                        INNER JOIN users ON user_mobile.user_id = users.user_id INNER JOIN user_organization ON users.user_id = user_organization.user_id INNER JOIN sites ON user_organization.fk_site_id = sites.site_id WHERE smsinbox_users.inbox_id = '".$inbox_id."';";
        $execute_query = $this->dbconn->query($inbox_data);
        $distinct_numbers = "";
        $all_numbers = [];
        $all_messages = [];
        $quick_inbox_messages = [];
        $ctr = 0;

        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                $normalized_number = substr($row["sim_num"], -10);
                $all_messages[$ctr]['user_id'] = $row['user_id'];
                $all_messages[$ctr]['sms_id'] = $row['inbox_id'];
                $all_messages[$ctr]['full_name'] = strtoupper($row['full_name']);
                $all_messages[$ctr]['user_number'] = $normalized_number;
                $all_messages[$ctr]['mobile_id'] = $row['mobile_id'];
                $all_messages[$ctr]['msg'] = $row['sms_msg'];
                $all_messages[$ctr]['gsm_id'] = $row['gsm_id'];
                $all_messages[$ctr]['ts_received'] = $row['ts_sms'];
                $ctr++;
            }

            $full_data['data'] = $all_messages;
        } else {
            $inbox_data = "SELECT smsinbox_users.inbox_id, smsinbox_users.ts_sms, smsinbox_users.mobile_id, smsinbox_users.sms_msg, 
                            smsinbox_users.read_status, smsinbox_users.web_status,smsinbox_users.gsm_id,user_mobile.sim_num, CONCAT(dewsl_teams.team_code,' - ', users.lastname, ', ', users.firstname) as full_name, users.user_id 
                            FROM smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id 
                            INNER JOIN users ON user_mobile.user_id = users.user_id INNER JOIN dewsl_team_members ON users.user_id = dewsl_team_members.users_users_id 
                            INNER JOIN dewsl_teams ON dewsl_team_members.dewsl_teams_team_id = dewsl_teams.team_id WHERE smsinbox_users.inbox_id = '".$inbox_id."';";
            $execute_query = $this->dbconn->query($inbox_data);
            $distinct_numbers = "";
            $all_numbers = [];
            $all_messages = [];
            $quick_inbox_messages = [];
            $ctr = 0;

            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    $normalized_number = substr($row["sim_num"], -10);
                    $all_messages[$ctr]['user_id'] = $row['user_id'];
                    $all_messages[$ctr]['sms_id'] = $row['inbox_id'];
                    $all_messages[$ctr]['full_name'] = strtoupper($row['full_name']);
                    $all_messages[$ctr]['user_number'] = $normalized_number;
                    $all_messages[$ctr]['mobile_id'] = $row['mobile_id'];
                    $all_messages[$ctr]['msg'] = $row['sms_msg'];
                    $all_messages[$ctr]['gsm_id'] = $row['gsm_id'];
                    $all_messages[$ctr]['ts_received'] = $row['ts_sms'];
                    $ctr++;
                }
                $full_data['data'] = $all_messages;
            } else {
                echo "0 results\n";
                $full_data['data'] = null;
            }
        }
        $full_data['type'] = "newSmsInbox";
        return $full_data;
    }

    function updateSmsOutboxStatus($outbox_id) {
        $full_data['type'] = 'smsoutboxStatusUpdate';
        $status_update = [];
        $outbox_data = "SELECT * FROM smsoutbox_user_status WHERE outbox_id = '".$outbox_id."'";
        $execute_query = $this->dbconn->query($outbox_data);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                $status_update = [
                    "stat_id" => $row['stat_id'],
                    "outbox_id" => $row['outbox_id'],
                    "mobile_id" => $row['mobile_id'],
                    "ts_sent" => $row['ts_sent'],
                    "send_status" => $row['send_status'],
                    "gsm_id" => $row['gsm_id']
                ];
            }
            $full_data['data'] = $status_update;
        } else {
            echo "0 results\n";
            $full_data['data'] = null;
        }
        return $full_data;
    }

    function fetchImportantGintags() {
        $gintags_query = "SELECT gintags_reference.tag_name FROM gintags_reference INNER JOIN gintags_manager ON gintags_reference.tag_id = gintags_manager.tag_id_fk;";
        $tags = [];
        $execute_query = $this->dbconn->query($gintags_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                array_push($tags, $row['tag_name']);
            }
            $full_data['data'] = $tags;
        } else {
            echo "0 results\n";
            $full_data['data'] = null;
        }
        $full_data['type'] = "fetchedImportantTags";
        return $full_data;
    }

    function fetchSmsTags($sms_id) {
        $tags = [];
        $get_tags_query = "SELECT * FROM gintags INNER JOIN gintags_reference ON tag_id_fk = gintags_reference.tag_id WHERE table_element_id = '".$sms_id."';";
        $execute_query = $this->dbconn->query($get_tags_query);
        if ($execute_query->num_rows > 0) {
            $full_data['data'] = $execute_query->fetch_assoc();
            while ($row = $execute_query->fetch_assoc()) {
                array_push($tags,$row['tag_name']);
            }
            $full_data['data'] = $tags;
        } else {
            $full_data['data'] = [];
        }
        $full_data['type'] = "fetchedSmsTag ";
        return $full_data;
    }

    function tagMessage($data) {
        $status = false;
        if ($data['tag_important'] != true) {
            
            $tag_exist_query = "SELECT * FROM gintags_reference WHERE tag_name = '".$data['tag']."'";
            $execute_query = $this->dbconn->query($tag_exist_query);
            if ($execute_query->num_rows == 0) {
                $tag_message_query = "INSERT INTO gintags_reference VALUES (0,'".$data['tag']."','NULL')";
                $execute_query = $this->dbconn->query($tag_message_query);
                if ($execute_query == true) {
                    $status = true;
                    $last_inserted_id = $this->dbconn->insert_id;
                }
            } else {
                $status = true;
                $last_inserted_id = $execute_query->fetch_assoc()['tag_id'];
            }

            if ($data['full_name'] == "You") {
                $database_reference = "smsoutbox_users";
                $convo_id_collection = $this->searchConvoIdViaMessageAttribute  ($data['ts'], $data['msg']);
            } else {
                $database_reference = "smsinbox_users";
                array_push($convo_id_collection, $data['sms_id']);
            }

            foreach ($convo_id_collection as $id) {
                if ($status == true) {
                    $tag_insertion_query = "INSERT INTO gintags VALUES (0,'".$last_inserted_id."','".$data['account_id']."','".$id."','".$database_reference."','".$data['ts']."','Null')";
                    $execute_query = $this->dbconn->query($tag_insertion_query);
                }
            }

            $full_data['type'] = "messageTaggingStatus";
            if ($execute_query == true) {
                $full_data['status_message'] = "Successfully tagged message!";
                $full_data['status'] = true;
            } else {
                $full_data['status_message'] = "Failed to tag message!";
                $full_data['status'] = false;
            }
            return $full_data;
        } else { 
            $this->tagToNarratives($data);
        }
    }

    function searchConvoIdViaMessageAttribute($ts, $msg) {
        $convo_id_container = [];
        $convo_id_query = "SELECT * FROM senslopedb.smsoutbox_users natural join smsoutbox_user_status where sms_msg = '".$msg."' and ts_written = '".$ts."' order by ts_written desc;";
        $execute_query = $this->dbconn->query($convo_id_query);
        while ($row = $execute_query->fetch_assoc()) {
            array_push($convo_id_container, $row['outbox_id']);
        }
        return $convo_id_container;
    }

    function tagToNarratives($data) {
        $database_reference = ($data['account_id'] == 'You') ? "smsoutbox_users" : "smsinbox_users";
        $tag_query = "INSERT INTO gintags VALUES (0,(SELECT tag_id FROM gintags_reference WHERE tag_name = '".$data['tag']."'),'".$data['account_id']."','".$data['sms_id']."','".$database_reference."','".$data['ts']."','Null')";
        $execute_query = $this->dbconn->query($tag_query);
        if ($execute_query == true) {
            $narrative_template_query = "";
            $execute_query = $this->dbconn->query($narrative_template_query);
            
        } else {

        }
    }


    function autoTagMessage($offices, $event_id, $site_id,$data_timestamp, $timestamp, $tag, $msg) {
        $get_tag_narrative = "SELECT narrative_input FROM comms_db.gintags_manager INNER JOIN gintags_reference ON gintags_manager.tag_id_fk = gintags_reference.tag_id WHERE gintags_reference.tag_name = '".$tag."';";
        $narrative_input = $this->dbconn->query($get_tag_narrative);

        $template = $narrative_input->fetch_assoc()['narrative_input'];
        $narrative = $this->parseTemplateCodes($offices, $site_id, $data_timestamp, $timestamp, $template, $msg);
        if ($template != "") {
            $sql = "INSERT INTO narratives VALUES(0,'".$event_id."','".$data_timestamp."','".$narrative."')";
            $result = $this->senslope_dbconn->query($sql);
        } else {
            $result = false;
            echo "No templates fetch..\n\n";
        }
        return $result;
    }

    function parseTemplateCodes($offices, $site_id, $data_timestamp, $timestamp, $template, $msg) {
        $codes = ["(sender)","(sms_msg)","(current_release_time)","(stakeholders)"];
        foreach ($codes as $code) {
            switch ($code) {
                case '(sender)':
                    $template = str_replace($code,'NA',$template);
                    break;

                case '(sms_msg)':
                    $template = str_replace($code, $msg,$template);
                    break;

                case '(current_release_time)':
                    $template = str_replace($code,$timestamp,$template);
                    break;

                case '(stakeholders)':
                    $stakeholders = "";
                    $counter = 0;
                    foreach ($offices as $office) {
                        if ($counter == 0) {
                            $stakeholders = $office;
                        } else {
                            $stakeholders = $stakeholders.", ".$office;
                        }
                        $counter++;
                    }
                    $template = str_replace($code,$stakeholders,$template);
                    break;

                default:
                    $template = str_replace($code,'NA',$template);
                    break;
            }
        }
        return $template;
    }

    function fetchSitesForRoutine() {
        $sites_query = "SELECT site_code,season from sites;";
        $sites = [];
        $execute_query = $this->dbconn->query($sites_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                $raw = [
                    "site" => $row['site_code'],
                    "season" => $row['season']
                ];
                array_push($sites, $raw);
            }
            $full_data['data'] = $sites;
        } else {
            echo "0 results\n";
            $full_data['data'] = null;
        }
        $full_data['type'] = "fetchSitesForRoutine";
        return $full_data; 
    }

    function fetchRoutineReminder() {
        $routine_query = "SELECT * from ewi_backbone_template WHERE alert_status = 'Routine-Reminder';";
        $template = [];
        $execute_query = $this->dbconn->query($routine_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                array_push($template, $row);
            }
            $full_data['data'] = $template;
        } else {
            echo "0 results\n";
            $full_data['data'] = null;
        }
        $full_data['type'] = "fetchRoutineReminder";
        return $full_data;        
    }

    function fetchRoutineTemplate() {
        $routine_query = "SELECT * from ewi_backbone_template WHERE alert_status = 'Routine';";
        $template = [];
        $execute_query = $this->dbconn->query($routine_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                array_push($template, $row);
            }
            $full_data['data'] = $template;
        } else {
            echo "0 results\n";
            $full_data['data'] = null;
        }
        $full_data['type'] = "fetchRoutineTemplate";
        return $full_data;          
    }

    function fetchAlertStatus() {
        $alert_query = "SELECT distinct alert_status FROM ewi_template;";
        $alert_collection = [];
        $site_collection = [];
        $execute_query = $this->dbconn->query($alert_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                array_push($alert_collection, $row);
            }
        } else {
            echo "0 results\n";
            $alert_collection = null;
        }

        $site_query = "SELECT distinct site_code FROM sites;";
        $execute_query = $this->dbconn->query($site_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                array_push($site_collection, $row);
            }
        } else {
            echo "0 results\n";
            $site_collection = null;
        }

        $full_data ['data'] = [
            "site_code" => $site_collection,
            "alert_status" => $alert_collection
        ];

        $full_data['type'] = "fetchAlertStatus";
        return $full_data;   
    }

    function fetchEWISettings($alert_status) {
        $settings_collection = [];
        $settings_query = "SELECT distinct alert_symbol_level FROM ewi_template where alert_status like '%".$alert_status."%';";
        $execute_query = $this->dbconn->query($settings_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                array_push($settings_collection, $row);
            }
            $full_data['data'] = $settings_collection;
        } else {
            echo "0 results\n";
            $alert_collection = null;
        }
        $full_data['type'] = "fetchEWISettings";
        return $full_data;
    }

    function fetchEventTemplate($template_data) {
        $site_query = "SELECT * FROM sites WHERE site_code = '".$template_data->site_name."';";
        $site_container = [];
        $ewi_backbone_container = [];
        $ewi_key_input_container = [];
        $ewi_recommended_container = [];
        $execute_query = $this->dbconn->query($site_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                array_push($site_container, $row);
            }
        } else {
            echo "0 results\n";
        }

        $ewi_backbone_query = "SELECT * FROM ewi_backbone_template WHERE alert_status = '".$template_data->alert_status."';";
        $execute_query = $this->dbconn->query($ewi_backbone_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                array_push($ewi_backbone_container, $row);
            }
        } else {
            echo "0 results\n";
        }

        $key_input_query = "SELECT * FROM ewi_template WHERE alert_symbol_level = '".$template_data->internal_alert."' AND alert_status = '".$template_data->alert_status."';";
        $execute_query = $this->dbconn->query($key_input_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                array_push($ewi_key_input_container, $row);
            }
        } else {
            echo "0 results\n";
        }
        if ($template_data->alert_level == "ND"){$template_data->alert_level = "A1";}
        $alert_level = str_replace('A','Alert ',$template_data->alert_level);
        $recom_query = "SELECT * FROM ewi_template WHERE alert_symbol_level = '".$alert_level."' AND alert_status = '".$template_data->alert_status."';";
        $execute_query = $this->dbconn->query($recom_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                array_push($ewi_recommended_container, $row);
            }
        } else {
            echo "0 results\n";
        }


        $raw_template = [
            "site" => $site_container,
            "backbone" => $ewi_backbone_container,
            "tech_info" => $ewi_key_input_container,
            "recommended_response" => $ewi_recommended_container,
            "formatted_data_timestamp" => $template_data->formatted_data_timestamp,
            "data_timestamp" => $template_data->data_timestamp,
            "alert_level" => $alert_level
        ];

        $full_data['data'] = $this->reconstructEWITemplate($raw_template);
        return $full_data;
    }

    function reconstructEWITemplate($raw_data) {
        $counter = 0;

        $time_submission = null;
        $date_submission = null;
        $ewi_time = null;
        $greeting = null;
        date_default_timezone_set('Asia/Manila');
        $current_date = date('Y-m-d H:i:s');

        $final_template = $raw_data['backbone'][0]['template'];
        if ($raw_data['site'][0]['purok'] == "") {
            $reconstructed_site_details = $raw_data['site'][0]['sitio'].", ".$raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
        }

        if ($raw_data['site'][0]['sitio'] == "") {
             $reconstructed_site_details = $raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
        } else {
             $reconstructed_site_details = $raw_data['site'][0]['purok'].", ".$raw_data['site'][0]['sitio'].", ".$raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
        }

        if(strtotime($current_date) >= strtotime(date("Y-m-d 00:00:00")) && strtotime($current_date) < strtotime(date("Y-m-d 11:59:00"))){
            $greeting = "umaga";
        }else if(strtotime($current_date) >= strtotime(date("Y-m-d 12:00:00")) && strtotime($current_date) < strtotime(date("Y-m-d 13:00:00"))){
            $greeting = "tanghali";
        }else if(strtotime($current_date) >= strtotime(date("Y-m-d 13:01:59")) && strtotime($current_date) < strtotime(date("Y-m-d 17:59:59"))) {
            $greeting = "hapon";
        }else if(strtotime($current_date) >= strtotime(date("Y-m-d 18:00:00")) && strtotime($current_date) < strtotime(date("Y-m-d 23:59:59"))){
            $greeting = "gabi";
        }

        $time_of_release = $raw_data['data_timestamp'];
        $time_stamp = date("Y-m-d 02:30:00");
        $datetime = explode(" ",$time_of_release);
        $time = $datetime[1];

        if(strtotime($time) >= strtotime(date("00:00:00")) && strtotime($time) <= strtotime(date("04:00:00"))){
            $time_submission = "bago mag-07:30 AM";
            $date_submission = "mamaya";
            $ewi_time = "04:00 AM";
        } else if(strtotime($time) >= strtotime(date("04:00:00")) && strtotime($time) <= strtotime(date("08:00:00"))){
            $time_submission = "bago mag-07:30 AM";
            $date_submission = "mamaya";
            $ewi_time = "08:00 AM";
        } else if(strtotime($time) >= strtotime(date("08:00:00")) && strtotime($time) <= strtotime(date("12:00:00"))){
            $time_submission = "bago mag-11:30 AM";
            $date_submission = "mamaya";
            $ewi_time = "12:00 NN";
        } else if(strtotime($time) >= strtotime(date("12:00:00")) && strtotime($time) <= strtotime(date("16:00:00"))){
            $time_submission = "bago mag-3:30 PM";
            $date_submission = "mamaya";
            $ewi_time = "04:00 PM";
        } else if(strtotime($time) >= strtotime(date("16:00:00")) && strtotime($time) <= strtotime(date("20:00:00"))){
            $time_submission = "bago mag-7:30 AM";
            $date_submission = "bukas";
            $ewi_time = "08:00 PM";
        } else if(strtotime($time) >= strtotime(date("20:00:00"))){
            $time_submission = "bago mag-7:30 AM";
            $date_submission = "bukas";
            $ewi_time = "12:00 MN";
        } else {
            echo "Error Occured: Please contact Administrator";
        }

        $final_template = str_replace("(site_location)",$reconstructed_site_details,$final_template);
        $final_template = str_replace("(alert_level)",$raw_data['alert_level'],$final_template);
        $final_template = str_replace("(current_date_time)",$raw_data['formatted_data_timestamp'],$final_template);
        $final_template = str_replace("(technical_info)",$raw_data['tech_info'][0]['key_input'],$final_template);
        $final_template = str_replace("(recommended_response)",$raw_data['recommended_response'][0]['key_input'],$final_template);
        $final_template = str_replace("(gndmeas_date_submission)",$date_submission,$final_template);
        $final_template = str_replace("(gndmeas_time_submission)",$time_submission,$final_template);
        $final_template = str_replace("(next_ewi_time)",$ewi_time,$final_template);
        $final_template = str_replace("(greetings)",$greeting,$final_template);

        return $final_template;
    }

    function fetchSearchKeyViaGlobalMessages($search_key, $search_limit) {
        $search_key_container = [];
        $search_key_query = "SELECT smsinbox_users.sms_msg, CONCAT(users.firstname,' ',users.lastname) AS user, smsinbox_users.ts_sms AS ts, smsinbox_users.inbox_id AS sms_id, 'smsinbox' AS table_source , smsinbox_users.mobile_id as mobile_id
        FROM senslopedb.smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id INNER JOIN users ON user_mobile.user_id = users.user_id WHERE sms_msg LIKE '".$search_key."' 
        UNION 
        SELECT smsoutbox_users.sms_msg, 'You' AS user, smsoutbox_user_status.ts_sent AS ts, smsoutbox_user_status.outbox_id AS sms_id, 'smsoutbox' AS table_source , smsoutbox_user_status.mobile_id
        from smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE sms_msg LIKE '".$search_key."' order by ts desc limit ".$search_limit.";";

        $execute_query = $this->dbconn->query($search_key_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                array_push($search_key_container, $row);
            }
        } else {
            echo "0 results\n";
        }
        $full_data['type'] = "fetchedSearchKeyViaGlobalMessage";
        $full_data['data'] = $search_key_container;
        return $full_data;
    }

    function fetchSearchKeyViaGintags($search_key, $search_limit) {
        $search_key_query = "SELECT smsinbox_users.sms_msg, CONCAT(users.firstname,' ',users.lastname) AS user, smsinbox_users.ts_sms AS ts, smsinbox_users.inbox_id AS sms_id, 'smsinbox' AS table_source , smsinbox_users.mobile_id as mobile_id
        FROM senslopedb.smsinbox_users 
        INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id 
        INNER JOIN users ON user_mobile.user_id = users.user_id 
        INNER JOIN gintags ON gintags.table_element_id = smsinbox_users.inbox_id
        INNER JOIN gintags_reference ON gintags_reference.tag_id = gintags.tag_id_fk WHERE gintags_reference.tag_name = '".$search_key."' limit ".$search_limit.";";

        $execute_query = $this->dbconn->query($search_key_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                array_push($search_key_container, $row);
            }
        } else {
            echo "0 results\n";
        }
        $full_data['type'] = "fetchedSearchKeyViaGlobalMessage";
        $full_data['data'] = $search_key_container;
        return $full_data;
    }

    function fetchSearchedMessageViaGlobal($data) {
        $convo_container = [];
        $number_container = $this->getMobileDetails($data);
        $full_name = $this->getUserFullname($number_container[0]);
        $prev_messages_container = $this->getTwentySearchedPreviousMessages($number_container[0],$full_name,$data->ts);
        $latest_messages_container = $this->getTwentySearchedLatestMessages($number_container[0],$full_name,$data->ts);
        foreach ($latest_messages_container as $sms) {
            array_push($convo_container,$sms);
        }
        foreach ($prev_messages_container as $sms) {
            array_push($convo_container,$sms);
        }
        $full_data = [];
        $full_data['full_name'] = $full_name;
        $full_data['recipients'] = $number_container;
        $full_data['type'] = "loadSmsConversation";
        $full_data['data'] = $convo_container;
        return $full_data;
    }

    function getUserFullname($data) {
        $full_name_container = "";
        $full_name_query = "SELECT CONCAT(sites.site_code,' ',user_organization.org_name, ' - ', users.lastname, ', ', users.firstname) as full_name 
        from users INNER JOIN user_organization ON users.user_id = user_organization.user_id INNER JOIN sites ON user_organization.fk_site_id = sites.site_id where users.user_id = '".$data['user_id']."';";
        $execute_query = $this->dbconn->query($full_name_query);
        if ($execute_query->num_rows > 0) {
            while ($row = $execute_query->fetch_assoc()) {
                $full_name_container = $full_name_container." ".$row['full_name'];
            }
        } else {
            echo "0 results\n";
        }

        return strtoupper($full_name_container);
    }

    function getTwentySearchedPreviousMessages($details, $fullname, $ts) {
        $convo_container = [];
        $inbox_query = "SELECT smsinbox_users.inbox_id as convo_id, mobile_id, 
                        smsinbox_users.ts_sms as ts_received, null as ts_written, null as ts_sent, smsinbox_users.sms_msg,
                        smsinbox_users.read_status, smsinbox_users.web_status, smsinbox_users.gsm_id ,
                        null as send_status , ts_sms as timestamp , '".$fullname."' as user from smsinbox_users WHERE mobile_id = (SELECT mobile_id FROM user_mobile where sim_num LIKE '%".substr($details["sim_num"], -10)."%') and smsinbox_users.ts_sms <'".$ts."'";
        $outbox_query = "SELECT smsoutbox_users.outbox_id as convo_id, mobile_id,
                        null as ts_received, ts_written, ts_sent, sms_msg , null as read_status,
                        web_status, gsm_id , send_status , ts_written as timestamp, 'You' as user FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE smsoutbox_user_status.mobile_id = 
                        (SELECT mobile_id FROM user_mobile where sim_num LIKE '%".substr($details["sim_num"], -10)."%') and smsoutbox_users.ts_written <'".$ts."'";


        $full_query = "SELECT * FROM (".$inbox_query." UNION ".$outbox_query.") as full_contact group by sms_msg order by timestamp desc limit 20;";

        $fetch_convo = $this->dbconn->query($full_query);
        if ($fetch_convo->num_rows != 0) {
            while($row = $fetch_convo->fetch_assoc()) {
                array_push($convo_container,$row);
            }
        } else {
            echo "No message fetched!";
        }
        return $convo_container;
    }

    function getTwentySearchedLatestMessages($details, $fullname, $ts) {
        $convo_container = [];
        $inbox_query = "SELECT smsinbox_users.inbox_id as convo_id, mobile_id, 
                        smsinbox_users.ts_sms as ts_received, null as ts_written, null as ts_sent, smsinbox_users.sms_msg,
                        smsinbox_users.read_status, smsinbox_users.web_status, smsinbox_users.gsm_id ,
                        null as send_status , ts_sms as timestamp , '".$fullname."' as user from smsinbox_users WHERE mobile_id = (SELECT mobile_id FROM user_mobile where sim_num LIKE '%".substr($details["sim_num"], -10)."%') and smsinbox_users.ts_sms >'".$ts."'";
        $outbox_query = "SELECT smsoutbox_users.outbox_id as convo_id, mobile_id,
                        null as ts_received, ts_written, ts_sent, sms_msg , null as read_status,
                        web_status, gsm_id , send_status , ts_written as timestamp, 'You' as user FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE smsoutbox_user_status.mobile_id = 
                        (SELECT mobile_id FROM user_mobile where sim_num LIKE '%".substr($details["sim_num"], -10)."%') and smsoutbox_users.ts_written >='".$ts."'";


        $full_query = "SELECT * FROM (".$inbox_query." UNION ".$outbox_query.") as full_contact group by sms_msg order by timestamp desc limit 21;";
        $fetch_convo = $this->dbconn->query($full_query);
        if ($fetch_convo->num_rows != 0) {
            while($row = $fetch_convo->fetch_assoc()) {
                array_push($convo_container,$row);
            }
        } else {
            echo "No message fetched!";
        }
        return $convo_container;
    }

    function fetchTeams() {
        $teams = [];
        $get_teams_query = "SELECT DISTINCT TRIM(team_name) as team_name FROM dewsl_teams;";
        $get_teams = $this->dbconn->query($get_teams_query);
        if ($get_teams->num_rows != 0) {
            while ($row = $get_teams->fetch_assoc()) {
               array_push($teams, $row['team_name']);
            }
        } else {
            echo "No teams fetched!\n\n";
        }
        $full_data['type'] = "fetchedTeams";
        $full_data['data'] = $teams;
        return $full_data;
    }
}
