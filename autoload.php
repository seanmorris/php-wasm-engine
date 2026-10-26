<?php
declare(strict_types=1);

/** Register the neutral framework and its pre-1.0 aliases. Games register their own aliases. */
spl_autoload_register(/**
 * Apply the registered session callback.
 *
 * @param string $class Fully qualified class or factory name.
 * @return void Completes the operation.
 */
	static function(string $class): void {
		$aliases = require __DIR__ . '/legacy.php';
		$target = $aliases[$class] ?? $class;

		if(!str_starts_with($target, 'Phonics\\')) return;

		$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($target, 8)) . '.php';

		if(is_file($file)) require_once $file;

		foreach($aliases as $old => $canonical)
		{
			if($canonical === $target && !class_exists($old, false) && !interface_exists($old, false)
				&& (class_exists($target, false) || interface_exists($target, false))
			) class_alias($target, $old);
		}
	}
);
