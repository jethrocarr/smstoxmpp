<?php
/*
	SMStoXMPP Gateway Template

	The file is a template gateway plugin for SMStoXMPP. If you wish to add
	support for additional gateways, this is a good starting file to use.

	For information on developing gateways, please refer to the wiki at:
	https://projects.jethrocarr.com/p/oss-smstoxmpp/page/Developer-GatewaySupport/

	Existing gateways in this directory may also be a useful resources.
	- See eu.apksoft.android.smsgateway.php for example of a remote gateway device.
*/


class device_gateway
{
	var $log;
	var $section;

	var $address		= null; // once the address is discovered, record it here for future use.
	var $port		= null;

	var $health_check_last	= null;	 // time of the last health check
	var $health_check_state	= null;	 // last check status
	var $health_check_freq	= HEALTH_CHECK_FREQUENCY; // perform a health check every x seconds.


	function __construct()
	{
		global $log;
		global $section;

		$this->log	= &$log;
		$this->section	= &$section;
	}


	/*
		set_address

		Either called with the static IP/port from the configuration file, or
		called by the application itself when we discover the current address.

		This code allows us to support both dynamic and statically addressed
		devices. If you are supporting a local hardware device only, you can
		just turn this function into a place holder.
	*/
	function set_address($address, $port = "9090")
	{
		if ($this->address != $address)
		{	
			// address has changed, set
			$this->address	= $address;
			$this->port	= $port;

			// reset the health check timer
			$this->health_check_last = null;

			$this->log->info("[{$this->section}] Updated device address to {$this->address}:{$this->port}");
		}
	}


	/*
		set_path

		Provided with the path from the configuration file, this function validates
		the input and then calls set_address to save if it's static.

		Returns
		-1	Empty Path
		0	Invalid Path
		1	Address Set / Valid / Automatic
	*/
	function set_path($path)
	{
		if (empty($path))
		{
			return -1;
		}

		if ($path == "auto" && $path == "dynamic")
		{
			// dynamics can't be set, we must wait for the gateway
			// to announce itself by sending us an SMS.
			return 1;
		}
		else
		{
			// gateway device has a static path configured
			list($address, $port) = @explode(":", $path);

			if (preg_match("/^[0-9]*$/", $port) && preg_match("/^\S*$/", $address))
			{
				$this->set_address($address, $port);
				return 1;
			}
		}

		return 0;
	}


	/*
		health_check

		Checks reachability of the remote device based on the current address.

		Returns
		0	Unhealthy
		1	Healthy - send me messages bro!
	*/
	function health_check($force = false)
	{
		$curr_time = time();

		if ($force == true)
		{
			$this->health_check_last = null;
		}

		if ($this->health_check_last < ($curr_time - $this->health_check_freq))
		{
			$this->health_check_last = $curr_time;

			if (!$this->address)
			{
				$this->log->debug("[{$this->section}] Health check failed, gateway IP is unknown");
				$this->health_check_state = 0;
			}
			else
			{
				// test if we can open the port within X seconds
				if ($fp = @fsockopen($this->address, $this->port, $errno, $errstr, HEALTH_CHECK_TIMEOUT))
				{
					fclose($fp);

					$this->log->debug("[{$this->section}] Health check succeeded in opening port. :-)");
					$this->health_check_state = 1;
				}
				else
				{
					$this->log->debug("[{$this->section}] Health check failed, unable to connect to IP/port.");
					$this->health_check_state = 0;
				}
			}
		}

		return $this->health_check_state;
	}


	/*
		message_listen

		If your gateway sends SMS messages to SMStoXMPP via making an HTTP call (ie webhook) to a
		particular HTTP address, this function is called by listener.php when a request is recieved.

		The function takes the incoming message information and adds it to the message queue to be
		processed by the application itself.

		Returns
		0	No message
		array	Message in array
	*/

	function message_listen()
	{
		// we don't do input validation here, it's done in a standard way
		// with the main listener logic
	
		$message = array();

		$message["phone"]	= $_GET["phone"];
		$message["body"]	= $_GET["text"];

		if (!$message["phone"] || !$message["body"])
		{
			return 0;
		}

		return $message;
	}

	
	/*
		message_poll

		Actively polls for a new incomming message. This is unused by this gateway, but
		is needed with any gateways that are unable to push to an HTTP listener which
		can be handled by message_listen()

		This function may be useful when detailing with hardware devices or gateways
		which store their messages in a poll-only system, such as a POP3 mailbox.

		Returns
		0	No Message
		array	Message in array
	*/

	function message_poll()
	{
		/*
			Once processing has been performed, prepare a multidimensional
			array of hashes with messages recieved.

			return (
			 [0] => (
			 	 phone => value,
				 body => value,
				)
			 [1] => (
			 	 phone => value
				 body => value
			        )
			)
			etc

		*/
		// placeholder only
		return 0;
	}


	/*
		message_send

		Sends a message out via the gateway. If the gateway is unavailable, we return
		an error so the user can be notified.

		TODO: should we implement some form of queuing here for future use?

		Returns
		0	Failure
		1	Success
	*/
	function message_send($phone, $body)
	{
		$this->log->debug("[{$this->section}] Sending message to \"$phone\" contents: \"$body\"");

		/*
			The following is an example of sending an SMS to a gateway by connecting
			to it via HTTP. Other approaches could include emailing a particular address
			using the mail() command.
		*/

		// ensure that data is SMS & HTTP GET safe
		$phone	= urlencode($phone);
		$body	= urlencode($body);

		// don't need to do anything too complex with HTTP, etc... just open up and shove it some data
		if (@file_get_contents("http://{$this->address}:{$this->port}/sendsms?phone=$phone&text=$body"))
		{
			return 1;
		}

		return 0;
	}
}

?>
