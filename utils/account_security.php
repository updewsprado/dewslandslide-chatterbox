<?php

	$servername = "localhost";
	$username = "root";
	$password = "senslope";
	$dbname = "senslopedb";

	$servername = "localhost";
	$username = "root";
	$password = "senslope";
	$rack_db = "comms_db";

    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }  else {
        echo "Connection to localhost established.\n\n";
    }

    $rack = new mysqli($servername, $username, $password, $rack_db);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }  else {
        echo "Connection to rack db established.\n\n";
    }

    echo "Cleaning sql files..\n\n";
    $clean = exec('sudo rm membership.sql');

    echo "Dumping membership database from web machine..\n\n";
	$get_web_membership = exec("sudo ssh -i /var/www/keys/senslopeReservedInstanceT2Medium.pem ubuntu@www.dewslandslide.com mysqldump -uroot -psenslope senslopedb membership > membership.sql");

    echo "Truncating local membership database..\n\n";
    $sql = "TRUNCATE TABLE membership";
    $result = $conn->query($sql);

	echo "Importing membership db to old senslopedb...\n\n";
	$import_database_outbox = exec('mysql -uroot -psenslope senslopedb < membership.sql');


	echo "Reading membership from old db... \n\n";
	$read = "SELECT * FROM membership";
	$members_container = $conn->query($read);

	while ($row = $members_container->fetch_assoc()) {
		echo "Checking user: ".$row['first_name'].", ".$row['last_name']." if existing in the rack database..\n";
		$rack_users = "SELECT user_id FROM comms_db.users where firstname = '".$row['first_name']."' AND lastname = '".$row['last_name']."'";
    	$result = $rack->query($rack_users);
    	if ($result->num_rows != 0) {
    		while ($user_details = $result->fetch_assoc()) {
				echo $row['first_name'].", ".$row['last_name']." exists!\n";
	    		echo "Insert hashed password into rack database..\n";
	    		$sql = "INSERT INTO comms_db.membership VALUES (0, '".$user_details['user_id']."','".$row['username']."','".$row['password']."','NULL')";
	    		$insert_members_to_rack = $rack->query($sql);
	    		if ($insert_members_to_rack == true) {
	    			echo "Successfully inserted membership account for ".$row['first_name'].", ".$row['last_name']."\n\n";
	    		} else {
	    			echo "Failed to insert membership account for ".$row['first_name'].", ".$row['last_name']."\n\n";
	    		}
    		}
    	} else {
    		echo "User: ".$row['first_name'].", ".$row['last_name']." does not exist\n\n";
    	}
	}


?>