<?php
$host = 'www.codesword.com';  //where is the websocket server
$port = 5051;
$local = "http://localhost";  //url where this script run
$data = '{"id": 2,"command": "server_info"}';  //data to be send

$head = "GET / HTTP/1.1"."\r\n".
        "Upgrade: WebSocket"."\r\n".
        "Connection: Upgrade"."\r\n".
        "Origin: $local"."\r\n".
        "Host: $host"."\r\n".
        "Sec-WebSocket-Version: 13"."\r\n".
        "Sec-WebSocket-Key: asdasdaas76da7sd6asd6as7d"."\r\n".
        "Content-Length: ".strlen($data)."\r\n"."\r\n";
//WebSocket handshake
$sock = fsockopen($host, $port, $errno, $errstr, 2);
fwrite($sock, $head ) or die('error:'.$errno.':'.$errstr);
$headers = fread($sock, 2000);
fwrite($sock, 'test hello') or die('error:'.$errno.':'.$errstr);
$wsdata = fread($sock, 2000);
fclose($sock);
