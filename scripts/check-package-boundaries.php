<?php

declare(strict_types=1);

$options = getopt('', ['package:']);
$package = realpath($options['package'] ?? '');
if ($package === false || !is_file($package.'/composer.json')) throw new InvalidArgumentException('Missing package');
$manifest = json_decode(file_get_contents($package.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$forbidden = ['corex', 'illuminate', 'laravel', 'billing', 'crm', 'common', 'stancl', 'postgres'];
foreach (array_keys($manifest['require']) as $dependency) foreach ($forbidden as $word) if (str_contains(strtolower($dependency), $word)) throw new RuntimeException('Forbidden dependency '.$dependency);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package.'/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;
    $contents = file_get_contents($file->getPathname());
    if (preg_match('/\b(?:CoreX|Illuminate|Stancl|Billium|FlexCRM)\\\\/i', $contents)) throw new RuntimeException('Forbidden import '.$file->getPathname());
}
echo "PASS neutral runtime graph and source imports\n";
