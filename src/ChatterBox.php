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
        //Load the Chat Message Model
        $this->chatModel = new ChatMessageModel;
        $this->clients = new \SplObjectStorage;
        date_default_timezone_set("Asia/Singapore");
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
                $ewi_tag_id = [];
                $temp_tag_id = [];
                //save message in DB (maybe create a thread to handle the DB write for the sake of scalability)
                //saving "smssend"

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
                
                                if ($decodedText->timestamp_written == null || isset($decodedText->timestamp_written) == false) {
                                    $sentTS = date("Y-m-d H:i:s", time());
                                } else {
                                    $sentTS = $decodedText->timestamp_written;
                                }

                                if (!isset($decodedText->retry)) {
                                    $this->chatModel->insertSMSOutboxEntry($recipients, $sentMsg, $sentTS);
                                }
                                $displayMsg['type'] = "smssend";
                                $displayMsg['timestamp'] = $sentTS;
                                $displayMsg['user'] = "You";
                                $displayMsg['numbers'] = $recipients;
                                $displayMsg['msg'] = $sentMsg;
                                $displayMsg['gsm_id'] = "UNKNOWN";
                                $displayMsgJSON = json_encode($displayMsg);

                                //broadcast JSON message from GSM to all connected clients
                                foreach ($this->clients as $client) {
                                    if ($from !== $client) {
                                        // The sender is not the receiver, send to each client connected
                                        $client->send($displayMsgJSON);
                                    }
                                } 
                                array_push($tempNumber,$numbers[$x]["number"]);  
                            }
                        }
                        $ewi_tag_id['data'] = $tempNumber;
                        $ewi_tag_id['type'] = "ewi_tagging";
                        $ewi_tag_id['timestamp'] = $sentTS;
                        $from->send(json_encode($ewi_tag_id)); 

                    } else {

                        $recipients = $decodedText->numbers;
                        $sentMsg = $decodedText->msg;
                        $ewitag = $decodedText->ewi_tag;

                        if ($decodedText->timestamp_written == null || isset($decodedText->timestamp_written) == false) {
                            $sentTS = date("Y-m-d H:i:s", time());
                        } else {
                            $sentTS = $decodedText->timestamp_written;
                        }

                        echo "sentTS = $sentTS \n";

                        var_dump(isset($decodedText->retry));
                        if (!isset($decodedText->retry)) {
                            //store data in 'smsoutbox' table
                            $result_ewi_entry = $this->chatModel->insertSMSOutboxEntry($recipients, $sentMsg, $sentTS,$ewitag);
                            if (!empty($result_ewi_entry)){
                                array_push($temp_tag_id,$result_ewi_entry);
                            }
                        }

                        $displayMsg['type'] = "smssend";
                        $displayMsg['timestamp'] = $sentTS;
                        $displayMsg['user'] = "You";
                        $displayMsg['numbers'] = $recipients;
                        $displayMsg['msg'] = $sentMsg;
                        $displayMsg['gsm_id'] = "UNKNOWN";
                        $displayMsgJSON = json_encode($displayMsg);

                        //broadcast JSON message from GSM to all connected clients
                        foreach ($this->clients as $client) {
                            if ($from !== $client) {
                                // The sender is not the receiver, send to each client connected
                                $client->send($displayMsgJSON);
                            }
                        }
                        $ewi_tag_id['data'] = $temp_tag_id;
                        $ewi_tag_id['type'] = "ewi_tagging";
                        $ewi_tag_id['timestamp'] = $sentTS;
                        $from->send(json_encode($ewi_tag_id)); 
                    }    
                }
                //saving "smsrcv"
                elseif ($msgType == "smsrcv") {
                    echo "Message received from GSM.\n";

                    //store data in 'smsinbox' table
                    $rcvTS = $decodedText->timestamp;
                    $sender = $decodedText->sender;
                    $rcvMsg = $decodedText->msg;

                    if ($this->chatModel->isSenderValid($sender) == false) {
                        echo "Error: sender '$sender' is invalid.\n";
                        return;
                    }

                    echo "Blocked list checking..\n";

                    $isBlocked = $this->isBlockedListed($sender);
                    var_dump($isBlocked);
                    if ($isBlocked == false) {
                        $this->chatModel->insertSMSInboxEntry($rcvTS, $sender, $rcvMsg);

                        //Get tags (office, sitename, tags) from number
                        $name = $this->chatModel->getNameFromNumber($sender);
                        $displayMsg['type'] = "smsrcv";
                        $displayMsg['timestamp'] = $rcvTS;
                        $displayMsg['user'] = $sender;
                        $displayMsg['name'] = $name['fullname'];
                        $displayMsg['msg'] = $rcvMsg;
                        $displayMsg['onevent'] = $name['onevent'];
                        $displayMsgJSON = json_encode($displayMsg);

                        //broadcast JSON message from GSM to all connected clients
                        foreach ($this->clients as $client) {
                            if ($from !== $client) {
                                // The sender is not the receiver, send to each client connected
                                $client->send($displayMsgJSON);
                            }
                        }

                        //TODO: Call function to push new incoming message to the 
                        //  quick inbox cache
                        $this->chatModel->addQuickInboxMessageToCache($displayMsg);
                    } else {
                        echo "Blocked number, ignoring message..\n";
                    }
                }
            } 
            elseif ($msgType == "smssendgroup") {
                $ewi_tag_id = [];
                $temp_tag_id = [];
                echo "send groups/tag messages...\n";

                //broadcast JSON message from GSM to all connected clients
                foreach ($this->clients as $client) {
                    if ($from !== $client) {
                        // The sender is not the receiver, send to each client connected
                        $client->send($msg);
                    }
                }

                //Get the offices and sitenames info and group message
                $offices = $decodedText->offices;
                $sitenames = $decodedText->sitenames;
                $sentMsg = $decodedText->msg;
                $ewiRecipient = $decodedText->ewi_filter;
                $ewitag = $decodedText->ewi_tag;
                if (isset($decodedText->timestamp_written) == false) {
                    $sentTS = date("Y-m-d H:i:s", time());
                } else {
                    $sentTS = $decodedText->timestamp_written;
                }

                $displayMsg['type'] = "smssend";
                $displayMsg['timestamp'] = $sentTS;
                $displayMsg['user'] = "You";
                $displayMsg['numbers'] = null;
                $displayMsg['name'] = null;
                $displayMsg['msg'] = $sentMsg;

                //Get contact numbers using group tags
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

                //broadcast JSON message from GSM to all connected clients
                foreach ($this->clients as $client) {
                    if ($from !== $client) {
                        foreach ($contacts['data'] as $singleContact) {
                            $displayMsg['numbers'] = array($singleContact['number']);
                            $displayMsg['name'] = $singleContact['sitename'] . " " . $singleContact['office'];
                            $displayMsg['gsm_id'] = "UNKNOWN";
                            $displayMsgJSON = json_encode($displayMsg);
                            // The sender is not the receiver, send to each client connected
                            $client->send($displayMsgJSON);
                        }
                    }
                }
                $ewi_tag_id['data'] = $temp_tag_id;
                $ewi_tag_id['type'] = "ewi_tagging";
                $ewi_tag_id['timestamp'] = $sentTS;
                $from->send(json_encode($ewi_tag_id));
            } 
            elseif ($msgType == "smsloadrequest") {
                echo "Loading messages...";
                //Load the message exchanges between Chatterbox and a number
                $number = $decodedText->number;
                $timestamp = $decodedText->timestamp;
                $type = $decodedText->type;

                $exchanges = $this->chatModel->getMessageExchanges($number, $type,$timestamp);
                $from->send(json_encode($exchanges));
            } elseif ($msgType == "smsloadrequestgroup") {
                echo "Loading groups/tag messages...";

                //Load the message exchanges between Chatterbox and group selected
                $offices = $decodedText->offices;
                $sitenames = $decodedText->sitenames;

                //Load Message Exchanges using group tags
                $exchanges = $this->chatModel->getMessageExchangesFromGroupTags($offices, $sitenames);

                $from->send(json_encode($exchanges));
            }
            elseif ($msgType == "smsloadquickinboxrequest") {
                //Load latest message from 20 registered numbers
                //Load latest message from 20 unknown numbers
                echo "Loading latest message from 20 registered and unknown numbers for the past 7 days...";

                //Get the quick inbox messages
                //$quickInboxMessages = $this->getQuickInboxMessages();
               $quickInboxMessages = $this->chatModel->getCachedQuickInboxMessages();

                //TODO: Send the quick inbox messages to the 
                $from->send(json_encode($quickInboxMessages));
            } else if ($msgType == "latestAlerts") {
                echo "Loading latest public alerts.";
                $latestAlerts = $this->chatModel->getLatestAlerts();
                $from->send(json_encode($latestAlerts));
            }  else if ($msgType == "groumMembersForQuickAccess") {
                $offices = $decodedText->offices;
                $sites = $decodedText->sites;
                $contacts = $this->chatModel->getSiteMembers($offices,$sites);
                $from->send(json_encode($contacts));
            } elseif ($msgType == "loadofficeandsitesrequest") {
                echo "Loading office and sitename information...";

                //Load the office and sitenames
                $officeAndSites = $this->chatModel->getAllOfficesAndSites();
                $from->send(json_encode($officeAndSites));
            }
            elseif ($msgType == "loadcontactsrequest") {
                echo "Loading contact information...";

                //Load the contacts list
                $contacts = $this->chatModel->getAllContactsList();
                $from->send(json_encode($contacts));
            }
            elseif ($msgType == "loadcommunitycontactrequest") {
                echo "Loading a community contact information...";

                //Load a community contact information
                $sitename = $decodedText->sitename;
                $office = $decodedText->office;

                $commcontact = $this->chatModel->getCommunityContact($sitename, $office);
                $from->send(json_encode($commcontact));
            }
            elseif ($msgType == "loadcontactfromnamerequest") {
                echo "Loading a contact information from name...";

                //Load a community contact information
                $contactname = $decodedText->contactname;

                $contact = $this->chatModel->getContactsFromName($contactname);
                $from->send(json_encode($contact));
            }
            elseif ($msgType == "requestnamesuggestions") {
                echo "Loading name suggestions...";

                //Load a community contact information
                $namequery = $decodedText->namequery;

                //$namesuggestions = $this->getNameSuggestions($namequery);
                $namesuggestions = $this->chatModel->getContactSuggestions($namequery);
                $from->send(json_encode($namesuggestions));
            }
            //Acknowledgement Message from RPi that it has received your message
            elseif ($msgType == "ackrpi") {
                echo "Received Acknowledgment: RPi has received your smsoutbox message...";

                //Acknowledgement Data includes: 
                // timestamp written - to identify what time it was sent by Chatterbox
                // receipient - which number was the information sent to
                $writtenTS = $decodedText->timestamp_written;
                $recipients = $decodedText->recipients;
                $sendStatus = $decodedText->send_status;

                echo "\n\n$writtenTS, $recipients, $sendStatus\n\n";

                //Attempt to Update the smsoutbox entry
                if (!isset($decodedText->retry)) {
                    $updateStatus = $this->chatModel->updateSMSOutboxEntry($recipients, 
                                                        $writtenTS, $sendStatus);
                }
                
                if ($updateStatus >= 0) {
                    //Send the acknowledgment to all connected web socket clients
                    foreach ($this->clients as $client) {
                        if ($from !== $client) {
                            // The sender is not the receiver, send to each client connected
                            $client->send($msg);
                        }
                    }
                }
            }
            //Acknowledgement Message that Chatterbox's outgoing message has either been
            //  sent SUCCESSFULLY or FAILED by the GSM
            elseif ( ($msgType == "ackgsm") || ($msgType == "failgsm") ) {
                if ($msgType == "ackgsm") {
                    echo "Received Acknowledgment: GSM has sent your smsoutbox message...";
                    $sendStatus = "SENT";
                }
                elseif ($msgType == "failgsm") {
                    echo "Fail Acknowledgment: GSM FAILED sending your smsoutbox message...";
                    $sendStatus = "FAIL";
                
}                
                //Acknowledgement Data includes: 
                // timestamp written - to identify what time it was sent by Chatterbox
                // receipient - which number was the information sent to
                $writtenTS = $decodedText->timestamp_written;
                $recipients = $decodedText->recipients;
                $sentTS = $decodedText->timestamp_sent;

                echo "\n\n$writtenTS, $sentTS, $recipients, $sendStatus\n\n";
                $temp = json_decode($msg);
                $temp->status = $sendStatus;
                $msg = json_encode($temp);
                //Attempt to Update the smsoutbox entry
                $updateStatus = $this->chatModel->updateSMSOutboxEntry($recipients, 
                                                    $writtenTS, $sendStatus, $sentTS);
                var_dump($msg);
                if ($updateStatus >= 0) {
                    //Send the acknowledgment to all connected web socket clients
                    foreach ($this->clients as $client) {
                        if ($from !== $client) {
                            // The sender is not the receiver, send to each client connected
                            $client->send($msg);
                        }
                    }
                }
            }
