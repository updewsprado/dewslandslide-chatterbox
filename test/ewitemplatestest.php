<?php

// To run test: ./vendor/bin/phpunit --bootstrap vendor/autoload.php test/cbxtest

require_once "/var/www/chatterbox/src/ChatMessageModel.php";
use MyApp\EwiTemplate;

use PHPUnit\Framework\TestCase;

final class EwiTemplatesTest extends TestCase {

  public function __construct() {
    $this->ewiTemplates = new EwiTemplate;
  }

  /***************************************************************************/
  // test Generated Messages from time of release
  /***************************************************************************/

  // release time of 4 AM
  public function testGenTime_0400_AM() {
  	$release_time = strtotime("04:00:00");
  	$timeTemplate = $this->ewiTemplates->generateTimeMessages($release_time);

    $timeExpected = [
      "date_submission" => "mamaya",
      "time_submission" => "bago mag-7:30 AM",
      "next_ewi_time" => "8:00 AM"
    ];

    $this->assertEquals($timeExpected, $timeTemplate);
  }

  // release time of 8 AM
  public function testGenTime_0800_AM() {
  	$release_time = strtotime("08:00:00");
  	$timeTemplate = $this->ewiTemplates->generateTimeMessages($release_time);

    $timeExpected = [
      "date_submission" => "mamaya",
      "time_submission" => "bago mag-11:30 AM",
      "next_ewi_time" => "12:00 NN"
    ];

    $this->assertEquals($timeExpected, $timeTemplate);
  }

  // release time of 12 NN
  public function testGenTime_1200_NN() {
  	$release_time = strtotime("12:00:00");
  	$timeTemplate = $this->ewiTemplates->generateTimeMessages($release_time);

    $timeExpected = [
      "date_submission" => "mamaya",
      "time_submission" => "bago mag-3:30 PM",
      "next_ewi_time" => "4:00 PM"
    ];

    $this->assertEquals($timeExpected, $timeTemplate);
  }

  // release time of 4 PM
  public function testGenTime_0400_PM() {
  	$release_time = strtotime("16:00:00");
  	$timeTemplate = $this->ewiTemplates->generateTimeMessages($release_time);

    $timeExpected = [
      "date_submission" => "bukas",
      "time_submission" => "bago mag-7:30 AM",
      "next_ewi_time" => "8:00 PM"
    ];

    $this->assertEquals($timeExpected, $timeTemplate);
  }

  // release time of 8 PM
  public function testGenTime_0800_PM() {
  	$release_time = strtotime("20:00:00");
  	$timeTemplate = $this->ewiTemplates->generateTimeMessages($release_time);

    $timeExpected = [
      "date_submission" => "bukas",
      "time_submission" => "bago mag-7:30 AM",
      "next_ewi_time" => "12:00 MN"
    ];

    $this->assertEquals($timeExpected, $timeTemplate);
  }

  // release time of 12 MN
  public function testGenTime_1200_MN() {
  	$release_time = strtotime("00:00:00");
  	$timeTemplate = $this->ewiTemplates->generateTimeMessages($release_time);

    $timeExpected = [
      "date_submission" => "mamaya",
      "time_submission" => "bago mag-7:30 AM",
      "next_ewi_time" => "4:00 AM"
    ];

    $this->assertEquals($timeExpected, $timeTemplate);
  }

  /***************************************************************************/
  // test Generated Greetings from time of release
  /***************************************************************************/

  // release time of 12 MN
  public function testGreeting_1200_MN() {
  	$release_time = strtotime("00:00:00");
  	$greeting = $this->ewiTemplates->generateGreetingsMessage($release_time);
  	$greetingExpected = "gabi";

  	$this->assertEquals($greetingExpected, $greeting);
  }

  // release time of 4 AM
  public function testGreeting_0400_AM() {
  	$release_time = strtotime("04:00:00");
  	$greeting = $this->ewiTemplates->generateGreetingsMessage($release_time);
  	$greetingExpected = "umaga";

  	$this->assertEquals($greetingExpected, $greeting);
  }

  // release time of 8 AM
  public function testGreeting_0800_AM() {
  	$release_time = strtotime("08:00:00");
  	$greeting = $this->ewiTemplates->generateGreetingsMessage($release_time);
  	$greetingExpected = "umaga";

  	$this->assertEquals($greetingExpected, $greeting);
  }

  // release time of 12 NN
  public function testGreeting_1200_NN() {
  	$release_time = strtotime("12:00:00");
  	$greeting = $this->ewiTemplates->generateGreetingsMessage($release_time);
  	$greetingExpected = "tanghali";

  	$this->assertEquals($greetingExpected, $greeting);
  }

  // release time of 1:30 PM
  public function testGreeting_0130_PM() {
  	$release_time = strtotime("13:30:00");
  	$greeting = $this->ewiTemplates->generateGreetingsMessage($release_time);
  	$greetingExpected = "hapon";

  	$this->assertEquals($greetingExpected, $greeting);
  }

