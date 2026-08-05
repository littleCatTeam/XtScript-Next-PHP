<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use XtScript\Cache\ArrayCompiledTemplateCache;
use XtScript\Cache\ArrayFragmentCache;
use XtScript\Contract\LoaderInterface;
use XtScript\Contract\PluginInterface;
use XtScript\Exception\SecurityException;
use XtScript\Engine;
use XtScript\ExecutionBackend;
use XtScript\EngineOptions;
use XtScript\Exception\PluginException;
use XtScript\Exception\TemplateNotFoundException;
use XtScript\Exception\XtScriptException;
use XtScript\Http\ArrayCookieStore;
use XtScript\Loader\ArrayLoader;
use XtScript\Loader\FilesystemLoader;
use XtScript\Plugin\BlockTagDefinition;
use XtScript\Profiler\ArrayProfiler;
use XtScript\Plugin\CookiePlugin;
use XtScript\Plugin\FunctionContext;
use XtScript\Plugin\FilterDefinition;
use XtScript\Plugin\FunctionDefinition;
use XtScript\Plugin\TagDefinition;
use XtScript\Plugin\TestDefinition;
use XtScript\Plugin\XtTagDefinition;
use XtScript\Plugin\PluginTrait;
use XtScript\Security\AllowListSecurityPolicy;
use XtScript\TemplateReference;
use XtScript\TemplateSource;


final class TestSiteLoader implements LoaderInterface
{
    /** @param array<string,string> $templates */
    public function __construct(private array $templates)
    {
    }

    public function exists(string $name, ?string $from = null): bool
    {
        return array_key_exists($this->resolve($name, $from), $this->templates);
    }

    public function load(string $name, ?string $from = null): TemplateSource
    {
        $resolved = $this->resolve($name, $from);
        if (!array_key_exists($resolved, $this->templates)) {
            throw new TemplateNotFoundException($name);
        }

        return new TemplateSource($resolved, $this->templates[$resolved], 'site-store://' . $resolved);
    }

    private function resolve(string $name, ?string $from): string
    {
        TemplateReference::assertAllowed($name, true);
        if (TemplateReference::splitDomainQualified($name) !== null) {
            return $this->normalize($name);
        }

        if ($from !== null) {
            TemplateReference::assertAllowed($from, true);
            $fromDomain = TemplateReference::splitDomainQualified($from);
            if ($fromDomain !== null) {
                $base = dirname($fromDomain['path']);
                $path = $this->normalize(($base === '.' ? '' : $base . '/') . $name);
                return $fromDomain['domain'] . '/' . $path;
            }

            $base = dirname($from);
            return $this->normalize(($base === '.' ? '' : $base . '/') . $name);
        }

        return $this->normalize($name);
    }

    private function normalize(string $name): string
    {
        $segments = [];
        foreach (preg_split('~[\\/]+~', trim($name)) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}

final class TestPlugin implements PluginInterface
{
    use PluginTrait;

    public function getName(): string
    {
        return 'test';
    }

    public function getFunctions(): iterable
    {
        yield new FunctionDefinition('test::upper', static fn (FunctionContext $context, array $args): string => strtoupper((string) ($args['$value'] ?? '')));
    }

    public function getTags(): iterable
    {
        yield new TagDefinition('shout', static fn (FunctionContext $context, string $arguments): string => strtoupper($arguments));
    }
}

final class TestFullPlugin implements PluginInterface
{
    use PluginTrait;

    public function getName(): string
    {
        return 'full-plugin-test';
    }

    public function getFunctions(): iterable
    {
        yield new FunctionDefinition('plugin_value', static fn (FunctionContext $context, array $args): string => 'plugin');
    }

    public function getTags(): iterable
    {
        yield new TagDefinition('plugin_bang', static fn (FunctionContext $context, string $arguments): string => '!');
    }

    public function getBlockTags(): iterable
    {
        yield new BlockTagDefinition(
            'plugin_wrap',
            'endplugin_wrap',
            static fn (FunctionContext $context, string $arguments, Closure $render): string => '<' . $render() . '>',
        );
    }

    public function getFilters(): iterable
    {
        yield new FilterDefinition('bracket', static fn (XtScript\Context $context, mixed $value, array $args, bool $defined): string => '[' . (string) $value . ']');
    }

    public function getTests(): iterable
    {
        yield new TestDefinition('longer_than', static fn (XtScript\Context $context, mixed $value, array $args, bool $defined): bool => $defined && strlen((string) $value) > (int) ($args[0] ?? 0));
    }

    public function getXtTags(): iterable
    {
        yield new XtTagDefinition('hello', static function (FunctionContext $context, string $name, array $attributes, ?string $body): string {
            return 'XT[' . ($attributes['name'] ?? 'guest') . ':' . ($body ?? '') . ']';
        });
    }

    public function getGlobals(): array
    {
        return ['site_name' => 'Global Site'];
    }
}

$tests = [];
$test = static function (string $name, callable $callback) use (&$tests): void {
    $tests[] = [$name, $callback];
};

$assertSame = static function (mixed $expected, mixed $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? $message . ': ' : '') . sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true)));
    }
};

$assertThrows = static function (string $class, callable $callback): void {
    try {
        $callback();
    } catch (Throwable $throwable) {
        if ($throwable instanceof $class) {
            return;
        }
        throw new RuntimeException(sprintf('Expected %s, got %s: %s', $class, $throwable::class, $throwable->getMessage()), previous: $throwable);
    }
    throw new RuntimeException(sprintf('Expected %s but nothing was thrown.', $class));
};

$test('assign/math/if/elseif', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
assign $a = 2
assign $b = 3
assign $sum = ($a + $b * 4)
if $sum < 10
print bad
elseif $sum == 14
print ok:$sum
else
print bad2
endif
XT;
    $assertSame('ok:14', $engine->renderString($source));
});

$test('foreach with key/value and escaping', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
foreach $items as $key => $item
print [$key]=$item,
endforeach
XT;
    $assertSame('[a]=&lt;x&gt;,[b]=y,', $engine->renderString($source, ['items' => ['a' => '<x>', 'b' => 'y']]));
});

$test('print_raw opt-out', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $assertSame('<b>x</b>', $engine->renderString('print_raw $html', ['html' => '<b>x</b>']));
});

$test('include and legacy @function alias', static function () use ($assertSame): void {
    $loader = new ArrayLoader([
        'main.xt' => "include partial.xt\nassign \$who = <Admin>\ncall @greet \$name=\$who;",
        'partial.xt' => "function greet \$name=World;\nprint Hello \$name!\nendfunction",
    ]);
    $engine = new Engine($loader);
    $assertSame('Hello &lt;Admin&gt;!', $engine->render('main.xt'));
});

$test('filesystem loader relative include', static function () use ($assertSame): void {
    $engine = new Engine(new FilesystemLoader(__DIR__ . '/fixtures'));
    $assertSame('Hello &lt;Admin&gt;!', $engine->render('main.xt'));
});

$test('filesystem loader blocks traversal', static function () use ($assertThrows): void {
    $loader = new FilesystemLoader(__DIR__ . '/fixtures');
    $assertThrows(TemplateNotFoundException::class, static fn () => $loader->load('../run.php'));
});

