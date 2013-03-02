#!/usr/bin/php
<?php
/*
	SMStoXMPP :: Dispatcher

	Main application daemon, responsible for the following core functions:
	- Establishes and listens to a message queue
	- Forks per device and
		- Listens to the XMPP socket for replies from the user
		  and then processes them and calls the SMS gateway API
		- Listens to the message queue for incomming SMS messages
		  and then generates XMPP messages to the user.
	
	There are also some optional functions this application is responsible for:
	- Resolution of phone numbers against CardDAV directories

	Copyright 2013 Jethro Carr <jethro.carr@jethrocarr.com>
	Licensed under the GNU AGPL license.
*/

// external libraries
include 'XMPPHP/XMPP.php';

// internal libraries
include 'include/defines.php';
include 'include/logger.php';



/*
	Load options & configuration
*/

$options_all = array(
			"verbose" => array(
				"short" => "v",
				"long"  => "verbose",
				"about"	=> "Log application to screen"
				),
			"debug" => array(
				"short"	=> "d",
				"long"	=> "debug",
				"about" => "Include debugging messages in logs. Use this if experiencing issues."
				),
			"version" => array(
				"short" => null,
				"long"	=> "version",
				"about" => "Version & other application details"
				),
			"daemon" => array(
				"short"	=> "D",
				"long"	=> "daemon",
				"about" => "Run application as a backgrounded daemon"
				),
			"config" => array(
				"short"	=> "c",
				"long"	=> "config",
				"about" => "Specify path to an alternative configuration file",
				"getopt" => "c::"
				),
			"help" => array(
				"short" => "h",
				"long"	=> "help",
				"about"	=> "This message",
				)
			);

$options_long	= array();
$options_short	= "";

foreach (array_keys($options_all) as $key)
{
	$options_long[] = $key;

	if ($options_all[$key]["short"])
	{
		if ($options_all[$key]["getopt"])
		{
			$options_short .= $options_all[$key]["getopt"];
		}
		else
		{
			$options_short .= $options_all[$key]["short"];
		}
	}
	
}

$options_set = getopt($options_short, $options_long);

// set logging options
if (isset($options_set["v"]))
{
	$options_set["verbose"] = true;
}
if (isset($options_set["d"]))
{
	$options_set["debug"] = true;
}


// version display then quit
if (isset($options_set["version"]))
{
	print "\n";
	print "". APP_NAME ." (". APP_VERSION .")\n";
	print "\n";
	print "". APP_HOMEPAGE ."\n";
	print "Licensed under the ". APP_LICENSE ." license.\n";
	print "Running on PHP version ". phpversion() ."\n";
	print "\n";
	exit();
}

if (isset($options_set["help"]))
{
	print "Usage: ". basename( __FILE__ ) ." [OPTION]...\n";
	print "SMS to XMPP Gateway daemon\n";
	print "\n";

	foreach (array_keys($options_all) as $key)
	{
		if ($options_all[$key]["short"])
		{
			print "\t-{$options_all[$key]["short"]}, --{$options_all[$key]["long"]}\t\t{$options_all[$key]["about"]}\n";
		}
		else
		{
			print "\t    --{$options_all[$key]["long"]}\t\t{$options_all[$key]["about"]}\n";
		}
	}

	print "\n";
	print "". APP_NAME ." (". APP_VERSION .")\n";
	print "For more information, try the project homepage:\n";
	print "". APP_HOMEPAGE ."\n";
	exit();
}


// set default unless an override option has been provided.
if (!$options_set["config"])
{
	$options_set["config"] = "config/sample_config.ini";
}

if (!file_exists($options_set["config"]))
{
	die("Fatal Error: Unable to open configuration file ". $options_set["config"] ."\n");
}

// read configuration file
$config = parse_ini_file($options_set["config"], true);


/*
	Establish Logging
*/
$log = New logger();
$log->set_logfile($config["SMStoXMPP"]["app_log"]);

if (isset($options_set["verbose"]))
{
	$log->set_stdout();
}
if (isset($options_get["debug"]))
{
	$log->set_debug();
}

$log->info("Launched ". APP_NAME ." (". APP_VERSION .")");



