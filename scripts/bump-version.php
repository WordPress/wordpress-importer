#!/usr/bin/env php
<?php
declare(strict_types=1);

$usage = "Usage: composer run bump-version -- <version|major|minor|patch>\n";

if ($argc < 2) {
    fwrite(STDERR, $usage);
    exit(1);
}

$root = dirname(__DIR__);
$files = [
    'wordpress-importer.php' => $root . DIRECTORY_SEPARATOR . 'wordpress-importer.php',
    'src/wordpress-importer.php' => $root . DIRECTORY_SEPARATOR . 'src/wordpress-importer.php',
    'src/readme.txt' => $root . DIRECTORY_SEPARATOR . 'src/readme.txt',
];

preg_match('/\* Version: (\d+\.\d+\.\d+)/', file_get_contents($files['wordpress-importer.php']), $matches);
$current_version = $matches[1];

list($major, $minor, $patch) = explode('.', $current_version);
switch ($argv[1] ?? 'patch') {
    case 'patch':
        $patch++;
        break;
    case 'major':
        $major++;
        break;
    case 'minor':
        $minor++;
        break;
}
$next_version = "$major.$minor.$patch";

$files['wordpress-importer.php'] = preg_replace(
    '/(\* Version:\s+)([\d\.]+)[ ]*/',
    '${1}'.$next_version,
    file_get_contents($files['wordpress-importer.php'])
);
$files['src/wordpress-importer.php'] = preg_replace(
    '/(\* Version:\s+)([\d\.]+)[ ]*/',
    '${1}'.$next_version,
    file_get_contents($files['src/wordpress-importer.php'])
);
$files['src/readme.txt'] = preg_replace(
    '/(Stable tag:\s+)([\d\.]+)[ ]*/',
    '${1}'.$next_version,
    file_get_contents($files['src/readme.txt'])
);

foreach ($files as $file => $new_content) {
    file_put_contents($file, $new_content);
    echo "Updated $file\n";
}

echo "Bumped version from $current_version to $next_version\n";