$test('user function return value', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
function add $a=0;$b=0;
return ($a + $b)
endfunction
assign $result = call add $a=7;$b=5;
print $result
XT;
    $assertSame('12', $engine->renderString($source));
});

$test('custom function and tag plugin', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader(), plugins: [new TestPlugin()]);
    $source = <<<'XT'
assign $x = call test::upper $value=hello;
print $x-
shout plugin tag
XT;
    $assertSame('HELLO-PLUGIN TAG', $engine->renderString($source));
});

$test('cookie plugin is injectable and namespaced', static function () use ($assertSame): void {
    $store = new ArrayCookieStore();
    $engine = new Engine(new ArrayLoader(), plugins: [new CookiePlugin($store)]);
    $source = <<<'XT'
call cookie::set $name=foo;$val=<bar>;
print call cookie::get $name=foo;
XT;
    $assertSame('&lt;bar&gt;', $engine->renderString($source, name: 'site-a.xt'));
    $assertSame('<bar>', $store->get('xtscript', 'foo'));
});

$test('duplicate plugin registration rejected', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader(), plugins: [new TestPlugin()]);
    $assertThrows(PluginException::class, static fn () => $engine->addPlugin(new TestPlugin()));
});

$test('legacy substr length is optional like PHP substr', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $assertSame('cdef', $engine->renderString('call substr $val=abcdef;$start=2'));
    $assertSame('cd', $engine->renderString('call substr $val=abcdef;$start=2;$length=2'));
});

$test('goto jumps forward to label and continues after it', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
goto @marked
print Hello
@marked
print World
XT;
    $assertSame('World', $engine->renderString($source));
});

$test('goto loop is bounded by instruction budget', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader(), new EngineOptions(maxInstructions: 20));
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString("@loop\ngoto @loop"));
});

$test('output budget', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader(), new EngineOptions(maxOutputBytes: 5));
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString('print 123456'));
});

$test('undefined function is rejected', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader());
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString('call no_such_function'));
});

$test('source utility uses loader boundary', static function () use ($assertSame): void {
    $loader = new ArrayLoader(['main.xt' => 'print call source $file=data.txt;', 'data.txt' => '<data>']);
    $engine = new Engine($loader);
    $assertSame('&lt;data&gt;', $engine->render('main.xt'));
});

$test('legacy parser wrappers and get_or_default', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
<!--parser:xtscript-->
get name
get_or_default missing;fallback
print $name/$missing
<!--/parser:xtscript-->
XT;
    $assertSame('Alice/fallback', $engine->renderString($source, ['name' => 'Alice']));
});

$test('parser wrappers support spacing case inline HTML and multiple blocks', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'HTML'
<header>$name</header><!-- PARSER : XTSCRIPT -->print $name<!-- /parser : xtscript --><main>middle</main><!--parser:xtscript-->print_raw OK<!--/PARSER:XTSCRIPT--><footer>done</footer>
HTML;

    $assertSame('<header>$name</header>&lt;Admin&gt;<main>middle</main>OK<footer>done</footer>', $engine->renderString($source, ['name' => '<Admin>']));
});

$test('parser wrapper syntax is balanced and non-nesting', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader());
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString('<!--/parser:xtscript-->'));
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString('<!--parser:xtscript-->print x'));
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString('<!--parser:xtscript--><!-- parser : xtscript -->print x<!--/parser:xtscript-->'));
});

$test('quoted semicolon in function default', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
function demo $value="a;b";
return $value
endfunction
print call demo
XT;
    $assertSame('a;b', $engine->renderString($source));
});

$test('duplicate user function rejected', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader());
    $source = "function a\nendfunction\nfunction a\nendfunction";
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString($source));
});

$test('context per-value budget', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader(), new EngineOptions(maxContextValueBytes: 8));
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString('print $value', ['value' => '123456789']));
});

$test('context aggregate budget on assignment', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader(), new EngineOptions(maxContextBytes: 20, maxContextValueBytes: 20));
    $source = "assign \$a = 1234567890\nassign \$b = 1234567890";
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString($source));
});

$test('top-level return rejected', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader());
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString('return nope'));
});

$test('return inside included top-level template cannot unwind caller function', static function () use ($assertThrows): void {
    $loader = new ArrayLoader([
        'main.xt' => "function demo\ninclude child.xt\nendfunction\ncall demo",
        'child.xt' => 'return leaked',
    ]);
    $engine = new Engine($loader);
    $assertThrows(XtScriptException::class, static fn () => $engine->render('main.xt'));
});

$test('duplicate label rejected', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader());
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString("@a\n@a"));
});

$test('missing include preserves TemplateNotFoundException', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader(['main.xt' => 'include missing.xt']));
    $assertThrows(TemplateNotFoundException::class, static fn () => $engine->render('main.xt'));
});

$test('plugin failure preserves PluginException', static function () use ($assertThrows): void {
    $plugin = new class implements PluginInterface {
        use PluginTrait;
        public function getName(): string { return 'thrower'; }
        public function getFunctions(): iterable {
            yield new FunctionDefinition('thrower::fail', static function (FunctionContext $context, array $args): never {
                throw new RuntimeException('boom');
            });
        }
        public function getTags(): iterable { return []; }
    };
    $engine = new Engine(new ArrayLoader(), plugins: [$plugin]);
    $assertThrows(PluginException::class, static fn () => $engine->renderString('call thrower::fail'));
});

$test('legacy file_get_contents uses loader boundary', static function () use ($assertSame): void {
    $loader = new ArrayLoader(['main.xt' => 'print call file_get_contents $file=data.txt;', 'data.txt' => '<safe>']);
    $engine = new Engine($loader);
    $assertSame('&lt;safe&gt;', $engine->render('main.xt'));
});

$test('execution_time utility is available', static function (): void {
    $engine = new Engine(new ArrayLoader());
    $result = $engine->renderString('print call execution_time');
    if (preg_match('/^\\d+\\.\\d{6}s\\.$/D', $result) !== 1) {
        throw new RuntimeException('Unexpected execution_time format: ' . $result);
    }
});

$test('context update does not double-charge variable name', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader(), new EngineOptions(maxContextBytes: 26, maxContextValueBytes: 16));
    $source = "assign \$longname = 1\nassign \$longname = 2\nprint \$longname";
    $assertSame('2', $engine->renderString($source));
});

$test('legacy multiline double-brace value', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = "assign \$value = {{first\nsecond}}\nprint \$value";
    $assertSame("first\nsecond", $engine->renderString($source));
});

$test('NUL byte source rejected', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader());
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString("print a\0b"));
});

$test('boolean operators short-circuit safely', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
if true or (1 / 0)
print A
endif
if false and (1 / 0)
print bad
else
print B
endif
XT;
    $assertSame('AB', $engine->renderString($source));
});

