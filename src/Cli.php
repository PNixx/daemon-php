<?php
namespace PNixx\Daemon;

use League\CLImate\CLImate;

class Cli extends CLImate {

	/**
	 * Cli constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->init();
		$this->arguments->parse();
	}

	protected function init() {
		$this->arguments->add([
			'pid'     => [
				'prefix'       => 'p',
				'longPrefix'   => 'pid',
				'description'  => 'Pid file',
				'defaultValue' => '/var/run/web_socket.pid',
			],
			'log'     => [
				'prefix'       => 'l',
				'longPrefix'   => 'log',
				'description'  => 'Log file',
				'defaultValue' => '/var/log/web_socket.log',
			],
			'quiet'   => [
				'longPrefix'  => 'quiet',
				'description' => 'Run in background. Do not output any message. All massages will be write to log file.',
				'noValue'     => true,
			],
			'include' => [
				'prefix'      => 'i',
				'longPrefix'  => 'include',
				'description' => 'Path to settings your environment variable. For example: app/init.php',
			],
			'help'    => [
				'prefix'      => 'h',
				'longPrefix'  => 'help',
				'description' => 'Prints a usage statement',
				'noValue'     => true,
			],
			'restart' => [
				'prefix'      => 'r',
				'longPrefix'  => 'restart',
				'description' => 'Restart worker for read new configuration',
				'noValue'     => true,
			],
			'log_level' => [
				'prefix'      => 'v',
				'longPrefix'  => 'log_level',
				'description' => 'Log level, available: ' . implode(', ', [Logger::TYPE_DEBUG, Logger::TYPE_INFO, Logger::TYPE_ERROR]),
				'defaultValue'=> Logger::TYPE_ERROR,
			],
		]);
	}
}