<?php
namespace MyApp;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class EwiTemplate {

  public function __construct() {

  }

  function reconstructEWITemplate($raw_data) {
    $counter = 0;
    $time_submission = null;
    $date_submission = null;
    $ewi_time = null;
    $greeting = null;
    date_default_timezone_set('Asia/Manila');
    $current_date = date('Y-m-d H:i:s');//H:i:s
    // var_dump($current_date);
    $final_template = $raw_data['backbone'][0]['template'];
    
    if (($raw_data['site'][0]['purok'] == "" || $raw_data['site'][0]['purok'] == NULL) && $raw_data['site'][0]['sitio'] != NULL) {
      $reconstructed_site_details = $raw_data['site'][0]['sitio'].", ".$raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
    } 
    else if ($raw_data['site'][0]['sitio'] == "" || $raw_data['site'][0]['sitio'] == NULL) {
       $reconstructed_site_details = $raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
    } 
    else if (($raw_data['site'][0]['sitio'] == "" || $raw_data['site'][0]['sitio'] == NULL) && ($raw_data['site'][0]['purok'] == "" || $raw_data['site'][0]['purok'] == NULL)) {
      $reconstructed_site_details = $raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
    } 
    else {
      $reconstructed_site_details = $raw_data['site'][0]['purok'].", ".$raw_data['site'][0]['sitio'].", ".$raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
    }

    $time_of_release = strtotime($raw_data['data_timestamp']);
    $greeting = $this->generateGreetingsMessage($time_of_release);
    echo $greeting;
    if(strtotime($current_date) >= strtotime(date("Y-m-d 00:00:00")) && strtotime($current_date) < strtotime(date("Y-m-d 11:59:59"))){
      $greeting = "umaga";
    }else if(strtotime($current_date) >= strtotime(date("Y-m-d 12:00:00")) && strtotime($current_date) < strtotime(date("Y-m-d 13:00:00"))){
      $greeting = "tanghali";
    }else if(strtotime($current_date) >= strtotime(date("Y-m-d 13:00:01")) && strtotime($current_date) < strtotime(date("Y-m-d 17:59:59"))) {
      $greeting = "hapon";
    }else if(strtotime($current_date) >= strtotime(date("Y-m-d 18:00:00")) && strtotime($current_date) < strtotime(date("Y-m-d 23:59:59"))){
      $greeting = "gabi";
    }
    // var_dump($greeting);
    
    // $time_of_release = date("2018-09-21 02:30:00");
    // $datetime = explode(" ",$time_of_release);
    // $time = strtotime($datetime[1]);

    if($time_of_release >= strtotime(date("Y-m-d 00:00:00")) && $time_of_release <= strtotime(date("Y-m-d 04:00:00"))){
      $date_submission = "mamaya";
      $time_submission = "bago mag-07:30 AM";
      $ewi_time = "04:00 AM";
    } 
    else if($time_of_release >= strtotime(date("Y-m-d 04:00:00")) && $time_of_release <= strtotime(date("Y-m-d 07:59:59"))){
      $date_submission = "mamaya";
      $time_submission = "bago mag-07:30 AM";
      $ewi_time = "08:00 AM";
    } 
    else if($time_of_release >= strtotime(date("Y-m-d 08:00:00")) && $time_of_release <= strtotime(date("Y-m-d 15:59:59"))){
      $date_submission = "mamaya";
      $time_submission = "bago mag-3:30 PM";
      $ewi_time = "04:00 PM";
    } 
    else if($time_of_release >= strtotime(date("Y-m-d 16:00:00")) && $time_of_release <= strtotime(date("Y-m-d 19:59:59"))){
      $date_submission = "bukas";
      $time_submission = "bago mag-7:30 AM";
      $ewi_time = "08:00 PM";
    } 
    else if($time_of_release >= strtotime(date("Y-m-d 20:00:00"))){
      $date_submission = "bukas";
      $time_submission = "bago mag-7:30 AM";
      $ewi_time = "12:00 MN";
    } 
    else {
      $date_submission = "mamaya";
      $time_submission = "bago mag-07:30 AM";
      $ewi_time = "04:00 AM";
    }

    if($raw_data['alert_level'] == "Alert 0" || $raw_data['event_category'] == "extended" && $raw_data['alert_level'] == "Alert 1"){
      $final_template = str_replace("(site_location)",$reconstructed_site_details,$final_template);
      $final_template = str_replace("(alert_level)",$raw_data['alert_level'],$final_template);
      $final_template = str_replace("(current_date_time)",$raw_data['formatted_data_timestamp'],$final_template);
      $final_template = str_replace("(greetings)",$greeting,$final_template);

      if($raw_data['event_category'] == "extended"){
        $extended_day_text = null;
        if($raw_data['extended_day'] == 3){
            $extended_day_text = "susunod na routine";
        }
        else if($raw_data['extended_day'] == 2) {
            $extended_day_text = "ikalawang araw ng 3-day extended";
        }
        else if($raw_data['extended_day'] == 1) {
            $extended_day_text = "unang araw ng 3-day extended";
        }

        $final_template = str_replace("(current_date)",$raw_data['formatted_data_timestamp'],$final_template);
        $final_template = str_replace("(nth-day-extended)",$extended_day_text ,$final_template);
      }
      else if ($raw_data['event_category'] == "event") {
        $final_template = str_replace("(current_date_time)",$raw_data['formatted_data_timestamp'],$final_template);
        $final_template = str_replace("(nth-day-extended)",$raw_data['extended_day'] . "-day" ,$final_template);
      }
    }
    else {
      $final_template = str_replace("(site_location)",$reconstructed_site_details,$final_template);
      $final_template = str_replace("(alert_level)",$raw_data['alert_level'],$final_template);
      $final_template = str_replace("(current_date_time)",$raw_data['formatted_data_timestamp'],$final_template);
      $final_template = str_replace("(technical_info)",$raw_data['tech_info'][0]['key_input'],$final_template);
      $final_template = str_replace("(recommended_response)",$raw_data['recommended_response'][0]['key_input'],$final_template);
      $final_template = str_replace("(gndmeas_date_submission)",$date_submission,$final_template);
      $final_template = str_replace("(gndmeas_time_submission)",$time_submission,$final_template);
      $final_template = str_replace("(next_ewi_time)",$ewi_time,$final_template);
      $final_template = str_replace("(greetings)",$greeting,$final_template);
    }

    

    return $final_template;
  }

