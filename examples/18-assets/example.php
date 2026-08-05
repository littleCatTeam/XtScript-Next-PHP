<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_autoload.php';

use XtScript\Engine;
use XtScript\Loader\ArrayLoader;

$engine = new Engine(new ArrayLoader());
$css = '/* comment */ .card { color: red; margin: 0  1rem; }';
$js = "// comment\nconst value = 1; // trailing\nconsole.log(value);";
$html = '<main><h1>Hello</h1><script>function x(){return 1;}</script></main>';

echo $engine->renderString('print_raw $css | minify_css', ['css' => $css]), "\n";
echo $engine->renderString('print_raw $js | minify_js', ['js' => $js]), "\n";
echo $engine->renderString('print_raw $html | beautify_html', ['html' => $html]), "\n";


echo $engine->renderString("beautify html
print_raw \$html
endbeautify", ['html' => $html]), "
";
echo $engine->renderString("minify css
print_raw \$css
endminify", ['css' => $css]), "
";
echo $engine->renderString("minify js
print_raw \$js
endminify", ['js' => $js]), "
";
