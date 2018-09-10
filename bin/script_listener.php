<?php
	$status = false;
	while ($status == false) {
		echo "-----------LISTENER----------\n";
		$output = shell_exec('ps -C php -f');
		if (strpos($output, "php /var/www/chatterbox/bin/chatterbox-server.php") === false){ 

  			$process_list = shell_exec('ps ax | grep chatterbox-server.php');
			$get_id_raw_cbx = explode(" ", $process_list);
			$get_id = $get_id_raw_cbx[1];
			echo $get_id;
			echo "\n\n";
			$kill = shell_exec("kill -KILL ".$get_id);

  			$process_list = shell_exec('ps ax | grep smsoutbox-server.php');
			$get_id_raw_cbx = explode(" ", $process_list);
			$get_id = $get_id_raw_cbx[1];
			echo $get_id;
			echo "\n\n";
			$kill = shell_exec("kill -KILL ".$get_id);

  			$process_list = shell_exec('ps ax | grep smsinbox-server.php');
			$get_id_raw_cbx = explode(" ", $process_list);
			$get_id = $get_id_raw_cbx[1];
			echo $get_id;
			echo "\n\n";
			$kill = shell_exec("kill -KILL ".$get_id);

	  		shell_exec('screen -S chatterbox-server -d -m php /var/www/chatterbox/bin/chatterbox-server.php');
		} else {
			echo "Chatterbox script is running\n";
		}

		if (strpos($output, "php /var/www/chatterbox/bin/smsinbox-server.php") === false){ 
	  		shell_exec('screen -S smsinbox-server -d -m php /var/www/chatterbox/bin/smsinbox-server.php');
		} else {
			echo "Smsinbox script is running\n";
		}

		if (strpos($output, "php /var/www/chatterbox/bin/smsoutbox-server.php") === false){ 
	  		shell_exec('screen -S smsoutbox-server -d -m php /var/www/chatterbox/bin/smsoutbox-server.php');
		} else {
			echo "Smsoutbox script is running\n";
		}

		echo "-----------LISTENER----------\n\n\n";
		sleep(1);
	}
?>