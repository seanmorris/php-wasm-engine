<?php
require_once __DIR__ . '/vendor/autoload.php';

$finder = PhpCsFixer\Finder::create()->in(
	[__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples', __DIR__ . '/benchmarks']
)
	->append([__DIR__ . '/autoload.php',__DIR__ . '/scripts/check-phpdoc.php',__FILE__]);

$config = SeanMorris\NoSaccadeStyle\ConfigFactory::recommended($finder)->setUsingCache(false);
$rules = $config->getRules();
$rules[SeanMorris\NoSaccadeStyle\Fixers::ALLMAN_TABS] = ['max_line_length' => 120];

return $config->setRules(
	$rules + ['no_multiple_statements_per_line' => true
		, 'binary_operator_spaces' => ['default' => 'single_space']
	]
);
