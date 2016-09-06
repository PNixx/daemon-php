<?php
namespace PNixx\Daemon;

use Closure;
use Exception;

abstract class Daemon {

	/**
	 * @var string
	 */
	protected $pid_file;

	/**
	 * Process pid
	 * @var int
	 */
	protected $pid;

	/**
	 * Children pid for receive message
	 * @var array
	 */
	protected $pid_children = [];

	/**
	 * @var array
	 */
	protected $signal_queue = [];

	/**
	 * @var bool
	 */
	protected $stop = false;

	/**
	 * @var Cli
	 */
	protected $cli;

	/**
	 * @var Logger
	 */
	protected $logger;

	/**
	 * Daemon constructor.
	 * @param Cli $cli
	 */
	public function __construct(Cli $cli) {
		$this->pid_file = $cli->arguments->get('pid');
		$this->cli = $cli;

		//shutdown running process
		if( $cli->arguments->get('restart') && $this->isRunning() ) {
			posix_kill($this->pid, SIGQUIT);
			while( $this->isRunning() ) {
				usleep(500);
			}
		}

		//Initialize logger
		$this->logger = new Logger($cli, $cli->arguments->get('log'), $cli->arguments->get('log_level'));

		if( $this->isRunning() ) {
			$this->logger->error('Worker already running, pid: ' . $this->pid);
			exit(127);
		}

		//if quiet
		if( $cli->arguments->get('quiet') ) {
			$child_pid = pcntl_fork();
			if( $child_pid ) {
				exit;
			}
			posix_setsid();
		}

		//Bind signals
		pcntl_signal(SIGTERM, [$this, 'handler']);
		pcntl_signal(SIGINT, [$this, 'handler']);
		pcntl_signal(SIGQUIT, [$this, 'handler']);
		pcntl_signal(SIGCHLD, [$this, 'handler']);

		//Write pid
		file_put_contents($this->pid_file, getmypid());

		//include settings environment
		$include = $cli->arguments->get('include');
		if( $include ) {
			if( !file_exists($include) ) {
				$this->logger->error('Include file ' . $include . ' not found');
				exit(127);
			}
			require_once $cli->arguments->get('include');
		}

		set_error_handler($this->errorHandler());
		set_exception_handler($this->exceptionHandler());
		register_shutdown_function($this->shutdownHandler());
	}

	/**
	 * @return bool
	 */
	public function isRunning() {
		if( is_file($this->pid_file) ) {
			//get saved pid and check process
			$this->pid = file_get_contents($this->pid_file);
			if( posix_kill($this->pid, 0) ) {
				return true;
			}
			//remove incorrect pid file
			if( !unlink($this->pid_file) ) {
				exit(127);
			}
		}

		return false;
	}

	/**
	 * @return Closure
	 */
	protected function errorHandler() {
		return function ($type, $message, $file, $line) {
			switch( $type ) {
				case E_USER_ERROR:
					$type = 'Fatal Error';
					break;
				case E_USER_WARNING:
				case E_WARNING:
					$type = 'Warning';
					break;
				case E_USER_NOTICE:
				case E_NOTICE:
					$type = 'Notice';
					break;
				default:
					$type = 'Unknown Error';
					break;
			}
			//get last error
			throw new Exception(sprintf('%s: %s in %s:%d', $type, $message, $file, $line));
		};
	}

	/**
	 * @return Closure
	 */
	protected function exceptionHandler() {
		return function (Exception $e) {
			$this->exception($e);
		};
	}

	/**
	 * @param Exception $e
	 */
	public function exception(Exception $e) {
		$this->logger->error(sprintf('%s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
		if( $this->cli->arguments->get('log_level') == Logger::TYPE_DEBUG ) {
			$this->logger->error($e->getTraceAsString());
		}
	}

	/**
	 * @return Closure
	 */
	public function shutdownHandler() {
		return function () {
			$this->onShutdown();
		};
	}

	/**
	 * @return void
	 */
	abstract protected function onShutdown();

	/**
	 * @param int $signal
	 */
	public function handler($signal) {
		switch( $signal ) {
			case SIGQUIT:
				$this->stop = true;
				if( getmypid() == file_get_contents($this->pid_file) ) {
					$this->logger->debug('Signal quit, waiting close children process...');
				}
				break;
			case SIGTERM:
			case SIGINT:
				$this->stop = true;
				if( getmypid() == file_get_contents($this->pid_file) ) {
					$this->logger->debug('Kill all working process...' . getmypid());
					foreach( array_merge($this->pid_children, array_keys($this->signal_queue)) as $pid ) {
						posix_kill($pid, SIGTERM);
					}
					pcntl_wait($status);
				}
				exit;
				break;
			case SIGCHLD:
				$this->childHandler();
				break;
		}
	}

	/**
	 * @param null $pid
	 * @param null $status
	 * @return bool
	 */
	public function childHandler($pid = null, $status = null) {

		//If no pid is provided, that means we're getting the signal from the system.
		if( !$pid ) {
			$pid = pcntl_waitpid(-1, $status, WNOHANG);
		}

		//Make sure we get all of the exited children
		while( $pid > 0 ) {

			if( $pid && array_search($pid, $this->pid_children) !== false ) {
				$code = pcntl_wexitstatus($status);
				if( $code != 0 ) {
					$this->logger->debug($pid . ' exited with status ' . $code);
				}
				unset($this->pid_children[array_search($pid, $this->pid_children)]);
			} elseif( $pid ) {
				//Oh no, our job has finished before this parent process could even note that it had been launched!
				//Let's make note of it and handle it when the parent process is ready for it
				$this->logger->debug('Adding ' . $pid . ' to the signal queue...');
				$this->signal_queue[$pid] = $status;
			}
			$pid = pcntl_waitpid(-1, $status, WNOHANG);
		}

		return true;
	}

	/**
	 * Run async process
	 * @param \Closure $function
	 * @return bool
	 */
	public function async(\Closure $function) {

		//create a new process
		$pid = pcntl_fork();
		if( $pid < 0 ) {
			$this->logger->error('Error fork process');

			return false;
		} elseif( $pid ) {
			posix_setsid();

			//Parent process
			array_push($this->pid_children, $pid);

			if( isset($this->signal_queue[$pid]) ) {
				$this->logger->debug('Found ' . $pid . ' in the signal queue, processing it now');
				$this->childHandler($pid, $this->signal_queue[$pid]);
				unset($this->signal_queue[$pid]);
			}
		} else {
			$function();

			//close process
			exit(0);
		}

		return true;
	}

	/**
	 * Run daemon
	 * @return void
	 */
	abstract public function run();
}