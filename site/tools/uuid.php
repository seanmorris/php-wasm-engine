#!/usr/bin/env php
<?php

$bytes = random_bytes(16);
$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
$hex = bin2hex($bytes);

printf(
	'%s-%s-%s-%s-%s%s',
	substr($hex, 0, 8),
	substr($hex, 8, 4),
	substr($hex, 12, 4),
	substr($hex, 16, 4),
	substr($hex, 20, 4),
	substr($hex, 24)
);
