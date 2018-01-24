<?php

	// to run : ./vendor/bin/phpunit --bootstrap vendor/autoload.php tests/ChatMessageModelTest

	require_once "/var/www/chatterbox/src/ChatMessageModel.php";
	use MyApp\ChatMessageModel;
    use PHPUnit\Framework\TestCase;
	

    final class ChatMessageModelTest extends TestCase {
    	public function __construct() {
    		$this->chatModel = new ChatMessageModel;
    	}

        public function testConnectionFromDatabase() {
            $this->assertEquals(true,$this->chatModel->connectSenslopeDB());
        }

        public function testInsertSmsinbox() {
        	$inbox_timestamp_entry = "3018-01-01 01:00:00";
        	$inbox_sender_entry = "09554288976";
        	$inbox_message_entry = "TEST #2: Insert Smsinbox";
        	// Returns false if failed.
        	$this->assertTrue($this->chatModel->insertSMSInboxEntry($inbox_timestamp_entry,$inbox_sender_entry,$inbox_message_entry));
        }

        public function testInsertSmsoutbox() {
        	$outbox_timestamp_entry = "3018-01-01 01:00:00";
        	$outbox_sender_entry = ["09554288976"];
        	$outbox_message_entry = "TEST #3: Insert Smsoutbox";
        	$outbox_ewi_tag_entry = false;
        	// Returns empty array if failed.
        	$this->assertNotEmpty($this->chatModel->insertSMSOutboxEntry($outbox_sender_entry,$outbox_message_entry,$outbox_timestamp_entry,$outbox_ewi_tag_entry));
        }

        public function testUpdateSmsoutboxPending() {
        	$update_outbox_written_timestamp = "3018-01-01 01:00:00";
        	$update_outbox_sent_timestamp = "3018-01-01 01:00:01";
        	$update_outbox_send_status = "PENDING";
        	$update_outbox_recipient = "09554288976";
        	// Returns -1 if failed.
        	$this->assertEquals(0,$this->chatModel->updateSMSOutboxEntry($update_outbox_recipient,$update_outbox_written_timestamp,$update_outbox_send_status,$update_outbox_sent_timestamp));
        }

        public function testUpdateSmsoutboxFailed() {
        	$update_outbox_written_timestamp = "3018-01-01 01:00:00";
        	$update_outbox_sent_timestamp = "3018-01-01 01:00:01";
        	$update_outbox_send_status = "FAIL";
        	$update_outbox_recipient = "09554288976";
        	// Returns -1 if failed.
        	$this->assertEquals(0,$this->chatModel->updateSMSOutboxEntry($update_outbox_recipient,$update_outbox_written_timestamp,$update_outbox_send_status,$update_outbox_sent_timestamp));
        }

        public function testUpdateSmsoutboxFailedWSS() {
        	$update_outbox_written_timestamp = "3018-01-01 01:00:00";
        	$update_outbox_sent_timestamp = "3018-01-01 01:00:01";
        	$update_outbox_send_status = "FAIL-WSS";
        	$update_outbox_recipient = "09554288976";
        	// Returns -1 if failed.
        	$this->assertEquals(0,$this->chatModel->updateSMSOutboxEntry($update_outbox_recipient,$update_outbox_written_timestamp,$update_outbox_send_status,$update_outbox_sent_timestamp));
        }

        public function testUpdateSmsoutboxSent() {
        	$update_outbox_written_timestamp = "3018-01-01 01:00:00";
        	$update_outbox_sent_timestamp = "3018-01-01 01:00:01";
        	$update_outbox_send_status = "SENT";
        	$update_outbox_recipient = "09554288976";
        	// Returns -1 if failed.
        	$this->assertEquals(0,$this->chatModel->updateSMSOutboxEntry($update_outbox_recipient,$update_outbox_written_timestamp,$update_outbox_send_status,$update_outbox_sent_timestamp));
        }

        public function testUpdateSmsoutboxSentWSS() {
        	$update_outbox_written_timestamp = "3018-01-01 01:00:00";
        	$update_outbox_sent_timestamp = "3018-01-01 01:00:01";
        	$update_outbox_send_status = "SENT-WSS";
        	$update_outbox_recipient = "09554288976";
        	// Returns -1 if failed.
        	$this->assertEquals(0,$this->chatModel->updateSMSOutboxEntry($update_outbox_recipient,$update_outbox_written_timestamp,$update_outbox_send_status,$update_outbox_sent_timestamp));
        }

        public function testGetCurrentAlerts() {
        	// Returns error code if failed.
        	$this->assertInternalType('array',$this->chatModel->getLatestAlerts());
        }

        public function testGetQuickInboxMessages() {
        	// Returns error code if failed.
        	$this->assertInternalType('array',$this->chatModel->getQuickInboxMessages());
        }

        public function testGetMessageExchangesIndividual() {
        	// 2018-01-23 15:01:51
        	$message_timestamp = date("Y-m-d h:i:s");
        	$message_number = ["09554288976"];
        	$message_type = "smsloadrequest";
        	$message_limit = "20";
        	$message_tags = "";
        	// Returns error code if failed.
        	$this->assertInternalType('array',$this->chatModel->getMessageExchanges($message_number,$message_type,$message_timestamp,$message_limit,$message_tags));
        }

        public function testGetMessageExchangesGroup() {

        }

        // public function testGetMessageExchangeEmployee() {

        // }

        // public function testGetEWIRecipients() {

        // }

        // public function testGetAllCommunityContacts() {

        // }

        // public function testGetAllEmployeeContacts() {

        // }

        // public function testAddNewContact() {

        // }

        // public function testModifyContact() {

        // }
    }
?>l