  function generateEwiFinalMessage($raw_data, $time_messages, $greeting) {
    $final_template = $raw_data['backbone'][0]['template'];
    $site_details = $this->generateSiteDetails($raw_data);
    if($raw_data['alert_level'] == "Alert 0" || $raw_data['event_category'] == "extended" && $raw_data['alert_level'] == "Alert 1"){
      $final_template = str_replace("(site_location)",$site_details,$final_template);
      $final_template = str_replace("(alert_level)",$raw_data['alert_level'],$final_template);
      $final_template = str_replace("(current_date_time)",$raw_data['formatted_data_timestamp'],$final_template);
      $final_template = str_replace("(greetings)",$greeting,$final_template);
      if($raw_data['event_category'] == "extended"){
          $extended_day_text = $this->generateExtendedDayMessage($raw_data['extended_day']);
          $final_template = str_replace("(current_date)",$raw_data['formatted_data_timestamp'],$final_template);
          $final_template = str_replace("(nth-day-extended)",$extended_day_text ,$final_template);
      }else if ($raw_data['event_category'] == "event") {
          $final_template = str_replace("(current_date_time)",$raw_data['formatted_data_timestamp'],$final_template);
          $final_template = str_replace("(nth-day-extended)",$raw_data['extended_day'] . "-day" ,$final_template);
      }
    }else {
      $final_template = str_replace("(site_location)",$site_details,$final_template);
      $final_template = str_replace("(alert_level)",$raw_data['alert_level'],$final_template);
      $final_template = str_replace("(current_date_time)",$raw_data['formatted_data_timestamp'],$final_template);
      $final_template = str_replace("(technical_info)",$raw_data['tech_info'][0]['key_input'],$final_template);
      $final_template = str_replace("(recommended_response)",$raw_data['recommended_response'][0]['key_input'],$final_template);
      $final_template = str_replace("(gndmeas_date_submission)",$time_messages["date_submission"],$final_template);
      $final_template = str_replace("(gndmeas_time_submission)",$time_messages["time_submission"],$final_template);
      $final_template = str_replace("(next_ewi_time)",$time_messages["next_ewi_time"],$final_template);
      $final_template = str_replace("(greetings)",$greeting,$final_template);
    }

    return $final_template;
  }


