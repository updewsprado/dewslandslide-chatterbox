<?php
require_once "/var/www/chatterbox/src/ChatMessageModel.php";
use MyApp\ChatMessageModel;

use PHPUnit\Framework\TestCase;

final class ChatterboxTest extends TestCase {

    public function __construct() {
        $this->chatModel = new ChatMessageModel;
    }

    public function testGetSites() {
        $this->assertEquals(true,$this->chatModel->initDBforCB());
    }

    public function testGetOffices() {
        $this->assertInternalType("array",$this->chatModel->getAllSites());
    }

    public function testGetContactSuggestions() {
        // Empty parameter for getting all contacts
        $this->assertInternalType("array",$this->chatModel->getContactSuggestions(""));
    }

    public function testGetRoutineSites() {
        $this->assertInternalType("array",$this->chatModel->fetchSitesForRoutine());
    }

    public function testGetQuickInboxMain() {
        $this->assertInternalType("array",$this->chatModel->getQuickInboxMessages());
    }

    public function testGetQuickInboxUnregistered() {
        // ToDo
    }

    public function testGetQuickInboxEvent() {
        // ToDo
    }

    public function testGetQuickInboxDatalogger() {
        // ToDo
    }

    public function testGetCommunityContacts() {
        $this->assertInternalType("array", $this->chatModel->getAllCmmtyContacts());
    }

    public function testGetEmployeeContacts() {
        $this->assertInternalType("array", $this->chatModel->getAllDwslContacts());
    }

    public function testGetRoutineTemplate() {
        $this->assertInternalType("array", $this->chatModel->fetchRoutineTemplate());
    }

    public function testGetEventTemplate() {
        $sample_data = [
            "site_name" => "BLC",
            "internal_alert" => "R",
            "alert_status" => "Event",
            "alert_level" => "Alert 1",
            "data_timestamp" => "2018-07-09 00:00:00",
            "formatted_data_timestamp" => "2018-07-09 00:00:00"
        ];
        $this->assertInternalType("array", $this->chatModel->fetchEventTemplate((object) $sample_data));
    }

    public function testGetExtendedTemplate() {
        $sample_data = [
            "site_name" => "BLC",
            "internal_alert" => "Alert 0",
            "alert_status" => "Extended",
            "alert_level" => "Alert 0",
            "data_timestamp" => "2018-07-09 00:00:00",
            "formatted_data_timestamp" => "2018-07-09 00:00:00"
        ];
        $this->assertInternalType("array", $this->chatModel->fetchEventTemplate((object) $sample_data));
    }

    public function testGetReminderTemplate() {

    }

    public function testGetRainfallTemplate() {

    }

    public function testGetQuickAccessSiteWithEvent() {

    }

    public function testGetQuickAccessGroupMessage() {

    }

    public function testUpdateCommunityContact() {

    }

    public function testUpdateEmployeeContact() {

    }

    public function testLoadSitesForQuickSiteSelection() {

    }

    public function testQuickSearchMessage() {

    }

    public function testQuickSearchGintagsMessage() {

    }

    public function testQuickSearchTimestampMessage() {

    }

    public function testQuickSearchUnknownMessage() {

    }

    public function testLoadIndividualMessage() {

    }

    public function testLoadMultipleMessageRecipients() {

    }

    public function testLoadSiteConversation() {

    }

    public function testSendIndividualMessage() {

    }

    public function testSendMultipleMessage() {

    }

    public function testSendMessageViaQuickSiteSelection() {

    }

}
