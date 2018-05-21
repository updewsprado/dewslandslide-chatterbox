<?php

	$servername = "localhost";
	$username = "root";
	$password = "senslope";
	$dbname = "senslopedb";
	$newdb = "newdb";

	echo "Fetching smsinbox.\n";

	$smsinbox = shell_exec("ssh -i /var/www/keys/senslopeReservedInstanceT2Medium.pem ubuntu@www.dewslandslide.com mysqldump -uroot -psenslope senslopedb smsinbox > smsinbox.sql");

	echo "Fetching smsoutbox.\n";
	$smsoutbox = shell_exec("ssh -i /var/www/keys/senslopeReservedInstanceT2Medium.pem ubuntu@www.dewslandslide.com mysqldump -uroot -psenslope senslopedb smsoutbox > smsoutbox.sql");

	echo "Fetching communitycontacts.\n";
	$community_contacts = shell_exec("ssh -i /var/www/keys/senslopeReservedInstanceT2Medium.pem ubuntu@www.dewslandslide.com mysqldump -uroot -psenslope senslopedb communitycontacts > communitycontacts.sql");

	echo "Fetching dewslcontacts.\n";
	$employee_contacts = shell_exec("ssh -i /var/www/keys/senslopeReservedInstanceT2Medium.pem ubuntu@www.dewslandslide.com mysqldump -uroot -psenslope senslopedb dewslcontacts > dewslcontact.sql");


	$conn = new mysqli($servername, $username, $password, $dbname);
	if ($conn->connect_error) {
	    die("Connection failed: " . $conn->connect_error);
	}  else {
	    echo "Connection established to senslopedb.\n\n";
	}

	echo "Cleaning up chatterbox related tables. \n\n";
	$sql = "TRUNCATE TABLE dewslcontacts; TRUCATE TABLE communitycontacts; TRUNCATE TABLE smsinbox; TRUCATE TABLE smsoutbox";
	$result = $conn->query($sql);

	echo "Cleaning up chatterboxRefDB related tables. \n\n";
	$sql = "TRUNCATE TABLE newdb.users; TRUCATE TABLE newdb.user_mobile; TRUNCATE TABLE newdb.user_organization;";
	$result = $conn->query($sql);

	echo "Importing dewslcontacts.\n";
	$smsinbox = shell_exec("mysql -uroot -psenslope senslopedb < dewslcontact.sql");

	echo "\nImporting communitycontacts.\n";
	$smsinbox = shell_exec("mysql -uroot -psenslope senslopedb < communitycontacts.sql");

	echo "\nImporting smsinbox.\n";
	$smsinbox = shell_exec("mysql -uroot -psenslope senslopedb < smsinbox.sql");

	echo "\nImporting smsoutbox.\n";
	$smsinbox = shell_exec("mysql -uroot -psenslope senslopedb < smsoutbox.sql");


	$fetch_dewsl_contacts = "SELECT DISTINCT * FROM dewslcontacts;";
	$dewslcontacts = $conn->query($fetch_dewsl_contacts);

	echo "Inserting dewsl contacts to newdb!\n";
	if ($dewslcontacts->num_rows > 0) {
	    while($row = $dewslcontacts->fetch_assoc()) {
	       $check_if_existing = "SELECT * FROM newdb.users where firstname like '%".$row['firstname']."%' and lastname like '%".$row['lastname']."%';";
	       $existing = $conn->query($check_if_existing);
	       if ($existing->num_rows == 0) {
	       		$insert_contact = "INSERT INTO newdb.users VALUES (0,'','".$row['firstname']."','','".$row['lastname']."','','1990-01-01','','1')";
	       		$result = $conn->query($insert_contact);

	       		$last_id = $conn->insert_id;

	       		$check_if_mobile_exists = "SELECT * FROM newdb.user_mobile where sim_num like '%".substr($row["numbers"], -10)."%'";
	       		$mobile_exists = $conn->query($check_if_mobile_exists);
	       		if ($mobile_exists->num_rows == 0) {
	       			$insert_mobile = "INSERT INTO newdb.user_mobile VALUES (0,'".$last_id."','"."63".substr($row["numbers"], -10)."','1','1')";
	       			$result = $conn->query($insert_mobile);
	       		}
	       		echo "Successfully added new contact! ".$row['lastname'].", ".$row['firstname']."! Mobile#: ".$row['numbers']." status: ".$result."\n";
	       }
	    }
	} else {
	    echo "0 results.";
	    return;
	}

	$fetch_community_contacts = "SELECT DISTINCT * FROM communitycontacts;";
	$community_contacts = $conn->query($fetch_community_contacts);

	echo "Inserting community contacts to newdb!\n";
	if ($community_contacts->num_rows > 0) {
	    while($row = $community_contacts->fetch_assoc()) {
	       $check_if_existing = "SELECT * FROM newdb.users where firstname like '%".$row['firstname']."%' and lastname like '%".$row['lastname']."%';";
	       $existing = $conn->query($check_if_existing);
	       if ($existing->num_rows == 0) {
	       		$insert_contact = "INSERT INTO newdb.users VALUES (0,'','".$row['firstname']."','','".$row['lastname']."','','1990-01-01','','1')";
	       		$result = $conn->query($insert_contact);

	       		$last_id = $conn->insert_id;

	       		$check_if_mobile_exists = "SELECT * FROM newdb.user_mobile where sim_num like '%".substr($row["number"], -10)."%'";
	       		$mobile_exists = $conn->query($check_if_mobile_exists);
	       		if ($mobile_exists->num_rows == 0) {
	       			$insert_mobile = "INSERT INTO newdb.user_mobile VALUES (0,'".$last_id."','"."63".substr($row["number"], -10)."','1','1')";
	       			$result = $conn->query($insert_mobile);
	       		}

	       		$get_site_id = "SELECT * FROM newdb.sites where site_code like '%".$row['sitename']."%'";
	       		$result = $conn->query($get_site_id);
	       		$insert_org = "INSERT INTO newdb.user_organization VALUES (0,'".$last_id."','".$result->fetch_assoc()['site_id']."','".$row['office']."','1')";
	       		$result = $conn->query($insert_org);

       			echo "Successfully added new contact! ".$row['lastname'].", ".$row['firstname']."! Mobile#: ".$row['number']." status: ".$result."\n";
	        }
	    }
	} else {
	    echo "0 results.";
	    return;
	}

	echo "Syncing smsinbox table..\n\n";

	$get_smsinbox = "SELECT * FROM smsinbox";
	$result = $conn->query($get_smsinbox);
	if ($result->num_rows != 0) {
		while ($row = $result->fetch_assoc()) {
			$get_mobile_id = "SELECT * FROM newdb.user_mobile where sim_num like '%".substr($row["sim_num"], -10)."%'";
			$mobile_id = $conn->query($get_mobile_id);

			if ($mobile_id->num_rows > 0) {
				$insert_sms_refdb = "INSERT INTO newdb.smsinbox_users VALUES (0,'".$row['timestamp']."','".$mobile_id->fetch_assoc()['mobile_id']."','".$row['sms_msg']."','1','0','1')";
				$sms = $conn->query($insert_sms_refdb);
				if ($sms == true) {
					echo "Successfull added new entry for smsinboxRefDB! SMS ID: ".$conn->insert_id."\n";
				} else {
					echo "Failed to add new entry for smsinboxRefDB! SMS ID: ".$conn->insert_id."\n";
				}
			} else {
				echo "Mobile ID unknown.. skipping..\n";
			}
		}
	} else {
		echo "0 results.\n\n";
	}

	echo "Syncing smsoutbox table..\n\n";

	$get_smsoutbox = "SELECT * FROM smsoutbox";
	$result = $conn->query($get_smsoutbox);
	if ($result->num_rows != 0) {
		while ($row = $result->fetch_assoc()) {
			$get_mobile_id = "SELECT * FROM newdb.user_mobile where sim_num like '%".substr($row["recepients"], -10)."%'";
			$mobile_id = $conn->query($get_mobile_id);

			if ($mobile_id->num_rows > 0) {
				$insert_sms_refdb = "INSERT INTO newdb.smsoutbox_users VALUES (0,'".$row['timestamp_written']."','central','".$row['sms_msg']."')";
				$sms = $conn->query($insert_sms_refdb);
				if ($sms == true) {
					$insert_sms_outbox_status = "INSERT INTO newdb.smsoutbox_user_status VALUES (0,'".$conn->insert_id."','".$mobile_id->fetch_assoc()['mobile_id']."','0000-00-00 00:00:00','0','0','1')";
					$smsoutbox = $conn->query($insert_sms_outbox_status);
					if ($smsoutbox == true) {
						echo "Successfull added new entry for smsoutboxRefDB! SMS ID: ".$conn->insert_id."\n";
					} else {
						echo "Failed to add new entry for smsoutboxRefDB! SMS ID: ".$conn->insert_id."\n";
					}
				} else {
					echo "Failed to add new entry for smsoutboxRefDB! SMS ID: ".$conn->insert_id."\n";
				}
			} else {
				echo "Mobile ID unknown.. skipping..\n";
			}
		}
	} else {
		echo "0 results.\n\n";
	}

?>