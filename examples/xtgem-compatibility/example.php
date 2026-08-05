<?php

declare(strict_types=1);

use XtScript\Engine;
use XtScript\Examples\XtGemCompatibility\DemoXtGemAdapter;
use XtScript\Examples\XtGemCompatibility\XtGemCompatibilityPlugin;
use XtScript\Loader\ArrayLoader;

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
require is_file($autoload) ? $autoload : $root . '/tests/bootstrap.php';
require __DIR__ . '/XtGemAdapterInterface.php';
require __DIR__ . '/XtGemCompatibilityPlugin.php';
require __DIR__ . '/DemoXtGemAdapter.php';

$plugin = new XtGemCompatibilityPlugin(new DemoXtGemAdapter());
$engine = new Engine(new ArrayLoader(), plugins: [$plugin]);

$template = <<<'HTML'
<!--parser:xtscript--><!--/parser:xtscript-->
<!doctype html>
<title>XT tag plugin example</title>
<p>URL: <xt:url /></p>
<p>Browser: <xt:browser /></p>
<p>Random: <xt:random from="1" to="9" /></p>
<xt:filelist folder="/downloads" per_page="10" sort_type="updated" sort_dir="desc" />
<xt:blog per_page="5" />
<xt:forum />
<xt:auth />
<xt:widget id="demo">paired body</xt:widget>
<xt:third_party_future_tag foo="bar" />
HTML;

echo $engine->renderString($template, [
    'request_url' => '/downloads',
    'request_browser' => 'ExampleBrowser',
]);
