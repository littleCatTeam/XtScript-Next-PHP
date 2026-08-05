<?php

declare(strict_types=1);

namespace XtScript\Parser;

use XtScript\Ast\Instruction;
use XtScript\Ast\InstructionType;
use XtScript\Ast\Program;
use XtScript\Exception\SyntaxErrorException;

final class Parser
{
    private const WRAPPER_PATTERN = '~<!--\s*(/?)\s*parser\s*:\s*xtscript\s*-->~i';

    /** @var list<array{line:int,text:string}> */
    private array $lines = [];
    private int $index = 0;
    private string $template = '';

    /** @var array<string, string> */
    private array $customBlockTags = [];

    /** @var array<string, true> */
    private array $customBlockEndTags = [];

    /** @var array<string, string> */
    private array $verbatimPayloads = [];

    /** @param array<string, string> $blockTags map of opening tag => closing tag */
    public function configureBlockTags(array $blockTags): void
    {
        $this->customBlockTags = [];
        $this->customBlockEndTags = [];
        foreach ($blockTags as $name => $endTag) {
            $name = strtolower(trim($name));
            $endTag = strtolower(trim($endTag));
            if (preg_match('/^[a-z_][a-z0-9_]*$/D', $name) !== 1
                || preg_match('/^[a-z_][a-z0-9_]*$/D', $endTag) !== 1) {
                throw new SyntaxErrorException('Invalid configured custom block tag name.');
            }
            $this->customBlockTags[$name] = $endTag;
            $this->customBlockEndTags[$endTag] = true;
        }
    }

    public function parse(string $source, string $template = '__string__'): Program
    {
        $this->template = $template;
        if (str_contains($source, "\0")) {
            throw $this->syntax('Template source contains a NUL byte.', 1);
        }

        $source = str_replace(["\r\n", "\r"], "\n", $source);
        if (preg_match(self::WRAPPER_PATTERN, $source) === 1) {
            return $this->parseWrappedDocument($source);
        }

        return new Program($this->parseScriptFragment($source, 1));
    }

