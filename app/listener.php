<?php
/*
	SMStoXMPP :: Listener

	Runs inside an HTTP server and listens to incomming connections
	from the SMS gateway device. Once input is valided, the listener
	passes the details to the dispatcher which handles the actual
	process of opening an XMPP connection to the end user and delivery
	of the message.

	Copyright 2013 Jethro Carr
	Licensed under the GNU AGPL license.
*/

// internal libraries
include 'include/defines.php';

// load config file
$config = parse_ini_file("config/sample_config.ini", true);

// verify message queue is up and running
if (!file_exists($config["SMStoXMPP"]["app_lock"]))
{
	header('HTTP/1.1 500 Internal Server Error');
	die("Fatal Error: Unable to connect to active message queue for dispatcher\n");
}
if (!function_exists("msg_get_queue"))
{
	header('HTTP/1.1 500 Internal Server Error');
	die("Fatal Error: PHP PCNTL/Process module not installed. This is required for IPC communication. If you have just installed it, make sure you re-start your webserver so it detects the new PHP modules");
}
if (!$msg_queue = msg_get_queue(ftok($config["SMStoXMPP"]["app_lock"], 'R'),0666 | IPC_CREAT))
{
	header('HTTP/1.1 500 Internal Server Error');
	die("Unable to attach to queue ". $config["SMStoXMPP"]["app_lock"] ."\n");
}

// validate input
// TODO: security/validation here
$message = array();
$message["dest"]	= $_GET["device"];
$message["phone"]	= $_GET["phone"];
$message["body"]	= $_GET["text"];

// verify the dest
if (!$config[ $message["dest"] ])
{
	header('HTTP/1.1 500 Internal Server Error');
	die("Sorry, there is no such destination device.\n");
}

// append to the message queue
$msg_mylistener = intval(base_convert(hash("crc32", $message["dest"], false), 16, 10));
if (!msg_send($msg_queue, $msg_mylistener, $message))
{
	header('HTTP/1.1 500 Internal Server Error');
	die("Unexpected failure attempting to deliver message to queue.\n");
}
else
{
	// work here is done
	print "200/SUCCESS";
}

?>
