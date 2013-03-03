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
	- Resolution of phone numbers against CardDAV directories (TODO)

	Copyright 2013 Jethro Carr <jethro.carr@jethrocarr.com>
	Licensed under the GNU AGPL license.
*/

// external libraries
include 'XMPPHP/XMPP.php';

// internal libraries
include 'include/defines.php';
include 'include/logger.php';


/*
	Custom Signal Handlers

	Due to the multi-process nature, logger and other complexities, we need to
	intercept the usual signal handling so that we can cleanly shutdown all
	the threads and the parent process.

	All the logic is in the parent process, which does a clean shutdown upon
	a SIGTERM/SIGHUP/SIGINT and as part of it, sends a shutdown to all the child
	processes.

	The child processes themselves have a custom handler to prevent a SIGINT from
	terminating the process - instead, it will send a SIGTERM to the parent upon
	recieving a SIGINT, so that the parent gets to handle the proper shutdown
	of the process.
*/

declare(ticks = 1);
$pid_parent = getmypid();

function sig_handler_parent($signo)
{
	global $log;
	global $fork_pids;
	global $pid_logger;
	global $msg_queue;
	global $config;

	if ($signo == SIGTERM || $signo == SIGHUP || $signo == SIGINT)
	{
		/*
			Terminate the daemon and all the associated threads

			We send a shutdown command for each thread and then wait
			for them to cleanly terminate.
		*/

		$log->debug("Shutdown POSIX signal recieved");

		for ($i=0; $i < count($fork_pids); $i++)
		{
			msg_send($msg_queue, MESSAGE_CTRL, "shutdown");
		}

		foreach ($fork_pids as $pid)
		{

			$log->debug("Waiting for child processes to complete... ($pid)");
			pcntl_waitpid($pid, $status, WUNTRACED);
		}


		/*
			Clean Wrapup
		*/

		$log->debug("[master] Peak memory usage of ". memory_get_peak_usage() ." bytes");
		$log->info("[master] Clean Shutdown");

		// send shutdown to logger fork
		posix_kill($pid_logger, SIGTERM);
		pcntl_waitpid($pid_logger, $status, WUNTRACED);

		// remove message queue
		msg_remove_queue($msg_queue);
		unlink($config["SMStoXMPP"]["app_lock"]);

		exit();
	}
	else
	{
		print "Unknown signal of $signo!\n";
		$log->info("Recieved unproccessible POSIX signal of \"$signo\", ignoring");
	}
}