    private function parseWrappedDocument(string $source): Program
    {
        if (preg_match_all(self::WRAPPER_PATTERN, $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw $this->syntax('Unable to scan XtScript parser wrappers.', 1);
        }

        /** @var list<array{type:'text'|'script',content:string,line:int}> $segments */
        $segments = [];
        $cursor = 0;
        $inside = false;
        $scriptStart = 0;
        $scriptLine = 1;

        foreach ($matches[0] as $index => $captured) {
            $marker = (string) $captured[0];
            $offset = (int) $captured[1];
            $closing = ($matches[1][$index][0] ?? '') === '/';
            $markerLine = $this->lineAtOffset($source, $offset);

            if (!$inside) {
                if ($closing) {
                    throw $this->syntax('Unexpected closing XtScript parser wrapper.', $markerLine);
                }

                if ($offset > $cursor) {
                    $segments[] = [
                        'type' => 'text',
                        'content' => substr($source, $cursor, $offset - $cursor),
                        'line' => $this->lineAtOffset($source, $cursor),
                    ];
                }

                $inside = true;
                $scriptStart = $offset + strlen($marker);
                $scriptLine = $this->lineAtOffset($source, $scriptStart);
                $cursor = $scriptStart;
                continue;
            }

            if (!$closing) {
                throw $this->syntax('Nested XtScript parser wrappers are not allowed.', $markerLine);
            }

            $segments[] = [
                'type' => 'script',
                'content' => substr($source, $scriptStart, $offset - $scriptStart),
                'line' => $scriptLine,
            ];
            $inside = false;
            $cursor = $offset + strlen($marker);
        }

        if ($inside) {
            throw $this->syntax('Unclosed XtScript parser wrapper.', $scriptLine);
        }

        if ($cursor < strlen($source)) {
            $segments[] = [
                'type' => 'text',
                'content' => substr($source, $cursor),
                'line' => $this->lineAtOffset($source, $cursor),
            ];
        }

        $hasLiteralContent = false;
        foreach ($segments as $segment) {
            if ($segment['type'] === 'text' && trim($segment['content']) !== '') {
                $hasLiteralContent = true;
                break;
            }
        }

        $instructions = [];
        foreach ($segments as $segment) {
            if ($segment['type'] === 'script') {
                array_push($instructions, ...$this->parseScriptFragment($segment['content'], $segment['line']));
                continue;
            }

            // Wrapper-only templates historically do not emit the formatting
            // whitespace around marker lines. In a mixed HTML document, preserve
            // literal text byte-for-byte (including whitespace) outside wrappers.
            if (!$hasLiteralContent && trim($segment['content']) === '') {
                continue;
            }

            if ($segment['content'] !== '') {
                $instructions[] = new Instruction(InstructionType::Text, $segment['line'], [
                    'text' => $segment['content'],
                ]);
            }
        }

        return new Program($instructions);
    }

    /** @return list<Instruction> */
    private function parseScriptFragment(string $source, int $startLine): array
    {
        $this->index = 0;
        $this->verbatimPayloads = [];
        $source = $this->protectVerbatimBlocks($source, $startLine);
        $source = preg_replace_callback('~/\*.*?\*/~s', static function (array $match): string {
            return str_repeat("\n", substr_count((string) $match[0], "\n"));
        }, $source) ?? $source;

        // Legacy XtScript uses {{...}} to keep embedded newlines inside one
        // command. Protect those newlines before splitting into statements.
        $placeholder = "\x1EXTSCRIPT_NEWLINE\x1E";
        $source = preg_replace_callback('/\{\{(.*?)\}\}/s', static function (array $match) use ($placeholder): string {
            return str_replace("\n", $placeholder, (string) $match[1]);
        }, $source) ?? $source;

        $parts = str_contains($source, "\n") ? explode("\n", $source) : explode('[br]', $source);
        $this->lines = [];
        foreach ($parts as $offset => $line) {
            $line = str_replace($placeholder, "\n", $line);
            $line = str_replace('\\n', "\n", $line);
            $line = trim(trim($line), ';');
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $this->lines[] = ['line' => $startLine + $offset, 'text' => $line];
        }

        [$instructions, $terminator] = $this->parseBlock([]);
        if ($terminator !== null) {
            throw $this->syntax(sprintf('Unexpected "%s".', $terminator['command']), $terminator['line']);
        }

        return $instructions;
    }

    private function lineAtOffset(string $source, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }

        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /**
     * @param list<string> $terminators
     * @return array{0:list<Instruction>,1:array{command:string,args:string,line:int}|null}
     */
    private function parseBlock(array $terminators): array
    {
        $instructions = [];
        while ($this->index < count($this->lines)) {
            $entry = $this->lines[$this->index];
            [$command, $args] = $this->splitCommand($entry['text']);
            $command = strtolower($command);

            if (in_array($command, $terminators, true)) {
                ++$this->index;
                return [$instructions, ['command' => $command, 'args' => $args, 'line' => $entry['line']]];
            }

            ++$this->index;
            $instructions[] = $this->parseInstruction($command, $args, $entry['line']);
        }

        return [$instructions, null];
    }

    private function parseInstruction(string $command, string $args, int $line): Instruction
    {
        if (str_starts_with($command, '@')) {
            return new Instruction(InstructionType::Label, $line, ['name' => $command]);
        }
        if (isset($this->customBlockTags[$command])) {
            return $this->parseCustomBlockTag($command, $args, $line);
        }
        if (isset($this->customBlockEndTags[$command])) {
            throw $this->syntax(sprintf('Unexpected "%s".', $command), $line);
        }

        return match ($command) {
            'assign', 'var' => $this->parseAssign($args, $line),
            'del', 'delete' => $this->simple(InstructionType::Delete, $line, ['name' => trim($args)]),
            'get' => $this->simple(InstructionType::Get, $line, ['name' => trim($args)]),
            'get_or_default' => $this->parseGetOrDefault($args, $line),
            'print' => $this->simple(InstructionType::Print, $line, ['expression' => $args]),
            'print_raw' => $this->simple(InstructionType::PrintRaw, $line, ['expression' => $args]),
            'return' => $this->simple(InstructionType::Return, $line, ['expression' => $args]),
            'call' => $this->simple(InstructionType::Call, $line, ['call' => $args]),
            'include' => $this->parseInclude($args, $line),
            'extends' => $this->parseExtends($args, $line),
            'block', 'section' => $this->parseNamedBlock($args, $line, $command === 'section' ? 'endsection' : 'endblock'),
            'yield' => $this->parseYield($args, $line),
            'parent' => $this->simple(InstructionType::Parent, $line, []),
            'if' => $this->parseIf($args, $line),
            'foreach' => $this->parseForeach($args, $line, 'endforeach'),
            'for' => $this->parseFor($args, $line),
            'switch' => $this->parseSwitch($args, $line),
            'break' => $this->simple(InstructionType::Break, $line, []),
            'continue' => $this->simple(InstructionType::Continue, $line, []),
            'component' => $this->parseComponent($args, $line),
            'capture' => $this->parseCapture($args, $line),
            'cache' => $this->parseCache($args, $line),
            'with' => $this->parseScopedBlock(InstructionType::With, $args, $line, 'endwith'),
            'do' => $this->simple(InstructionType::Do, $line, ['expression' => $args]),
            'once' => $this->parseScopedBlock(InstructionType::Once, $args, $line, 'endonce'),
            'apply' => $this->parseRequiredScopedBlock(InstructionType::Apply, $args, $line, 'endapply'),
            'autoescape' => $this->parseAutoEscape($args, $line),
            'beautify' => $this->parseFormatterBlock(InstructionType::Beautify, $args, $line, 'endbeautify', ['html', 'css', 'js']),
            'minify' => $this->parseFormatterBlock(InstructionType::Minify, $args, $line, 'endminify', ['css', 'js']),
            'import' => $this->parseImport($args, $line),
            '__xt_verbatim__' => $this->parseInternalVerbatim($args, $line),
            'push' => $this->parseNamedStackBlock(InstructionType::Push, $args, $line, 'endpush'),
            'prepend' => $this->parseNamedStackBlock(InstructionType::Prepend, $args, $line, 'endprepend'),
            'stack' => $this->parseStack($args, $line),
            'function' => $this->parseFunction($args, $line, 'endfunction'),
            'goto' => $this->simple(InstructionType::Goto, $line, ['label' => trim($args)]),
            'else', 'elseif', 'endif', 'endforeach', 'endfor', 'endfunction',
            'endblock', 'endsection', 'case', 'default', 'endswitch', 'slot', 'endslot', 'endcomponent',
            'endcapture', 'endcache', 'endwith', 'endonce', 'endapply', 'endautoescape', 'endbeautify', 'endminify', 'endpush', 'endprepend', 'endverbatim' => throw $this->syntax(sprintf('Unexpected "%s".', $command), $line),
            default => $this->simple(InstructionType::CustomTag, $line, ['name' => $command, 'arguments' => $args]),
        };
    }

    private function parseCustomBlockTag(string $name, string $args, int $line): Instruction
    {
        $endTag = $this->customBlockTags[$name];
        [$body, $terminator] = $this->parseBlock([$endTag]);
        if ($terminator === null) {
            throw $this->syntax(sprintf('Unclosed custom block tag "%s".', $name), $line);
        }

        return new Instruction(InstructionType::CustomBlockTag, $line, [
            'name' => $name,
            'arguments' => $args,
        ], $body);
    }

    private function parseAssign(string $args, int $line): Instruction
    {
        $parts = explode('=', $args, 2);
        if (count($parts) !== 2 || !$this->isVariable(trim($parts[0]))) {
            throw $this->syntax('assign/var requires "$name = value".', $line);
        }

        return $this->simple(InstructionType::Assign, $line, [
            'name' => trim($parts[0]),
            'expression' => trim($parts[1]),
        ]);
    }

    private function parseGetOrDefault(string $args, int $line): Instruction
    {
        $parts = $this->splitDelimited($args, ';');
        $name = trim($parts[0] ?? '');
        if ($name === '') {
            throw $this->syntax('get_or_default requires a variable name.', $line);
        }

        return $this->simple(InstructionType::GetOrDefault, $line, [
            'name' => $name,
            'default' => trim($parts[1] ?? ''),
        ]);
    }

    private function parseInclude(string $args, int $line): Instruction
    {
        $templates = array_values(array_filter(array_map('trim', explode(',', $args)), static fn (string $value): bool => $value !== ''));
        if ($templates === []) {
            throw $this->syntax('include requires at least one template name.', $line);
        }

        return $this->simple(InstructionType::Include, $line, ['templates' => $templates]);
    }

    private function parseExtends(string $args, int $line): Instruction
    {
        $template = $this->templateLiteral($args);
        if ($template === '') {
            throw $this->syntax('extends requires a parent template name.', $line);
        }

        return $this->simple(InstructionType::Extends, $line, ['template' => $template]);
    }

    private function parseNamedBlock(string $args, int $line, string $endCommand): Instruction
    {
        $name = trim($args);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name) !== 1) {
            throw $this->syntax('block/section requires a valid block name.', $line);
        }