$test('filesystem loader blocks symlink escape', static function () use ($assertThrows): void {
    $base = sys_get_temp_dir() . '/xtscript-' . bin2hex(random_bytes(6));
    $root = $base . '/root';
    $outside = $base . '/outside';
    mkdir($root, 0700, true);
    mkdir($outside, 0700, true);
    file_put_contents($outside . '/secret.xt', 'print secret');
    try {
        if (!symlink($outside . '/secret.xt', $root . '/link.xt')) {
            throw new RuntimeException('Unable to create symlink fixture.');
        }
        $loader = new FilesystemLoader($root);
        $assertThrows(TemplateNotFoundException::class, static fn () => $loader->load('link.xt'));
    } finally {
        @unlink($root . '/link.xt');
        @unlink($outside . '/secret.xt');
        @rmdir($root);
        @rmdir($outside);
        @rmdir($base);
    }
});

$test('filesystem loader accepts absolute path inside root', static function () use ($assertSame): void {
    $loader = new FilesystemLoader(__DIR__ . '/fixtures');
    $engine = new Engine($loader);
    $path = realpath(__DIR__ . '/fixtures/main.xt');
    if ($path === false) {
        throw new RuntimeException('Fixture path missing.');
    }
    $assertSame('Hello &lt;Admin&gt;!', $engine->render($path));
});

$test('recursive include is bounded', static function () use ($assertThrows): void {
    $loader = new ArrayLoader(['loop.xt' => 'include loop.xt']);
    $engine = new Engine($loader, new EngineOptions(maxIncludeDepth: 3));
    $assertThrows(XtScriptException::class, static fn () => $engine->render('loop.xt'));
});

$test('recursive function is bounded', static function () use ($assertThrows): void {
    $source = "function loop\ncall loop\nendfunction\ncall loop";
    $engine = new Engine(new ArrayLoader(), new EngineOptions(maxFunctionDepth: 3));
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString($source));
});

$test('foreach loop budget is enforced', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader(), new EngineOptions(maxLoopIterations: 2));
    $source = "foreach \$items as \$item\nprint \$item\nendforeach";
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString($source, ['items' => [1, 2, 3]]));
});

$test('compiled cache follows loader source changes', static function () use ($assertSame): void {
    $loader = new ArrayLoader(['main.xt' => 'print one']);
    $engine = new Engine($loader);
    $assertSame('one', $engine->render('main.xt'));
    $loader->set('main.xt', 'print two');
    $assertSame('two', $engine->render('main.xt'));
});

$test('source byte budget is enforced before parsing', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader(), new EngineOptions(maxSourceBytes: 8));
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString('print 123456789'));
});

$test('autoEscape can be disabled explicitly', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader(), new EngineOptions(autoEscape: false));
    $assertSame('<b>x</b>', $engine->renderString('print $html', ['html' => '<b>x</b>']));
});

$test('strict comparison keeps types', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = "if \$value === 1\nprint bad\nelse\nprint ok\nendif";
    $assertSame('ok', $engine->renderString($source, ['value' => '1']));
});

$test('cookie namespace can isolate tenants on the same host', static function () use ($assertSame): void {
    $store = new ArrayCookieStore();
    $siteA = new Engine(new ArrayLoader(), plugins: [new CookiePlugin($store, 'site-a')]);
    $siteB = new Engine(new ArrayLoader(), plugins: [new CookiePlugin($store, 'site-b')]);
    $siteA->renderString('call cookie::set $name=foo;$val=A;');
    $assertSame('A', $siteA->renderString('print call cookie::get $name=foo;'));
    $assertSame('', $siteB->renderString('print call cookie::get $name=foo;'));
});

$test('function preserves output emitted before return', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
function demo
print A
if true
print B
return C
endif
print unreachable
endfunction
call demo
XT;
    $assertSame('ABC', $engine->renderString($source));
});

$test('modern filter pipelines compose and default handles undefined values', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
print $name | trim | upper
print :
print $missing | default("Guest")
XT;
    $assertSame('ALICE:Guest', $engine->renderString($source, ['name' => '  alice  ']));
});

$test('built-in tests support defined empty iterable and arguments', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
if $missing is not defined
print A
endif
if $empty is empty
print B
endif
if $items is iterable and 2 in $items
print C
endif
if 4 is divisible_by(2)
print D
endif
XT;
    $assertSame('ABCD', $engine->renderString($source, ['empty' => [], 'items' => [1, 2, 3]]));
});

$test('modern membership concat and not-in operators', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
if "bc" in "abcd" and 9 not in $items
print "A" ~ "B"
endif
XT;
    $assertSame('AB', $engine->renderString($source, ['items' => [1, 2, 3]]));
});

$test('modern collection system functions integrate with filters', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
assign $items = call range $start=1;$end=4;
print $items | join("-")
print :
print call cycle $values=$items;$index=5;
XT;
    $assertSame('1-2-3-4:2', $engine->renderString($source));
});

$test('data system functions cover split slice json and type checks', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
assign $parts = call split $val=a,b,c;$delimiter=,;
assign $tail = $parts | slice(1)
print $tail | join("+")
if $tail is sequence
print :S
endif
print :
print_raw call json_encode $val=$tail;
XT;
    $assertSame('b+c:S:["b","c"]', $engine->renderString($source));
});

$test('PluginInterface provides functions filters tests tags block tags XT tags and globals', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader(), plugins: [new TestFullPlugin()]);
    $source = <<<'XT'
print $site_name | bracket
print :
print call plugin_value
plugin_bang
plugin_wrap
print W
endplugin_wrap
if $site_name is longer_than(5)
print :yes
endif
XT;
    $assertSame('[Global Site]:plugin!<W>:yes', $engine->renderString($source));
    $assertSame('XT[Cat:body]', $engine->renderString('<!--parser:xtscript--><!--/parser:xtscript--><xt:hello name="Cat">body</xt:hello>'));
    $assertSame(['hello'], $engine->xtTagNames());
    $assertSame(['bracket'], array_values(array_intersect(['bracket'], $engine->filterNames())));
    $assertSame(['longer_than'], array_values(array_intersect(['longer_than'], $engine->testNames())));
    $assertSame(['site_name'], $engine->globalNames());
});

$test('render variables override globals without mutating engine globals', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader(), globals: ['site_name' => 'Global']);
    $assertSame('Local', $engine->renderString('print $site_name', ['site_name' => 'Local']));
    $assertSame('Global', $engine->renderString('print $site_name'));
});

$test('unknown filter and test are rejected', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader());
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString('print $value | no_such_filter', ['value' => 'x']));
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString("if \$value is no_such_test\nprint bad\nendif", ['value' => 'x']));
});

$test('range function is bounded before allocating a large collection', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader());
    $assertThrows(PluginException::class, static fn () => $engine->renderString('call range $start=0;$end=100001;'));
});

$test('duplicate globals are rejected atomically', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader(), plugins: [new TestFullPlugin()]);
    $assertThrows(PluginException::class, static fn () => $engine->addGlobal('site_name', 'second'));
});


$test('template inheritance supports blocks parent and three levels', static function () use ($assertSame): void {
    $loader = new ArrayLoader([
        'base.xt' => "print_raw <main>\nblock content\nprint Base\nendblock\nprint_raw </main>",
        'middle.xt' => "extends base.xt\nblock content\nparent\nprint Mid\nendblock",
        'child.xt' => "extends middle.xt\nblock content\nprint Child\nparent\nendblock",
    ]);
    $engine = new Engine($loader);
    $assertSame('<main>ChildBaseMid</main>', $engine->render('child.xt'));
});

