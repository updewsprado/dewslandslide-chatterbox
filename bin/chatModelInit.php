<?php
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use MyApp\ChatterBox;
use MyApp\ChatMessageModel;

require dirname(__DIR__) . '/vendor/autoload.php';

$chatModel = new ChatMessageModel;
//$chatModel->getCachedQuickInboxMessages(true);