  // release time of 4 PM
  public function testGreeting_0400_PM() {
  	$release_time = strtotime("16:00:00");
  	$greeting = $this->ewiTemplates->generateGreetingsMessage($release_time);
  	$greetingExpected = "hapon";

  	$this->assertEquals($greetingExpected, $greeting);
  }

  // release time of 6 PM
  public function testGreeting_0600_PM() {
  	$release_time = strtotime("18:00:00");
  	$greeting = $this->ewiTemplates->generateGreetingsMessage($release_time);
  	$greetingExpected = "gabi";

  	$this->assertEquals($greetingExpected, $greeting);
  }

  // release time of 8 PM
  public function testGreeting_0800_PM() {
  	$release_time = strtotime("20:00:00");
  	$greeting = $this->ewiTemplates->generateGreetingsMessage($release_time);
  	$greetingExpected = "gabi";

  	$this->assertEquals($greetingExpected, $greeting);
  }

  // release time of 11:55 PM
  public function testGreeting_1155_PM() {
  	$release_time = strtotime("23:55:00");
  	$greeting = $this->ewiTemplates->generateGreetingsMessage($release_time);
  	$greetingExpected = "gabi";

  	$this->assertEquals($greetingExpected, $greeting);
  }

  /***************************************************************************/
  // test Generated extended day messages
  /***************************************************************************/

  public function testExtended_day_3() {
    $day = 3;
    $get_extended_text = $this->ewiTemplates->generateExtendedDayMessage($day);
    $text_expected = "susunod na routine";

    $this->assertEquals($text_expected, $get_extended_text);
  }

  public function testExtended_day_2() {
    $day = 2;
    $get_extended_text = $this->ewiTemplates->generateExtendedDayMessage($day);
    $text_expected = "huling araw ng 3-day extended";

    $this->assertEquals($text_expected, $get_extended_text);
  }

  public function testExtended_day_1() {
    $day = 1;
    $get_extended_text = $this->ewiTemplates->generateExtendedDayMessage($day);
    $text_expected = "ikalawang araw ng 3-day extended";

    $this->assertEquals($text_expected, $get_extended_text);
  }


  public function testSiteDetails_bak() {
    $site_details = [
      "site_id" => 2,
      "site_code" => "bak",
      "purok" => "",
      "sitio" => "",
      "barangay" => "Poblacion",
      "municipality" => "Bakun",
      "province" => "Benguet",
      "region" => "CAR",
      "psgc_source" => 141103009,
      "season" => 2
    ];
    $site_container = [$site_details];
    $raw_template = [
      "site" => $site_container
    ];
    $generated_site_details = $this->ewiTemplates->generateSiteDetails($raw_template);
    $expected_site_details = "Poblacion, Bakun, Benguet";
    $this->assertEquals($expected_site_details, $generated_site_details);
  }

  public function testSiteDetails_blc() {
    $site_details = [
      "site_id" => 6,
      "site_code" => "blc",
      "purok" => "",
      "sitio" => "",
      "barangay" => "Boloc",
      "municipality" => "Tubungan",
      "province" => "Iloilo",
      "region" => "VI",
      "psgc_source" => 63046016,
      "season" => 1
    ];
    $site_container = [$site_details];
    $raw_template = [
      "site" => $site_container
    ];
    $generated_site_details = $this->ewiTemplates->generateSiteDetails($raw_template);
    $expected_site_details = "Boloc, Tubungan, Iloilo";
    $this->assertEquals($expected_site_details, $generated_site_details);
  }

  public function testSiteDetails_pug() {
    $site_details = [
      "site_id" => 42,
      "site_code" => "pug",
      "purok" => "",
      "sitio" => "Longlong",
      "barangay" => "Puguis",
      "municipality" => "La Trinidad",
      "province" => "Benguet",
      "region" => "CAR",
      "psgc_source" => 141110013,
      "season" => 2
    ];
    $site_container = [$site_details];
    $raw_template = [
      "site" => $site_container
    ];
    $generated_site_details = $this->ewiTemplates->generateSiteDetails($raw_template);
    $expected_site_details = "Longlong, Puguis, La Trinidad, Benguet";
    $this->assertEquals($expected_site_details, $generated_site_details);
  }

  public function testSiteDetails_umi() {
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
    $raw_template = [
      "site" => $site_container
    ];
    $generated_site_details = $this->ewiTemplates->generateSiteDetails($raw_template);
    $expected_site_details = "Umingan, Alimodian, Iloilo";
    $this->assertEquals($expected_site_details, $generated_site_details);
  }

  public function testSiteDetails_tue() {
    $site_details = [
      "site_id" => 49,
      "site_code" => "tue",
      "purok" => "",
      "sitio" => "",
      "barangay" => "Tue",
      "municipality" => "Tadian",
      "province" => "Mt. Province",
      "region" => "CAR",
      "psgc_source" => 144410019,
      "season" => 1
    ];
    $site_container = [$site_details];
    $raw_template = [
      "site" => $site_container
    ];
    $generated_site_details = $this->ewiTemplates->generateSiteDetails($raw_template);
    $expected_site_details = "Tue, Tadian, Mt. Province";
    $this->assertEquals($expected_site_details, $generated_site_details);
  }

}