<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failed = false;
foreach (['src', 'tests', 'tools', 'examples', 'exemple'] as $directory) {
    if (!is_dir($root . '/' . $directory)) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            passthru(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()), $status);
            $failed = $failed || $status !== 0;
        }
    }
}
exit($failed ? 1 : 0);
