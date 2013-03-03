<?php
/*
	logger.php

	Provides a simplistic lightweight logger process. It does some basic sanity checking
	and supports STDOUT, STDERR and writing to a file.
*/

class logger
{
	private $option_stdout			= false;
	private $option_debug			= false;
	private $option_msgqueue_listener	= false;
	private $option_msgqueue_sender		= false;

	private $logfile_handle;

	private $msgqueue_handle;
	private $msgqueue_type;
	private $msgqueue_maxsize;


	/*
		Cleanup Properly
	*/
	function __destruct()
	{
		if ($logfile_handle)
		{
			fclose($logfile_handle);
		}
	}



	/*
		Set the logfile to be used - this function checks and creates the logfile as required.
	*/
		
	function set_logfile($filename)
	{
		// if the log file doesn't exist, we should create or return error
		if (!file_exists($filename))
		{
			if (!touch($filename))
			{
				print "Warning: Unable to create log $filename, please check directory permissions.\n";
				return 0;
			}
		}

		// sort out permissions to owner/group access only, we need to be careful
		// since there may be private data in the log messages
		@chmod($filename, 0640);

		if (!$this->logfile_handle = fopen($filename, "a"))
		{
			print "Warning: Unable to open log file $filename for writing.\n";
			return 0;
		}

		return 1;
	}


	/*
		When running apps in verbose mode, it's often desirable to output to stdout.
	*/
	function set_stdout()
	{
		$this->option_stdout = true;
	}

	function set_debug()
	{
		$this->option_debug = true;
	}


	/*
		Message Queue Handling

		This is useful when writing forking applications which need to carefully handle their logging
		to avoid clashing in the same log file.

		We could leave it up to the kernel and hope for the best, but it's not an ideal solution, we
		should handle it properly by using IPC to communicate the log messages.

		To provide for this, we have two functions - a sender that will always send log messages
		to the queue and a listener, that can be called for a non-blocking listen for log messages.
	*/

	function set_queue($msgqueue_handle, $message_type, $message_maxsize)
	{
		$this->msgqueue_handle	= $msgqueue_handle;
		$this->msgqueue_type	= $message_type;
		$this->msgqueue_maxsize = $message_maxsize;
	}

	function set_queue_listener($msgqueue_handle, $message_type, $message_maxsize)
	{
		$this->set_queue($msgqueue_handle, $message_type, $message_maxsize);

		$this->option_set_queue_listener = true;
	}
	
	function set_queue_sender($msgqueue_handle, $message_type, $message_maxsize)
	{
		$this->set_queue($msgqueue_handle, $message_type, $message_maxsize);

		$this->option_set_queue_sender = true;
	}



	/*
		write()

		Write the provided log message to all enable destinations.
	*/
	function write($level, $message)
	{
		$time = date("Y-m-d H:i:s");

		// to console/display
		if ($this->option_stdout)
		{
			if ($level == "error")
			{
				fwrite(STDERR, "$time [$level] $message\n");
			}
			else
			{
				print "$time [$level] $message\n";
			}
		}

		// via IPC to another process
		if ($this->option_set_queue_sender)
		{
			// send to queue
			$queue_message = array(
					"time"	=> $time,
					"level"	=> $level,
					"body"	=> $message,
					);

			msg_send($this->msgqueue_handle, $this->msgqueue_type, $queue_message);
		}


		// to log file
		if ($this->logfile_handle)
		{
			fwrite($this->logfile_handle, "$time [$level] $message\n");
		}

	}



	/*
		write_fromqueue()

		Check the IPC queue for any messages and then write them using the standard
		process. Non-blocking check.
	*/

	function write_fromqueue($blocking = false)
	{
		if ($this->option_set_queue_listener)
		{
			$message = "";
			$message_type = "";
			$errorcode = null;

			if ($blocking == true)
			{
				$return = msg_receive($this->msgqueue_handle, $this->msgqueue_type, $message_type, $this->msgqueue_maxsize, $message, TRUE, $errorcode);
			}
			else
			{
				$return = msg_receive($this->msgqueue_handle, $this->msgqueue_type, $message_type, $this->msgqueue_maxsize, $message, TRUE, MSG_IPC_NOWAIT);
			}

			if ($return)
			{
				// call the appropiate write function for the level defined
				$this->$message['level']($message['body']);
			}

			if ($errorcode)
			{
				print "Serious Error: MSG queue recieve failed with $errorcode\n";
			}

			return 1;
		}

		return 0;
	}




	/*
		Loglevel write commands
	*/
	function debug($message)
	{
		if ($this->option_debug)
		{
			$this->write("debug", $message);
		}
	}

	function info($message)
	{
		$this->write("info", $message);
	}

	function warning()
	{
		$this->write("warning", $message);
	}

	function error($message)
	{
		$this->write("error", $message);
	}

	function error_fatal($message)
	{
		$this->write("error", $message);
		die("Fatal Error: $message");
	}

}

?>
