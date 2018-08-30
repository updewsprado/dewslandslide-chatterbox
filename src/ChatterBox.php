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

            if ($msgType == "smsloadquickinboxrequest") {
                echo "Loading latest message from 20 registered and unknown numbers for the past 7 days...";
                $quickInboxMessages = $this->chatModel->getQuickInboxMain();
                $from->send(json_encode($quickInboxMessages));
            } else if ($msgType == "smsloadquickinboxunregisteredrequest") {

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
                        "msg" => $decodedText->data->msg,
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