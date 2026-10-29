<?php
declare(strict_types=1);

// The Node packer supplies an allow-list of verified staging files. Never walk the project here.
[$script, $specification, $output] = $argv;
$spec = json_decode(file_get_contents($specification), true, flags: JSON_THROW_ON_ERROR);
$archive = new Phar($output);
$archive->startBuffering();
foreach($spec['files'] as $member => $file) $archive->addFile($file, $member);
$entry = var_export('/' . $spec['entry'], true);
$archive->setStub("<?php require 'phar://' . __FILE__ . " . $entry . "; __HALT_COMPILER(); ?>");
$archive->setSignatureAlgorithm(Phar::SHA256);
$archive->stopBuffering();
