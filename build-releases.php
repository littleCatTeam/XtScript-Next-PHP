<?php
declare(strict_types=1);

$root = __DIR__;
$composerPath = $root . DIRECTORY_SEPARATOR . 'composer.json';
$composerJson = @file_get_contents($composerPath);
if ($composerJson === false) {
    fwrite(STDERR, "Unable to read composer.json\n");
    exit(1);
}
$composer = json_decode($composerJson, true);
$version = $argv[1] ?? getenv('VERSION') ?: '';
if ($version === '' && is_array($composer) && !empty($composer['version'])) {
    $version = (string) $composer['version'];
}
if ($version === '') {
    $tag = trim(@shell_exec('git describe --tags --abbrev=0 2> /dev/null'));
    if ($tag !== '') {
        $version = $tag;
    }
}
$version = preg_replace('/[^0-9A-Za-z._-]+/', '', $version);
if ($version === '') {
    fwrite(STDERR, "Unable to determine version. Provide a git tag, VERSION env, or pass version as the first argument.\n");
    exit(1);
}

$pharFile = $root . DIRECTORY_SEPARATOR . "xtscript-v{$version}.phar";

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "Cannot create PHAR because phar.readonly is enabled.\n");
    fwrite(STDERR, "Run with: php -d phar.readonly=0 build-releases.php\n");
    exit(1);
}

@unlink($pharFile);

$phar = new Phar($pharFile, 0, 'xtscript.phar');
$phar->startBuffering();

$BS = chr(92);
$stub = "#!/usr/bin/env php\n"
      . "<?php\n"
      . "Phar::mapPhar(\"xtscript.phar\");\n"
      . "spl_autoload_register(function(string \$c): void {\n"
      . "    if (!str_starts_with(\$c, 'XtScript\\\\')) return;\n"
      . "    \$f2 = 'phar://xtscript.phar/' . strtr(substr(\$c, 9), '\\\\', '/') . '.php';\n"
      . "    if (is_file(\$f2)) require \$f2;\n"
      . "});\n"
      . "if (php_sapi_name() === 'cli' && (str_ends_with(\$_SERVER['argv'][0], '.phar') || basename(\$_SERVER['argv'][0]) === 'xtscript')) {\n"
      . "    require 'phar://xtscript.phar/bin/cli.php';\n"
      . "}\n"
      . "__HALT_COMPILER();\n";
$phar->setStub($stub);

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . 'src', FilesystemIterator::SKIP_DOTS)
);
$count = 0;
foreach ($it as $fi) {
    $fullPath = $fi->getPathname();
    if (!str_ends_with($fullPath, '.php')) {
        continue;
    }
    $localPath = strtr(substr($fullPath, strlen($root . DIRECTORY_SEPARATOR . 'src') + 1), [DIRECTORY_SEPARATOR => '/']);
    $phar->addFile($fullPath, $localPath);
    $count++;
}
echo "Added $count source files\n";

$cli = file_get_contents($root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'xtscript');
if ($cli === false) {
    fwrite(STDERR, "Unable to read bin/xtscript\n");
    exit(1);
}
$i = strpos($cli, '$root = dirname');
$j = strpos($cli, 'use XtScript\\Engine;');
if ($i !== false && $j !== false && $i < $j) {
    $cli = substr($cli, 0, $i) . substr($cli, $j);
}
$phar->addFromString('bin/cli.php', $cli);

$phar->stopBuffering();
$pharSize = round(filesize($pharFile) / 1024, 1);
echo "Built: {$pharFile} ({$pharSize} KB)\n";
