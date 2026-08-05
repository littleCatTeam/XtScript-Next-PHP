<?php

declare(strict_types=1);

namespace XtScript\Compiler;

use Closure;
use LogicException;
use RuntimeException;
use XtScript\Ast\Program;

final readonly class PhpFileCompiler
{
    public function __construct(private PhpEvalCompiler $compiler = new PhpEvalCompiler())
    {
    }

    public function compile(Program $program, string $directory): ?Closure
    {
        $source = $this->compiler->compileSource($program);
        if ($source === null) {
            return null;
        }

        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($directory === '') {
            throw new RuntimeException('PHP file cache directory cannot be empty.');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create PHP file cache directory "%s".', $directory));
        }
        if (!is_writable($directory)) {
            throw new RuntimeException(sprintf('PHP file cache directory "%s" is not writable.', $directory));
        }

        $key = hash('sha256', "xtscript-php-file-v1\0" . serialize($program));
        $path = $directory . DIRECTORY_SEPARATOR . $key . '.php';
        if (!is_file($path)) {
            $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . $source . ";\n";
            $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (file_put_contents($temporary, $php, LOCK_EX) === false) {
                throw new RuntimeException(sprintf('Unable to write compiled PHP template "%s".', $temporary));
            }
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
                if (!is_file($path)) {
                    throw new RuntimeException(sprintf('Unable to publish compiled PHP template "%s".', $path));
                }
            }
        }

        /** @var mixed $compiled */
        $compiled = require $path;
        if (!$compiled instanceof Closure) {
            throw new LogicException(sprintf('Compiled PHP template "%s" did not return a Closure.', $path));
        }
        return $compiled;
    }

    public function clear(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            @unlink($file);
        }
    }
}