function sig_handler_child($signo)
{
	global $pid_parent;

	if ($signo == SIGTERM || $signo == SIGHUP)
	{
		exit();
	}
	elseif ($signo == SIGINT || $signo == SIGUSR1)
	{
		// interrupt - we should send a kill to the parent process, so that
		// it actually gets the shutdown command, since if the SIGINT has come
		// to the child first, the parent won't have recieved an alert
		posix_kill($pid_parent, SIGTERM);
	}
}



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
if (isset($options_set["D"]))
{
	$options_set["daemon"] = true;
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
if (isset($options_set["config"]))
{
	$options_set["config_file"] = $options_set["config"];
}
else
{
	$currdir = realpath(dirname(__FILE__));

	$options_set["config_file"] = "$currdir/config/sample_config.ini";
}

if (!file_exists($options_set["config_file"]))
{
	die("Fatal Error: Unable to open configuration file ". $options_set["config_file"] ."\n");
}

// read configuration file
$config = parse_ini_file($options_set["config_file"], true);





/*
	Daemon Mode

	By default, the program runs in the foreground, with the parent process become
	a simple blocking wait that monitors the child processes and cleanly shuts them
	down once complete.
	
	However when called with the -D option, we want to turn this process into a proper
	daemon, in which case we need to pcntl_exec() this same program, but without the -D
	option, so that the new process runs as expected.
*/

if (isset($options_set["daemon"]))
{
	if ($options_set["debug"])
	{
		print "Launching background process & terminating current process (Daemon mode)\n";
	}

	// get this executable program name
	$program = $argv[0];

	// only pass through useful arguments
	$arguments = array();

	if (isset($options_set["debug"]))
	{
		$arguments[] = "--debug";
	}

	if (isset($options_set["config"]))
	{
		$arguments[] = "--config={$options_set["config"]}";
	}


	// we fork, then 
	$pid = pcntl_fork();

	if (!$pid)
	{

		// launch new instance
		pcntl_exec($program, $arguments);
	}

	// terminate origional parent leaving only the backgrounded processes
	exit();
}


/*
	Run as a non-privileged user.

	Considering we are running as a long running process, we should
	definetely run as a non-privileged user to protect in case of a 
	worst-case exploit.

	Note that this doesn't apply unless the process is launched by
	the root user, such as when started by init
*/

if ($config["SMStoXMPP"]["app_user"] && $config["SMStoXMPP"]["app_group"])
{
	$user = posix_getpwnam($config["SMStoXMPP"]["app_user"]);
	@posix_setuid($user['uid']);

	$group = posix_getgrnam($config["SMStoXMPP"]["app_group"]);
	@posix_setgid($group['gid']);
}




/*
	The message queue is vital, we need to use it to recieve
	incomming messages from the web-based listeners, as well as
	communicating between the master process and the threads.
*/
if (file_exists($config["SMStoXMPP"]["app_lock"]))
{
	die("Fatal Error: Queue already exists, dispatcher already running?\n");

}

if (!touch($config["SMStoXMPP"]["app_lock"]))
{
	die("Fatal Error: Unable to create lock file\n");
}

if (!$msg_queue = msg_get_queue(ftok($config["SMStoXMPP"]["app_lock"], 'R'),0666 | IPC_CREAT))
{
	die("Fatal Error: Unable to attach to queue ". $config["SMStoXMPP"]["app_lock"] ."\n");
}



/*
	Establish Logging

*/

$pid_logger = pcntl_fork();

if (!$pid_logger)
{
	/*
		We run the logger as a seporate fork so that it can constantly run a blocking
		listen for log messages from the other processes and components of this application.

		All other processes including the master send their logs via IPC to the logger fork.
	*/

	$log = New logger();

	$log->set_queue_listener(&$msg_queue, MESSAGE_LOG, MESSAGE_MAX_SIZE);
	$log->set_logfile($config["SMStoXMPP"]["app_log"]);

	if (isset($options_set["verbose"]))
	{
		$log->set_stdout();
	}
	if (isset($options_set["debug"]))
	{
		$log->set_debug();
	}

	$log->debug("Launched logger fork");


	// handle posix signals in a sane way
	pcntl_signal(SIGTERM, "sig_handler_child", false);
	pcntl_signal(SIGHUP,  "sig_handler_child", false);
	pcntl_signal(SIGINT, "sig_handler_child", false);
	pcntl_signal(SIGUSR1, "sig_handler_child", false);


	// blocking write of logs
	while (true)
	{
		$log->write_fromqueue($blocking = true);
	}

	// terminate
	// in reality, we will never get here - SIGTERM will kill
	// the above block and then just stop this fork & GC.
	exit();
}
else
{
	/*
		We are the parent process
		
		Create a new logger object, we need to log via the IPC
		message queue, rather than into the same text log file
		and ending up with write clashes.

		Note: we don't do print to STDOUT here, it's something
		that the logger process will do for us - although there's no
		reason why we couldn't do it here if we so desired.
	*/

	$log = new logger();
	
	if (isset($options_set["debug"]))
	{
		$log->set_debug();
	}

	$log->set_queue_sender(&$msg_queue, MESSAGE_LOG, MESSAGE_MAX_SIZE);
}

$log->info("[master] Launched ". APP_NAME ." (". APP_VERSION .")");




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


		// handle posix signals in a sane way
		pcntl_signal(SIGTERM, "sig_handler_child");
		pcntl_signal(SIGHUP,  "sig_handler_child");
		pcntl_signal(SIGINT, "sig_handler_child");
		pcntl_signal(SIGUSR1, "sig_handler_child");


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
							$log->warning("[$section] Denied connection attempt from {$pl["from"]}, only connections from {$config[$section]["xmpp_reciever"]} are permitted");

							break;
						}

						// process message
						switch ($pl["body"])
						{
							case "about":
							case "license":
							case "version":
							case "help":
								$conn->message($pl["from"], $body="". APP_NAME ." (". APP_VERSION .")");
								$conn->message($pl["from"], $body="". APP_HOMEPAGE ."");
								$conn->message($pl["from"], $body="Licensed under the ". APP_LICENSE ." license.");
								$conn->message($pl["from"], $body="Running on PHP version ". phpversion() ."");
							break;

							default:
								$log->debug("[$section] XMPP message recieved! \"". $pl["body"] ."\"");
							break;
						}
					break;

					case 'presence':
						$log->debug("[$section] Presence notification from ". $pl["from"] ." with status of ". $pl["status"] ."");
					break;

					case 'session_start':
						$log->debug("[$section] Established XMPP connection & listening for inbound requests");

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
						$log->debug("[$section] User closed our XMPP session");
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

					$log->debug("[$section] Recieved SMS message from {$message["phone"]} with content of {$message["body"]}");

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
							$log->info("[$section] Shutdown command recieved!");
							$log->debug("[$section] Peak memory usage of ". memory_get_peak_usage() ." bytes");
							$conn->message($config[$section]["xmpp_reciever"], $body="Gateway shutting down... goodbye!");

							// terminate fork cleanly
							$conn->disconnect();
							exit();
						break;

						default:
							$log->debug("[$section] Unknown message instruction recieved");
						break;
					}
				}

			} // end if available messages
		}


		// terminate
    		$conn->disconnect();
		unset($log);
		exit();
	}

	// increase fork count
	$i++;
}





/*
	Wait till shutdown

	We perform a blocking wait until all the child forks have shutdown (or via a
	shutdown being initiated via the master using signal handlers).

	Technically, this wait will never be completed, since the shutdown should
	take place using the signal handler logic and then the process will terminate 
	before this wait completes. :-)
*/

pcntl_signal(SIGTERM, "sig_handler_parent", false);
pcntl_signal(SIGHUP,  "sig_handler_parent", false);
pcntl_signal(SIGINT, "sig_handler_parent", false);
pcntl_signal(SIGUSR1, "sig_handler_parent", false);

pcntl_waitpid(-1,$status);

// unexpected ending
die("Unexpected application ending");

