<?php
/*
	Shared definitions throughout the application
*/

define("APP_NAME", "SMStoXMPP");
define("APP_VERSION", "1.0.0_beta_1");
define("APP_LICENSE", "GNU AGPLv3");
define("APP_HOMEPAGE", "https://projects.jethrocarr.com/p/oss-smstoxmpp/");

define("MESSAGE_CTRL", 100000);
define("MESSAGE_RECV", 200000);
define("MESSAGE_SEND", 300000);
define("MESSAGE_LOG", 400000);
define("MESSAGE_CONTACTS_REQ", 500000);
define("MESSAGE_CONTACTS_ASR", 600000);

define("MESSAGE_MAX_SIZE", 2000);
define("MESSAGE_TIMEOUT_SECONDS", 1);

define("CARDDAV_TIMEOUT", "10");

define("HEALTH_CHECK_FREQUENCY", "10");
define("HEALTH_CHECK_TIMEOUT", "10");

?>
