<?php
	$status = false;
	echo "-----------LISTENER----------\n";
	$output = shell_exec('ps -C php -f');
	if (strpos($output, "php chatterbox-server.php") === false){ 
  		shell_exec('php smsinbox-server.php  > /dev/null 2>&1 &');
  		shell_exec('screen -S chatterbox-server -d -m php /var/www/chatterbox/bin/chatterbox-server.php');
	} else {
		echo "Chatterbox script is running\n";
	}

	if (strpos($output, "php smsinbox-server.php") === false){ 
    	shell_exec('php smsinbox-server.php  > /dev/null 2>&1 &');
  		shell_exec('screen -S smsinbox-server -d -m php /var/www/chatterbox/bin/smsinbox-server.php');
	} else {
		echo "Smsinbox script is running\n";
	}

	if (strpos($output, "php smsoutbox-server.php") === false){ 
  		shell_exec('php smsinbox-server.php  > /dev/null 2>&1 &');
  		shell_exec('screen -S smsoutbox-server -d -m php /var/www/chatterbox/bin/smsoutbox-server.php');
	} else {
		echo "Smsoutbox script is running\n";
	}
	echo "-----------LISTENER----------\n\n\n";
	
?>