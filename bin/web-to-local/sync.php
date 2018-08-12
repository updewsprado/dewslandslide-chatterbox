<?php

    $servername = "localhost";
    $username = "root";
    $password = "senslope";
    $dbname = "senslopedb";

    $rack_servername = "192.168.150.75";
    $rack_username = "pysys_local";
    $rack_password = "NaCAhztBgYZ3HwTkvHwwGVtJn5sVMFgg";
    $rack_dbname = "comms_db";

    // Clean existing files
    $clean = exec('sudo rm community.sql');
    $clean = exec('sudo rm dewsl.sql');

    // Dump sql files
	$get_web_db_community = exec('sudo ssh -i /var/www/keys/senslopeReservedInstanceT2Medium.pem ubuntu@www.dewslandslide.com mysqldump -uroot -psenslope senslopedb communitycontacts > community.sql');
	$get_out = exec('exit');
	$get_web_db_dewsl = exec('sudo ssh -i /var/www/keys/senslopeReservedInstanceT2Medium.pem ubuntu@www.dewslandslide.com mysqldump -uroot -psenslope senslopedb dewslcontacts > dewsl.sql');

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }  else {
        echo "Connection established: localhost.\n\n";
    }

    // Drop existing tables
    $sql = "DROP TABLE communitycontacts";
    $result = $conn->query($sql);


    $sql = "DROP TABLE dewslcontacts";
	$result = $conn->query($sql);

	echo "Importing database dewsl...\n";
	$import_database_outbox = exec('mysql -uroot -psenslope senslopedb < dewsl.sql');
	echo "Importing database community...\n";
	$import_database_outbox = exec('mysql -uroot -psenslope senslopedb < community.sql');

	// Create connection
    $rack_conn = new mysqli($rack_servername, $rack_username, $rack_password, $rack_dbname);
    // Check connection
    if ($conn->connect_error) {
        die("\nConnection failed: " . $conn->connect_error);
    }  else {
        echo "\nConnection established: rack server.\n\n";
    }

    $set_key_checks = "SET FOREIGN_KEY_CHECKS = 0;";
    $status = $rack_conn->query($set_key_checks);


    echo "Fetching Old database ..\n\n";

    $dewsl_contacts_query = "SELECT * FROM dewslcontacts;";
    $result = $conn->query($dewsl_contacts_query);

    $networkSmart = ["00","07","08","09","10","11","12","14","18","19","20","21","22","23","24","25","28","29","30","31","32","33","34","38","39","40","42","43","44","46"];
    $networkGlobe = ["05","06","15","16","17","25","26","27","35","36","37","45","55","56","65","75","77","78","79","94","95","96","97"];

    echo "Importing to rack server: dewslcontacts\n";
    echo "Rows fetched: ".$result->num_rows."\n";
    while($row = $result->fetch_assoc()){
   		$if_exists_query = "SELECT * FROM comms_db.users WHERE firstname = '".$row['firstname']."' AND lastname = '".$row['lastname']."';";
   		$rack_result = $rack_conn->query($if_exists_query);
   		if ($rack_result->num_rows == 0) {
   			$insert_dewsl_contact_to_rack_query = "INSERT INTO comms_db.users VALUES (".$row['eid'].", 'NA', '".$row['firstname']."', 'NA', '".$row['lastname']."', '".$row['nickname']."','".$row['birthday']."', 'NA' ,'1')";
	    	$user_result = $rack_conn->query($insert_dewsl_contact_to_rack_query);


            $get_last_id = "SELECT user_id FROM comms_db.users WHERE firstname = '".$row['firstname']."' AND lastname = '".$row['lastname']."';";
            $result_rack_last_id = $rack_conn->query($get_last_id);
            $user_id = $result_rack_last_id->fetch_assoc()['user_id'];

	    	if ($user_result == true) {
	    		$split_number = explode(',',$row['numbers']);
	    		$counter = 1;
	    		foreach ($split_number as $number) {
	    			$stripped_number = substr($number, -10);
	    			if (in_array($stripped_number[0].$stripped_number[1], $networkGlobe)) {
	    				if ($counter > 1) {
	    					$network_carrier = "6"; // SECONDARY GLOBE
	    				} else {
	    					$network_carrier = "4"; // GLOBE
	    				}
	    				
	    			} else {
	    				if ($counter > 1) {
	    					$network_carrier = "7"; // SECONDARY SMART
	    				} else {
	    					$network_carrier = "5"; // SMART
	    				}	
	    			}
	    			$insert_dewsl_number_query = "INSERT INTO comms_db.user_mobile VALUES (0, '".$user_id."', '"."63".$stripped_number."', '".$counter."','1','".$network_carrier."')";
	    			$number_result = $rack_conn->query($insert_dewsl_number_query);
	    			echo "Added mobile number for ".$row['firstname']." ".$row['lastname'].", mobile #: ".$number." Status: ".$number_result."\n";
	    			$counter++;
	    		}
	    	} else {
    			echo "Error";
    		}
   		} else {
   			echo "Contact: ".$row['firstname']." ".$row['lastname']." exists! Skipping..\n";
   		}
    }


    $communitycontacts_query = "SELECT * FROM communitycontacts;";
    $result = $conn->query($communitycontacts_query);
    echo "\nImporting to rack server: communitycontacts\n";
    echo "Rows fetched: ".$result->num_rows."\n";
    
    while($row = $result->fetch_assoc()){
   		$if_exists_query = "SELECT * FROM comms_db.users WHERE firstname = '".$row['firstname']."' AND lastname = '".$row['lastname']."';";
   		$rack_result = $rack_conn->query($if_exists_query);
   		if ($rack_result->num_rows == 0) {
   			$insert_community_contact_to_rack_query = "INSERT INTO comms_db.users VALUES (".$row['c_id'].", '".$row['prefix']."', '".$row['firstname']."', 'NA', '".$row['lastname']."', 'NA','1994-08-16', 'NA' ,'1')";
	    	$user_result = $rack_conn->query($insert_community_contact_to_rack_query);

            $get_last_id = "SELECT user_id FROM comms_db.users WHERE firstname = '".$row['firstname']."' AND lastname = '".$row['lastname']."';";
            $result_rack_last_id = $rack_conn->query($get_last_id);
            $user_id = $result_rack_last_id->fetch_assoc()['user_id'];

            if ($user_result == true) {
	    		$split_number = explode(',',$row['number']);
	    		$counter = 1;
	    		foreach ($split_number as $number) {
	    			$stripped_number = substr($number, -10);
	    			if (in_array($stripped_number[0].$stripped_number[1], $networkGlobe)) {
	    				if ($counter > 1) {
	    					$network_carrier = "6"; // SECONDARY GLOBE
	    				} else {
	    					$network_carrier = "4"; // GLOBE
	    				}
	    				
	    			} else {
	    				if ($counter > 1) {
	    					$network_carrier = "7"; // SECONDARY SMART
	    				} else {
	    					$network_carrier = "5"; // SMART
	    				}	
	    			}
	    			$insert_dewsl_number_query = "INSERT INTO comms_db.user_mobile VALUES (0, '".$user_id."', '"."63".$stripped_number."', '".$counter."','1','".$network_carrier."')";
	    			$number_result = $rack_conn->query($insert_dewsl_number_query);
	    			echo "Added mobile number for ".$row['firstname']." ".$row['lastname'].", mobile #: ".$number." Status: ".$number_result."\n";
	    			$counter++;
	    		}
	    	} else {
    			echo "Error";
    		}

        } else {
        	echo "Contact: ".$row['firstname']." ".$row['lastname']." exists! Skipping..\n";
        }
    }


?>