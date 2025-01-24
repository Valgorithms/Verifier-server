<?php
require 'vendor/autoload.php';

use PHPUnit\TextUI\Command;

$argv = [
    'phpunit',
    '--bootstrap', 'vendor/autoload.php',
    'tests'
];

// Capture the output
ob_start();
$command = new Command();
$command->run($argv, false);
$output = ob_get_clean();

// Print the output
echo $output;