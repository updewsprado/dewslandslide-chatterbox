<?php

    $servername = "localhost";
    $username = "root";
    $password = "senslope";
    $dbname = "senslopedb";

    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }  else {
        echo "Connection established.\n\n";
    }

    $alter_password_column = "ALTER TABLE `senslopedb`.`membership` CHANGE COLUMN `password` `password` VARCHAR(128) NULL DEFAULT NULL ;";
    $execute_alteration = $conn->query($alter_password_column);

    if ($execute_alteration == true) {
    	echo "Altered success!\n";
    } else {
    	echo "No changes applied!\n";
    }

    $account_security_query = "SELECT * FROM membership;";
    $result = $conn->query($account_security_query);

    if ($result->num_rows > 0) {
    	while($row = $result->fetch_assoc()) {
    		$update_password = "UPDATE membership SET password = '".hash("sha512",$row['password'])."' WHERE id = '".$row['id']."'";
    		$update_result = $conn->query($update_password);
    		if ($update_result == true) {
    			echo "Password updated!\n";
    		} else {
    			echo "Password didn't get hashed!.\n";
    		}
    	}
    } else {
    	echo "No accounts needs to be hashed!.\nExiting..";
    }
?>