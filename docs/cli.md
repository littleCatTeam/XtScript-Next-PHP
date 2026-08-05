# CLI

Composer exposes `bin/xtscript`.

```bash
vendor/bin/xtscript lint templates/
vendor/bin/xtscript deps templates/ main
vendor/bin/xtscript inspect templates/ main
vendor/bin/xtscript compile templates/ var/xtscript-cache
vendor/bin/xtscript warmup templates/ var/xtscript-cache
vendor/bin/xtscript benchmark templates/ main 500
```

No file extension is required; directory commands inspect regular files regardless of extension.

Asset helpers:

```bash
vendor/bin/xtscript beautify html page.html
vendor/bin/xtscript beautify css app.css --write
vendor/bin/xtscript beautify js app.js
vendor/bin/xtscript minify css app.css --write
vendor/bin/xtscript minify js app.js --write
```

`lint` parses templates without rendering them. `deps` prints static include/import/extends/component dependencies. `compile` and `warmup` parse templates and persist PHP-file fast-path artifacts for eligible programs.
