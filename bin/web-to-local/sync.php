<?php

    $servername = "localhost";
    $username = "root";
    $password = "senslope";
    $dbname = "old_senslopedb";

    $rack_servername = "192.168.150.72";
    $rack_username = "root";
    $rack_password = "senslope";
    $rack_dbname = "comms_db";

    // $rack_servername = "localhost";
    // $rack_username = "root";
    // $rack_password = "senslope";
    // $rack_dbname = "comms_db";

    // Clean existing files
    $clean = exec('sudo rm community.sql');
    $clean = exec('sudo rm dewsl.sql');
    $clean = exec('sudo rm membership.sql');

 //    // Dump sql files
	$get_web_db_community = exec('sudo ssh -i /var/www/keys/senslopeReservedInstanceT2Medium.pem ubuntu@www.dewslandslide.com mysqldump -uroot -psenslope senslopedb communitycontacts > community.sql');
	$get_out = exec('exit');
	$get_web_db_membership = exec('sudo ssh -i /var/www/keys/senslopeReservedInstanceT2Medium.pem ubuntu@www.dewslandslide.com mysqldump -uroot -psenslope senslopedb membership > membership.sql');

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

    $sql = "DROP TABLE membership";
    $result = $conn->query($sql);

	echo "Importing database dewsl...\n";
	$import_database_outbox = exec('mysql -uroot -psenslope old_senslopedb < dewsl.sql');

    echo "Importing database membership...\n";
    $import_database_outbox = exec('mysql -uroot -psenslope old_senslopedb < membership.sql');
	echo "Importing database community...\n";
	$import_database_outbox = exec('mysql -uroot -psenslope old_senslopedb < community.sql');

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

    $dewsl_contacts_query = "SELECT * FROM dewslcontacts INNER JOIN membership ON dewslcontacts.firstname LIKE membership.first_name;";
    $result = $conn->query($dewsl_contacts_query);

    $networkSmart = ["00","07","08","09","10","11","12","14","18","19","20","21","22","23","24","25","28","29","30","31","32","33","34","38","39","40","42","43","44","46"];
    $networkGlobe = ["05","06","15","16","17","25","26","27","35","36","37","45","55","56","65","75","77","78","79","94","95","96","97"];

    echo "Importing to rack server: dewslcontacts\n";
    echo "Rows fetched: ".$result->num_rows."\n";
    $employee_new = 0;
    $employee_existing = 0;
    while($row = $result->fetch_assoc()){
   		$if_exists_query = "SELECT * FROM comms_db.users WHERE firstname = '".$row['firstname']."' AND lastname = '".$row['lastname']."';";
   		$rack_result = $rack_conn->query($if_exists_query);
   		if ($rack_result->num_rows == 0) {
   			$insert_dewsl_contact_to_rack_query = "INSERT INTO comms_db.users VALUES (".$row['id'].", 'NA', '".$row['firstname']."', 'NA', '".$row['lastname']."', '".$row['nickname']."','".$row['birthday']."', 'M' ,'1')";
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
	    					$network_carrier = "4"; // SECONDARY GLOBE
	    				} else {
	    					$network_carrier = "4"; // GLOBE
	    				}
	    				
	    			} else {
	    				if ($counter > 1) {
	    					$network_carrier = "5"; // SECONDARY SMART
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
                echo $insert_dewsl_contact_to_rack_query."\n";
    			echo "Error";
    		}

            echo "Adding teams...\n";
            $teams = explode(',',$row['grouptags']);
            foreach ($teams as $team) {
                $team_exists_query = "SELECT team_id FROM comms_db.dewsl_teams WHERE team_name = '".$team."'";
                $team_exists = $rack_conn->query($team_exists_query);

                if ($team_exists->num_rows == 0) {
                    $insert_team_query = "INSERT INTO comms_db.dewsl_teams VALUES (0,'".$team."','".$team."','dynaslope')";
                    $insert_team = $rack_conn->query($insert_team_query);
                    if ($insert_team == true) {
                        $team_id_query = "SELECT LAST_INSERT_ID();";
                        $team_id_raw = $rack_conn->query($team_id_query);
                        $team_id = $team_id_raw->fetch_assoc()["LAST_INSERT_ID()"];

                        $check_if_contact_is_existing_team_query = "SELECT * FROM comms_db.dewsl_team_members WHERE users_users_id = '".$user_id."' AND dewsl_teams_team_id = '".$team_id."'";
                        $contact_existing_team = $rack_conn->query($check_if_contact_is_existing_team_query);
                        if ($contact_existing_team->num_rows == 0) {
                            $insert_individual_to_team_query = "INSERT INTO comms_db.dewsl_team_members VALUES (0,'".$user_id."','".$team_id."')";
                            $individual_team = $rack_conn->query($insert_individual_to_team_query);
                            if ($individual_team == true) {
                                echo "Added ".$row['firstname']." ".$row['lastname']." to ".$team." team...\n";
                            } else {
                                echo "Failed to add ".$row['firstname']." ".$row['lastname']." to ".$team." team...\n";
                            }
                        } else {
                            echo "Contact already in the team...\n";
                        }
                    } else {
                        echo "Failed to add team...\n";
                    }

                } else {
                    $check_if_contact_is_existing_team_query = "SELECT * FROM comms_db.dewsl_team_members WHERE users_users_id = '".$user_id."' AND dewsl_teams_team_id = '".$team_id."'";
                    $contact_existing_team = $rack_conn->query($check_if_contact_is_existing_team_query);
                    if ($contact_existing_team->num_rows == 0) {
                        $insert_individual_to_team_query = "INSERT INTO comms_db.dewsl_team_members VALUES (0,'".$user_id."','".$team_id."')";
                        $individual_team = $rack_conn->query($insert_individual_to_team_query);
                        if ($individual_team == true) {
                            echo "Added ".$row['firstname']." ".$row['lastname']." to ".$team." team...\n";
                        } else {
                            echo "Failed to add ".$row['firstname']." ".$row['lastname']." to ".$team." team...\n";
                        }
                    } else {
                        echo "Contact already in the team...\n";
                    }
                }
            }
            $employee_new++;
   		} else {

            echo "Contact: ".$row['firstname']." ".$row['lastname']." exists!\n";
            echo "Check mobile # if existing...: ".$row['numbers']."\n";
            $split_number = explode(',',$row['numbers']);
            $counter = 1;
            foreach ($split_number as $number) {
                $stripped_number = substr($number, -10);
                $check_if_number_existing = "SELECT * FROM user_mobile WHERE sim_num like '%".$stripped_number."%'";
                $number_exist = $rack_conn->query($check_if_number_existing);
                if ($number_exist->num_rows == 0) {
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
                    $insert_dewsl_number_query = "INSERT INTO comms_db.user_mobile VALUES (0, '".$rack_result->fetch_assoc()[0]['user_id']."', '"."63".$stripped_number."', '".$counter."','1','".$network_carrier."')";
                    $number_result = $rack_conn->query($insert_dewsl_number_query);
                    echo "Added mobile number for ".$row['firstname']." ".$row['lastname'].", mobile #: ".$number." Status: ".$number_result."\n";
                    $counter++;
                } else {
                    echo "Phone number already existing..\n";
                }
            }


            echo "Adding teams...\n";
            $teams = explode(',',$row['grouptags']);
            foreach ($teams as $team) {
                $team_exists_query = "SELECT team_id FROM comms_db.dewsl_teams WHERE team_name = '".$team."'";
                $team_exists = $rack_conn->query($team_exists_query);

                if ($team_exists->num_rows == 0) {
                    $insert_team_query = "INSERT INTO comms_db.dewsl_teams VALUES (0,'".$team."','".$team."','dynaslope')";
                    $insert_team = $rack_conn->query($insert_team_query);
                    if ($insert_team == true) {
                        $team_id_query = "SELECT LAST_INSERT_ID();";
                        $team_id_raw = $rack_conn->query($team_id_query);
                        $team_id = $team_id_raw->fetch_assoc()["LAST_INSERT_ID()"];

                        $check_if_contact_is_existing_team_query = "SELECT * FROM comms_db.dewsl_team_members WHERE users_users_id = '".$rack_result->fetch_assoc()[0]['user_id']."' AND dewsl_teams_team_id = '".$team_id."'";
                        $contact_existing_team = $rack_conn->query($check_if_contact_is_existing_team_query);
                        if ($contact_existing_team->num_rows == 0) {
                            $insert_individual_to_team_query = "INSERT INTO comms_db.dewsl_team_members VALUES (0,'".$rack_result->fetch_assoc()[0]['user_id']."','".$team_id."')";
                            $individual_team = $rack_conn->query($insert_individual_to_team_query);
                            if ($individual_team == true) {
                                echo "Added ".$row['firstname']." ".$row['lastname']." to ".$team." team...\n";
                            } else {
                                echo "Failed to add ".$row['firstname']." ".$row['lastname']." to ".$team." team...\n";
                            }
                        } else {
                            echo "Contact already in the team...\n";
                        }
                    } else {
                        echo "Failed to add team...\n";
                    }

                } else {
                    $check_if_contact_is_existing_team_query = "SELECT * FROM comms_db.dewsl_team_members WHERE users_users_id = '".$rack_result->fetch_assoc()['user_id']."' AND dewsl_teams_team_id = '".$team_id."'";
                    $contact_existing_team = $rack_conn->query($check_if_contact_is_existing_team_query);
                    if ($contact_existing_team->num_rows == 0) {
                        $insert_individual_to_team_query = "INSERT INTO comms_db.dewsl_team_members VALUES (0,'".$rack_result->fetch_assoc()['user_id']."','".$team_id."')";
                        $individual_team = $rack_conn->query($insert_individual_to_team_query);
                        if ($individual_team == true) {
                            echo "Added ".$row['firstname']." ".$row['lastname']." to ".$team." team...\n";
                        } else {
                            echo "Failed to add ".$row['firstname']." ".$row['lastname']." to ".$team." team...\n";
                        }
                    } else {
                        echo "Contact already in the team...\n";
                    }
                }
            }
            $employee_existing++;
   		}
    }


    $communitycontacts_query = "SELECT * FROM communitycontacts;";
    $result = $conn->query($communitycontacts_query);
    echo "\nImporting to rack server: communitycontacts\n";
    echo "Rows fetched: ".$result->num_rows."\n";
    echo "Start index at 100..\n\n";
    $community_index = 100;
    $new_contacts = 0;
    $existing_contacts = 0;
    while($row = $result->fetch_assoc()){
   		$if_exists_query = "SELECT * FROM comms_db.users WHERE firstname = '".$row['firstname']."' AND lastname = '".$row['lastname']."';";
   		$rack_result = $rack_conn->query($if_exists_query);
   		if ($rack_result->num_rows == 0) {
   			$insert_community_contact_to_rack_query = "INSERT INTO comms_db.users VALUES (".$community_index.", '".$row['prefix']."', '".$row['firstname']."', 'NA', '".$row['lastname']."', 'NA','1994-08-16', 'M' ,'1')";
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
	    					$network_carrier = "4"; // SECONDARY GLOBE
	    				} else {
	    					$network_carrier = "4"; // GLOBE
	    				}
	    			} else {
	    				if ($counter > 1) {
	    					$network_carrier = "7"; // SECONDARY SMART
	    				} else {
	    					$network_carrier = "7"; // SMART
	    				}	
	    			}
	    			$insert_dewsl_number_query = "INSERT INTO comms_db.user_mobile VALUES (0, '".$user_id."', '"."63".$stripped_number."', '".$counter."','1','".$network_carrier."')";
	    			$number_result = $rack_conn->query($insert_dewsl_number_query);
	    			echo "Added mobile number for ".$row['firstname']." ".$row['lastname'].", mobile #: ".$number." Status: ".$number_result."\n";
	    			$counter++;

                    $mobile_id_query = "SELECT LAST_INSERT_ID();";
                    $mobile_result = $rack_conn->query($mobile_id_query);
                    $mobile_id = $mobile_result->fetch_assoc()["LAST_INSERT_ID()"];

                    $ewi_query = "INSERT INTO comms_db.user_ewi_status VALUES ('".$mobile_id."', '1', 'Active', '".$user_id."')";
                    $ewi_result = $rack_conn->query($ewi_query);
                    if ($ewi_result == true) {
                        echo "Added as EWI Recipient. Status: ".$ewi_result."\n";
                    } else {
                        echo "Failed to add EWI Recipient. Status: ".$ewi_result."\n";
                    }
	    		}

                $get_scope_query = "SELECT org_scope FROM comms_db.organization WHERE org_name LIKE '%".$row['office']."%'";
                $scope_result = $rack_conn->query($get_scope_query);
                while ($row_scope = $scope_result->fetch_assoc()) {
                    $insert_org_query = "INSERT INTO user_organization VALUES (0,'".$user_id."',(SELECT site_id FROM comms_db.sites WHERE site_code = '".$row['sitename']."'),'".strtoupper($row['office'])."','".$row_scope['org_scope']."')";
                    $result_org  = $rack_conn->query($insert_org_query);
                    if ($result_org == true) {
                        echo "Added reference site for ".$row['firstname']." ".$row['lastname']."\n";
                    } else {
                        echo "Failed to add reference site for ".$row['firstname']." ".$row['lastname']."\n";
                    }                
                }
	    	} else {
                echo $insert_community_contact_to_rack_query."\n";
    			echo "Error";
    		}
            $new_contacts++;
            $community_index++;
        } else {

            //Checking if mobile number is existing...
            echo "Contact: ".$row['firstname']." ".$row['lastname']." exists!\n";
            echo "Check mobile # if existing...: ".$row['number']."\n";

            $split_number = explode(',',$row['number']);
            $counter = 1;
            foreach ($split_number as $number) {
                $stripped_number = substr($number, -10);
                $check_if_number_existing = "SELECT * FROM user_mobile WHERE sim_num like '%".$stripped_number."%'";
                $number_exist = $rack_conn->query($check_if_number_existing);
                if ($number_exist->num_rows == 0) {
                    if (in_array($stripped_number[0].$stripped_number[1], $networkGlobe)) {
                        if ($counter > 1) {
                            $network_carrier = "4"; // SECONDARY GLOBE
                        } else {
                            $network_carrier = "4"; // GLOBE
                        }
                        
                    } else {
                        if ($counter > 1) {
                            $network_carrier = "5"; // SECONDARY SMART
                        } else {
                            $network_carrier = "5"; // SMART
                        }   
                    }
                    $insert_dewsl_number_query = "INSERT INTO comms_db.user_mobile VALUES (0, '".$rack_result->fetch_assoc()['user_id']."', '"."63".$stripped_number."', '".$counter."','1','".$network_carrier."')";
                    $number_result = $rack_conn->query($insert_dewsl_number_query);
                    echo "Added mobile number for ".$row['firstname']." ".$row['lastname'].", mobile #: ".$number." Status: ".$number_result."\n";
                    $counter++;

                    $mobile_id_query = "SELECT LAST_INSERT_ID();";
                    $mobile_result = $rack_conn->query($mobile_id_query);
                    $mobile_id = $mobile_result->fetch_assoc()["LAST_INSERT_ID()"];

                    $ewi_query = "INSERT INTO comms_db.user_ewi_status VALUES ('".$mobile_id."', '1', 'Active', '".$rack_result->fetch_assoc()['user_id']."')";
                    $ewi_result = $rack_conn->query($ewi_query);
                    if ($ewi_result == true) {
                        echo "Added as EWI Recipient. Status: ".$ewi_result."\n";
                    } else {
                        echo "Failed to add EWI Recipient. Status: ".$ewi_result."\n";
                    }

                } else {
                    echo "Phone number already existing..\n";
                }
            }

            $check_if_site_org_exist_query = "SELECT * FROM comms_db.user_organization WHERE user_id = '".$rack_result->fetch_assoc()['user_id']."' AND fk_site_id = (SELECT site_id FROM comms_db.sites WHERE site_code = '".$row['sitename']."') AND org_name = '".$row['office']."'";
            $site_org_exist = $rack_conn->query($check_if_site_org_exist_query);

            if ($site_org_exist->num_rows == 0) {
                $get_scope_query = "SELECT org_scope FROM comms_db.organization WHERE org_name LIKE '%".$row['office']."%'";
                $scope_result = $rack_conn->query($get_scope_query);
                while ($row_scope = $scope_result->fetch_assoc()) {
                    $insert_org_query = "INSERT INTO user_organization VALUES (0,'".$rack_result->fetch_assoc()['user_id']."',(SELECT site_id FROM comms_db.sites WHERE site_code = '".$row['sitename']."'),'".strtoupper($row['office'])."','".$row_scope['org_scope']."')";
                    $result_org  = $rack_conn->query($insert_org_query);
                    if ($result_org == true) {
                        echo "Added reference site for ".$row['firstname']." ".$row['lastname']."\n";
                    } else {
                        echo "Failed to add reference site for ".$row['firstname']." ".$row['lastname']."\n";
                    }                
                }
            }
            $existing_contacts++;
        	echo "Contact: ".$row['firstname']." ".$row['lastname']." exists! Skipping..\n";
        }
    }


    echo "Number of new Employee contacts: ".$employee_new;
    echo "\n";
    echo "Number of existing Employee contacts: ".$employee_existing;
    echo "\n";
    echo "\n";
    echo "Number of new community contacts: ".$new_contacts;
    echo "\n";
    echo "Number of existing community contacts: ".$existing_contacts;



    echo "\n\n";
    echo "------------------------------------------------------------------------------------\n";
    echo "------------------------------------------------------------------------------------\n";
    echo "------------------------------------------------------------------------------------\n";
    echo "--------------------------SYNCING SMS FROM WEB TO RACK------------------------------\n";
    echo "------------------------------------------------------------------------------------\n";
    echo "------------------------------------------------------------------------------------\n";
    echo "------------------------------------------------------------------------------------\n";

    sleep(5);

    // Clean existing files
    $clean = exec('sudo rm smsinbox.sql');
    $clean = exec('sudo rm smsoutbox.sql');

    // Dump sql files
    $get_web_db_community = exec('sudo ssh -i /var/www/keys/senslopeReservedInstanceT2Medium.pem ubuntu@www.dewslandslide.com mysqldump -uroot -psenslope senslopedb smsinbox > smsinbox.sql');
    $get_out = exec('exit');
    $get_web_db_dewsl = exec('sudo ssh -i /var/www/keys/senslopeReservedInstanceT2Medium.pem ubuntu@www.dewslandslide.com mysqldump -uroot -psenslope senslopedb smsoutbox > smsoutbox.sql');


    // Drop existing tables
    $sql = "DROP TABLE smsinbox";
    $result = $conn->query($sql);


    $sql = "DROP TABLE smsoutbox";
    $result = $conn->query($sql);

    echo "Importing database smsinbox...\n";
    $import_database_outbox = exec('mysql -uroot -psenslope old_senslopedb < smsinbox.sql');
    echo "Importing database smsoutbox...\n";
    $import_database_outbox = exec('mysql -uroot -psenslope old_senslopedb < smsoutbox.sql');

    $get_old_inbox_query = "SELECT * FROM smsinbox";
    $old_inbox = $conn->query($get_old_inbox_query);

    while ($row = $old_inbox->fetch_assoc()) {
        echo "Inserting sms to inbox...\n";
        $get_mobile_id_gsm_id = "SELECT mobile_id FROM comms_db.user_mobile WHERE sim_num LIKE '%".substr($row['sim_num'], -10)."%'";
        $mobile_gsm_id = $rack_conn->query($get_mobile_id_gsm_id);
        if ($mobile_gsm_id->num_rows != 0) {
            $stripped_number = substr($row['sim_num'], -10);
            if (in_array($stripped_number[0].$stripped_number[1], $networkGlobe)) {
                if ($counter > 1) {
                    $network_carrier = "4"; // SECONDARY GLOBE
                } else {
                    $network_carrier = "4"; // GLOBE
                }
            } else {
                if ($counter > 1) {
                    $network_carrier = "5"; // SECONDARY SMART
                } else {
                    $network_carrier = "5"; // SMART
                }   
            }
            $row['sms_msg'] = str_replace("'","",$row['sms_msg']);
            $rack_inbox_query = "INSERT INTO comms_db.smsinbox_users VALUES (0,'".$row['timestamp']."','".$row['timestamp']."','".$mobile_gsm_id->fetch_assoc()['mobile_id']."', '".$row['sms_msg']."','0','0','".$network_carrier."')";
            $rack_inbox = $rack_conn->query($rack_inbox_query);
            echo $rack_inbox_query;
            // exit;
            if ($rack_inbox == true) {
                echo "Successfully synced SMS to RackDB Inbox for : ".$mobile_gsm_id->fetch_assoc()['mobile_id']."...\n";
            } else {
                echo "Failed to sync SMS for Inbox..\n";
            }
        } else {
            echo "Contact number not in the database..\n\n";
        }
    }


    $get_old_outbox_query = "SELECT * FROM smsoutbox";
    $old_outbox = $conn->query($get_old_outbox_query);

    while ($row = $old_outbox->fetch_assoc()) {
        $get_mobile_id_gsm_id = "SELECT mobile_id FROM comms_db.user_mobile WHERE sim_num LIKE '%".substr($row['recepients'], -10)."%'";
        $mobile_gsm_id = $rack_conn->query($get_mobile_id_gsm_id);
        $mobile_id = $mobile_gsm_id->fetch_assoc()['mobile_id'];
        if ($mobile_gsm_id->num_rows != 0) {
            $row['sms_msg'] = str_replace("'","",$row['sms_msg']);
            $insert_outbox_query = "INSERT INTO comms_db.smsoutbox_users VALUES (0,'".$row['timestamp_written']."','central','".$row['sms_msg']."')";
            $insert_outbox = $rack_conn->query($insert_outbox_query);
            if ($insert_outbox == true) {

                $outbox_id_query = "SELECT LAST_INSERT_ID();";
                $outbox_id_raw = $rack_conn->query($outbox_id_query);
                $outbox_id = $outbox_id_raw->fetch_assoc()["LAST_INSERT_ID()"];

                $stripped_number = substr($row['recepients'], -10);
                if (in_array($stripped_number[0].$stripped_number[1], $networkGlobe)) {
                    if ($counter > 1) {
                        $network_carrier = "4"; // SECONDARY GLOBE
                    } else {
                        $network_carrier = "4"; // GLOBE
                    }
                } else {
                    if ($counter > 1) {
                        $network_carrier = "5"; // SECONDARY SMART
                    } else {
                        $network_carrier = "5"; // SMART
                    }   
                }

                $insert_outbox_status_query = "INSERT INTO comms_db.smsoutbox_user_status VALUES (0,'".$outbox_id."','".$mobile_id."','".$row['timestamp_sent']."','".$sent_status = ($row['timestamp_sent'] == "" OR $row['timestamp_sent'] == Null) ? '-1': '5'."','0','".$network_carrier."')";
                $insert_outbox_status = $rack_conn->query($insert_outbox_status_query);
                if ($insert_outbox_status == true) {
                    echo "Successfully synced SMS to RackDB Outbox for : ".$mobile_id."...\n";
                } else {
                    echo $insert_outbox_status_query."\n";
                    echo "Failed to sync SMS to Outbox 1/2..\n";
                }
            } else {
                echo $insert_outbox_query."\n";
                echo "Failed to sync SMS to Outbox 2/2..\n";
            }
        } else {
            echo "Contact number not in the database..\n\n";
        }
    }

?>