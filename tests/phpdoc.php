<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/check-phpdoc.php';

$checks = $failures = 0;

/**
 * Run a native tooling regression without adding CLI scripts to the WASM runtime.
 *
 * @param string $name Description printed with the result.
 * @param Closure $test Regression containing assertions.
 * @return void Records success or failure and prints the result.
 */
function phpDocTest(string $name, Closure $test): void
{
	global $checks, $failures;
	$checks++;

	try
	{
		$test();
		echo "PASS $name\n";
	}
	catch(Throwable $error)
	{
		$failures++;
		fwrite(STDERR, "FAIL $name: {$error->getMessage()}\n");
	}
}

/**
 * Fail a tooling regression when its expectation is not met.
 *
 * @param bool $condition Result of the expectation.
 * @param string $message Explanation included in a failed result.
 * @return void Returns when the assertion passes; otherwise throws an exception.
 */
function phpDocCheck(bool $condition, string $message = 'Assertion failed'): void
{
	if(!$condition) throw new RuntimeException($message);
}

phpDocTest('PHPDoc follows assigned closures and arrow functions through modifiers and attributes', function() {
	$source = <<<'PHP'
<?php
/** Copy a value.
 * @param mixed $value Value to copy.
 * @return mixed The copied value.
 */
$copy = function($value) { return $value; };
/** Copy a value.
 * @param mixed $value Value to copy.
 * @return mixed The copied value.
 */
$static = #[Example] static function &($value) { return $value; };
/** Copy a value.
 * @param mixed $value Value to copy.
 * @return mixed The copied value.
 */
$arrows['copy'] = static fn($value) => $value;
PHP;

	$functions = phpDocFunctions($source);
	phpDocCheck(count($functions) === 3);

	foreach($functions as $function)
	{
		phpDocCheck($function['doc'] !== '', 'assignment PHPDoc should reach its function');
		phpDocCheck($function['params'] === ['value']);
		phpDocCheck(phpDocErrors($function) === [], 'assignment PHPDoc should satisfy the checker');
	}
});

phpDocTest('PHPDoc does not borrow documentation from surrounding calls or array assignments', function() {
	$source = <<<'PHP'
<?php
/** Explain the registration, not its anonymous callback. */
register($callback = function() {});
/** Explain the table, not the value inside it. */
$callbacks = ['run' => function() {}];
/** Explain an earlier assignment. */
$other = null;
$callback = function() {};
PHP;

	$functions = phpDocFunctions($source);
	phpDocCheck(count($functions) === 3);

	foreach($functions as $function) phpDocCheck($function['doc'] === '', 'unrelated documentation was borrowed');
});

phpDocTest('PHPDoc keeps directly documented callbacks and named functions valid', function() {
	$source = <<<'PHP'
<?php
/** Complete work.
 * @return void Marks completion.
 */
function complete(): void {}
register(
    /** Complete work.
     * @return void Marks completion.
     */
    static function(): void {}
);
PHP;

	foreach(phpDocFunctions($source) as $function) phpDocCheck(phpDocErrors($function) === []);
});

phpDocTest('breakables documents both assigned callbacks with parameter and return descriptions', function() {
	$functions = phpDocFunctions(file_get_contents(dirname(__DIR__) . '/examples/breakables.php'));
	phpDocCheck(count($functions) === 3);

	foreach($functions as $function) phpDocCheck(phpDocErrors($function) === [], $function['name'] . ' PHPDoc');
});

echo "$checks PHPDoc tests, $failures failures\n";
exit($failures ? 1 : 0);