$test('blade style section and yield aliases work with extends', static function () use ($assertSame): void {
    $loader = new ArrayLoader([
        'layout.xt' => "print_raw <title>\nyield title\nprint_raw </title>",
        'page.xt' => "extends layout.xt\nsection title\nprint Hello\nendsection",
    ]);
    $engine = new Engine($loader);
    $assertSame('<title>Hello</title>', $engine->render('page.xt'));
});

$test('component default and named slots render as safe captured markup', static function () use ($assertSame): void {
    $loader = new ArrayLoader([
        'card.xt' => "print_raw <article>\nprint \$slots.header\nprint_raw |\nprint \$slot\nprint_raw </article>",
        'page.xt' => "component card.xt\nslot header\nprint_raw <b>Head</b>\nendslot\nprint_raw <em>Body</em>\nendcomponent",
    ]);
    $engine = new Engine($loader);
    $assertSame('<article><b>Head</b>|<em>Body</em></article>', $engine->render('page.xt'));
});

$test('component arguments are isolated in a child scope', static function () use ($assertSame): void {
    $loader = new ArrayLoader([
        'hello.xt' => 'print $name',
        'page.xt' => "component hello.xt \$name=\"Component\";\nendcomponent\nprint :\$name",
    ]);
    $engine = new Engine($loader);
    $assertSame('Component:Caller', $engine->render('page.xt', ['name' => 'Caller']));
});

$test('foreach exposes twig and blade style loop metadata and else', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
foreach $items as $item
print $loop.index/$loop.index0/$loop.remaining/$loop.first/$loop.last:$item;
endforeach
foreach $empty as $item
print bad
else
print EMPTY
endforeach
XT;
    $assertSame('1/0/1/1/:A2/1/0//1:BEMPTY', $engine->renderString($source, ['items' => ['A', 'B'], 'empty' => []]));
});

$test('for alias break and continue are bounded to the active loop', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
for $item in $items
if $item == 2
continue
endif
if $item == 4
break
endif
print $item
endfor
XT;
    $assertSame('13', $engine->renderString($source, ['items' => [1, 2, 3, 4, 5]]));
});

$test('capture creates safe reusable markup', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
capture $html
print_raw <strong>safe</strong>
endcapture
print $html
XT;
    $assertSame('<strong>safe</strong>', $engine->renderString($source));
});

$test('raw and escape filters integrate with auto escaping', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $assertSame('<b>x</b>|&lt;b&gt;x&lt;/b&gt;', $engine->renderString("print \$html | raw\nprint_raw |\nprint \$html | escape", ['html' => '<b>x</b>']));
});

$test('fragment cache is optional and pluggable', static function () use ($assertSame): void {
    $cache = new ArrayFragmentCache();
    $engine = new Engine(new ArrayLoader(), fragmentCache: $cache);
    $source = "cache \"box\";60\nprint \$value\nendcache";
    $assertSame('one', $engine->renderString($source, ['value' => 'one'], 'cached.xt'));
    $assertSame('one', $engine->renderString($source, ['value' => 'two'], 'cached.xt'));

    $uncached = new Engine(new ArrayLoader());
    $assertSame('two', $uncached->renderString($source, ['value' => 'two'], 'cached.xt'));
});


$test('with scope do and once provide twig blade style utility blocks', static function () use ($assertSame): void {
    $calls = 0;
    $engine = new Engine(new ArrayLoader());
    $engine->addFunction(new FunctionDefinition('track', static function (FunctionContext $context, array $args) use (&$calls): string {
        ++$calls;
        return 'NOISE';
    }));
    $source = <<<'XT'
with $name="inner";
print $name
endwith
print :$name:
do call track
once "shared"
print ONE
endonce
once "shared"
print BAD
endonce
XT;
    $assertSame('inner:outer:ONE', $engine->renderString($source, ['name' => 'outer']));
    $assertSame(1, $calls);
});

$test('custom block tags can lazily render their body', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $engine->addBlockTag(new BlockTagDefinition(
        'twice',
        'endtwice',
        static fn (FunctionContext $context, string $arguments, Closure $render): string => $render() . $render(),
    ));
    $assertSame('XX', $engine->renderString("twice\nprint X\nendtwice"));
});

$test('direct capability registration supports functions filters tests and tags', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $engine->addFunction(new FunctionDefinition('hello_direct', static fn (FunctionContext $context, array $args): string => 'hello'));
    $engine->addFilter(new FilterDefinition('suffix_direct', static fn (XtScript\Context $context, mixed $value, array $args, bool $defined): string => (string) $value . '!'));
    $engine->addTest(new TestDefinition('hello_direct', static fn (XtScript\Context $context, mixed $value, array $args, bool $defined): bool => $defined && $value === 'hello'));
    $engine->addTag(new TagDefinition('bang_direct', static fn (FunctionContext $context, string $arguments): string => '!'));
    $source = <<<'XT'
assign $value = call hello_direct
print $value | suffix_direct
if $value is hello_direct
bang_direct
endif
XT;
    $assertSame('hello!!', $engine->renderString($source));
});

$test('security policy can restrict filters functions tags and templates', static function () use ($assertThrows): void {
    $policy = new AllowListSecurityPolicy(
        functions: [],
        filters: ['upper'],
        tests: null,
        tags: ['print'],
        templates: ['safe.xt'],
    );
    $loader = new ArrayLoader(['safe.xt' => 'print $name | upper', 'bad.xt' => 'print bad']);
    $engine = new Engine($loader, securityPolicy: $policy);
    if ($engine->render('safe.xt', ['name' => 'ok']) !== 'OK') {
        throw new RuntimeException('Allowed policy path failed.');
    }
    $assertThrows(SecurityException::class, static fn () => $engine->render('bad.xt'));
    $assertThrows(SecurityException::class, static fn () => $engine->renderString('print $name | lower', ['name' => 'x']));
    $assertThrows(SecurityException::class, static fn () => $engine->renderString('call strlen $val=x;'));
});

$test('inheritance cycles are rejected deterministically', static function () use ($assertThrows): void {
    $loader = new ArrayLoader(['a.xt' => 'extends b.xt', 'b.xt' => 'extends a.xt']);
    $engine = new Engine($loader);
    $assertThrows(XtScriptException::class, static fn () => $engine->render('a.xt'));
});


$test('import loads functions without emitting template body', static function () use ($assertSame): void {
    $loader = new ArrayLoader([
        'functions.xt' => "print SHOULD_NOT_RENDER\nfunction greet \$name=World;\nreturn Hi \$name\nendfunction",
        'page.xt' => "import functions.xt as util\nprint call util@greet \$name=Cat;",
    ]);
    $engine = new Engine($loader);
    $assertSame('Hi Cat', $engine->render('page.xt'));
});

$test('imported function namespace resolves sibling functions lexically', static function () use ($assertSame): void {
    $loader = new ArrayLoader([
        'library.xt' => <<<'XT'
function decorate $value=""
    return decorated:$value
endfunction
function greet $name="World"
    return call decorate $value=$name
endfunction
XT,
        'page.xt' => "import library.xt as util\nprint call util@greet \$name=Cat;",
    ]);
    $engine = new Engine($loader);
    $assertSame('decorated:Cat', $engine->render('page.xt'));
});

