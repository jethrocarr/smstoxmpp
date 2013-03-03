<?php
/*
	eu.apksoft.android.smsgateway

	A lightweight Android-based SMS to HTTP gateway application that turns any
	Android phone into a bi-directional gateway.

	https://play.google.com/store/apps/details?id=eu.apksoft.android.smsgateway


	This files provides bindings so that SMStoXMPP can talk with Android SMS Gateway
	via HTTP offering push-style behaviour.

	Android SMS Gateway delivers SMS messages by opening an HTTP connection to the SMStoXMPP
	listener script and providing the details via HTTP GET. In order to send messages back out
	via the application, SMStoXMPP needs to open an HTTP connection back to the device and
	provide the message and phone numbers via HTTP GET.

	The challenge is that the listening address may change (or be behind NAT) so we have
	two options - using a statically configured address in the configuration file, or by
	waiting for the device to identify itself with it's first message, and then retaining
	that address for future conversations.
*/


class device_gateway
{
	var $log;
	var $section;

	var $address		= null; // once the address is discovered, record it here for future use.
	var $port		= null;

	var $health_check_last	= null;	 // time of the last health check
	var $health_check_state	= null;	 // last check status
	var $health_check_freq	= '300'; // perform a health check every x seconds.


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
	*/
	function set_address($address, $port)
	{
		$this->address	= $address;
		$this->port	= $port;

		// reset the health check timer
		$this->health_check_last = null;
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
		}

		return $this->health_check_state;
	}


	/*
		message_listen

		Due to the nature of this gateway, this listen function is non-blocking and just checks
		the current $_GET variables for valid content and validates, before returning the information
		in an array.

		Returns
		0	No message
		array	Message in array
	*/

	function message_listen()
	{
		
	}



	/*
		message_send

		Sends a message out via the gateway. If the gateway is unavailable, we return
		an error so the user can be notified.

		TODO: should we implement some form of queuing here for future use?
	*/
	function message_send($phone, $body)
	{
		$this->log->debug("[{$this->section}] Sending message to \"$phone\" contents: \"$body\"");

	}
}

?>
