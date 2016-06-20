<?php

//$addr = gethostbyname();
$addr = "127.0.0.1";

$client = stream_socket_client("ws://$addr:5051", $errno, $errorMesage);

if ($client === false) {
    throw new UnexpectedValueException("Failed to connect: $errorMessage");
}

fwrite($client, "GET / HTTP/1.0\r\nHost: www.example.com\r\nAccept: */*\r\n\r\n");
echo stream_get_contents($client);
fclose($client);