$test('function namespaces isolate duplicate helper names', static function () use ($assertSame): void {
    $loader = new ArrayLoader([
        'a.xt' => <<<'XT'
function helper
    return A
endfunction
function render
    return call helper
endfunction
XT,
        'b.xt' => <<<'XT'
function helper
    return B
endfunction
function render
    return call helper
endfunction
XT,
        'page.xt' => "import a.xt as a\nimport b.xt as b\nprint call a@render\nprint call b@render",
    ]);
    $engine = new Engine($loader);
    $assertSame('AB', $engine->render('page.xt'));
});

$test('nested function imports compose namespaces lexically', static function () use ($assertSame): void {
    $loader = new ArrayLoader([
        'helpers.xt' => <<<'XT'
function decorate $value=""
    return <$value>
endfunction
XT,
        'forms.xt' => <<<'XT'
import helpers.xt as h
function input $name="email"
    return call h@decorate $value=$name
endfunction
XT,
        'page.xt' => "import forms.xt as forms\nprint call forms@input \$name=user;",
    ]);
    $engine = new Engine($loader);
    $assertSame('&lt;user&gt;', $engine->render('page.xt'));
});

$test('namespaced function libraries cannot call caller private user functions', static function () use ($assertThrows): void {
    $loader = new ArrayLoader([
        'library.xt' => <<<'XT'
function render
    return call private_helper
endfunction
XT,
        'page.xt' => <<<'XT'
function private_helper
    return SECRET
endfunction
import library.xt as lib
print call lib@render
XT,
    ]);
    $engine = new Engine($loader);
    $assertThrows(XtScriptException::class, static fn () => $engine->render('page.xt'));
});

$test('circular function-library imports are rejected', static function () use ($assertThrows): void {
    $loader = new ArrayLoader([
        'a.xt' => "import b.xt as b\nfunction a\nreturn a\nendfunction",
        'b.xt' => "import a.xt as a\nfunction b\nreturn b\nendfunction",
        'page.xt' => 'import a.xt as lib',
    ]);
    $engine = new Engine($loader);
    $assertThrows(XtScriptException::class, static fn () => $engine->render('page.xt'));
});

$test('apply filters a captured block', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
apply trim | upper
print_raw   hello world  
endapply
XT;
    $assertSame('HELLO WORLD', $engine->renderString($source));
});

$test('autoescape can be changed for a lexical block and restores afterwards', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
autoescape off
print $html
autoescape on
print $html
endautoescape
print $html
endautoescape
print $html
XT;
    $assertSame('<b>x</b>&lt;b&gt;x&lt;/b&gt;<b>x</b>&lt;b&gt;x&lt;/b&gt;', $engine->renderString($source, ['html' => '<b>x</b>']));
});

$test('import obeys template security policy', static function () use ($assertThrows): void {
    $policy = new AllowListSecurityPolicy(tags: ['import', 'print'], templates: ['page.xt']);
    $loader = new ArrayLoader(['page.xt' => 'import secret.xt', 'secret.xt' => 'function x\nreturn x\nendfunction']);
    $engine = new Engine($loader, securityPolicy: $policy);
    $assertThrows(SecurityException::class, static fn () => $engine->render('page.xt'));
});


$test('capture output is bounded independently from final output', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader(), new EngineOptions(maxCaptureBytes: 5, maxOutputBytes: 1024));
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString("capture \$value\nprint_raw 123456\nendcapture"));
});

$test('template inheritance depth is bounded even without a cycle', static function () use ($assertThrows): void {
    $loader = new ArrayLoader([
        'a.xt' => 'extends b.xt',
        'b.xt' => 'extends c.xt',
        'c.xt' => 'print root',
    ]);
    $engine = new Engine($loader, new EngineOptions(maxIncludeDepth: 1));
    $assertThrows(XtScriptException::class, static fn () => $engine->render('a.xt'));
});

$test('traversable membership is bounded before exhausting an iterator', static function () use ($assertThrows): void {
    $generator = static function (): Generator {
        yield 1;
        yield 2;
        yield 3;
        yield 99;
    };
    $engine = new Engine(new ArrayLoader(), new EngineOptions(maxLoopIterations: 2));
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString("if 99 in \$items\nprint yes\nendif", ['$items' => $generator()]));
});

$test('custom block tag boundaries cannot shadow built in syntax', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader());
    $assertThrows(PluginException::class, static fn () => $engine->addBlockTag(new BlockTagDefinition(
        'danger',
        'endif',
        static fn (FunctionContext $context, string $arguments, Closure $renderBody): string => $renderBody(),
    )));
});

$test('custom block tag failures are normalized as PluginException', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader());
    $engine->addBlockTag(new BlockTagDefinition(
        'explode_block',
        'endexplode_block',
        static function (FunctionContext $context, string $arguments, Closure $renderBody): string {
            throw new RuntimeException('boom');
        },
    ));
    $assertThrows(PluginException::class, static fn () => $engine->renderString("explode_block\nprint x\nendexplode_block"));
});


$test('verbatim preserves literal XtScript-looking content without parsing it', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
print before|
verbatim
if $danger
    print {{ literal }}
endif
endverbatim
print after
XT;
    $assertSame("before|if \$danger\n    print {{ literal }}\nendif\nafter", $engine->renderString($source));
});

$test('named stacks support prepend push and later stack rendering', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
push scripts
print_raw B
endpush
prepend scripts
print_raw A
endprepend
stack scripts
XT;
    $assertSame('AB', $engine->renderString($source));
});

$test('named stacks are shared across component rendering in one render state', static function () use ($assertSame): void {
    $loader = new ArrayLoader([
        'component.xt' => "push scripts\nprint_raw <script>x</script>\nendpush\nprint body",
        'page.xt' => "component component.xt\nendcomponent\nstack scripts",
    ]);
    $engine = new Engine($loader);
    $assertSame('body<script>x</script>', $engine->render('page.xt'));
});

$test('named stack memory is bounded independently from final output', static function () use ($assertThrows): void {
    $engine = new Engine(new ArrayLoader(), new EngineOptions(maxStackBytes: 5, maxCaptureBytes: 1024));
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString("push scripts\nprint_raw 123456\nendpush"));
});


$test('null coalescing is short circuited and preserves defined falsey values', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
print $missing ?? "Guest"
print_raw |
print $zero ?? 9
print_raw |
print $null ?? "fallback"
XT;
    $assertSame('Guest|0|fallback', $engine->renderString($source, ['zero' => 0, 'null' => null]));
});

$test('ternary expressions short circuit inactive branches', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
print $enabled ? "yes" : "no"
print_raw |
print true ? "safe" : (1 / 0)
XT;
    $assertSame('yes|safe', $engine->renderString($source, ['enabled' => true]));
});

$test('array and map literals work with filters dotted access and membership', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $source = <<<'XT'
assign $items = [1, 2, 3]
assign $user = {"name": "Cat", role: "admin"}
print $items | join("-")
print_raw |
print $user.name
print_raw |
if 2 in $items
print yes
endif
XT;
    $assertSame('1-2-3|Cat|yes', $engine->renderString($source));
});


