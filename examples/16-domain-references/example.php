<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_autoload.php';

use XtScript\Contract\LoaderInterface;
use XtScript\Engine;
use XtScript\EngineOptions;
use XtScript\Exception\TemplateNotFoundException;
use XtScript\TemplateReference;
use XtScript\TemplateSource;

final class SiteTemplateLoader implements LoaderInterface
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
            $domain = TemplateReference::splitDomainQualified($from);
            if ($domain !== null) {
                $base = dirname($domain['path']);
                $path = $this->normalize(($base === '.' ? '' : $base . '/') . $name);
                return $domain['domain'] . '/' . $path;
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

$loader = new SiteTemplateLoader([
    'main' => "include shared.example/banner\nimport shared.example/helpers as shared\nprint call shared@label",
    'shared.example/banner' => 'print DOMAIN-',
    'shared.example/helpers' => "function label\nreturn STORAGE\nendfunction",
]);

$engine = new Engine($loader);
echo $engine->render('main') . PHP_EOL;

$disabled = new Engine($loader, new EngineOptions(allowDomainTemplateReferences: false));
try {
    $disabled->render('shared.example/banner');
} catch (TemplateNotFoundException) {
    echo "domain-disabled\n";
}
