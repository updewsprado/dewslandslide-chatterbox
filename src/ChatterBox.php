<?php
namespace MyApp;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use MyApp\ChatMessageModel;

class ChatterBox implements MessageComponentInterface {
    protected $clients;
    protected $dbconn;
    protected $qiInit;
    protected $chatModel;

    public function __construct() {
        $this->chatModel = new ChatMessageModel;
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";

    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $numRecv = count($this->clients) - 1;

        $decodedText = json_decode($msg);

        if ($decodedText == NULL) {
            echo "Message is not in JSON format ($msg).\n";
            return;
        } else {
            echo "Valid data\n";
            echo sprintf('Connection %d sending message "%s" to %d other connection%s' . 
                    "\n", $from->resourceId, $msg, $numRecv, $numRecv == 1 ? '' : 's');

            $msgType = $decodedText->type;

            if (($msgType == "smssend") || ($msgType == "smsrcv")) {
                $ewi_tag_id = [];
                $temp_tag_id = [];

                echo "Message sent by ChatterBox Users to GSM and the community.\n";     

                if ($msgType == "smssend") {
                    if (isset($decodedText->tag)) {
                        $tempNumber = [];

                        $recipientsTag = $decodedText->tag;
                        $numbers = $this->chatModel->getEmpTagNumbers($recipientsTag);

                        for ($x = 0;$x < sizeof($numbers);$x++) {
                            if (!in_array($numbers[$x]["number"], $tempNumber)) {
                                $recipients = [$numbers[$x]["number"]];
                                $sentMsg = $decodedText->msg;
                                $sentTS = $decodedText->timestamp;
                                $this->chatModel->insertSMSOutboxEntry($recipients, $sentMsg, $sentTS);
                                $displayMsg['type'] = "smssend";
                                $displayMsg['timestamp'] = $sentTS;
                                $displayMsg['user'] = "You";
                                $displayMsg['numbers'] = $recipients;
                                $displayMsg['msg'] = $sentMsg;
                                $displayMsg['gsm_id'] = "UNKNOWN";
                                $displayMsgJSON = json_encode($displayMsg);
                                foreach ($this->clients as $client) {
                                    if ($from !== $client) {
                                        $client->send($displayMsgJSON);
                                    }
                                } 
                                array_push($tempNumber,$numbers[$x]["number"]);  
                            }
                        }

                    } else {
                        $recipients = $decodedText->numbers;
                        $sentMsg = $decodedText->msg;
                        $sentTS = $decodedText->timestamp;
                        $ewitag = $decodedText->ewi_tag;

                        echo "sentTS = $sentTS \n";

                        $result_ewi_entry = $this->chatModel->insertSMSOutboxEntry($recipients, $sentMsg, $sentTS,$ewitag);
                        if (!empty($result_ewi_entry)){
                            array_push($temp_tag_id,$result_ewi_entry);
                        }
                        $displayMsg['type'] = "smssend";
                        $displayMsg['timestamp'] = $sentTS;
                        $displayMsg['user'] = "You";
                        $displayMsg['numbers'] = $recipients;
                        $displayMsg['msg'] = $sentMsg;
                        $displayMsg['gsm_id'] = "UNKNOWN";
                        $displayMsgJSON = json_encode($displayMsg);
                        foreach ($this->clients as $client) {
                            if ($from !== $client) {
                                $client->send($displayMsgJSON);
                            }
                        }
                    $ewi_tag_id['data'] = $temp_tag_id;
                    $ewi_tag_id['type'] = "ewi_tagging";
                    $from->send(json_encode($ewi_tag_id)); 
                    }    
                }
                elseif ($msgType == "smsrcv") {
                    echo "Message received from GSM.\n";
                    $rcvTS = $decodedText->timestamp;
                    $sender = $decodedText->sender;
                    $rcvMsg = $decodedText->msg;

                    if ($this->chatModel->isSenderValid($sender) == false) {
                        echo "Error: sender '$sender' is invalid.\n";
                        return;
                    }

                    $this->chatModel->insertSMSInboxEntry($rcvTS, $sender, $rcvMsg);
                    $name = $this->chatModel->getNameFromNumber($sender);

                    $displayMsg['type'] = "smsrcv";
                    $displayMsg['timestamp'] = $rcvTS;
                    $displayMsg['user'] = $sender;
                    $displayMsg['name'] = $name['fullname'];
                    $displayMsg['msg'] = $rcvMsg;
                    $displayMsgJSON = json_encode($displayMsg);
                    foreach ($this->clients as $client) {
                        if ($from !== $client) {
                            $client->send($displayMsgJSON);
                        }
                    }
                    $this->chatModel->addQuickInboxMessageToCache($displayMsg);
                }
            } elseif ($msgType == "smssendgroup") {
                $ewi_tag_id = [];
                $temp_tag_id = [];
                echo "send groups/tag messages...\n";
                foreach ($this->clients as $client) {
                    if ($from !== $client) {
                        $client->send($msg);
                    }
                }
                $offices = $decodedText->offices;
                $sitenames = $decodedText->sitenames;
                $sentTS = $decodedText->timestamp;
                $sentMsg = $decodedText->msg;
                $ewiRecipient = $decodedText->ewi_filter;
                $ewitag = $decodedText->ewi_tag;

                $displayMsg['type'] = "smssend";
                $displayMsg['timestamp'] = $sentTS;
                $displayMsg['user'] = "You";
                $displayMsg['numbers'] = null;
                $displayMsg['name'] = null;
                $displayMsg['msg'] = $sentMsg;
                $contacts = $this->chatModel->getContactNumbersFromGroupTags($offices, $sitenames,$ewiRecipient);

                $numContacts = count($contacts['data']);
                $allMsgs = [];

                foreach ($contacts['data'] as $singleContact) {
                    $displayMsg['numbers'] = array($singleContact['number']);
                    $displayMsg['name'] = $singleContact['sitename'] . " " . $singleContact['office'];
                    $displayMsgJSON = json_encode($displayMsg);

                    $result_ewi_entry = $this->chatModel->insertSMSOutboxEntry($displayMsg['numbers'], $sentMsg, $sentTS,$ewitag);
                    if (!empty($result_ewi_entry)){
                        array_push($temp_tag_id,$result_ewi_entry);
                    }
                }
                foreach ($this->clients as $client) {
                    if ($from !== $client) {
                        foreach ($contacts['data'] as $singleContact) {
                            $displayMsg['numbers'] = array($singleContact['number']);
                            $displayMsg['name'] = $singleContact['sitename'] . " " . $singleContact['office'];
                            $displayMsg['gsm_id'] = "UNKNOWN";
                            $displayMsgJSON = json_encode($displayMsg);
                            $client->send($displayMsgJSON);
                        }
                    }
                }
                $ewi_tag_id['data'] = $temp_tag_id;
                $ewi_tag_id['type'] = "ewi_tagging";
                $from->send(json_encode($ewi_tag_id));
            } elseif ($msgType == "smsloadrequestgroup") {
                echo "Loading groups messages...";
                $offices = $decodedText->offices;
                $sitenames = $decodedText->sitenames;
                $exchanges = $this->chatModel->getMessageExchangesFromGroupTags($offices, $sitenames);
                $from->send(json_encode($exchanges));
            } elseif ($msgType == "smsloadquickinboxrequest") {
                echo "Loading latest message from 20 registered and unknown numbers for the past 7 days...";
                $quickInboxMessages = $this->chatModel->getQuickInboxMain();
                $from->send(json_encode($quickInboxMessages));
            } else if ($msgType == "smsloadquickinboxunregisteredrequest") {

            } else if ($msgType == "latestAlerts") {
                echo "Loading latest public alerts.";
                $latestAlerts = $this->chatModel->getLatestAlerts();
                $from->send(json_encode($latestAlerts));
            } elseif ($msgType == "loadofficeandsitesrequest") {
                echo "Loading office and sitename information...";
                $officeAndSites = $this->chatModel->getAllOfficesAndSites();
                $from->send(json_encode($officeAndSites));
            } elseif ($msgType == "loadcontactsrequest") {
                echo "Loading contact information...";
                $contacts = $this->chatModel->getAllContactsList();
                $from->send(json_encode($contacts));
            } elseif ($msgType == "loadcommunitycontactrequest") {
                echo "Loading a community contact information...";
                $sitename = $decodedText->sitename;
                $office = $decodedText->office;

                $commcontact = $this->chatModel->getCommunityContact($sitename, $office);
                $from->send(json_encode($commcontact));
            } elseif ($msgType == "loadcontactfromnamerequest") {
                echo "Loading a contact information from name...";
                $contactname = $decodedText->contactname;

                $contact = $this->chatModel->getContactsFromName($contactname);
                $from->send(json_encode($contact));
            } elseif ($msgType == "ackrpi") {
                echo "Received Acknowledgment: RPi has received your smsoutbox message...";
                $writtenTS = $decodedText->timestamp_written;
                $recipients = $decodedText->recipients;
                $sendStatus = $decodedText->send_status;

                echo "\n\n$writtenTS, $recipients, $sendStatus\n\n";
                $updateStatus = $this->chatModel->updateSMSOutboxEntry($recipients, 
                                                        $writtenTS, $sendStatus);

                if ($updateStatus >= 0) {
                    foreach ($this->clients as $client) {
                        if ($from !== $client) {
                            $client->send($msg);
                        }
                    }
                }
            } elseif (($msgType == "ackgsm") || ($msgType == "failgsm")) {
                if ($msgType == "ackgsm") {
                    echo "Received Acknowledgment: GSM has sent your smsoutbox message...";
                    $sendStatus = "SENT";
                }
                elseif ($msgType == "failgsm") {
                    echo "Fail Acknowledgment: GSM FAILED sending your smsoutbox message...";
                    $sendStatus = "FAIL";
                }
                $writtenTS = $decodedText->timestamp_written;
                $recipients = $decodedText->recipients;
                $sentTS = $decodedText->timestamp_sent;

                echo "\n\n$writtenTS, $sentTS, $recipients, $sendStatus\n\n";
                $updateStatus = $this->chatModel->updateSMSOutboxEntry($recipients, 
                                                    $writtenTS, $sendStatus, $sentTS);

                if ($updateStatus >= 0) {
                    foreach ($this->clients as $client) {
                        if ($from !== $client) {
                            $client->send($msg);
                        }
                    }
                }
            } else if ($msgType == "oldMessage"){
                echo "Loading messages for individual chat";
                $number = $decodedText->number;
                $timestampYou = $decodedText->timestampYou;
                $timestampIndi = $decodedText->timestampIndi;
                $timestamp = $timestampYou.",".$timestampIndi;
                $type = $decodedText->type;
                $exchanges = $this->chatModel->getMessageExchanges($number,$type,$timestamp,10);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "oldMessageGroupEmployee") {
                $number = $decodedText->tags;
                $timestampYou = $decodedText->timestampYou;
                $timestampGroup = $decodedText->timestampGroup;
                $timestamp = $timestampYou.",".$timestampGroup;
                $type = $decodedText->type;
                $tags = $decodedText->tags;

                $exchanges = $this->chatModel->getMessageExchanges($number,$type,$timestamp,10,$tags);

                $from->send(json_encode($exchanges));
            } else if ($msgType == "oldMessageGroup"){
                echo "Loading messages groups/tag";
                $offices = $decodedText->offices;
                $sitenames = $decodedText->sitenames;
                $type = $decodedText->type;
                $yourTimeStamp = $decodedText->lastMessageTimeStampYou;
                $groupTimeStamp = $decodedText->lastMessageTimeStampGroup;
                $lastTimeStamps = $yourTimeStamp.",".$groupTimeStamp;
                $exchanges = $this->chatModel->getMessageExchangesFromGroupTags($offices,$sitenames,$type,$lastTimeStamps,10);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "searchMessageIndividual") {
                echo "Searching messages for individual chat";
                $number = $decodedText->number;
                $timestamp = $decodedText->timestamp;
                $searchKey = $decodedText->searchKey;
                $exchanges = $this->chatModel->searchMessage($number,$timestamp,$searchKey);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "searchMessageGroup"){
                echo "Searching groups/tag messages...";
                $offices = $decodedText->offices;
                $sitenames = $decodedText->sitenames;
                $searchKey = $decodedText->searchKey;
                $exchanges = $this->chatModel->searchMessageGroup($offices, $sitenames,$searchKey);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "smsLoadSearched"){
                $number = $decodedText->number;
                $timestamp = $decodedText->timestamp;
                $type = $decodedText->type;
                $exchanges = $this->chatModel->getSearchedConversation($number,$type, $timestamp);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "smsLoadGroupSearched"){
                $offices = $decodedText->offices;
                $sitenames = $decodedText->sitenames;
                $timestampYou = $decodedText->timestampYou;
                $timestampGroup = $decodedText->timestampGroup;
                $timestamp = $timestampYou.",".$timestampGroup;
                $type = $decodedText->type;
                $exchanges = $this->chatModel->getSearchedGroupConversation($offices,$sitenames,$type, $timestamp);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "searchMessageGlobal") {
                $type = $decodedText->type;
                $searchKey = $decodedText->searchKey;
                $exchanges = $this->chatModel->searchMessageGlobal($type,$searchKey);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "searchGintagMessages"){
                $type = $decodedText->type;
                $searchKey = $decodedText->searchKey;
                $exchanges = $this->chatModel->searchGintagMessage($type,$searchKey);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "smsloadGlobalSearched"){
                $user = $decodedText->user;
                $user_number =$decodedText->user_number;
                $timestamp = $decodedText->timestamp;
                $msg = $decodedText->sms_msg;
                $exchanges = $this->chatModel->getSearchedGlobalConversation($user,$user_number,$timestamp,$msg);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "updateEwiRecipients"){
                $type = $decodedText->type;
                $data = $decodedText->data;
                $exchanges = $this->chatModel->updateEwiRecipients($type,$data);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "smsloadrequesttag"){
                $type = $decodedText->type;
                $data = $decodedText->teams;
                $exchanges = $this->chatModel->getMessageExchangesFromEmployeeTags($type,$data);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "loadAllCommunityContacts"){
                $exchanges = $this->chatModel->getAllCmmtyContacts();
                $from->send(json_encode($exchanges)); // New Code Starts here
            } else if ($msgType == "loadAllDewslContacts") {
                $exchanges = $this->chatModel->getAllDwslContacts();
                $from->send(json_encode($exchanges));
            } else if ($msgType == "loadDewslContact") {
                $data = $decodedText->data;
                $exchanges = $this->chatModel->getDwslContact($data);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "loadCommunityContact") {
                $data = $decodedText->data;
                $exchanges = $this->chatModel->getCmmtyContact($data);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "updateDewslContact") {
                $data = $decodedText->data;
                $exchanges = $this->chatModel->updateDwslContact($data);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "updateCommunityContact") {
                $data = $decodedText->data;
                $exchanges = $this->chatModel->updateCmmtyContact($data);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "newDewslContact") {
                $data = $decodedText->data;
                $exchanges = $this->chatModel->createDwlsContact($data);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "newCommunityContact") {
                $data = $decodedText->data;
                $exchanges = $this->chatModel->createCommContact($data);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "getSelectedDewslContact") {
                $id = $decodedText->data;
                $exchanges = $this->chatModel->getDwslContact($id);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "getSelectedCommunityContact") {
                $id = $decodedText->data;
                $exchanges = $this->chatModel->getCmmtyContact($id);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "getAllSitesConSet") {
                $res = $this->chatModel->getAllSites();
                $exchanges['data'] = $res;
                $exchanges['type'] = "conSetAllSites";
                $from->send(json_encode($exchanges));
            } else if ($msgType == "getAllOrgsConSet") {   
                $res = $this->chatModel->getAllOrganization();
                $exchanges['data'] = $res;
                $exchanges['type'] = "conSetAllOrgs";
                $from->send(json_encode($exchanges));
            } else if ($msgType == "qgrSites") {
                $res = $this->chatModel->getAllSites();
                $exchanges['data'] = $res;
                $exchanges['type'] = "qgrAllSites";
                $from->send(json_encode($exchanges));
            } else if ($msgType == "qgrOrgs")  {
                $res = $this->chatModel->getAllOrganization();
                $exchanges['data'] = $res;
                $exchanges['type'] = "qgrAllOrgs";
                $from->send(json_encode($exchanges));
            } else if ($msgType == "loadSmsPerGroup") {
                $organizations = $decodedText->organizations;
                $sitenames = $decodedText->sitenames;
                $exchanges = $this->chatModel->getSmsForGroups($organizations,$sitenames);
                $from->send(json_encode($exchanges));
            } elseif ($msgType == "requestnamesuggestions") {
                echo "Loading name suggestions...";
                $namequery = $decodedText->namequery;
                $namesuggestions = $this->chatModel->getContactSuggestions($namequery);
                $from->send(json_encode($namesuggestions));
            } elseif ($msgType == "loadSmsPerSite") {
                echo "Loading messages...";
                $fullname = $decodedText->fullname;
                $timestamp = $decodedText->timestamp;
                $type = $decodedText->type;

                $exchanges = $this->chatModel->getSmsPerContact($fullname,$timestamp);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "loadSmsConversation") { // NEW CODE STARTS HERE
                $request = [
                    "office" => $decodedText->data->office,
                    "site" => $decodedText->data->site,
                    "first_name" => $decodedText->data->firstname,
                    "last_name" => $decodedText->data->lastname,
                    "full_name" => $decodedText->data->full_name,
                    "number" => $decodedText->data->number
                ];
                $exchanges = $this->chatModel->getMessageConversations($request);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "loadSmsForSites") {
                $offices = $decodedText->organizations;
                $sitenames = $decodedText->sitenames;
                $exchanges = $this->chatModel->getMessageConversationsPerSites($offices,$sitenames);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "sendSmsToRecipients") {
                $exchanges = $this->chatModel->sendSms($decodedText->recipients,$decodedText->message);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "newSmsInbox") {
                echo "New Incomming SMS Received. Sending data to all WSS clients.\n";
                foreach ($decodedText->data as $inbox_id) {
                    $exchanges = $this->chatModel->fetchSmsInboxData($inbox_id);
                    foreach ($this->clients as $client) {
                        if ($from !== $client) {
                            $client->send(json_encode($exchanges));
                        }
                    } 
                }
            } else if ($msgType == "smsoutboxStatusUpdate") {
                echo "Update Outgoing SMS. Sending data to all WSS clients.\n";
                foreach ($decodedText->data as $outbox_id) {
                    $exchanges = $this->chatModel->updateSmsOutboxStatus($outbox_id);
                    foreach ($this->clients as $client) {
                        if ($from !== $client) {
                            $client->send(json_encode($exchanges));
                        }
                    } 
                }
            } else if ($msgType == "autoGintagMessage") {
                echo "Message flagged for auto gintagging.\n";
                $request = [
                    "office" => $decodedText->data->office,
                    "site" => $decodedText->data->site,
                    "gintag" => $decodedText->data->gintag,
                    "sms_id" => $decodedText->data->sms_id,
                    "message" => $decodedText->data->message,
                    "account_id" => $decodedText->data->account_id,
                    "tag_important" => $decodedText->data->tag_important
                ];
                $exchanges = $this->chatModel->autoTagMessage($request);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "gintaggedMessage") {
                echo "Message flagged for gintagging.\n";
                foreach ($decodedText->data->tag as $tag) {
                    $request = [
                        "user_id" => $decodedText->data->user_id,
                        "sms_id" => $decodedText->data->sms_id,
                        "tag" => $tag,
                        "full_name" => $decodedText->data->full_name,
                        "ts" => $decodedText->data->ts,
                        "account_id" => $decodedText->data->account_id,
                        "tag_important" => $decodedText->data->tag_important
                    ];
                    $exchanges = $this->chatModel->tagMessage($request);
                    $from->send(json_encode($exchanges));
                }
            } else if ($msgType == "getImportantTags") {
                echo "Fecthing Important GINTags.\n";
                $exchanges = $this->chatModel->fetchImportantGintags();
                $from->send(json_encode($exchanges));
            } else if ($msgType == "getSmsTags") {
                echo "Fetching tags for the specified sms_id.\n";
                $exchanges = $this->chatModel->fetchSmsTags($decodedText->data->sms_id);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "getRoutineSites") {
                echo "Fetching Sites for Routine";
                $exchanges = $this->chatModel->fetchSitesForRoutine();
                $from->send(json_encode($exchanges));
            } else if ($msgType == "getRoutineReminder") {
                echo "Fetching Routine Template.\n";
                $exchanges = $this->chatModel->fetchRoutineReminder();
                $from->send(json_encode($exchanges));
            } else if ($msgType == "getRoutineTemplate") {
                $exchanges = $this->chatModel->fetchRoutineTemplate();
                $from->send(json_encode($exchanges));
            } else {
                echo "Message will be ignored\n";
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";

        $conn->close();
    }
}