$test('persistent compiled cache can be shared across engine instances', static function () use ($assertSame): void {
    $cache = new ArrayCompiledTemplateCache();
    $loader = new ArrayLoader(['page.xt' => 'print $name']);
    $first = new Engine($loader, compiledTemplateCache: $cache);
    $assertSame('one', $first->render('page.xt', ['name' => 'one']));
    $assertSame(1, $cache->count());

    $second = new Engine($loader, compiledTemplateCache: $cache);
    $assertSame('two', $second->render('page.xt', ['name' => 'two']));
    $assertSame(1, $cache->count());
});


$test('profiler is opt in and reports compile render and cache hits', static function () use ($assertSame): void {
    $profiler = new ArrayProfiler();
    $engine = new Engine(new ArrayLoader(['page.xt' => 'print $name']), profiler: $profiler);
    $assertSame('one', $engine->render('page.xt', ['name' => 'one']));
    $assertSame('two', $engine->render('page.xt', ['name' => 'two']));
    $events = array_column($profiler->events(), 'event');
    if (!in_array('compile', $events, true) || !in_array('render', $events, true) || !in_array('compile_cache_hit', $events, true)) {
        throw new RuntimeException('Expected profiler events were not recorded.');
    }
});


$test('php eval backend matches evaluator for eligible legacy templates', static function () use ($assertSame): void {
    $source = <<<'XT'
foreach $items as $item
if ($item % 2 == 0)
print $item
else
print ($item + 1)
endif
endforeach
XT;
    $variables = ['items' => range(1, 30)];
    $evaluator = new Engine(new ArrayLoader(), new EngineOptions(executionBackend: ExecutionBackend::Evaluator));
    $php = new Engine(new ArrayLoader(), new EngineOptions(executionBackend: ExecutionBackend::PhpEval));
    $assertSame($evaluator->renderString($source, $variables), $php->renderString($source, $variables));
});

$test('php eval backend falls back for unsupported legacy features', static function () use ($assertSame): void {
    $loader = new ArrayLoader([
        'part.xt' => 'print OK',
        'page.xt' => 'include part.xt',
    ]);
    $engine = new Engine($loader, new EngineOptions(executionBackend: ExecutionBackend::PhpEval));
    $assertSame('OK', $engine->render('page.xt'));
});

$test('security policy forces evaluator path even when php eval is requested', static function () use ($assertSame): void {
    $policy = new AllowListSecurityPolicy(tags: ['print'], templates: ['page.xt']);
    $engine = new Engine(
        new ArrayLoader(['page.xt' => 'print $name']),
        new EngineOptions(executionBackend: ExecutionBackend::PhpEval),
        securityPolicy: $policy,
    );
    $assertSame('safe', $engine->render('page.xt', ['name' => 'safe']));
});


$test('redundant modern system function aliases stay removed while legacy equivalents remain', static function (): void {
    $engine = new Engine(new ArrayLoader());
    $names = array_fill_keys($engine->functionNames(), true);
    foreach (['upper', 'lower', 'length', 'slice', 'reverse', 'default', 'is_empty', 'is_defined', 'is_iterable', 'is_array', 'is_number', 'is_string', 'contains', 'capitalize', 'title'] as $removed) {
        if (isset($names[$removed])) {
            throw new RuntimeException(sprintf('Redundant function alias %s should not be registered.', $removed));
        }
    }
    foreach (['strtoupper', 'strtolower', 'strlen', 'substr', 'strrev', 'ucfirst', 'ucwords'] as $legacy) {
        if (!isset($names[$legacy])) {
            throw new RuntimeException(sprintf('Legacy function %s must remain registered.', $legacy));
        }
    }
});


$test('XT tags are inert until registered and support self closing paired and nested forms', static function () use ($assertSame): void {
    $plain = new Engine(new ArrayLoader());
    $assertSame('<xt:hello name="Cat" />', $plain->renderString("<!--parser:xtscript--><!--/parser:xtscript--><xt:hello name=\"Cat\" />"));

    $plugin = new class implements PluginInterface {
        use PluginTrait;
        public function getName(): string { return 'xt-runtime-test'; }
        public function getXtTags(): iterable
        {
            yield new XtTagDefinition('hello', static fn (FunctionContext $context, string $name, array $attributes, ?string $body): string => ($attributes['name'] ?? 'guest') . ($body === null ? '' : ':' . $body));
            yield new XtTagDefinition('wrap', static fn (FunctionContext $context, string $name, array $attributes, ?string $body): string => '[' . ($body ?? '') . ']');
        }
    };
    $engine = new Engine(new ArrayLoader(), plugins: [$plugin]);
    $assertSame('Cat', $engine->renderString("<!--parser:xtscript--><!--/parser:xtscript--><xt:hello name='Cat' />"));
    $assertSame('Cat', $engine->renderString("<!--parser:xtscript--><!--/parser:xtscript--><xt:hello name=Cat>"));
    $assertSame('[Cat:ok]', $engine->renderString("<!--parser:xtscript--><!--/parser:xtscript--><xt:wrap><xt:hello name=Cat>ok</xt:hello></xt:wrap>"));
});

$test('XT tags emitted from legacy XtScript variables are expanded after rendering', static function () use ($assertSame): void {
    $plugin = new class implements PluginInterface {
        use PluginTrait;
        public function getName(): string { return 'xt-variable-test'; }
        public function getXtTags(): iterable
        {
            yield new XtTagDefinition('filelist', static fn (FunctionContext $context, string $name, array $attributes): string => ($attributes['folder'] ?? '') . ':' . ($attributes['per_page'] ?? ''));
        }
    };
    $engine = new Engine(new ArrayLoader(), plugins: [$plugin]);
    $source = <<<'XT'
var $list=<xt:filelist folder="/files" per_page="5" />
print_raw $list
XT;
    $assertSame('/files:5', $engine->renderString($source));
});

$test('XT wildcard handlers preserve arbitrary compatibility tags and receive attributes', static function () use ($assertSame): void {
    $plugin = new class implements PluginInterface {
        use PluginTrait;
        public function getName(): string { return 'xt-wildcard-test'; }
        public function getXtTags(): iterable
        {
            yield new XtTagDefinition('*', static fn (FunctionContext $context, string $name, array $attributes): string => $name . ':' . ($attributes['file'] ?? ''));
        }
    };
    $engine = new Engine(new ArrayLoader(), plugins: [$plugin]);
    $assertSame('include:/a.html', $engine->renderString("<!--parser:xtscript--><!--/parser:xtscript--><xt:include file='/a.html' />"));
    $assertSame(['*'], $engine->xtTagNames());
});

$test('XT tags obey the existing tag security allow list using xt:name', static function () use ($assertSame, $assertThrows): void {
    $plugin = new class implements PluginInterface {
        use PluginTrait;
        public function getName(): string { return 'xt-policy-test'; }
        public function getXtTags(): iterable
        {
            yield new XtTagDefinition('hello', static fn (): string => 'ok');
            yield new XtTagDefinition('secret', static fn (): string => 'bad');
        }
    };
    $policy = new AllowListSecurityPolicy(tags: ['xt:hello']);
    $engine = new Engine(new ArrayLoader(), plugins: [$plugin], securityPolicy: $policy);
    $assertSame('ok', $engine->renderString("<!--parser:xtscript--><!--/parser:xtscript--><xt:hello />"));
    $assertThrows(SecurityException::class, static fn () => $engine->renderString("<!--parser:xtscript--><!--/parser:xtscript--><xt:secret />"));
});

