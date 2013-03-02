<?php
/*
	logger.php

	Provides a simplistic lightweight logger process. It does some basic sanity checking
	and supports STDOUT, STDERR and writing to a file.
*/

class logger
{
	private $option_stdout		= false;
	private $option_debug		= false;

	private $logfile_handle;


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



	/*
		Log write commands
	*/
	function write($level, $message)
	{
		$time = date("Y-m-d H:i:s");

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

		if ($this->logfile_handle)
		{
			fwrite($this->logfile_handle, "$time [$level] $message\n");
		}
	}

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