  function generateTimeMessages($release_time) {
    if($release_time >= strtotime(date("Y-m-d 00:00:00")) && $release_time < strtotime(date("Y-m-d 04:00:00"))){
      $date_submission = "mamaya";
      $time_submission = "bago mag-7:30 AM";
      $next_ewi_time = "4:00 AM";
    } 
    else if($release_time >= strtotime(date("Y-m-d 04:00:00")) && $release_time < strtotime(date("Y-m-d 08:00:00"))){
      $date_submission = "mamaya";
      $time_submission = "bago mag-7:30 AM";
      $next_ewi_time = "8:00 AM";
    } 
    else if($release_time >= strtotime(date("Y-m-d 08:00:00")) && $release_time < strtotime(date("Y-m-d 12:00:00"))){
      $date_submission = "mamaya";
      $time_submission = "bago mag-11:30 AM";
      $next_ewi_time = "12:00 NN";
    } 
    else if($release_time >= strtotime(date("Y-m-d 08:00:00")) && $release_time < strtotime(date("Y-m-d 16:00:00"))){
      $date_submission = "mamaya";
      $time_submission = "bago mag-3:30 PM";
      $next_ewi_time = "4:00 PM";
    } 
    else if($release_time >= strtotime(date("Y-m-d 16:00:00")) && $release_time < strtotime(date("Y-m-d 20:00:00"))){
      $date_submission = "bukas";
      $time_submission = "bago mag-7:30 AM";
      $next_ewi_time = "8:00 PM";
    } 
    else if($release_time >= strtotime(date("Y-m-d 20:00:00"))){
      $date_submission = "bukas";
      $time_submission = "bago mag-7:30 AM";
      $next_ewi_time = "12:00 MN";
    } 
    else {
      $date_submission = "mamaya";
      $time_submission = "bago mag-7:30 AM";
      $next_ewi_time = "4:00 AM";
    }

    $timeTemplate = [
      "date_submission" => $date_submission,
      "time_submission" => $time_submission,
      "next_ewi_time" => $next_ewi_time
    ];

    return $timeTemplate;
  }

  function generateGreetingsMessage($release_time) {
    if( $release_time >= strtotime(date("Y-m-d 18:00:00")) && $release_time <= strtotime(date("Y-m-d 23:59:59")) ){
      $greeting = "gabi";
    } 
    else if( $release_time == strtotime(date("Y-m-d 00:00:00")) ){
      $greeting = "gabi";
    } 
    else if( $release_time > strtotime(date("Y-m-d 00:00:00")) && $release_time < strtotime(date("Y-m-d 12:00:00")) ){
      $greeting = "umaga";
    } 
    else if( $release_time == strtotime(date("Y-m-d 12:00:00")) ){
      $greeting = "tanghali";
    } 
    else if( $release_time > strtotime(date("Y-m-d 12:00:00")) && $release_time < strtotime(date("Y-m-d 18:00:00")) ){
      $greeting = "hapon";
    } 
    else {
      $greeting = "araw";
    }

    return $greeting;
  }


  function generateExtendedDayMessage($day) {
    if($day == 3){
        $extended_day_message = "susunod na routine";
    }else if($day == 2) {
        $extended_day_message = "huling araw ng 3-day extended";
    }else if($day == 1) {
        $extended_day_message = "ikalawang araw ng 3-day extended";
    }

    return $extended_day_message;
  }


  function generateSiteDetails($raw_data) {
    if (($raw_data['site'][0]['purok'] == "" || $raw_data['site'][0]['purok'] == NULL) && $raw_data['site'][0]['sitio'] != NULL) {
        $reconstructed_site_details = $raw_data['site'][0]['sitio'].", ".$raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
    } else if ($raw_data['site'][0]['sitio'] == "" || $raw_data['site'][0]['sitio'] == NULL) {
         $reconstructed_site_details = $raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
    } else if (($raw_data['site'][0]['sitio'] == "" || $raw_data['site'][0]['sitio'] == NULL) && ($raw_data['site'][0]['purok'] == "" || $raw_data['site'][0]['purok'] == NULL)) {
        $reconstructed_site_details = $raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
    } else {
         $reconstructed_site_details = $raw_data['site'][0]['purok'].", ".$raw_data['site'][0]['sitio'].", ".$raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
    }

    return $reconstructed_site_details;
  }
}
