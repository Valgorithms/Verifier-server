<?php declare(strict_types=1);

// Parse every source file without executing configuration or opening sockets.
$root = dirname(__DIR__);
$paths = [$root . '/run.php', $root . '/runtests.php'];
foreach (['src', 'scripts', 'tests'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') $paths[] = $file->getPathname();
    }
}
sort($paths);
foreach ($paths as $path) {
    $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start PHP lint');
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        fwrite(STDERR, $output . $error);
        exit($status);
    }
}
printf("Parsed %d PHP files with PHP %s.\n", count($paths), PHP_VERSION);