        [$body, $terminator] = $this->parseBlock([$endCommand]);
        if ($terminator === null) {
            throw $this->syntax(sprintf('Unclosed %s block.', $endCommand === 'endsection' ? 'section' : 'block'), $line);
        }

        return new Instruction(InstructionType::Block, $line, ['name' => $name], $body);
    }

    private function parseYield(string $args, int $line): Instruction
    {
        $name = trim($args);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name) !== 1) {
            throw $this->syntax('yield requires a valid block name.', $line);
        }

        return new Instruction(InstructionType::Block, $line, ['name' => $name], []);
    }

    private function parseIf(string $condition, int $line): Instruction
    {
        if ($condition === '') {
            throw $this->syntax('if requires a condition.', $line);
        }

        [$body, $terminator] = $this->parseBlock(['elseif', 'else', 'endif']);
        $branches = [['condition' => $condition, 'body' => $body, 'line' => $line]];
        $alternate = [];

        while ($terminator !== null && $terminator['command'] === 'elseif') {
            $elseifCondition = trim($terminator['args']);
            $elseifLine = $terminator['line'];
            if ($elseifCondition === '') {
                throw $this->syntax('elseif requires a condition.', $elseifLine);
            }

            [$branchBody, $terminator] = $this->parseBlock(['elseif', 'else', 'endif']);
            $branches[] = [
                'condition' => $elseifCondition,
                'body' => $branchBody,
                'line' => $elseifLine,
            ];
        }

        if ($terminator !== null && $terminator['command'] === 'else') {
            [$alternate, $terminator] = $this->parseBlock(['endif']);
        }
        if ($terminator === null || $terminator['command'] !== 'endif') {
            throw $this->syntax('Unclosed if block.', $line);
        }

        return new Instruction(InstructionType::If, $line, ['branches' => $branches], [], $alternate);
    }

    private function parseForeach(string $args, int $line, string $endCommand): Instruction
    {
        if (preg_match('/^(.+?)\s+as\s+(?:(\$[A-Za-z_][A-Za-z0-9_]*)\s*=>\s*)?(\$[A-Za-z_][A-Za-z0-9_]*)$/iD', trim($args), $match) !== 1) {
            throw $this->syntax('foreach syntax is "foreach expression as $value" or "foreach expression as $key => $value".', $line);
        }

        [$body, $terminator] = $this->parseBlock(['else', $endCommand]);
        $alternate = [];
        if ($terminator !== null && $terminator['command'] === 'else') {
            [$alternate, $terminator] = $this->parseBlock([$endCommand]);
        }
        if ($terminator === null) {
            throw $this->syntax('Unclosed foreach/for block.', $line);
        }

        return new Instruction(InstructionType::Foreach, $line, [
            'expression' => trim($match[1]),
            'key' => $match[2] !== '' ? $match[2] : null,
            'value' => $match[3],
        ], $body, $alternate);
    }

    private function parseFor(string $args, int $line): Instruction
    {
        if (preg_match('/^(\$[A-Za-z_][A-Za-z0-9_]*)\s+in\s+(.+)$/iD', trim($args), $match) !== 1) {
            throw $this->syntax('for syntax is "for $value in expression".', $line);
        }

        return $this->parseForeach(trim($match[2]) . ' as ' . $match[1], $line, 'endfor');
    }

    private function parseSwitch(string $expression, int $line): Instruction
    {
        $expression = trim($expression);
        if ($expression === '') {
            throw $this->syntax('switch requires an expression.', $line);
        }

        [$leading, $terminator] = $this->parseBlock(['case', 'default', 'endswitch']);
        if ($leading !== []) {
            throw $this->syntax('switch cannot contain statements before the first case/default.', $line);
        }

        $cases = [];
        $alternate = [];
        while ($terminator !== null && $terminator['command'] === 'case') {
            $caseExpression = trim($terminator['args']);
            if ($caseExpression === '') {
                throw $this->syntax('case requires an expression.', $terminator['line']);
            }
            [$caseBody, $terminator] = $this->parseBlock(['case', 'default', 'endswitch']);
            $cases[] = [
                'expression' => $caseExpression,
                'body' => $caseBody,
                'line' => $terminator['line'] ?? $line,
            ];
        }

        if ($terminator !== null && $terminator['command'] === 'default') {
            [$alternate, $terminator] = $this->parseBlock(['endswitch']);
        }
        if ($terminator === null || $terminator['command'] !== 'endswitch') {
            throw $this->syntax('Unclosed switch block.', $line);
        }

        return new Instruction(InstructionType::Switch, $line, [
            'expression' => $expression,
            'cases' => $cases,
        ], [], $alternate);
    }

    private function parseComponent(string $args, int $line): Instruction
    {
        [$template, $argumentSource] = $this->splitCommand($args);
        $template = $this->templateLiteral($template);
        if ($template === '') {
            throw $this->syntax('component requires a template name.', $line);
        }

        $body = [];
        $slots = [];
        while (true) {
            [$fragment, $terminator] = $this->parseBlock(['slot', 'endcomponent']);
            array_push($body, ...$fragment);
            if ($terminator === null) {
                throw $this->syntax('Unclosed component block.', $line);
            }
            if ($terminator['command'] === 'endcomponent') {
                break;
            }

            $slotName = trim($terminator['args']);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $slotName) !== 1) {
                throw $this->syntax('slot requires a valid slot name.', $terminator['line']);
            }
            if (array_key_exists($slotName, $slots)) {
                throw $this->syntax(sprintf('Duplicate slot "%s".', $slotName), $terminator['line']);
            }
            [$slotBody, $slotEnd] = $this->parseBlock(['endslot']);
            if ($slotEnd === null) {
                throw $this->syntax(sprintf('Unclosed slot "%s".', $slotName), $terminator['line']);
            }
            $slots[$slotName] = $slotBody;
        }

        return new Instruction(InstructionType::Component, $line, [
            'template' => $template,
            'arguments' => trim($argumentSource),
            'slots' => $slots,
        ], $body);
    }

    private function parseCapture(string $args, int $line): Instruction
    {
        $name = trim($args);
        if (!$this->isVariable($name)) {
            throw $this->syntax('capture requires a variable name.', $line);
        }

        [$body, $terminator] = $this->parseBlock(['endcapture']);
        if ($terminator === null) {
            throw $this->syntax('Unclosed capture block.', $line);
        }

        return new Instruction(InstructionType::Capture, $line, ['name' => $name], $body);
    }

    private function parseCache(string $args, int $line): Instruction
    {
        $parts = $this->splitDelimited($args, ';');
        $key = trim($parts[0] ?? '');
        if ($key === '') {
            throw $this->syntax('cache requires a cache key expression.', $line);
        }
        $ttl = trim($parts[1] ?? '300');

        [$body, $terminator] = $this->parseBlock(['endcache']);
        if ($terminator === null) {
            throw $this->syntax('Unclosed cache block.', $line);
        }

        return new Instruction(InstructionType::Cache, $line, [
            'key' => $key,
            'ttl' => $ttl,
        ], $body);
    }

    private function parseScopedBlock(InstructionType $type, string $args, int $line, string $endCommand): Instruction
    {
        [$body, $terminator] = $this->parseBlock([$endCommand]);
        if ($terminator === null) {
            throw $this->syntax(sprintf('Unclosed %s block.', $type->value), $line);
        }

        return new Instruction($type, $line, ['arguments' => trim($args)], $body);
    }

    private function parseRequiredScopedBlock(InstructionType $type, string $args, int $line, string $endCommand): Instruction
    {
        if (trim($args) === '') {
            throw $this->syntax(sprintf('%s requires arguments.', $type->value), $line);
        }
        return $this->parseScopedBlock($type, $args, $line, $endCommand);
    }

    private function parseAutoEscape(string $args, int $line): Instruction
    {
        $mode = strtolower(trim($args));
        $allowed = ['on', 'off', 'true', 'false', 'none', 'html', 'html_attr', 'attr', 'attribute', 'js', 'javascript', 'css', 'url', 'uri'];
        if (!in_array($mode, $allowed, true)) {
            throw $this->syntax('autoescape mode must be on/off or one of html, html_attr, js, css, url.', $line);
        }
        [$body, $terminator] = $this->parseBlock(['endautoescape']);
        if ($terminator === null) {
            throw $this->syntax('Unclosed autoescape block.', $line);
        }
        return new Instruction(InstructionType::AutoEscape, $line, ['strategy' => $mode], $body);
    }

    /** @param list<string> $allowed */
    private function parseFormatterBlock(InstructionType $type, string $args, int $line, string $endCommand, array $allowed): Instruction
    {
        $language = strtolower(trim($args));
        if (!in_array($language, $allowed, true)) {
            throw $this->syntax(sprintf('%s requires one of: %s.', $type->value, implode(', ', $allowed)), $line);
        }
        [$body, $terminator] = $this->parseBlock([$endCommand]);
        if ($terminator === null) {
            throw $this->syntax(sprintf('Unclosed %s block.', $type->value), $line);
        }
        return new Instruction($type, $line, ['language' => $language], $body);
    }

    private function parseImport(string $args, int $line): Instruction
    {
        $args = trim($args);
        if (preg_match('/^(.+?)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?$/iD', $args, $match) !== 1) {
            throw $this->syntax('import syntax is "import template" or "import template as namespace".', $line);
        }
        $template = $this->templateLiteral(trim($match[1]));
        if ($template === '') {
            throw $this->syntax('import requires a template name.', $line);
        }
        return $this->simple(InstructionType::Import, $line, [
            'template' => $template,
            'namespace' => isset($match[2]) && $match[2] !== '' ? $match[2] : null,
        ]);
    }

    private function parseInternalVerbatim(string $args, int $line): Instruction
    {
        $id = trim($args);
        if ($id === '' || !array_key_exists($id, $this->verbatimPayloads)) {
            throw $this->syntax('Invalid internal verbatim payload.', $line);
        }

        return new Instruction(InstructionType::Verbatim, $line, [
            'text' => $this->verbatimPayloads[$id],
        ]);
    }

    private function parseNamedStackBlock(InstructionType $type, string $args, int $line, string $endCommand): Instruction
    {
        $name = trim($args);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name) !== 1) {
            throw $this->syntax(sprintf('%s requires a valid stack name.', $type->value), $line);
        }
        [$body, $terminator] = $this->parseBlock([$endCommand]);
        if ($terminator === null) {
            throw $this->syntax(sprintf('Unclosed %s block.', $type->value), $line);
        }
        return new Instruction($type, $line, ['name' => $name], $body);
    }

    private function parseStack(string $args, int $line): Instruction
    {
        $name = trim($args);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name) !== 1) {
            throw $this->syntax('stack requires a valid stack name.', $line);
        }
        return new Instruction(InstructionType::Stack, $line, ['name' => $name]);
    }

    private function protectVerbatimBlocks(string $source, int $startLine): string
    {
        $lines = explode("\n", $source);
        $output = [];
        $inside = false;
        $buffer = [];
        $id = 0;
        $openLine = $startLine;

        foreach ($lines as $offset => $rawLine) {
            $normalized = strtolower(trim(trim($rawLine), ';'));
            if (!$inside) {
                if ($normalized !== 'verbatim') {
                    $output[] = $rawLine;
                    continue;
                }
                $inside = true;
                $buffer = [];
                $openLine = $startLine + $offset;
                $token = 'v' . (++$id);
                $output[] = '__xt_verbatim__ ' . $token;
                continue;
            }

            if ($normalized === 'endverbatim') {
                $payload = $buffer === [] ? '' : implode("\n", $buffer) . "\n";
                $this->verbatimPayloads['v' . $id] = $payload;
                $output[] = '';
                $inside = false;
                $buffer = [];
                continue;
            }

            $buffer[] = $rawLine;
            $output[] = '';
        }

        if ($inside) {
            throw $this->syntax('Unclosed verbatim block.', $openLine);
        }

        return implode("\n", $output);
    }

    private function parseFunction(string $args, int $line, string $endCommand): Instruction
    {
        [$name, $parameterSource] = $this->splitCommand($args);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_@.]*$/D', $name) !== 1) {
            throw $this->syntax('Invalid function name.', $line);
        }

        $parameters = [];
        foreach (array_filter(array_map('trim', $this->splitDelimited($parameterSource, ';')), static fn (string $value): bool => $value !== '') as $parameter) {
            $parts = explode('=', $parameter, 2);
            $variable = trim($parts[0]);
            if (!$this->isVariable($variable)) {
                throw $this->syntax(sprintf('Invalid function parameter "%s".', $variable), $line);
            }
            $parameters[$variable] = isset($parts[1]) ? trim($parts[1]) : '';
        }

        [$body, $terminator] = $this->parseBlock([$endCommand]);
        if ($terminator === null) {
            throw $this->syntax('Unclosed function block.', $line);
        }

        return new Instruction(InstructionType::Function, $line, ['name' => $name, 'parameters' => $parameters], $body);
    }

    /** @param array<string, mixed> $arguments */
    private function simple(InstructionType $type, int $line, array $arguments): Instruction
    {
        return new Instruction($type, $line, $arguments);
    }

    /** @return array{0:string,1:string} */
    private function splitCommand(string $line): array
    {
        $parts = preg_split('/\s+/', trim($line), 2) ?: [];
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /** @return list<string> */
    private function splitDelimited(string $source, string $delimiter): array
    {
        $parts = [];
        $buffer = '';
        $quote = null;
        $escaped = false;
        foreach (str_split($source) as $char) {
            if ($escaped) {
                $buffer .= $char;
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $buffer .= $char;
                $escaped = true;
                continue;
            }
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === $delimiter) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $parts[] = $buffer;
        return $parts;
    }

    private function templateLiteral(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return trim($value);
    }

    private function isVariable(string $value): bool
    {
        return preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/D', $value) === 1;
    }

    private function syntax(string $message, int $line): SyntaxErrorException
    {
        return new SyntaxErrorException($message, $this->template, $line > 0 ? $line : null);
    }
}
