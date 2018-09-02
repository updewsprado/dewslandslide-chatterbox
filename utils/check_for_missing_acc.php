<?php

	$servername = "localhost";
	$username = "root";
	$password = "senslope";
	$dbname = "old_senslopedb";

	$rack_servername = "192.168.150.72";
	$rack_username = "root";
	$rack_password = "senslope";
	$rack_db = "comms_db";

    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }  else {
        echo "Connection to localhost established.\n\n";
    }

    $rack = new mysqli($rack_servername, $rack_username, $rack_password, $rack_db);
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
	$import_database_outbox = exec('mysql -uroot -psenslope old_senslopedb < membership.sql');


	echo "Reading membership from old db... \n\n";
	$read = "SELECT * FROM membership";
	$members_container = $conn->query($read);

	    $incomplete_id = ["2","5","7","10","11","12","13","14","15","16","17","18","19","20","21","22","28","32","33","35","36","41","42","45","46","47","49","52","61","71","73","74","75","78","79","80","81","82"];

	while ($row = $members_container->fetch_assoc()) {
		if (in_array($row['id'], $incomplete_id) == true) {
			$insert_user_query = "INSERT INTO comms_db.users VALUES (".$row['id'].", 'NA', '".$row['first_name']."', 'NA', '".$row['last_name']."', '".$row['first_name']."','1994-08-16', 'M' ,'1')";
			$result = $rack->query($insert_user_query);
			echo $result;
			echo "\n\n";
		}
	}


?>