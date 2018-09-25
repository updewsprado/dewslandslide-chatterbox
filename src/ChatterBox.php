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

        $decodedText = (object) $this->chatModel->utf8_encode_recursive(json_decode($msg));

        if ($decodedText == NULL) {
            echo "Message is not in JSON format ($msg).\n";
            return;
        } else {
            echo "Valid data\n";
            echo sprintf('Connection %d sending message "%s" to %d other connection%s' . 
                    "\n", $from->resourceId, $msg, $numRecv, $numRecv == 1 ? '' : 's');

            $msgType = $decodedText->type;

            if ($msgType == "smsloadquickinboxrequest") {
                echo "Loading latest message from 20 registered and unknown numbers for the past 7 days...";
                $quickInboxMessages = $this->chatModel->getQuickInboxMain();
                $from->send(json_encode($quickInboxMessages));
            } else if ($msgType == "smsloadquickinboxunregisteredrequest") {

            } else if ($msgType == "getRoutineMobileIDsForRoutine") {
                echo "Loading LEWC Mobile Details for routine...";
                $sites = $decodedText->sites;
                $offices = $decodedText->offices;
                $exchanges = $this->chatModel->getRoutineMobileIDsViaSiteName($offices,$sites);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "latestAlerts") {
                echo "Loading latest public alerts.";
                $latestAlerts = $this->chatModel->getLatestAlerts();
                $from->send(json_encode($latestAlerts));
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
                $namesuggestions = $this->chatModel->getContactSuggestions();
                $from->send(json_encode($namesuggestions));
                $from->send(json_encode($exchanges));
            } else if ($msgType == "updateCommunityContact") {
                $data = $decodedText->data;
                $exchanges = $this->chatModel->updateCmmtyContact($data);
                $namesuggestions = $this->chatModel->getContactSuggestions();
                $from->send(json_encode($namesuggestions));
                $from->send(json_encode($exchanges));
            } else if ($msgType == "newDewslContact") {
                $data = $decodedText->data;
                $exchanges = $this->chatModel->createDwlsContact($data);
                $namesuggestions = $this->chatModel->getContactSuggestions();
                $from->send(json_encode($namesuggestions));
                $from->send(json_encode($exchanges));
            } else if ($msgType == "newCommunityContact") {
                $data = $decodedText->data;
                $exchanges = $this->chatModel->createCommContact($data);
                $namesuggestions = $this->chatModel->getContactSuggestions();
                $from->send(json_encode($namesuggestions));
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
            } else if ($msgType == "loadSmsConversation") {
                if (isset($decodedText->data->isMultiple) && $decodedText->data->isMultiple == true) {
                    $exchanges = $this->chatModel->getMessageConversationsForMultipleContact($decodedText->data->data);
                } else {
                    $request = [
                        "office" => $decodedText->data->office,
                        "site" => $decodedText->data->site,
                        "first_name" => $decodedText->data->firstname,
                        "last_name" => $decodedText->data->lastname,
                        "full_name" => $decodedText->data->full_name,
                        "number" => $decodedText->data->number
                    ];
                    $exchanges = $this->chatModel->getMessageConversations($request);
                }
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
            } else if ($msgType == "gintaggedMessage") {
                echo "Message flagged for gintagging.\n";
                foreach ($decodedText->data->tag as $tag) {
                    if (isset($decodedText->data->recipients)) {
                        $request = [
                            "recipients" => $decodedText->data->recipients,
                            "tag" => $tag,
                            "full_name" => $decodedText->data->full_name,
                            "ts" => $decodedText->data->ts,
                            "time_sent" => $decodedText->data->time_sent,
                            "msg" => $decodedText->data->msg,
                            "account_id" => $decodedText->data->account_id,
                            "tag_important" => $decodedText->data->tag_important,
                            "site_code" => $decodedText->data->site_code
                        ];
                    } else {
                        $request = [
                            "user_id" => $decodedText->data->user_id,
                            "sms_id" => $decodedText->data->sms_id,
                            "tag" => $tag,
                            "full_name" => $decodedText->data->full_name,
                            "ts" => $decodedText->data->ts,
                            "time_sent" => $decodedText->data->time_sent,
                            "msg" => $decodedText->data->msg,
                            "account_id" => $decodedText->data->account_id,
                            "tag_important" => $decodedText->data->tag_important,
                            "site_code" => $decodedText->data->site_code
                        ];
                    }
                    $exchanges = $this->chatModel->tagMessage($request);
                    $from->send(json_encode($exchanges));
                }
            } else if ($msgType == "getImportantTags") {
                echo "Fecthing Important GINTags.\n";
                $exchanges = $this->chatModel->fetchImportantGintags();
                $from->send(json_encode($exchanges));
            } else if ($msgType == "getSmsTags") {
                echo "Fetching tags for the specified sms_id.\n";
                $exchanges = $this->chatModel->fetchSmsTags($decodedText->data);
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
            } else if ($msgType == "getAlertStatus") {
                $exchanges = $this->chatModel->fetchAlertStatus();
                $from->send(json_encode($exchanges));
            } else if ($msgType == "getEWITemplateSettings") {
                $exchanges = $this->chatModel->fetchEWISettings($decodedText->data);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "fetchTemplateViaLoadTemplateCbx") {
                $exchanges = $this->chatModel->fetchEventTemplate($decodedText->data);
                $exchanges['type'] = "fetchedEWITemplateViaCbx";
                $from->send(json_encode($exchanges));
            } else if ($msgType == "searchMessageGlobal") {
                $exchanges = $this->chatModel->fetchSearchKeyViaGlobalMessages($decodedText->searchKey, $decodedText->searchLimit);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "loadSearchedMessageKey") {
                $exchanges = $this->chatModel->fetchSearchedMessageViaGlobal($decodedText->data);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "searchGintagMessages") {
                $exchanges = $this->chatModel->fetchSearchKeyViaGintags($decodedText->searchKey, $decodedText->searchLimit);
                $from->send(json_encode($exchanges));
            } else if ($msgType == "fetchTeams") {
                $exchanges = $this->chatModel->fetchTeams();
                $from->send(json_encode($exchanges));
            } else if ($msgType == "fetchTeamsForContactSettings") {
                $exchanges = $this->chatModel->fetchTeamsForContactSettings();
                $from->send(json_encode($exchanges));
            } else if ($msgType == "getEwiDetailsViaDashboard") {
                $internal_alert = explode('-',$decodedText->data->internal_alert_level);
                if($decodedText->data->internal_alert_level == "A0" || $decodedText->data->internal_alert_level == "ND"){
                    $internal_alert = "A0-0";
                    $internal_alert = explode('-',$internal_alert);
                }else {
                    $internal_alert = explode('-',$decodedText->data->internal_alert_level);
                }

                switch ($decodedText->event_category) {
                    case 'event':
                        if ($internal_alert[0] != "A3") {
                            $alert_status = 'Event';
                            $offices = ['BLGU','PLGU','LEWC','MLGU','REGION-8'];
                            $sites = [$decodedText->data->site_id];
                            $recipients = $this->chatModel->getMobileDetailsViaOfficeAndSitename($offices, $sites);
                        } else {
                             $alert_status = 'Event-Level3';
                            $offices = ['BLGU','PLGU','LEWC','MLGU','REGION-8'];
                            $sites = [$decodedText->data->site_id];
                            $recipients = $this->chatModel->getMobileDetailsViaOfficeAndSitename($offices, $sites);                           
                        }
                        break;  
                    case 'extended':
                        $alert_status = 'Extended';
                        $offices = ['LEWC'];
                        $sites = [$decodedText->data->site_id];
                        $recipients = $this->chatModel->getMobileDetailsViaOfficeAndSitename($offices, $sites);
                        break;
                    default:
                        break;
                }
                $data = [
                    "ewi_details" => $decodedText->data,
                    "event_category" => $decodedText->event_category,
                    "internal_alert" => $internal_alert[1][0],
                    "alert_level" => substr($decodedText->data->internal_alert_level, 0, 2),
                    "alert_status" => $alert_status,
                    "site_name" => $decodedText->data->site_code,
                    "data_timestamp" => $decodedText->data->data_timestamp,
                    "formatted_data_timestamp" => $decodedText->data->formatted_data_timestamp
                ];
                
                $template = $this->chatModel->fetchEventTemplate((object) $data);
                $full_data = [
                    "type" => "fetchedEwiDashboardTemplate",
                    "recipients" => $this->chatModel->utf8_encode_recursive($recipients),
                    "template" => $template
                ];
                $from->send(json_encode($full_data));
            } else if ($msgType == "sendEwiViaDashboard") {
                $status = [];
                $gintag_status = [];
                $recipients_to_tag = [];
                $counter = 0;
                foreach ($decodedText->recipients as $recipient) {
                    $raw = explode(" - ",$recipient);
                    $temp_org = explode(" ",$raw[0]);
                    $raw_name = explode(".",$raw[1]);
                    $name_data = [
                        "first_name" => trim($raw_name[0]),
                        "last_name" => trim($raw_name[1])
                    ];
                    $mobile_details = $this->chatModel->getMobileDetails($name_data);
                    $send_status = $this->chatModel->sendSms([$mobile_details[0]["mobile_id"]],$decodedText->msg);
                    $temp = [
                        "status" => $send_status['data'],
                        "recipient" => $recipient
                    ];
                    $temp_site = $temp_org[0];
                    array_push($recipients_to_tag,$temp_org[1]);
                    array_push($status,$temp);
                    array_push($gintag_status, $this->chatModel->autoTagMessage($decodedText->account_id,$send_status['convo_id'],$send_status['timestamp']));
                }
                $full_data['type'] = "sentEwiDashboard";
                $full_data['statuses'] = $status;
                $full_data['narrative_status'] = $this->chatModel->autoNarrative(array_unique($recipients_to_tag), $decodedText->event_id,$decodedText->site_id,$decodedText->data_timestamp,$decodedText->timestamp ,"#EwiMessage",$decodedText->msg);
                $full_data['gintag_status'] = $gintag_status;
                $from->send(json_encode($full_data));
            } else if ($msgType == "getGroundMeasDefaultSettings") {
                if (strtotime(date('h:i A')) >= strtotime('7:30 AM') && strtotime(date('h:i A')) <= strtotime('11:30 AM')) {
                    $ground_time = '11:30 AM';
                } else if (strtotime(date('h:i A')) >= strtotime('11:30 AM') && strtotime(date('h:i A')) <= strtotime('2:30 PM')) {
                    $ground_time = '3:30 PM';
                } else {
                    $ground_time = '7:30 AM';
                }
                if(isset($decodedText->overwrite)){if ($decodedText->overwrite == true) {$this->chatModel->flagGndMeasSettingsSentStatus();}}
                $check_if_settings_set = $this->chatModel->checkForGndMeasSettings($ground_time);
                $routine_sites = $this->chatModel->routineSites();
                $event_sites = $this->chatModel->eventSites();
                $extended_sites = $this->chatModel->extendedSites();
                if (sizeOf($check_if_settings_set) > 0) {
                    $full_data['save_settings'] = $check_if_settings_set;
                    $full_data['saved'] = true;
                } else {
                    $ground_meas_reminder_template = $this->chatModel->getGroundMeasurementReminderTemplate();
                    $ground_meas_reminder_template['template'] = str_replace("(ground_meas_submission)",$ground_time,$ground_meas_reminder_template['template']);
                    if (strtotime(date('h:i A')) >= strtotime('7:30 AM') && strtotime(date('h:i A')) <= strtotime('11:30 AM')) {
                        $ground_meas_reminder_template['template'] = str_replace("(greetings)","umaga",$ground_meas_reminder_template['template']);
                    } else if (strtotime(date('h:i A')) >= strtotime('11:30 AM') && strtotime(date('h:i A')) <= strtotime('2:30 PM')) {
                        $ground_meas_reminder_template['template'] = str_replace("(greetings)","hapon",$ground_meas_reminder_template['template']);
                    } else {
                        $ground_meas_reminder_template['template'] = str_replace("(greetings)","umaga",$ground_meas_reminder_template['template']);
                    }
                    $full_data['template'] = $ground_meas_reminder_template;
                    $full_data['time_of_sending'] = $ground_time;
                    $full_data['saved'] = false;
                }
                $full_data['event_sites'] = $event_sites;
                $full_data['extended_sites'] = $extended_sites;
                $full_data['routine_sites'] = $routine_sites;
                $full_data['cant_send_gndmeas'] = $this->chatModel->getGroundMeasurementsForToday();
                $full_data['type'] = "fetchGndMeasReminderSettings";
                $from->send(json_encode($full_data));
            } else if ($msgType == "setGndMeasReminderSettings") {
                $site_status = [];
                $this->chatModel->flagGndMeasSettingsSentStatus();
                foreach ($decodedText->sites as $site) {
                    if ($site == 'MSL' || $site == 'MSU') {
                        $site = 'mes';
                    }
                    $to_send = $this->chatModel->insertGndMeasReminderSettings($site, $decodedText->category, $decodedText->template, $decodedText->altered, $decodedText->modified);
                }
            } else if ($msgType == "searchViaTsSent") {
                
            } else if ($msgType == "searchViaTsWritten") {
                
            } else if ($msgType == "searchViaUnknownNumber") {

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