<?php
/*
	SMStoXMPP :: Listener

	Runs inside an HTTP server and listens to incomming connections
	from the SMS gateway device. Once input is valided, the listener
	passes the details to the dispatcher which handles the actual
	process of opening an XMPP connection to the end user and delivery
	of the message.

	This one file supports multiple device gateway types and detects the
	type of request being made into it.


	(c) Copyright 2013 Jethro Carr <jethro.carr@jethrocarr.com>

	Unless otherwise stated, all parts of this program are licensed under
	the GNU AGPL software license version 3 only as detailed in docs/COPYING
*/

// internal libraries
include 'include/defines.php';
include 'include/logger.php';



/*
	Load the configuration and establish the connection
	to the message queue. This is vital for listener-dispatcher
	communications to succeed.
*/

// load config file
$config = parse_ini_file("config/config.ini", true);

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



/*
	Create a new logger object, we need to log via the IPC
	message queue so that any general warnings/debug/errors
	get included in the general daemon log.
*/

$log = new logger();
$log->set_debug(); // send all debug through to the daemon, it can then decided whether to retain or toss
$log->set_queue_sender(&$msg_queue, MESSAGE_LOG, MESSAGE_MAX_SIZE);

$log->debug("[listener] Listener request recieved.");
$log->debug("[listener] Debug URL: {$_SERVER['REQUEST_URI']}");


/*
	Determine Device

	SMStoXMPP supports a number of different devices and types, we need to discover
	which one is providing information to this page and then fetch and process the
	information accordingly.
*/

$device = null;

if (isset($_GET["device"]))
{
	$device = $_GET["device"];
}

// other ID fields for other gateways?

if (!$device)
{
	header('HTTP/1.1 500 Internal Server Error');
	$log->error_fatal("[listener] No destination device was specified - make sure you're setting the ?device=myphone option.");
}


/*
	Launch gateway/device logic

	All the gateway/device logic is broken into include files that are only loaded into
	the particular worker fork which is using that gateway. If we can't load it for some
	reason we should fail with an error to all the apps.
*/

if (!$config[$device])
{
	header('HTTP/1.1 500 Internal Server Error');
	$log->error_fatal("[listener] An invalid destination device was requested - make sure you are using the correct ?device=example tag");
}


if (!$config[$device]["gw_type"])
{
	header('HTTP/1.1 500 Internal Server Error');
	$log->error_fatal("[listener] There is no gateway set! Unable to proceed!");
}

$gatewayfile = "include/gateways/". $config[$device]["gw_type"] .".php";

if (!file_exists($gatewayfile))
{
	header('HTTP/1.1 500 Internal Server Error');
	$log->error("[listener] The gateway include $gatewayfile could not be located - is this the correct gateway name?");
	$log->error_fatal("[listener] Sorry, there is no such destination device - please check your input.");
}

include($gatewayfile);

$gateway = New device_gateway;



/*
	Fetch the message details & input validate. Whilst there's no SQL DB to worry about, we do need
	to filter the phone number and body to ensure they're valid and that there's no sneaky XML
	injection taking place that could mess with the generated XMPP message.

	Format:
	$message_raw["phone"]	Source phone number
	$message_raw["body"]	Message string
*/

$message_raw =  $gateway->message_listen();

if (!$message_raw)
{
	header('HTTP/1.1 500 Internal Server Error');
	$log->error_fatal("[listener] An invalid message request was recieved, unable to process.");
}

$message = array();

if (preg_match("/^[+]*[0-9]{1}[0-9]*$/", $message_raw["phone"]))
{
	$message["phone"] = $message_raw["phone"];
}
else
{
	header('HTTP/1.1 500 Internal Server Error');
	$log->error_fatal("[listener] Invalid phone number recieved!");
}

if (preg_match("/^[\S\s]*$/", $message_raw["body"]))
{
	// quote out special XML characters to prevent
	// messing with the XML body of the XMPP message
	// that gets generated.

	$message["body"] = htmlspecialchars($message_raw["body"]);
}
else
{
	header('HTTP/1.1 500 Internal Server Error');
	$log->error_fatal("[listener] Invalid message content recieved!");
}


$log->info("[listener] Recieved message from phone \"{$message["phone"]}\" with string \"{$message["body"]}\"");


/*
	Add other useful information for the dispatcher

	We hand some other information such as the device's IP address
	to the backend, which may use it for logic such as setting the dynamic
	address.
*/

$message["device_ip"] = $_SERVER["REMOTE_ADDR"];	// device remove IP (may be behind NAT)


/*
	We have a valid message, process and add it to the message queue
	so that it can be processed by the dispatcher daemon.

	(We use the hash to generate unique(ish) ID for this device name
	so that the dispatcher can listen to the messages on the IPC queue.
*/

$msg_mylistener = intval(base_convert(hash("crc32", $device, false), 16, 10));

if (!msg_send($msg_queue, $msg_mylistener, $message))
{
	header('HTTP/1.1 500 Internal Server Error');
	$log->error_fatal("Unexpected failure attempting to deliver message to queue.");
}
else
{
	// work here is done
	print "200/SUCCESS<br>\n";
	print "Message successfully handled to the backend dispatcher\n";
}

?>
