<?php

// To run test: ./vendor/bin/phpunit --bootstrap vendor/autoload.php test/cbxtest

require_once "/var/www/chatterbox/src/ChatMessageModel.php";
use MyApp\EwiTemplate;

use PHPUnit\Framework\TestCase;

final class EwiTemplatesTest extends TestCase {

  public function __construct() {
    $this->ewiTemplates = new EwiTemplate;
  }

  public function testEWI_A1_R () {
    date_default_timezone_set('Asia/Manila');
    $current_date = date('Y-m-d H:i:s');//H:i:s
    $alert_level = "Alert 1";
    $tech_info = [["key_input" => "Maaaring magkaroon ng landslide dahil sa nakaraan o kasalukuyang ulan"]];
    $recommended_reponse = [["key_input" => "Ang recommended response ay PREPARE TO ASSIST THE HOUSEHOLDS AT RISK IN RESPONDING TO A HIGHER ALERT"]];
    $time = strtotime("07:00:00");
    $greeting = $this->ewiTemplates->generateGreetingsMessage($time);
    $release_time = strtotime("08:05:00");
    $time_messages = $this->ewiTemplates->generateTimeMessages($release_time);
    $site_details = [
      "site_id" => 50,
      "site_code" => "umi",
      "purok" => "",
      "sitio" => "",
      "barangay" => "Umingan",
      "municipality" => "Alimodian",
      "province" => "Iloilo",
      "region" => "VI",
      "psgc_source" => 63002053,
      "season" => 1
    ];
    $site_container = [$site_details];
    $backbone_template = [["template" => "Magandang (greetings) po.

    (alert_level) ang alert level sa (site_location) ngayong (current_date_time).
    (technical_info). (recommended_response). Inaasahan namin ang pagpapadala ng LEWC ng ground data (gndmeas_date_submission) (gndmeas_time_submission). Ang susunod na Early Warning Information ay mamayang (next_ewi_time).

    Salamat."]];

    $raw_template = [
      "site" => $site_container,
      "backbone" => $backbone_template,
      "tech_info" => $tech_info,
      "recommended_response" => $recommended_reponse,
      "formatted_data_timestamp" => "September 25, 2018 8:00 AM",
      "data_timestamp" => "2018-09-25 7:30:00",
      "alert_level" => $alert_level,
      "event_category" => "event",
      "extended_day" => 0
    ];

    $ewi_template = $this->ewiTemplates->generateEwiFinalMessage($raw_template, $time_messages, $greeting);
    
    $expected_output = "Magandang umaga po.

    Alert 1 ang alert level sa Umingan, Alimodian, Iloilo ngayong September 25, 2018 8:00 AM.
    Maaaring magkaroon ng landslide dahil sa nakaraan o kasalukuyang ulan. Ang recommended response ay PREPARE TO ASSIST THE HOUSEHOLDS AT RISK IN RESPONDING TO A HIGHER ALERT. Inaasahan namin ang pagpapadala ng LEWC ng ground data mamaya bago mag-11:30 AM. Ang susunod na Early Warning Information ay mamayang 12:00 NN.

    Salamat.";

    $this->assertEquals($expected_output, $ewi_template);
  }

  public function testEWI_A1_E () {
    date_default_timezone_set('Asia/Manila');
    $current_date = date('Y-m-d H:i:s');//H:i:s
    $alert_level = "Alert 1";
    $tech_info = [["key_input" => "Maaaring magkaroon ng landslide dahil sa nakaraang lindol o earthquake"]];
    $recommended_reponse = [["key_input" => "Ang recommended response ay PREPARE TO ASSIST THE HOUSEHOLDS AT RISK IN RESPONDING TO A HIGHER ALERT"]];
    $time = strtotime("07:00:00");
    $greeting = $this->ewiTemplates->generateGreetingsMessage($time);
    $release_time = strtotime("08:05:00");
    $time_messages = $this->ewiTemplates->generateTimeMessages($release_time);
    $site_details = [
      "site_id" => 50,
      "site_code" => "umi",
      "purok" => "",
      "sitio" => "",
      "barangay" => "Umingan",
      "municipality" => "Alimodian",
      "province" => "Iloilo",
      "region" => "VI",
      "psgc_source" => 63002053,
      "season" => 1
    ];
    $site_container = [$site_details];
    $backbone_template = [["template" => "Magandang (greetings) po.

    (alert_level) ang alert level sa (site_location) ngayong (current_date_time).
    (technical_info). (recommended_response). Inaasahan namin ang pagpapadala ng LEWC ng ground data (gndmeas_date_submission) (gndmeas_time_submission). Ang susunod na Early Warning Information ay mamayang (next_ewi_time).

    Salamat."]];

    $raw_template = [
      "site" => $site_container,
      "backbone" => $backbone_template,
      "tech_info" => $tech_info,
      "recommended_response" => $recommended_reponse,
      "formatted_data_timestamp" => "September 25, 2018 8:00 AM",
      "data_timestamp" => "2018-09-25 7:30:00",
      "alert_level" => $alert_level,
      "event_category" => "event",
      "extended_day" => 0
    ];

    $ewi_template = $this->ewiTemplates->generateEwiFinalMessage($raw_template, $time_messages, $greeting);
    
    $expected_output = "Magandang umaga po.

    Alert 1 ang alert level sa Umingan, Alimodian, Iloilo ngayong September 25, 2018 8:00 AM.
    Maaaring magkaroon ng landslide dahil sa nakaraang lindol o earthquake. Ang recommended response ay PREPARE TO ASSIST THE HOUSEHOLDS AT RISK IN RESPONDING TO A HIGHER ALERT. Inaasahan namin ang pagpapadala ng LEWC ng ground data mamaya bago mag-11:30 AM. Ang susunod na Early Warning Information ay mamayang 12:00 NN.

    Salamat.";
    // echo $ewi_template;
    $this->assertEquals($expected_output, $ewi_template);
  }

  public function testEWI_A1_D () {
    date_default_timezone_set('Asia/Manila');
    $current_date = date('Y-m-d H:i:s');//H:i:s
    $alert_level = "Alert 1";
    $tech_info = [["key_input" => "Nag-request ang LEWC/LGU ng monitoring sa site dahil sa malakas na pag ulan"]];
    $recommended_reponse = [["key_input" => "Ang recommended response ay PREPARE TO ASSIST THE HOUSEHOLDS AT RISK IN RESPONDING TO A HIGHER ALERT"]];
    $time = strtotime("07:00:00");
    $greeting = $this->ewiTemplates->generateGreetingsMessage($time);
    $release_time = strtotime("08:05:00");
    $time_messages = $this->ewiTemplates->generateTimeMessages($release_time);
    $site_details = [
      "site_id" => 50,
      "site_code" => "umi",
      "purok" => "",
      "sitio" => "",
      "barangay" => "Umingan",
      "municipality" => "Alimodian",
      "province" => "Iloilo",
      "region" => "VI",
      "psgc_source" => 63002053,
      "season" => 1
    ];
    $site_container = [$site_details];
    $backbone_template = [["template" => "Magandang (greetings) po.

    (alert_level) ang alert level sa (site_location) ngayong (current_date_time).
    (technical_info). (recommended_response). Inaasahan namin ang pagpapadala ng LEWC ng ground data (gndmeas_date_submission) (gndmeas_time_submission). Ang susunod na Early Warning Information ay mamayang (next_ewi_time).

    Salamat."]];

    $raw_template = [
      "site" => $site_container,
      "backbone" => $backbone_template,
      "tech_info" => $tech_info,
      "recommended_response" => $recommended_reponse,
      "formatted_data_timestamp" => "September 25, 2018 8:00 AM",
      "data_timestamp" => "2018-09-25 7:30:00",
      "alert_level" => $alert_level,
      "event_category" => "event",
      "extended_day" => 0
    ];

    $ewi_template = $this->ewiTemplates->generateEwiFinalMessage($raw_template, $time_messages, $greeting);
    
    $expected_output = "Magandang umaga po.

    Alert 1 ang alert level sa Umingan, Alimodian, Iloilo ngayong September 25, 2018 8:00 AM.
    Nag-request ang LEWC/LGU ng monitoring sa site dahil sa malakas na pag ulan. Ang recommended response ay PREPARE TO ASSIST THE HOUSEHOLDS AT RISK IN RESPONDING TO A HIGHER ALERT. Inaasahan namin ang pagpapadala ng LEWC ng ground data mamaya bago mag-11:30 AM. Ang susunod na Early Warning Information ay mamayang 12:00 NN.

    Salamat.";
    // echo $ewi_template;
    $this->assertEquals($expected_output, $ewi_template);
  }

  public function testEWI_A2_g () {
    date_default_timezone_set('Asia/Manila');
    $current_date = date('Y-m-d H:i:s');//H:i:s
    $alert_level = "Alert 2";
    $tech_info = [["key_input" => "Nakapagsukat ang LEWC ng significant ground movement"]];
    $recommended_reponse = [["key_input" => "Ang recommended response ay PREPARE TO EVACUATE THE HOUSEHOLDS AT RISK"]];
    $time = strtotime("07:00:00");
    $greeting = $this->ewiTemplates->generateGreetingsMessage($time);
    $release_time = strtotime("08:05:00");
    $time_messages = $this->ewiTemplates->generateTimeMessages($release_time);
    $site_details = [
      "site_id" => 50,
      "site_code" => "umi",
      "purok" => "",
      "sitio" => "",
      "barangay" => "Umingan",
      "municipality" => "Alimodian",
      "province" => "Iloilo",
      "region" => "VI",
      "psgc_source" => 63002053,
      "season" => 1
    ];
    $site_container = [$site_details];
    $backbone_template = [["template" => "Magandang (greetings) po.

    (alert_level) ang alert level sa (site_location) ngayong (current_date_time).
    (technical_info). (recommended_response). Inaasahan namin ang pagpapadala ng LEWC ng ground data (gndmeas_date_submission) (gndmeas_time_submission). Ang susunod na Early Warning Information ay mamayang (next_ewi_time).

    Salamat."]];

    $raw_template = [
      "site" => $site_container,
      "backbone" => $backbone_template,
      "tech_info" => $tech_info,
      "recommended_response" => $recommended_reponse,
      "formatted_data_timestamp" => "September 25, 2018 8:00 AM",
      "data_timestamp" => "2018-09-25 7:30:00",
      "alert_level" => $alert_level,
      "event_category" => "event",
      "extended_day" => 0
    ];

    $ewi_template = $this->ewiTemplates->generateEwiFinalMessage($raw_template, $time_messages, $greeting);
    
    $expected_output = "Magandang umaga po.

    Alert 2 ang alert level sa Umingan, Alimodian, Iloilo ngayong September 25, 2018 8:00 AM.
    Nakapagsukat ang LEWC ng significant ground movement. Ang recommended response ay PREPARE TO EVACUATE THE HOUSEHOLDS AT RISK. Inaasahan namin ang pagpapadala ng LEWC ng ground data mamaya bago mag-11:30 AM. Ang susunod na Early Warning Information ay mamayang 12:00 NN.

    Salamat.";
    // echo $ewi_template;
    $this->assertEquals($expected_output, $ewi_template);
  }

  public function testEWI_A2_s () {
    date_default_timezone_set('Asia/Manila');
    $current_date = date('Y-m-d H:i:s');//H:i:s
    $alert_level = "Alert 2";
    $tech_info = [["key_input" => "Naka-detect ang sensor ng significant ground movement"]];
    $recommended_reponse = [["key_input" => "Ang recommended response ay PREPARE TO EVACUATE THE HOUSEHOLDS AT RISK"]];
    $time = strtotime("07:00:00");
    $greeting = $this->ewiTemplates->generateGreetingsMessage($time);
    $release_time = strtotime("08:05:00");
    $time_messages = $this->ewiTemplates->generateTimeMessages($release_time);
    $site_details = [
      "site_id" => 50,
      "site_code" => "umi",
      "purok" => "",
      "sitio" => "",
      "barangay" => "Umingan",
      "municipality" => "Alimodian",
      "province" => "Iloilo",
      "region" => "VI",
      "psgc_source" => 63002053,
      "season" => 1
    ];
    $site_container = [$site_details];
    $backbone_template = [["template" => "Magandang (greetings) po.

    (alert_level) ang alert level sa (site_location) ngayong (current_date_time).
    (technical_info). (recommended_response). Inaasahan namin ang pagpapadala ng LEWC ng ground data (gndmeas_date_submission) (gndmeas_time_submission). Ang susunod na Early Warning Information ay mamayang (next_ewi_time).

    Salamat."]];

    $raw_template = [
      "site" => $site_container,
      "backbone" => $backbone_template,
      "tech_info" => $tech_info,
      "recommended_response" => $recommended_reponse,
      "formatted_data_timestamp" => "September 25, 2018 8:00 AM",
      "data_timestamp" => "2018-09-25 7:30:00",
      "alert_level" => $alert_level,
      "event_category" => "event",
      "extended_day" => 0
    ];

    $ewi_template = $this->ewiTemplates->generateEwiFinalMessage($raw_template, $time_messages, $greeting);
    
    $expected_output = "Magandang umaga po.

    Alert 2 ang alert level sa Umingan, Alimodian, Iloilo ngayong September 25, 2018 8:00 AM.
    Naka-detect ang sensor ng significant ground movement. Ang recommended response ay PREPARE TO EVACUATE THE HOUSEHOLDS AT RISK. Inaasahan namin ang pagpapadala ng LEWC ng ground data mamaya bago mag-11:30 AM. Ang susunod na Early Warning Information ay mamayang 12:00 NN.

    Salamat.";
    // echo $ewi_template;
    $this->assertEquals($expected_output, $ewi_template);
  }

  public function testEWI_A2_m () {
    date_default_timezone_set('Asia/Manila');
    $current_date = date('Y-m-d H:i:s');//H:i:s
    $alert_level = "Alert 2";
    $tech_info = [["key_input" => "Naka-detect ng bagong cracks sa site"]];
    $recommended_reponse = [["key_input" => "Ang recommended response ay PREPARE TO EVACUATE THE HOUSEHOLDS AT RISK"]];
    $time = strtotime("07:00:00");
    $greeting = $this->ewiTemplates->generateGreetingsMessage($time);
    $release_time = strtotime("08:05:00");
    $time_messages = $this->ewiTemplates->generateTimeMessages($release_time);
    $site_details = [
      "site_id" => 50,
      "site_code" => "umi",
      "purok" => "",
      "sitio" => "",
      "barangay" => "Umingan",
      "municipality" => "Alimodian",
      "province" => "Iloilo",
      "region" => "VI",
      "psgc_source" => 63002053,
      "season" => 1
    ];
    $site_container = [$site_details];
    $backbone_template = [["template" => "Magandang (greetings) po.

    (alert_level) ang alert level sa (site_location) ngayong (current_date_time).
    (technical_info). (recommended_response). Inaasahan namin ang pagpapadala ng LEWC ng ground data (gndmeas_date_submission) (gndmeas_time_submission). Ang susunod na Early Warning Information ay mamayang (next_ewi_time).

    Salamat."]];

    $raw_template = [
      "site" => $site_container,
      "backbone" => $backbone_template,
      "tech_info" => $tech_info,
      "recommended_response" => $recommended_reponse,
      "formatted_data_timestamp" => "September 25, 2018 8:00 AM",
      "data_timestamp" => "2018-09-25 7:30:00",
      "alert_level" => $alert_level,
      "event_category" => "event",
      "extended_day" => 0
    ];

    $ewi_template = $this->ewiTemplates->generateEwiFinalMessage($raw_template, $time_messages, $greeting);
    
    $expected_output = "Magandang umaga po.

    Alert 2 ang alert level sa Umingan, Alimodian, Iloilo ngayong September 25, 2018 8:00 AM.
    Naka-detect ng bagong cracks sa site. Ang recommended response ay PREPARE TO EVACUATE THE HOUSEHOLDS AT RISK. Inaasahan namin ang pagpapadala ng LEWC ng ground data mamaya bago mag-11:30 AM. Ang susunod na Early Warning Information ay mamayang 12:00 NN.

    Salamat.";
    // echo $ewi_template;
    $this->assertEquals($expected_output, $ewi_template);
  }

  public function testEWI_A3_G () {
    date_default_timezone_set('Asia/Manila');
    $current_date = date('Y-m-d H:i:s');//H:i:s
    $alert_level = "Alert 3";
    $tech_info = [["key_input" => "Nakapagsukat ang LEWC ng critical ground movement"]];
    $recommended_reponse = [["key_input" => "Ang recommended response ay EVACUATE THE HOUSEHOLDS AT RISK"]];
    $time = strtotime("07:00:00");
    $greeting = $this->ewiTemplates->generateGreetingsMessage($time);
    $release_time = strtotime("08:05:00");
    $time_messages = $this->ewiTemplates->generateTimeMessages($release_time);
    $site_details = [
      "site_id" => 50,
      "site_code" => "umi",
      "purok" => "",
      "sitio" => "",
      "barangay" => "Umingan",
      "municipality" => "Alimodian",
      "province" => "Iloilo",
      "region" => "VI",
      "psgc_source" => 63002053,
      "season" => 1
    ];
    $site_container = [$site_details];
    $backbone_template = [["template" => "Magandang (greetings) po.

    Alert 3 ang alert level sa (site_location) ngayong (current_date_time). (technical_info). Ang recommended response ay EVACUATE THE HOUSEHOLDS AT RISK. Ang susunod na Early Warning Information ay mamayang (next_ewi_time).

    Salamat."]];

    $raw_template = [
      "site" => $site_container,
      "backbone" => $backbone_template,
      "tech_info" => $tech_info,
      "recommended_response" => $recommended_reponse,
      "formatted_data_timestamp" => "September 25, 2018 8:00 AM",
      "data_timestamp" => "2018-09-25 7:30:00",
      "alert_level" => $alert_level,
      "event_category" => "event",
      "extended_day" => 0
    ];

    $ewi_template = $this->ewiTemplates->generateEwiFinalMessage($raw_template, $time_messages, $greeting);
    
    $expected_output = "Magandang umaga po.

    Alert 3 ang alert level sa Umingan, Alimodian, Iloilo ngayong September 25, 2018 8:00 AM. Nakapagsukat ang LEWC ng critical ground movement. Ang recommended response ay EVACUATE THE HOUSEHOLDS AT RISK. Ang susunod na Early Warning Information ay mamayang 12:00 NN.

    Salamat.";
    // echo $ewi_template;
    $this->assertEquals($expected_output, $ewi_template);
  }

  public function testEWI_A3_S () {
    date_default_timezone_set('Asia/Manila');
    $current_date = date('Y-m-d H:i:s');//H:i:s
    $alert_level = "Alert 3";
    $tech_info = [["key_input" => "Naka-detect ang sensor ng critical ground movement"]];
    $recommended_reponse = [["key_input" => "Ang recommended response ay EVACUATE THE HOUSEHOLDS AT RISK"]];
    $time = strtotime("07:00:00");
    $greeting = $this->ewiTemplates->generateGreetingsMessage($time);
    $release_time = strtotime("08:05:00");
    $time_messages = $this->ewiTemplates->generateTimeMessages($release_time);
    $site_details = [
      "site_id" => 50,
      "site_code" => "umi",
      "purok" => "",
      "sitio" => "",
      "barangay" => "Umingan",
      "municipality" => "Alimodian",
      "province" => "Iloilo",
      "region" => "VI",
      "psgc_source" => 63002053,
      "season" => 1
    ];
    $site_container = [$site_details];
    $backbone_template = [["template" => "Magandang (greetings) po.

    Alert 3 ang alert level sa (site_location) ngayong (current_date_time). (technical_info). Ang recommended response ay EVACUATE THE HOUSEHOLDS AT RISK. Ang susunod na Early Warning Information ay mamayang (next_ewi_time).

    Salamat."]];

    $raw_template = [
      "site" => $site_container,
      "backbone" => $backbone_template,
      "tech_info" => $tech_info,
      "recommended_response" => $recommended_reponse,
      "formatted_data_timestamp" => "September 25, 2018 8:00 AM",
      "data_timestamp" => "2018-09-25 7:30:00",
      "alert_level" => $alert_level,
      "event_category" => "event",
      "extended_day" => 0
    ];

    $ewi_template = $this->ewiTemplates->generateEwiFinalMessage($raw_template, $time_messages, $greeting);
    
    $expected_output = "Magandang umaga po.

    Alert 3 ang alert level sa Umingan, Alimodian, Iloilo ngayong September 25, 2018 8:00 AM. Naka-detect ang sensor ng critical ground movement. Ang recommended response ay EVACUATE THE HOUSEHOLDS AT RISK. Ang susunod na Early Warning Information ay mamayang 12:00 NN.

    Salamat.";
    // echo $ewi_template;
    $this->assertEquals($expected_output, $ewi_template);
  }

  public function testEWI_A3_M () {
    date_default_timezone_set('Asia/Manila');
    $current_date = date('Y-m-d H:i:s');//H:i:s
    $alert_level = "Alert 3";
    $tech_info = [["key_input" => "Nagkaroon ng landslide at maaaring magkaroon pa ng paggalaw"]];
    $recommended_reponse = [["key_input" => "Ang recommended response ay EVACUATE THE HOUSEHOLDS AT RISK"]];
    $time = strtotime("07:00:00");
    $greeting = $this->ewiTemplates->generateGreetingsMessage($time);
    $release_time = strtotime("08:05:00");
    $time_messages = $this->ewiTemplates->generateTimeMessages($release_time);
    $site_details = [
      "site_id" => 50,
      "site_code" => "umi",
      "purok" => "",
      "sitio" => "",
      "barangay" => "Umingan",
      "municipality" => "Alimodian",
      "province" => "Iloilo",
      "region" => "VI",
      "psgc_source" => 63002053,
      "season" => 1
    ];
    $site_container = [$site_details];
    $backbone_template = [["template" => "Magandang (greetings) po.

    Alert 3 ang alert level sa (site_location) ngayong (current_date_time). (technical_info). Ang recommended response ay EVACUATE THE HOUSEHOLDS AT RISK. Ang susunod na Early Warning Information ay mamayang (next_ewi_time).

    Salamat."]];

    $raw_template = [
      "site" => $site_container,
      "backbone" => $backbone_template,
      "tech_info" => $tech_info,
      "recommended_response" => $recommended_reponse,
      "formatted_data_timestamp" => "September 25, 2018 8:00 AM",
      "data_timestamp" => "2018-09-25 7:30:00",
      "alert_level" => $alert_level,
      "event_category" => "event",
      "extended_day" => 0
    ];

    $ewi_template = $this->ewiTemplates->generateEwiFinalMessage($raw_template, $time_messages, $greeting);
    
    $expected_output = "Magandang umaga po.

    Alert 3 ang alert level sa Umingan, Alimodian, Iloilo ngayong September 25, 2018 8:00 AM. Nagkaroon ng landslide at maaaring magkaroon pa ng paggalaw. Ang recommended response ay EVACUATE THE HOUSEHOLDS AT RISK. Ang susunod na Early Warning Information ay mamayang 12:00 NN.

    Salamat.";
    // echo $ewi_template;
    $this->assertEquals($expected_output, $ewi_template);
  }





}