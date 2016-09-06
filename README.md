#Daemon class for PHP

Simple daemon abstract class

##Requirements

* PHP 5.6+
* Composer

##Installation

```sh
composer require pnixx/daemon
```

##Usage

```php
class Server extends PNixx\Daemon\Daemon {

	public function run() {
		while( !$this->stop ) {
			//working process
		}
	}

	protected function onShutdown() {
		
	}
}
```

Example run background:

```sh
example/run -i /path/to/init.php --log_level debug --quiet
```

For list all commands, please use `--help` or `-h` argument.

For restart process after deploy use `--restart` or `-r` argument. A new process will be waiting finish all running processes.

See working example on [Delayed Job](https://github.com/PNixx/delayed_job)

##Signals

* `QUIT` - Wait finish processing then exit
* `TERM` / `INT` - Immediately kill processes then exit

##Author

Sergey Odintsov, [@pnixx](https://new.vk.com/djnixx)
