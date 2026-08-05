# Getting started

## Requirements

- PHP 8.2 or newer
- no mandatory PHP extension beyond the standard functionality used by PHP itself
- Composer is recommended but not required to run the source-tree examples/tests

## Install

Add the GitHub repository to your `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/littleCatTeam/XtScript-Next-PHP"
    }
  ],
  "require": {
    "littlecat-team/xtscript-next": "1.0.0"
  }
}
```

Then:

```bash
composer install
```
## Minimal render

```php
use XtScript\Engine;
use XtScript\Loader\ArrayLoader;

$engine = new Engine(new ArrayLoader([
    'page' => 'print Hello $name',
]));

echo $engine->render('page', ['name' => '<Admin>']);
```

`print` uses HTML escaping by default. Use `print_raw` or the `raw` filter only when raw output is intended.

## Filesystem templates

```php
use XtScript\Engine;
use XtScript\Loader\FilesystemLoader;

$engine = new Engine(new FilesystemLoader(__DIR__ . '/templates'));
echo $engine->render('page', ['name' => 'World']);
```

See `/examples` for runnable examples covering each major subsystem.