/*
	The message queue is vital, we need to use it to recieve
	incomming messages from the web-based listeners, as well as
	communicating between the master process and the threads.
*/
if (file_exists($config["SMStoXMPP"]["app_lock"]))
{
	$log->error_fatal("Queue already exists, dispatcher already running?");

}

if (!touch($config["SMStoXMPP"]["app_lock"]))
{
	$log->error_fatal("Unable to create lock file");
}

if (!$msg_queue = msg_get_queue(ftok($config["SMStoXMPP"]["app_lock"], 'R'),0666 | IPC_CREAT))
{
	$log->error_fatal("Unable to attach to queue ". $config["SMStoXMPP"]["app_lock"] ."");
}

// we instruct the logger class,so we can use the IPC message system to communicate
// from the client threads
$log->set_queue_listener($msg_queue);


// spawn one fork per device
// these forks loop until application termination

$fork_pids = array();

$i = 0;
foreach (array_keys($config) as $section)
{
	if ($section == "SMStoXMPP")
	{
		continue;
	}

	// fork process
	$fork_pids[$i] = pcntl_fork();

	if (!$fork_pids[$i])
	{
		// we are the child
		$log->debug("Child process launched for device \"$section\"\n");

		/*
			Establish connection to the XMPP server
		*/

		if (!$config[$section]["xmpp_server"])
		{
			$log->error_fatal("An XMPP server must be configured");
		}
		if (!$config[$section]["xmpp_port"])
		{
			// default protocol port
			$config[$section]["xmpp_port"] = "5222";
		}
		if (!$config[$section]["xmpp_username"])
		{
			$log->error_fatal("An XMPP user must be configured");
		}
		if (!$config[$section]["xmpp_reciever"])
		{
			$log->error_fatal("An XMPP reciever must be configured!");
		}


		$conn = new XMPPHP_XMPP($config[$section]["xmpp_server"], $config[$section]["xmpp_port"], $config[$section]["xmpp_username"], $config[$section]["xmpp_password"], $section, $config[$section]["xmpp_domain"], $printlog=false, $loglevel=XMPPHP_Log::LEVEL_INFO);

		try
		{
			$conn->connect();
		}
		catch(XMPPHP_Exception $e)
		{
		    die($e->getMessage());
		}


		// track whom we are chatting with
		$current_chat = null;

		// generate a message type ID we can use to listen on
		$msg_mylistener = intval(base_convert(hash("crc32", $section, false), 16, 10));

		while (true)
		{
			/*
				Wait for a valid event occurs that we can process, OR
				up to the timeout limit, in which case we then go and
				check if there's anything in the message queue that
				needs processing
			*/

			$payloads = $conn->processUntil(array('message', 'presence', 'end_stream', 'session_start'), MESSAGE_TIMEOUT_SECONDS);

			foreach($payloads as $event)
			{
				$pl = $event[1];

				switch($event[0])
				{
					case 'message': 

						// check sender - we only allow messages from the configured recipient
						if (preg_match("/^{$config[$section]["xmpp_reciever"]}\/\w$/", $pl["from"]))
						{
							// denied
							$conn->message($pl["from"], $body="Sorry you are not a user whom is permitted to talk with me. :-(");
							print "[$section] Denied connection attempt from {$pl["from"]}, only connections from {$config[$section]["xmpp_reciever"]} are permitted\n";

							break;
						}

						// process message
						switch ($pl["body"])
						{
							case "about":
							case "license":
							case "help":
								$conn->message($pl["from"], $body="". APP_NAME ." (". APP_VERSION .")");
								$conn->message($pl["from"], $body="". APP_HOMEPAGE ."");
								$conn->message($pl["from"], $body="Licensed under the ". APP_LICENSE ." license.");
								$conn->message($pl["from"], $body="Running on PHP version ". phpversion() ."");
							break;

							default:
								print "[$section] XMPP message recieved! \"". $pl["body"] ."\"\n";
							break;
						}
					break;

					case 'presence':
						print "[$section] Presence notification from ". $pl["from"] ." with status of ". $pl["status"] ."\n";
					break;

					case 'session_start':
						print "[$section] Established XMPP connection\n";

						// Online and Ready
						$conn->getRoster();
						$conn->presence($status="". APP_NAME ." connected to $section");

						// allow any user to subscribe - but we validate that only certain users can message us
						$conn->autoSubscribe(true);

						// send user a welcome
						$conn->message($config[$section]["xmpp_reciever"], $body="". APP_NAME ." (". APP_VERSION .") started", $type="chat");
						$conn->message($config[$section]["xmpp_reciever"], $body="Type \"help\" for usage and option information", $type="chat");
					break;

					case 'end_stream':
						print "[$section] User closed our XMPP session\n";
					break;
				}
			}


			/*
				Check the message queues for actions for this fork
				to complete.
			*/
			$stats = msg_stat_queue($msg_queue);
			if ($stats["msg_qnum"] > 0)
			{
				/*
					Check for unprocessed SMS recieved messages
				*/
				$message = null;
				$message_type = null;
				if (msg_receive($msg_queue, $msg_mylistener, $message_type, MESSAGE_MAX_SIZE, $message, TRUE, MSG_IPC_NOWAIT))
				{
					/*
						Recieved SMS message
						array (
							phone => "+120123456..."
							body  => "just a string" 
							time  => reserved for future use
						)
					*/

					print "[$section] Recieved SMS message from {$message["phone"]} with content of {$message["body"]}\n";

					if (!$current_chat)
					{
						$current_chat = $message["phone"];
					}
					else
					{
						if ($current_chat != $message["phone"])
						{
							$conn->message($config[$section]["xmpp_reciever"], $body="Chat target has changed, you are now talking to {$message["phone"]}, all unaddressed replies will go to this recipient.", $subject=$message["phone"]);
							$current_chat = $message["phone"];
						}
					}

					$conn->message($config[$section]["xmpp_reciever"], $body="{$message["phone"]}: {$message["body"]}", $subject=$message["phone"]);
				}
				
				/*
					Check for control messages
					(ie shutdown)
				*/
				$message = null;
				$message_type = null;
				if (msg_receive($msg_queue, MESSAGE_CTRL, $message_type, MESSAGE_MAX_SIZE, $message, TRUE, MSG_IPC_NOWAIT))
				{
					switch ($message)
					{
						case "shutdown":
							print "[$section] Shutdown command recieved!\n";
							print "[$section] Peak memory usage of ". memory_get_peak_usage() ." bytes\n";
							$conn->message($config[$section]["xmpp_reciever"], $body="Gateway shutting down... goodbye!");

							// terminate fork cleanly
							$conn->disconnect();
							exit();
						break;

						default:
							print "[$section] Unknown message instruction recieved\n";
						break;
					}
				}

			} // end if available messages
		}

    		$conn->disconnect();

		// terminate
		exit();
	}

	// increase fork count
	$i++;
}



/*
	Forks are running now and will continue to handle requests. The master
	just needs to listen to log messages from the threads and handle cleanly
	shutting down the application in response to signals.
*/

while (true)
{
	$message = null;
	$message_type = null;

	if (msg_receive($msg_queue, MESSAGE_LOG, $message_type, MESSAGE_MAX_SIZE, $message, TRUE, MSG_IPC_NOWAIT))
	{
		$log->$message[$level]($message[$level]);
	}
}
				



/*
	Terminate the daemon and all the associated threads

	We send a shutdown command for each thread and then wait
	for them to cleanly terminate before pulling down the message
	queue.
*/

for ($i=0; $i < count($fork_pids); $i++)
{
	msg_send($msg_queue, MESSAGE_CTRL, "shutdown");
}

foreach ($fork_pids as $pid)
{
	print "Waiting for child processes to complete... ($pid)\n";
	pcntl_waitpid($pid, $status, WUNTRACED);
}

// remove message queue
unlink($config["SMStoXMPP"]["app_lock"]);

// remove message queue
msg_remove_queue($msg_queue);

// complete
$log->info("[master] Peak memory usage of ". memory_get_peak_usage() ." bytes");
$log->info("Clean Shutdown");

exit();

