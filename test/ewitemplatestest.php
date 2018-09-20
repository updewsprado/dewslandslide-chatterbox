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

}