$test('XT recursive expansion is bounded by the instruction budget', static function () use ($assertThrows): void {
    $plugin = new class implements PluginInterface {
        use PluginTrait;
        public function getName(): string { return 'xt-loop-test'; }
        public function getXtTags(): iterable
        {
            yield new XtTagDefinition('loop', static fn (FunctionContext $context, string $name, array $attributes): string => '<xt:loop step="' . (($attributes['step'] ?? '1') === '1' ? '2' : '1') . '" />');
        }
    };
    $engine = new Engine(new ArrayLoader(), new EngineOptions(maxInstructions: 20), plugins: [$plugin]);
    $assertThrows(XtScriptException::class, static fn () => $engine->renderString("<!--parser:xtscript--><!--/parser:xtscript--><xt:loop />"));
});


$test('domain-qualified templates are passed to the single loader and can be disabled', static function () use ($assertSame, $assertThrows): void {
    $loader = new TestSiteLoader([
        'main' => "include b.example/shared\nimport b.example/library as remote\nprint call remote@value",
        'b.example/shared' => 'print DOMAIN-',
        'b.example/library' => "function value\nreturn LIB\nendfunction",
        'b.example/nested/main' => "include helper",
        'b.example/nested/helper' => 'print NESTED',
    ]);

    $engine = new Engine($loader);
    $assertSame('DOMAIN-LIB', $engine->render('main'));
    $assertSame('NESTED', $engine->render('b.example/nested/main'));

    $disabled = new Engine($loader, new EngineOptions(allowDomainTemplateReferences: false));
    $assertThrows(TemplateNotFoundException::class, static fn () => $disabled->render('b.example/shared'));
    $assertThrows(TemplateNotFoundException::class, static fn () => $disabled->renderString('include b.example/shared'));
    $assertThrows(TemplateNotFoundException::class, static fn () => $disabled->renderString('include helper', [], 'b.example/main'));

    // URL schemes are not the legacy domain-qualified template syntax.
    $assertThrows(TemplateNotFoundException::class, static fn () => $engine->render('https://example.com/page'));
});

$test('template names do not require the .xt extension', static function () use ($assertSame): void {
    $loader = new ArrayLoader([
        'main' => "include partial.html\nimport helpers.tpl as h\nprint call h@value",
        'partial.html' => 'print A',
        'helpers.tpl' => "function value\nreturn B\nendfunction",
    ]);
    $engine = new Engine($loader);
    $assertSame('AB', $engine->render('main'));
});

$test('plugin tag prefix is configurable and default xt becomes literal when changed', static function () use ($assertSame): void {
    $plugin = new class implements PluginInterface {
        use PluginTrait;
        public function getName(): string { return 'custom-prefix-test'; }
        public function getXtTags(): iterable
        {
            yield new XtTagDefinition('hello', static fn (FunctionContext $context, string $name, array $attributes): string => 'hi:' . ($attributes['name'] ?? 'guest'));
        }
    };
    $engine = new Engine(new ArrayLoader(), new EngineOptions(pluginTagPrefix: 'cms'), plugins: [$plugin]);
    $source = '<!--parser:xtscript--><!--/parser:xtscript--><cms:hello name="Cat" />|<xt:hello name="Old" />';
    $assertSame('hi:Cat|<xt:hello name="Old" />', $engine->renderString($source));
});

$test('custom plugin tag prefix participates in security policy identity', static function () use ($assertSame, $assertThrows): void {
    $plugin = new class implements PluginInterface {
        use PluginTrait;
        public function getName(): string { return 'custom-prefix-policy-test'; }
        public function getXtTags(): iterable
        {
            yield new XtTagDefinition('hello', static fn (): string => 'ok');
            yield new XtTagDefinition('secret', static fn (): string => 'bad');
        }
    };
    $policy = new AllowListSecurityPolicy(tags: ['cms:hello']);
    $engine = new Engine(new ArrayLoader(), new EngineOptions(pluginTagPrefix: 'cms'), plugins: [$plugin], securityPolicy: $policy);
    $assertSame('ok', $engine->renderString('<!--parser:xtscript--><!--/parser:xtscript--><cms:hello />'));
    $assertThrows(SecurityException::class, static fn () => $engine->renderString('<!--parser:xtscript--><!--/parser:xtscript--><cms:secret />'));
});

$test('plugin tag prefix validation rejects malformed prefixes', static function () use ($assertThrows): void {
    $assertThrows(InvalidArgumentException::class, static fn () => new EngineOptions(pluginTagPrefix: 'bad:prefix'));
    $assertThrows(InvalidArgumentException::class, static fn () => new EngineOptions(pluginTagPrefix: '1bad'));
});


$test('strict variables are opt in and preserve coalesce default and defined semantics', static function () use ($assertSame, $assertThrows): void {
    $engine = new Engine(new ArrayLoader(), new EngineOptions(strictVariables: true));
    $assertThrows(XtScript\Exception\SyntaxErrorException::class, static fn () => $engine->renderString('print $missing'));
    $assertThrows(XtScript\Exception\SyntaxErrorException::class, static fn () => $engine->renderString('print hello:$missing'));
    $assertSame('fallback', $engine->renderString('print $missing ?? "fallback"'));
    $assertSame('fallback', $engine->renderString('print $missing | default("fallback")'));
    $assertSame('', $engine->renderString("if \$missing is defined\nprint bad\nendif"));
});

$test('escape strategies work in scoped autoescape and escape filter', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $value = '</script>" a';
    $assertSame(
        XtScript\Escaper::escape($value, XtScript\EscapeStrategy::Js),
        $engine->renderString("autoescape js\nprint \$value\nendautoescape", ['value' => $value]),
    );
    $assertSame('a%20b', $engine->renderString("autoescape url\nprint \$value\nendautoescape", ['value' => 'a b']));
    $assertSame(
        XtScript\Escaper::escape($value, XtScript\EscapeStrategy::HtmlAttr),
        $engine->renderString('print $value | escape("html_attr")', ['value' => $value]),
    );
});

$test('AOT PHP file backend matches evaluator and persists generated PHP', static function () use ($assertSame): void {
    $directory = sys_get_temp_dir() . '/xtscript-aot-' . bin2hex(random_bytes(4));
    try {
        $source = "assign \$x = 2\nforeach [1,2,3] as \$n\nassign \$x = (\$x + \$n)\nendforeach\nprint \$x";
        $evaluator = new Engine(new ArrayLoader(), new EngineOptions(executionBackend: ExecutionBackend::Evaluator));
        $aot = new Engine(new ArrayLoader(), new EngineOptions(executionBackend: ExecutionBackend::PhpFile, phpFileCacheDirectory: $directory));
        $assertSame($evaluator->renderString($source), $aot->renderString($source));
        $files = glob($directory . '/*.php') ?: [];
        if (count($files) !== 1) throw new RuntimeException('Expected one persisted AOT PHP file.');
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($directory);
    }
});

