<?php
require_once __DIR__.'/vendor/autoload.php';
$finder = PhpCsFixer\Finder::create()->in([__DIR__.'/src',__DIR__.'/tests',__DIR__.'/examples'])->append([__DIR__.'/autoload.php',__FILE__]);
$config = SeanMorris\NoSaccadeStyle\ConfigFactory::recommended($finder)->setUsingCache(false);
return $config->setRules($config->getRules() + [
	'no_multiple_statements_per_line' => true
	, 'control_structure_braces' => true
	, 'binary_operator_spaces' => ['default' => 'single_space']
]);
