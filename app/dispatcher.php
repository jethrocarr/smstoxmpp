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
include 'include/external/XMPPHP/XMPP.php';
include 'include/external/CardDAV-PHP/carddav.php';
include 'include/external/CardDAV-PHP/support.php';

// internal libraries used by all forks
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
	global $pid_contacts;
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

		// send shutdown to contacts fork
		if (!empty($pid_contacts))
		{
			posix_kill($pid_contacts, SIGTERM);
			pcntl_waitpid($pid_contacts, $status, WUNTRACED);
		}

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
	global $log;
	global $pid_parent;
	global $pid_child;

	$log->debug("[child $pid_child] Peak memory usage of ". memory_get_peak_usage() ." bytes");
	$log->debug("[child $pid_child] Terminating child support process & parent");

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

	$options_set["config_file"] = "$currdir/config/config.ini";
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

	$pid_child = getmypid();

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
	$log->debug("[child $pid_child] is logging worker ");


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



/*
	Launch contacts lookup worker fork

	If enabled, we launch a seporate fork which looks up (and caches) address
	book look ups for all device worker forks.

	In the backend, this fork uses CardDAV which has numerious server
	implementations including Google Contacts which is built into Android.
*/

if ($config["SMStoXMPP"]["contacts_lookup"] == true)
{
	$pid_contacts = pcntl_fork();

	if (!$pid_contacts)
	{
		/*
			We are the fork!
			* Validate configuration provided
			* Establish a connection to the CardDAV backend
			* Listen on the message queue for incomming contact lookup requests
			* Pull contact name from CardDAV
			* Save into cache (just an array) for next time.
		*/

		$pid_child = getmypid();

		$log->info("[contacts] Launched CardDAV contacts/address book lookup worker fork");
		$log->debug("[child $pid_child] is contacts worker ");

		// verify installed modules
		if (!class_exists('XMLWriter'))
		{
			$log->error_fatal("PHP XMLWriter module must be installed to enable CardDAV functionality");
		}

		// verify configuration
		if (!$config["SMStoXMPP"]["contacts_url"])
		{
			$log->error_fatal("[contacts] No contacts_url provided to query for CardDAV contacts.");
		}

		if (!$config["SMStoXMPP"]["contacts_store"])
		{
			$log->error_fatal("[contacts] No contacts_store provided to store downloaded contacts to avoid large re-syncs");
		}
		else
		{
			if (!file_exists($config["SMStoXMPP"]["contacts_store"]))
			{
				$log->error_fatal("[contacts] Contacts storeectory ({$config["SMStoXMPP"]["contacts_store"]}) does not exist");
			}

			if (!is_writable($config["SMStoXMPP"]["contacts_store"]))
			{
				$log->error_fatal("[contacts] Contacts storeectory ({$config["SMStoXMPP"]["contacts_store"]}) is not writable");
			}
		}


		// connect to CardDAV
		$carddav = new carddav_backend($config["SMStoXMPP"]["contacts_url"]);
		$carddav->set_auth($config["SMStoXMPP"]["contacts_username"], $config["SMStoXMPP"]["contacts_password"]);

		// handle posix signals in a sane way
		pcntl_signal(SIGTERM, "sig_handler_child", false);
		pcntl_signal(SIGHUP,  "sig_handler_child", false);
		pcntl_signal(SIGINT, "sig_handler_child", false);
		pcntl_signal(SIGUSR1, "sig_handler_child", false);
	

		// we set the rescan option here, and do the actual CardDAV download
		// and sync inside the loop - this allows us to schedule automatic
		// rechecks
		$address_rescan = true;
		

		// store address to contact mapping in memory
		$address_map = array();

		// background worker loop
		while (true)
		{
			if ($address_rescan == true)
			{
				/*
					Sync CardDAV Addresses

					We don't want to be hitting the CardDAV server with every single
					lookup request, so we first fetch a lightweight list of contacts
					and times modified, then we download any CardDAV Vcards we don't
					have, or which has been updated.

					Once we have the Vcards, we save them to disk as a file per VCard
					which we can then search through when a lookup is required.

					We cache the lookup results in memory for the running session
					to avoid too many file searches.
				*/


				// download the list of address book objects
				// (we just grab the IDs and date modified here)
				try {
					$address_list_xml = @$carddav->get(false, false);
				}
				catch (Exception $e)
				{
					$log->error("[contacts] Error from CardDAV: {$e->getMessage()}");
					$log->error_fatal("[contacts] A fatal error occured whilst attempting to fetch contacts from CardDAV server");
				}

				$address_list_array	= xmlstr_to_array($address_list_xml);
				$address_list_update	= array();
				$address_list_all	= array();


				// run through the list and see which ones need updating
				foreach ($address_list_array["element"] as $address_list_entry)
				{
					$timestamp = null;

					if (preg_match("/^([0-9]*)-([0-9]*)-([0-9]*)T([0-9]*):([0-9]*):([0-9]*)/", $address_list_entry["etag"], $matches))
					{
						$timestamp = mktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]);
					}

					$filename = $config["SMStoXMPP"]["contacts_store"] ."/". $address_list_entry["id"] .".vcf";

					if (file_exists($filename))
					{
						if (filemtime($filename) < $timestamp)
						{
							$log->debug("[contacts] VCard $filename is outdated and the latest VCard needs to be fetched");
							$address_list_update[] = $address_list_entry["id"];
						}
					}
					else
					{
						$log->debug("[contacts] VCard $filename is not on disk and needs fetching");
						$address_list_update[] = $address_list_entry["id"];
					}

					$address_list_all[] = $address_list_entry["id"];
				}

				// delete old vcards that don't exist in the upstream address book
				foreach (glob($config["SMStoXMPP"]["contacts_store"] ."/*.vcf") as $filename)
				{
					$filename = substr(basename($filename), 0, -4);

					if (!in_array($filename, $address_list_all))
					{
						$log->debug("[contacts] Deleting \"$filename\", no longer present in upstream address book");
						@unlink($filename);
					}
				}


				$address_list_total_all		= count($address_list_all);
				$address_list_total_update	= count($address_list_update);

				unset($address_list_xml);
				unset($address_list_array);
				unset($address_list_all);

				$log->info("[contacts] Total of {$address_list_total_all} contacts, need to update/fetch {$address_list_total_update} of them");


				/*
					Fetch any new contacts
				*/
				if (!empty($address_list_update))
				{
					$i=0;

					foreach ($address_list_update as $id)
					{
						$i++;
						$log->info("[contacts] Fetching contact $id ($i/{$address_list_total_all})");

						// download vcard to offline space. We use touch to set the mtime of the file
						// so that we can check if it's changed without re-downloading

						if (!$vcard = $carddav->get_vcard($id))
						{
							$log->error("[contacts] Error reading contact $id, skipping");
						}
						else
						{
							$vcard_array	= split("\r\n", $vcard);
							$timestamp	= null;
							$name		= null;

							foreach ($vcard_array as $line)
							{
								if (preg_match("/^REV:([0-9]*)-([0-9]*)-([0-9]*)T([0-9]*):([0-9]*):([0-9]*)/", $line, $matches))
								{
									$timestamp = mktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]);
								}
								
								if (preg_match("/^FN:([\S\s]*)/", $line, $matches))
								{
									$name = $matches[1];
								}
							}

							$log->debug("[contacts] Downloaded Vcard for $name");
							$log->debug("[contacts] Last modified at $timestamp");
				
							$filename = $config["SMStoXMPP"]["contacts_store"] ."/$id.vcf";

							if (!file_put_contents($filename, $vcard))
							{
								$log->error("[contacts] Unable to write vcard $id to disk");
							}
							else
							{
								// set modified time, so we know whether our offline cache
								// is up to date or not
								touch($filename, $timestamp);
							}

						}

					} // end for each contact

					// completed sync :-)
					$log->info("[contacts] CardDAV Synchronisation Completed");
					unset($address_list_update);

				} // end if contacts to update
			

				/*
					Read all the files on disk into memory
				*/

				$log->info("[contacts] Scanning Vcard files from disk for contact numbers");
				$address_map = array();
				
				foreach (glob($config["SMStoXMPP"]["contacts_store"] ."/*.vcf") as $filename)
				{
					if ($contents = @file_get_contents($filename))
					{
						$vcard_array	= split("\r\n", $contents);
						$name		= null;

						// We care about two types of line:
						// FN:John Doe
						// TEL;TYPE=WORK:+12123456
						//
						// These can annoying be in any order, hence the double foreach
						//

						foreach ($vcard_array as $line)
						{
							if (preg_match("/^FN:([\S\s]*)/", $line, $matches))
							{
								$name = $matches[1];
							}
						}

						if ($name)
						{
							$custom_label_map = array(); // used to track custom labels within the carddav entry

							foreach ($vcard_array as $line)
							{

								if (preg_match("/^TEL;(\S*?):([\S\s]*)$/", $line, $matches))
								{
									/*
										Standard CardDAV Entry, looks something like:

										TEL;TYPE=CELL,PREF:+1223457
										TEL;TYPE=WORK:+12123456
									*/
									$options = $matches[1];
									$number  = $matches[2];
									$number  = str_replace(" ", "", $number);

									$address_map[ $number ]["name"] = $name;

									// see if we can get the type of device
									// (eg cell/work/home)
									if (preg_match("/TYPE=(\w*)/", $options, $matches))
									{
										$address_map[ $number ]["type"] = $matches[1];
									}
								}
								elseif (preg_match("/^item([0-9]*).TEL:([\S\s]*)$/", $line, $matches))
								{
									/*
										Custom Labels CardDAV Entry on Android

										Custom labels are trickier, since they end up having
										multiple lines, and it may not be standardised.

										Looks like:
										item1.TEL:+121234567
										item2.TEL:+988765444
										item1.X-ABLabel:Timbucktu Number
										item2.X-ABLabel:Seaside Batch
									*/

									$itemid = $matches[1];
									$number = $matches[2];
									$number = str_replace(" ", "", $number);

									$address_map[ $number ]["name"] = $name;

									$custom_label_map[ $itemid ] = $number;
								}
								elseif (preg_match("/^item([0-9]*).X-ABLabel:([\S\s]*)$/", $line, $matches))
								{
									/*
										Item ID for custom labels (see above)
									*/

									$itemid = $matches[1];
									$label  = $matches[2];

									$address_map[ $custom_label_map[ $itemid ] ]["type"] = $label;
								}
							}
						}
						else
						{
							$log->debug("[contacts] No FD/name record in $filename, skipping");
						}
					}

				} // end of reading all files from disk

				// complete
				$log->info("[contacts] Scan completed, all contact numbers now in memory");
				$address_rescan = false;

			} // end of CardDAV Sync


			/*
				Ready to process addressbook lookups

				XMPP workers can request adddressbook lookups for incoming numbers (fast)
				or request numbers for a particular name search.
			*/

			$message = null;
			$message_type = null;

			msg_receive($msg_queue, MESSAGE_CONTACTS_REQ, $message_type, MESSAGE_MAX_SIZE, $message, TRUE);
			
			if ($message["type"] == "phone_number")
			{
				$log->debug("[contacts] Phone number lookup request for {$message["phone"]}");

				$request = array();
				if (isset($address_map[ $message["phone"] ]))
				{
					$request["name"] = $address_map[ $message["phone"] ]["name"];
					$request["type"] = $address_map[ $message["phone"] ]["type"];
					
					$log->debug("[contacts] Phone number resolved to {$request["name"]}");
				}
				else
				{
					$log->debug("[contacts] Unknown phone number");
				}
			}
			elseif ($message["type"] == "search")
			{
				$log->debug("[contacts] Contacts search request for {$message["search"]}");
			}
			else
			{
				$log->error("[contacts] Malformed request provided!");
			}

			msg_send($msg_queue, (MESSAGE_CONTACTS_ASR + $message["workerpid"]), $request);
		}

		// terminate
		// in reality, we will never get here - SIGTERM will kill
		// the above block and then just stop this fork & GC.
		exit();
	}
}