$test('dependency graph covers include import extends and component transitively', static function () use ($assertSame): void {
    $loader = new ArrayLoader([
        'main' => "include part\nimport lib as util\ncomponent card\nendcomponent",
        'part' => 'include leaf',
        'leaf' => 'print leaf',
        'lib' => "function x\nreturn x\nendfunction",
        'card' => 'print card',
    ]);
    $graph = (new Engine($loader))->dependencies('main');
    $assertSame(['part', 'lib', 'card'], $graph->dependenciesOf('main'));
    $transitive = $graph->transitiveDependenciesOf('main');
    sort($transitive);
    $assertSame(['card', 'leaf', 'lib', 'part'], $transitive);
});

$test('typed template contracts validate required defaults types and extra variables', static function () use ($assertSame, $assertThrows): void {
    $contract = new XtScript\TemplateContract(
        ['name' => 'string', 'count' => 'int', 'note' => '?string'],
        ['note' => null],
        false,
    );
    $engine = new Engine(new ArrayLoader(['main' => 'print $name:$count']));
    $assertSame('Cat:2', $engine->renderWithContract('main', $contract, ['name' => 'Cat', 'count' => 2]));
    $assertThrows(XtScript\Exception\TemplateContractException::class, static fn () => $engine->renderWithContract('main', $contract, ['name' => 'Cat', 'count' => '2']));
    $assertThrows(XtScript\Exception\TemplateContractException::class, static fn () => $engine->renderWithContract('main', $contract, ['name' => 'Cat', 'count' => 2, 'extra' => true]));
});

$test('formatter block tags beautify HTML CSS JS and minify CSS JS', static function () use ($assertSame, $assertThrows): void {
    $engine = new Engine(new ArrayLoader());

    $html = '<div><span>x</span></div>';
    $assertSame("<div>\n  <span>\n    x\n  </span>\n</div>", $engine->renderString("beautify html\nprint_raw \$html\nendbeautify", ['html' => $html]));

    $css = '/* comment */ .a { color: red; margin: 0  1px; }';
    $assertSame('.a{color:red;margin:0 1px;}', $engine->renderString("minify css\nprint_raw \$css\nendminify", ['css' => $css]));
    $assertSame(".a {\n  color: red;\n  margin: 0 1px;\n}", $engine->renderString("beautify css\nprint_raw \$css\nendbeautify", ['css' => '.a { color: red; margin: 0  1px; }']));

    $js = "// comment\nconst x = 1;  // trailing\nconsole.log(x);";
    $assertSame("const x = 1;\nconsole.log(x);", $engine->renderString("minify js\nprint_raw \$js\nendminify", ['js' => $js]));
    $assertSame("function x() {\n  const a=1;\n  return a;\n}", $engine->renderString("beautify js\nprint_raw \$js\nendbeautify", ['js' => 'function x(){const a=1;return a;}']));

    $assertThrows(XtScript\Exception\SyntaxErrorException::class, static fn () => $engine->renderString("minify html\nprint_raw x\nendminify"));
});

$test('formatter filters beautify HTML CSS JS and conservatively minify CSS JS', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $css = '/* comment */ .a { color: red; margin: 0  1px; }';
    $assertSame('.a{color:red;margin:0 1px;}', $engine->renderString('print_raw $css | minify_css', ['css' => $css]));
    $js = "// comment\nconst x = 1;  // trailing\nconsole.log(x);";
    $assertSame("const x = 1;\nconsole.log(x);", $engine->renderString('print_raw $js | minify_js', ['js' => $js]));
    $html = '<div><span>x</span></div>';
    $assertSame("<div>\n  <span>\n    x\n  </span>\n</div>", $engine->renderString('print_raw $html | beautify_html', ['html' => $html]));
});


$test('regex supports PCRE literals matches operators tests and filters', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());

    $assertSame('yes', $engine->renderString("if \"abc123\" matches /\\d+/\nprint yes\nendif"));
    $assertSame('yes', $engine->renderString("if \"abc\" not matches /\\d+/\nprint yes\nendif"));
    $assertSame('yes', $engine->renderString("if \"abc123\" is matches(/\\d+/)\nprint yes\nendif"));
    $assertSame('1', $engine->renderString('print "abc123" | matches(/\\d+/)'));
    $assertSame('yes', $engine->renderString("if \"/api/test\" matches /^\\/api[\\/][a-z]+$/i\nprint yes\nendif"));
    $assertSame('4', $engine->renderString('print (8 / 2)'));
});

$test('regex match exposes named captures and advanced PCRE features', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());

    $source = <<<'XTS'
assign $m = ("ID:hé hé" | regex_match(/(?<=ID:)(?<word>\p{L}+)\s+\k<word>/u))
print $m.word
XTS;
    $assertSame('hé', $engine->renderString($source));

    $all = <<<'XTS'
assign $m = ("a1 b22 c333" | regex_match_all(/(?<n>\d+)/))
print $m | length
XTS;
    $assertSame('3', $engine->renderString($all));
});

$test('regex replace split grep quote count and validation are available', static function () use ($assertSame): void {
    $engine = new Engine(new ArrayLoader());

    $assertSame('abc[123]', $engine->renderString('print "abc123" | regex_replace(/(\\d+)/, "[$1]")'));
    $assertSame('a|b|c', $engine->renderString('print "a,,b,c" | regex_split(/,+/) | join("|")'));
    $assertSame('a|b|c', $engine->renderString('print "a,,b,c" | regex_split(/,/, -1, "no_empty") | join("|")'));
    $assertSame('ab|a1', $engine->renderString('print ["ab","12","a1"] | regex_grep(/^a/) | join("|")'));
    $assertSame('3', $engine->renderString('print "a1 b22 c333" | regex_count(/\\d+/)'));
    $assertSame('a\\+b', $engine->renderString('print "a+b" | regex_quote'));
    $assertSame('yes', $engine->renderString("if /\\d+/ is regex\nprint yes\nendif"));
    $assertSame('no', $engine->renderString("if \"/[\" is not regex\nprint no\nendif"));
});

$test('regex errors are normalized and security policy controls matches', static function () use ($assertThrows, $assertSame): void {
    $engine = new Engine(new ArrayLoader());
    $assertThrows(XtScript\Exception\RegexException::class, static fn () => $engine->renderString('print "x" | matches("/[/")'));

    $allowed = new Engine(new ArrayLoader(), securityPolicy: new AllowListSecurityPolicy(tests: ['matches']));
    $assertSame('ok', $allowed->renderString("if \"a1\" matches /\\d+/\nprint ok\nendif"));

    $denied = new Engine(new ArrayLoader(), securityPolicy: new AllowListSecurityPolicy(tests: []));
    $assertThrows(SecurityException::class, static fn () => $denied->renderString("if \"a1\" matches /\\d+/\nprint bad\nendif"));
});

$failures = [];
foreach ($tests as [$name, $callback]) {
    try {
        $callback();
        printf("PASS %s\n", $name);
    } catch (Throwable $throwable) {
        $failures[] = [$name, $throwable];
        printf("FAIL %s: %s\n", $name, $throwable->getMessage());
    }
}

printf("\n%d/%d tests passed.\n", count($tests) - count($failures), count($tests));
if ($failures !== []) {
    exit(1);
}
