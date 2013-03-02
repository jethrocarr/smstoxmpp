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
	var $address	= null; // once the address is discovered, record it here for future use.
	var $port	= null;

	function set_address($address, $port)
	{
		$this->address	= $address;
		$this->port	= $port;
	}

	function health_check()
	{
		
	}

	function listener()
	{
	}

	function dispatcher()
	{
	}
}

?>