/*            //Acknowledgement Message that Chatterbox's outgoing message
            //  send attempt FAILED on GSM level
            elseif ($msgType == "failgsm") {
                echo "Fail Acknowledgment: GSM FAILED sending your smsoutbox message...";

                //Acknowledgement Data includes: 
                // timestamp written - to identify what time it was sent by Chatterbox
                // receipient - which number was the information sent to
                $writtenTS = $decodedText->timestamp_written;
                $recipients = $decodedText->recipients;
                $sendStatus = "FAIL";
                $sentTS = $decodedText->timestamp_sent;

                echo "\n\n$writtenTS, $sentTS, $recipients, $sendStatus\n\n";

                //Attempt to Update the smsoutbox entry
                $updateStatus = $this->chatModel->updateSMSOutboxEntry($recipients, 
                                                    $writtenTS, $sendStatus, $sentTS);

                if ($updateStatus >= 0) {
                    //Send the acknowledgment to all connected web socket clients
                    foreach ($this->clients as $client) {
                        if ($from !== $client) {
                            // The sender is not the receiver, send to each client connected
                            $client->send($msg);
                        }
                    }
                }
            }*/
            else if ($msgType == "oldMessage"){
                echo "Loading messages for individual chat";
                $number = $decodedText->number;
                $timestampYou = $decodedText->timestampYou;
                $timestampIndi = $decodedText->timestampIndi;
                $timestamp = $timestampYou.",".$timestampIndi;
                $type = $decodedText->type;
                $exchanges = $this->chatModel->getMessageExchanges($number,$type,$timestamp,10);
                $from->send(json_encode($exchanges));
            } 
            else if ($msgType == "oldMessageGroupEmployee") {
                $number = $decodedText->tags; // Number is also the tags
                $timestampYou = $decodedText->timestampYou;
                $timestampGroup = $decodedText->timestampGroup;
                $timestamp = $timestampYou.",".$timestampGroup;
                $type = $decodedText->type;
                $tags = $decodedText->tags;

                $exchanges = $this->chatModel->getMessageExchanges($number,$type,$timestamp,10,$tags);

                $from->send(json_encode($exchanges));


            } 
            else if ($msgType == "oldMessageGroup"){
                echo "Loading messages groups/tag";
                $offices = $decodedText->offices;
                $sitenames = $decodedText->sitenames;
                $type = $decodedText->type;
                $yourTimeStamp = $decodedText->lastMessageTimeStampYou;
                $groupTimeStamp = $decodedText->lastMessageTimeStampGroup;
                $lastTimeStamps = $yourTimeStamp.",".$groupTimeStamp;
                $exchanges = $this->chatModel->getMessageExchangesFromGroupTags($offices,$sitenames,$type,$lastTimeStamps,10);
                $from->send(json_encode($exchanges));
            } 
            else if ($msgType == "searchMessageIndividual") {
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
            } else if ($msgType == "searchTimestampsent") { // search new code starts here
                $fromDate = $decodedText->searchFromDate;
                $toDate = $decodedText->searchToDate;
                $searchLimit = $decodedText->searchLimit;
                $exchanges = $this->chatModel->searchTimestampsent($fromDate,$toDate,$searchLimit);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "searchTimestampwritten") {
                $fromDate = $decodedText->searchFromDate;
                $toDate = $decodedText->searchToDate;
                $searchLimit = $decodedText->searchLimit;
                $exchanges = $this->chatModel->searchTimestampwritten($fromDate,$toDate,$searchLimit);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "searchUnknownNumber") {
                $searchKey = $decodedText->searchKey;
                $searchLimit = $decodedText->searchLimit;
                $exchanges = $this->chatModel->searchUnknownNumber($searchKey,$searchLimit);
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
            } else if ($msgType == "smsLoadTimestampsentSearched") {
                $exchanges = $this->chatModel->getSearchedConversationViaTimestampsent($decodedText);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "smsLoadTimestampwrittenSearched") {
                $exchanges = $this->chatModel->getSearchedConversationViaTimestampwritten($decodedText);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "searchMessageGlobal") {
                $type = $decodedText->type;
                $searchKey = $decodedText->searchKey;
                $searchLimit = $decodedText->searchLimit;
                $exchanges = $this->chatModel->searchMessageGlobal($type,$searchKey,$searchLimit);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "searchGintagMessages"){
                $type = $decodedText->type;
                $searchKey = $decodedText->searchKey;
                $searchLimit = $decodedText->searchLimit;
                $exchanges = $this->chatModel->searchGintagMessage($type,$searchKey,$searchLimit);
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
            } else if ($msgType == "getGroundMeasDefaultSettings") {
                $ground_meas_reminder_template = $this->chatModel->getGroundMeasurementReminderTemplate();
                $routine_sites = $this->chatModel->routineSites();
                $event_sites = $this->chatModel->eventSites();
                $extended_sites = $this->chatModel->extendedSites();
                $full_data['event_sites'] = $event_sites;
                $full_data['extended_sites'] = $extended_sites;
                $full_data['routine_sites'] = $routine_sites;
                $full_data['template'] = $ground_meas_reminder_template;
                $full_data['type'] = "fetchGndMeasReminderSettings";
                $from->send(json_encode($full_data));
            } else {
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

    public function isBlockedListed($sender) {
        $blocked_list = ['9189008008','9189008007','9168888888','9258828008','9255217304','9189031278'];
        $trim_number = substr($sender,'-10');
        foreach ($blocked_list as $blocked) {
            if ($blocked == $trim_number) {
                $is_blocked = true;
                return $is_blocked;
            } else {
                $is_blocked = false;
            }
        }
        return $is_blocked;
    }
}