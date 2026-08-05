<?php

declare(strict_types=1);

namespace XtScript;

enum ExecutionBackend: string
{
    /** Use the PHP fast path when the compiled program is eligible, otherwise fall back. */
    case Auto = 'auto';

    /** Always use the portable instruction evaluator. */
    case Evaluator = 'evaluator';

    /** Prefer the PHP fast path; unsupported programs still fall back for compatibility. */
    case PhpEval = 'php_eval';

    /** Prefer a persisted generated PHP file; unsupported programs fall back. */
    case PhpFile = 'php_file';
}
