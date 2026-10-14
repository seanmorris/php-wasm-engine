<?php
declare(strict_types=1);

spl_autoload_register(
	/**
	 * Load an engine class from its namespace-relative PHP source file.
	 *
	 * @param string $class Fully qualified class name requested by PHP.
	 * @return void Requires an existing engine class file; unrelated or missing classes are left to
	 * other loaders.
	 */
	static function(string $class): void {
		$prefix = 'PhpWasmEngine\\';

		if(strncmp($class, $prefix, strlen($prefix)) !== 0)
		{
			return;
		}

		$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

		if(is_file($file))
		{
			require $file;
		}
	}
);
