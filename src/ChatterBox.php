<?php
namespace MyApp;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class ChatterBox implements MessageComponentInterface {
    protected $clients;
    protected $dbconn;

    public function __construct() {
        $this->clients = new \SplObjectStorage;

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

    //Insert data for smsinbox table
    public function insertSMSInboxEntry($timestamp, $sender, $message) {
        //TODO: this needs to filter or check special characters

        $sql = "INSERT INTO smsinbox (timestamp, sim_num, sms_msg, read_status, web_flag)
                VALUES ('$timestamp', '$sender', '$message', 'READ-FAIL', 'WS')";

        if ($this->dbconn->query($sql) === TRUE) {
            echo "New record created successfully!\n";
        } else {
            echo "Error: " . $sql . "<br>" . $this->dbconn->error;
        }
    }

    //Insert data for smsoutbox table
    public function insertSMSOutboxEntry($recipients, $message, $sentTS = null) {
        //TODO: this needs to filter or check special characters

        if ($sentTS) {
            $curTime = $sentTS;
        } else {
            $curTime = date("Y-m-d H:i:s", time());
        }
        

        foreach ($recipients as $recipient) {
            echo "$curTime Message recipient: $recipient\n";

            $sql = "INSERT INTO smsoutbox (timestamp_sent, recepients, sms_msg, send_status)
                    VALUES ('$curTime', '$recipient', '$message', 'PENDING')";

            if ($this->dbconn->query($sql) === TRUE) {
                echo "New record created successfully!\n";
            } else {
                echo "Error: " . $sql . "<br>" . $this->dbconn->error;
            }
        }
    }

    //Return the message exchanges between Chatterbox and a number
    public function getMessageExchanges($number = null, $timestamp = null, $limit = 20) {
        $ctr = 0;
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

        //Construct the final query
        if ($timestamp == null) {
            $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, 
                            timestamp_sent as timestamp
                        FROM smsoutbox WHERE " . $sqlTargetNumbersOutbox;

            $sqlInbox = "SELECT sim_num as user, sms_msg as msg,
                            timestamp as timestamp
                        FROM smsinbox WHERE " . $sqlTargetNumbersInbox;

            $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp desc LIMIT $limit";
        } else {
            $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, 
                            timestamp_sent as timestamp
                        FROM smsoutbox WHERE " . $sqlTargetNumbersOutbox . "AND timestamp_sent < '$timestamp' ";

            $sqlInbox = "SELECT sim_num as user, sms_msg as msg,
                            timestamp as timestamp
                        FROM smsinbox WHERE " . $sqlTargetNumbersInbox . "AND timestamp < '$timestamp' ";

            $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp desc LIMIT $limit";
        }

        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'smsload';

        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['user'] = $row['user'];
                $dbreturn[$ctr]['msg'] = $row['msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];

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

    //Return the message exchanges between Chatterbox and a group
    public function getMessageExchangesFromGroupTags($offices = null, $sitenames = null, $limit = 70) {
        $ctr = 0;

        $ctrOffices = 0;
        $subQueryOffices;
        if ($offices == null) {
            echo "Error: no office selected.";
            return -1;
        } else {
            foreach ($offices as $office) {
                //echo "target: $number\n";
                echo "target office: $office\n";

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
        } else {
            foreach ($sitenames as $site) {
                //echo "target: $number\n";
                echo "target sitename: $site\n";

                if ($ctrSites == 0) {
                    $subQuerySitenames = "('$site'";
                } else {
                    $subQuerySitenames = $subQuerySitenames . ",'$site'";
                }
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

        $result = $this->dbconn->query($sqlTargetNumbers);
        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $numbers = explode(",", $row['number']);

                foreach ($numbers as $number) {
                    $dbreturn[$ctr]['office'] = $row['office'];
                    $dbreturn[$ctr]['sitename'] = $row['sitename'];
                    // $dbreturn[$ctr]['lastname'] = $row['lastname'];

                    $dbreturn[$ctr]['number'] = $number;

                    // echo $row['sitename'] . " " . $row['office'] . " " . $number . "\n";

                    $ctr = $ctr + 1;
                }
            }

            $fullData['data'] = $dbreturn;
        }
        else {
            echo "0 numbers found\n";
            $fullData['data'] = null;
        }

        // echo "JSON output ($ctr): " . json_encode($fullData);

        //Construct the query for loading messages from multiple numbers
        $num_numbers = count($fullData['data']);
        if ($num_numbers > 1) {
            for ($i = 0; $i < $num_numbers; $i++) { 
                $targetNum = substr($fullData['data'][$i]['number'], 1) ;

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
        $sqlOutbox = "SELECT 'You' as user, sms_msg as msg, 
                        timestamp_sent as timestamp
                    FROM smsoutbox WHERE $sqlTargetNumbersOutbox AND timestamp_sent IS NOT NULL ";

        $sqlInbox = "SELECT sim_num as user, sms_msg as msg,
                        timestamp as timestamp
                    FROM smsinbox WHERE $sqlTargetNumbersInbox AND timestamp IS NOT NULL ";

        $sql = $sqlOutbox . "UNION " . $sqlInbox . "ORDER BY timestamp desc LIMIT $limit";

        echo "\n\n$sql";
        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $msgData['type'] = 'smsload';

        if ($result->num_rows > 0) {
            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['user'] = $row['user'];
                $dbreturn[$ctr]['msg'] = $row['msg'];
                $dbreturn[$ctr]['timestamp'] = $row['timestamp'];

                $ctr = $ctr + 1;
            }

            $msgData['data'] = $dbreturn;
        }
        else {
            echo "0 results\n";
            $msgData['data'] = null;
        }

        // echo json_encode($msgData);

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

        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadcommunitycontact';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";

            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['fullname'] = utf8_decode($row['fullname']);
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

        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadcommunitycontact';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";

            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['fullname'] = utf8_decode($row['fullname']);
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

        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadnamesuggestions';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";

            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr]['fullname'] = utf8_decode($row['fullname']);
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

        $result = $this->dbconn->query($sql);

        $ctr = 0;
        $dbreturn = "";
        $fullData['type'] = 'loadnamesuggestions';

        if ($result->num_rows > 0) {
            $fullData['total'] = $result->num_rows;
            echo $result->num_rows . " results\n";

            // output data of each row
            while ($row = $result->fetch_assoc()) {
                $dbreturn[$ctr] = utf8_decode($row['fullname']);

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
        $sqlSitenames = "SELECT DISTINCT sitename FROM communitycontacts";
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

    //TODO: Resilience against Net Connection Loss
    //Create a protocol for checking whether the message was sent to GSM.
    //There should be a function that will attempt to send "PENDING" data
    //  to GSM everytime there is a new connection.

    public function onOpen(ConnectionInterface $conn) {
        // Store the new connection to send messages to later
        $this->clients->attach($conn);

        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $numRecv = count($this->clients) - 1;

        $decodedText = json_decode($msg);

        if ($decodedText == NULL) {
            echo "Message is not in JSON format ($msg).\n";
            return;
        }
        else {
            echo "Valid data\n";
            echo sprintf('Connection %d sending message "%s" to %d other connection%s' . 
                    "\n", $from->resourceId, $msg, $numRecv, $numRecv == 1 ? '' : 's');

            $msgType = $decodedText->type;

            if (($msgType == "smssend") || ($msgType == "smsrcv"))  {
                //save message in DB (maybe create a thread to handle the DB write for the sake of scalability)
                //saving "smssend"
                if ($msgType == "smssend") {
                    echo "Message sent by ChatterBox Users to GSM and the community.\n";

                    //store data in 'smsoutbox' table
                    $recipients = $decodedText->numbers;
                    $sentMsg = $decodedText->msg;
                    $sentTS = $decodedText->timestamp;

                    echo "sentTS = $sentTS \n";

                    $this->insertSMSOutboxEntry($recipients, $sentMsg, $sentTS);

                    $displayMsg['type'] = "smssend";
                    $displayMsg['timestamp'] = $sentTS;
                    $displayMsg['user'] = "You";
                    $displayMsg['numbers'] = $recipients;
                    $displayMsg['msg'] = $sentMsg;
                    $displayMsgJSON = json_encode($displayMsg);

                    //broadcast JSON message from GSM to all connected clients
                    foreach ($this->clients as $client) {
                        if ($from !== $client) {
                            // The sender is not the receiver, send to each client connected
                            $client->send($displayMsgJSON);
                        }
                    }                }
                //saving "smsrcv"
                elseif ($msgType == "smsrcv") {
                    echo "Message received from GSM.\n";

                    //store data in 'smsinbox' table
                    $rcvTS = $decodedText->timestamp;
                    $sender = $decodedText->sender;
                    $rcvMsg = $decodedText->msg;

                    $this->insertSMSInboxEntry($rcvTS, $sender, $rcvMsg);

                    $displayMsg['type'] = "smsrcv";
                    $displayMsg['timestamp'] = $rcvTS;
                    $displayMsg['user'] = $sender;
                    $displayMsg['msg'] = $rcvMsg;
                    $displayMsgJSON = json_encode($displayMsg);

                    //broadcast JSON message from GSM to all connected clients
                    foreach ($this->clients as $client) {
                        if ($from !== $client) {
                            // The sender is not the receiver, send to each client connected
                            $client->send($displayMsgJSON);
                        }
                    }
                }
            } 
            elseif ($msgType == "smsloadrequest") {
                echo "Loading messages...";

                //Load the message exchanges between Chatterbox and a number
                $number = $decodedText->number;
                $timestamp = $decodedText->timestamp;

                $exchanges = $this->getMessageExchanges($number, $timestamp);
                $from->send(json_encode($exchanges));
            }
            elseif ($msgType == "smsloadrequestgroup") {
                echo "Loading groups/tag messages...";

                //Load the message exchanges between Chatterbox and group selected
                $offices = $decodedText->offices;
                $sitenames = $decodedText->sitenames;

                //Load Message Exchanges using group tags
                //$exchanges = $this->getMessageExchanges($number, $timestamp);
                $exchanges = $this->getMessageExchangesFromGroupTags($offices, $sitenames);

                $from->send(json_encode($exchanges));
            }
            elseif ($msgType == "loadofficeandsitesrequest") {
                echo "Loading office and sitename information...";

                //Load the office and sitenames
                $officeAndSites = $this->getAllOfficesAndSites();
                $from->send(json_encode($officeAndSites));
            }
            elseif ($msgType == "loadcontactsrequest") {
                echo "Loading contact information...";

                //Load the contacts list
                $contacts = $this->getAllContactsList();
                $from->send(json_encode($contacts));
            }
            elseif ($msgType == "loadcommunitycontactrequest") {
                echo "Loading a community contact information...";

                //Load a community contact information
                $sitename = $decodedText->sitename;
                $office = $decodedText->office;

                $commcontact = $this->getCommunityContact($sitename, $office);
                $from->send(json_encode($commcontact));
            }
            elseif ($msgType == "loadcontactfromnamerequest") {
                echo "Loading a contact information from name...";

                //Load a community contact information
                $contactname = $decodedText->contactname;

                $contact = $this->getContactsFromName($contactname);
                $from->send(json_encode($contact));
            }
            elseif ($msgType == "requestnamesuggestions") {
                echo "Loading name suggestions...";

                //Load a community contact information
                $namequery = $decodedText->namequery;

                //$namesuggestions = $this->getNameSuggestions($namequery);
                $namesuggestions = $this->getContactSuggestions($namequery);
                $from->send(json_encode($namesuggestions));
            }
            else {
                echo "Message will be ignored\n";
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        // The connection is losed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);

        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";

        $conn->close();
    }
}