/*
	Launch per-device workers

	We fork the application once per device, which each fork handling
	both the XMPP and message queues for sending and recieving SMS messages.
*/

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
		$log->debug("Worker/child process launched for device \"$section\"");
		$pid_child = getmypid();


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


		/*
			Launch gateway/device logic

			All the gateway/device logic is broken into include files that are only loaded into
			the particular worker fork which is using that gateway. If we can't load it for some
			reason we should fail with an error to all the apps.
		*/
		
		if (!$config[$section]["gw_type"])
		{
			$log->error_fatal("There is no gateway set! Unable to proceed!");
		}

		$gatewayfile = "include/gateways/". $config[$section]["gw_type"] .".php";

		if (!file_exists($gatewayfile))
		{
			$log->error_fatal("The gateway include $gatewayfile could not be located - is this the correct gateway name?");
		}
		
		include($gatewayfile);

		$gateway = New device_gateway;

		if (!$gateway->set_path($config[$section]["gw_path"]))
		{
			$log->error("Invalid syntax for gw_path option! Please check your configuration");
		}


		/*
			Start Message Processer
		*/


		// track whom we are chatting with
		$current_chat		= null;
		$current_status		= null;

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
							case "_help":
							case "_about":
							case "_license":
							case "_version":
								/*
									User help & application status
								*/

								$help = array();

								$help[] = "". APP_NAME ." (". APP_VERSION .")";
								
								if ($pl["body"] == "_help")
								{
									$help[] = " ";
									$help[] = "Available Commands:";
									$help[] = "---";
									$help[] = "_chat NAME|NUMBER\tOpen an SMS conversation to a person or phone number";
									$help[] = "_find NAME\t\tSearch the address book for a name (use * to list all)";
									$help[] = "_help\t\t\tThis help message";
									$help[] = "_usage\t\tExamples of command usage";
									$help[] = "_version\t\tApplication & platform version information.";
									$help[] = "_health_check\t\tPerform an instant check of the gateway device status.";
									$help[] = " ";
								}

								$help[] = "". APP_HOMEPAGE ."";
								$help[] = "Licensed under the ". APP_LICENSE ." license.";
								$help[] = "Running on PHP version ". phpversion() ."";

								foreach ($help as $body)
								{
									$conn->message($pl["from"], $body);
								}
							break;

							case "_usage":
								$help = array();

								$help[] = " ";
								$help[] = "Application Usage:";
								$help[] = "---";
								$help[] = "To open a new chat, prefix the message with the destination phone number, eg:";
								$help[] = "+121234567 example message";
								$help[] = " ";
								$help[] = "Or use _chat to select the number or name of the person to chat to:";
								$help[] = "_chat +121234567";
								$help[] = "_chat example contact";
								$help[] = "example message";
								$help[] = " ";
								$help[] = "Once a chat is open (by yourself or by an incomming message) any replies without a prefix number will go to the current active chat recipient automatically.";
								$help[] = " ";

								foreach ($help as $body)
								{
									$conn->message($pl["from"], $body);
								}

							break;

							case "_health_check":
								/*
									Force a health check
								*/

								if ($gateway->health_check($force=true))
								{
									$conn->message($pl["from"], $body="Health check was successful!");
								}
								else
								{
									$conn->message($pl["from"], $body="Health check was unsuccessful :-(");
								}

								$current_status = null;
							break;
							
							default:
								if (preg_match("/^_chat/", $pl["body"]))
								{
									$log->debug("[$section] Recieved _chat command");

									if (preg_match("/^_chat ([+]*[0-9\s-]*)$/", $pl["body"], $matches))
									{
										$number = $matches[1];
										$number = str_replace(" ", "", $number);
										$number = str_replace("-", "", $number);

										$current_chat = $number;

										$conn->message($config[$section]["xmpp_reciever"], $body="You are now talking to $number", $subject=$number);
									}
									elseif (preg_match("/^_chat ([\S\s]*)$/", $pl["body"], $matches))
									{
										$search = $matches[1];

										$log->debug("[$section] Doing a _chat lookup for \"$search\"");
									}
									else
									{
										$conn->message($config[$section]["xmpp_reciever"], $body="Invalid command, syntax is: _chat NAME|NUMBER");
									}


								}
								elseif (preg_match("/^_find\s([\S\s]*)$/", $pl["body"], $matches))
								{
									$log->debug("[$section] Recieved _find command");

									if ($pid_contacts)
									{
										// we give the contacts worker our PID, so it can reply to us specifically
										$request = array();
										$request["type"]	= "search";
										$request["workerpid"]	= getmypid();
										$request["search"]	= $matches[1];

										msg_send($msg_queue, MESSAGE_CONTACTS_REQ, $request);

										// this is hacky, we wait 0.1 seconds for a response, ideally need a blocking request
										// here, but there's no native support for it.... so would have to do nasty tricks
										// like timer loops and checks. :-/
										usleep(100000);

										$address_lookup_type = null;
										$address_lookup = null;

										if (msg_receive($msg_queue, (MESSAGE_CONTACTS_ASR + $request["workerpid"]), $address_lookup_type, MESSAGE_MAX_SIZE, $address_lookup, TRUE, MSG_IPC_NOWAIT))
										{
											if (!empty($address_lookup))
											{
												// TODO: display/auto-select responses
											}
										}
									}
									else
									{
										$conn->message($pl["from"], $body="Sorry, there is no source of contacts information configured.");
									}

								}
								else
								{

									/*
										An actual message, we need to check it for syntax validity.

										Should be either
										"message text here" when in context of an existing chat
										or
										"+12134567: message text here" when direct sending
									*/
									
									// toss out those @$%^&* newlines
									if ($pl["body"] == "")
									{
										break;
									}

									$log->debug("[$section] XMPP message recieved! \"". $pl["body"] ."\"");

									// check gateway health - if unhealthy, we are not going to be able to send
									if (!$gateway->health_check($force=true))
									{
										$conn->message($config[$section]["xmpp_reciever"], $body="Message undeliverable, gateway is currently unhealthy.");
										break;
									}


									// TODO: input validation here!

									// fetch number & message then send
									if (preg_match("/^([+0-9]*)[:]*\s([\S\s]*)$/", $pl["body"], $matches))
									{
										$phone	= $matches[1];
										$body	= $matches[2];

										if ($gateway->message_send($phone, $body))
										{
											$log->debug("[$section] Message delivered successfully");
										}
										else
										{
											$log->warning("[$section] Unable to deliver message from XMPP to gateway.");
											$conn->message($config[$section]["xmpp_reciever"], $body="Message undeliverable, gateway is currently unhealthy.");
										}

										// save number as current chat destinatoin
										$conn->message($config[$section]["xmpp_reciever"], $body="Chat target has changed, you are now talking to {$phone}, all unaddressed replies will go to this recipient.", $subject=$phone);
										$current_chat = $phone;
									}
									else
									{
										if (!$current_chat)
										{
											$conn->message($pl["from"], $body="Unable to send - please specify destination phone number using syntax \"+XXXXXXX my message\"");
										}
										else
										{
											// we know the current chat name, so just send the string to them
											$gateway->message_send($current_chat, $pl["body"]);
										}
									}

								} // end of actual message

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

						// Clear current healthcheck status to trigger presence notification
						$current_status = null;

						// allow any user to subscribe - but we validate that only certain users can message us
						$conn->autoSubscribe(true);

						// send user a welcome
						$conn->message($config[$section]["xmpp_reciever"], $body="". APP_NAME ." (". APP_VERSION .") started", $type="chat");
						$conn->message($config[$section]["xmpp_reciever"], $body="Type \"_help\" for usage and option information", $type="chat");

						// note about dynamic/auto GW?
						if ($config[$section]["gw_path"] == "auto" || $config[$section]["gw_path"] == "dynamic")
						{
							$conn->message($config[$section]["xmpp_reciever"], $body="Note: This gateway is configured to be discovered automatically - you will be unable to send SMS via XMPP until the gateway first sends a message through to XMPP and reveals it's address. You can avoid this behaviour by statically assigning the device IP and adding it to the configuration.");
						}

						// ready
						$log->info("[$section] Ready to process messages");
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
							phone		=> "+120123456..."
							body		=> "just a string" 
							time		=> reserved for future use
							device_ip	=> IP device connected to listener on
							device_port	=> undef, reserved for future GWs
							device_url	=> undef, reserved for future GWs
						)
					*/

					$log->debug("[$section] Recieved SMS message from {$message["phone"]} with content of {$message["body"]}");


					/*
						Lookup the name of the contact for the recieved message, if contact
						lookups are handled.
					*/

					$message["phone_human"] = $message["phone"];

					if ($pid_contacts)
					{
						// we give the contacts worker our PID, so it can reply to us specifically
						$request = array();
						$request["type"]	= "phone_number";
						$request["workerpid"]	= getmypid();
						$request["phone"]	= $message["phone"];

						msg_send($msg_queue, MESSAGE_CONTACTS_REQ, $request);

						// this is hacky, we wait 0.1 seconds for a response, ideally need a blocking request
						// here, but there's no native support for it.... so would have to do nasty tricks
						// like timer loops and checks. :-/
						usleep(100000);

						$address_lookup_type = null;
						$address_lookup = null;

						if (msg_receive($msg_queue, (MESSAGE_CONTACTS_ASR + $request["workerpid"]), $address_lookup_type, MESSAGE_MAX_SIZE, $address_lookup, TRUE, MSG_IPC_NOWAIT))
						{
							if (!empty($address_lookup))
							{
								$message["phone_human"] = $address_lookup["name"] ." (". $address_lookup["type"] .")";
							}
						}
					}


					/*
						Switch focus of conversation
					*/

					if (!$current_chat)
					{
						$current_chat = $message["phone"];
					}
					else
					{
						if ($current_chat != $message["phone"])
						{
							$conn->message($config[$section]["xmpp_reciever"], $body="Chat target has changed, you are now talking to {$message["phone_human"]}, all unaddressed replies will go to this recipient.", $subject=$message["phone_human"]);
							$current_chat = $message["phone"];
						}
					}

					$conn->message($config[$section]["xmpp_reciever"], $body="{$message["phone_human"]}: {$message["body"]}", $subject=$message["phone_human"]);


					/*
						Do we need to use any of the extra info, such as IP?
					*/

					if ($config[$section]["gw_path"] == "auto" || $config[$section]["gw_path"] == "dynamic")
					{
						// we are using dynamic/auto configuration, we should set the IP of the gateway
						// to the one provided.
						$gateway->set_address($message["device_ip"]);

						// force health check
						$current_status = null;
					}

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


			/*
				Health check the gateway

				Note that we'll be hitting the function every second, but the gateway
				code may make checks more infrequent than that to avoid excessive
				polling.

				We show the gateway health to the user via means of XMPP presence aka
				status for the user.
			*/

			$status = null;

			if ($gateway->health_check())
			{
				$status = "". APP_NAME ." connected to $section";
			}
			else
			{
				$status = "Unhealthy gateway device $section :-(";
			}

			if ($current_status != $status)
			{
				$conn->presence($status);
				$current_status = $status;
			}


		} // end of loop


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

// unexpected ending - try a clean shutdown, otherwise fail with warning
posix_kill($pid_parent, SIGTERM);
die("Unexpected application ending");

