<?php

declare(strict_types=1);

namespace XtScript\Parser;

use Throwable;
use Traversable;
use XtScript\Context;
use XtScript\Contract\FilterInterface;
use XtScript\Contract\SecurityPolicyInterface;
use XtScript\Contract\TestInterface;
use XtScript\Exception\PluginException;
use XtScript\Exception\SecurityException;
use XtScript\Exception\XtScriptException;
use XtScript\Exception\SyntaxErrorException;

final class ExpressionEvaluator
{
    /** @var list<Token> */
    private array $tokens = [];
    private int $position = 0;

    /** @var array<string, list<Token>> */
    private array $tokenCache = [];

    /** @var list<string> */
    private array $tokenCacheOrder = [];

    /**
     * @param array<string, FilterInterface> $filters
     * @param array<string, TestInterface> $tests
     */
    public function __construct(
        private readonly Lexer $lexer = new Lexer(),
        private readonly array $filters = [],
        private readonly array $tests = [],
        private readonly ?SecurityPolicyInterface $securityPolicy = null,
        private readonly int $cacheSize = 256,
        private readonly int $collectionLimit = 100_000,
        private readonly bool $strictVariables = false,
    ) {
    }

    public function evaluate(string $expression, Context $context): mixed
    {
        $expression = trim($expression);
        if (isset($this->tokenCache[$expression])) {
            $this->tokens = $this->tokenCache[$expression];
        } else {
            $this->tokens = $this->lexer->tokenize($expression);
            if ($this->cacheSize > 0) {
                $this->tokenCache[$expression] = $this->tokens;
                $this->tokenCacheOrder[] = $expression;
                while (count($this->tokenCacheOrder) > $this->cacheSize) {
                    $oldest = array_shift($this->tokenCacheOrder);
                    if ($oldest !== null) {
                        unset($this->tokenCache[$oldest]);
                    }
                }
            }
        }
        $this->position = 0;
        $result = $this->parseTernary($context, true);

        if ($this->current()->type !== TokenType::Eof) {
            throw new SyntaxErrorException(sprintf('Unexpected token "%s" in expression.', $this->current()->lexeme));
        }

        if (!$result->defined && $this->strictVariables) {
            throw new SyntaxErrorException('Undefined variable in expression.');
        }

        return $result->defined ? $result->value : '';
    }

    public static function truthy(mixed $value): bool
    {
        return !($value === false || $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0');
    }


    private function parseTernary(Context $context, bool $evaluate): ExpressionValue
    {
        $condition = $this->parseCoalesce($context, $evaluate);
        if ($this->current()->type !== TokenType::Question) {
            return $evaluate ? $condition : ExpressionValue::undefined();
        }

        $this->advance();
        $truthy = $evaluate && $condition->defined && self::truthy($condition->value);
        $whenTrue = $this->parseTernary($context, $evaluate && $truthy);
        if ($this->current()->type !== TokenType::Colon) {
            throw new SyntaxErrorException('Ternary expression requires ":".');
        }
        $this->advance();
        $whenFalse = $this->parseTernary($context, $evaluate && !$truthy);

        if (!$evaluate) {
            return ExpressionValue::undefined();
        }

        return $truthy ? $whenTrue : $whenFalse;
    }

    private function parseCoalesce(Context $context, bool $evaluate): ExpressionValue
    {
        $left = $this->parseOr($context, $evaluate);
        if (!$this->matchOperator('??')) {
            return $evaluate ? $left : ExpressionValue::undefined();
        }

        $useLeft = $evaluate && $left->defined && $left->value !== null;
        $right = $this->parseCoalesce($context, $evaluate && !$useLeft);
        if (!$evaluate) {
            return ExpressionValue::undefined();
        }

        return $useLeft ? $left : $right;
    }

    private function parseOr(Context $context, bool $evaluate): ExpressionValue
    {
        $left = $this->parseAnd($context, $evaluate);
        while ($this->matchOperator('or')) {
            if ($evaluate && self::truthy($left->value)) {
                $this->parseAnd($context, false);
                $left = ExpressionValue::defined(true);
                continue;
            }

            $right = $this->parseAnd($context, $evaluate);
            if ($evaluate) {
                $left = ExpressionValue::defined(self::truthy($left->value) || self::truthy($right->value));
            }
        }

        return $evaluate ? $left : ExpressionValue::undefined();
    }

    private function parseAnd(Context $context, bool $evaluate): ExpressionValue
    {
        $left = $this->parseComparison($context, $evaluate);
        while ($this->matchOperator('and')) {
            if ($evaluate && !self::truthy($left->value)) {
                $this->parseComparison($context, false);
                $left = ExpressionValue::defined(false);
                continue;
            }

            $right = $this->parseComparison($context, $evaluate);
            if ($evaluate) {
                $left = ExpressionValue::defined(self::truthy($left->value) && self::truthy($right->value));
            }
        }

        return $evaluate ? $left : ExpressionValue::undefined();
    }

    private function parseComparison(Context $context, bool $evaluate): ExpressionValue
    {
        $left = $this->parseConcat($context, $evaluate);

        while (true) {
            if ($this->current()->type === TokenType::Operator
                && in_array($this->current()->lexeme, ['==', '!=', '===', '!==', '>', '<', '>=', '<='], true)) {
                $operator = $this->advance()->lexeme;
                $right = $this->parseConcat($context, $evaluate);
                if (!$evaluate) {
                    continue;
                }
                $left = ExpressionValue::defined(match ($operator) {
                    '==' => $left->value == $right->value,
                    '!=' => $left->value != $right->value,
                    '===' => $left->value === $right->value,
                    '!==' => $left->value !== $right->value,
                    '>' => $left->value > $right->value,
                    '<' => $left->value < $right->value,
                    '>=' => $left->value >= $right->value,
                    '<=' => $left->value <= $right->value,
                });
                continue;
            }

            $negatedMembership = $this->isOperator('not') && $this->peekOperator('in');
            if ($this->matchOperator('in') || $negatedMembership) {
                if ($negatedMembership) {
                    $this->advance(); // not
                    $this->advance(); // in
                }
                $right = $this->parseConcat($context, $evaluate);
                if ($evaluate) {
                    $matched = $this->contains($right->value, $left->value);
                    $left = ExpressionValue::defined($negatedMembership ? !$matched : $matched);
                }
                continue;
            }

            $negatedRegexMatch = $this->isOperator('not') && $this->peekOperator('matches');
            if ($this->matchOperator('matches') || $negatedRegexMatch) {
                if ($negatedRegexMatch) {
                    $this->advance(); // not
                    $this->advance(); // matches
                }
                $right = $this->parseConcat($context, $evaluate);
                if ($evaluate) {
                    $matched = $this->invokeTest('matches', $left, [$right->value], $context);
                    $left = ExpressionValue::defined($negatedRegexMatch ? !$matched : $matched);
                }
                continue;
            }

            if ($this->matchOperator('is')) {
                $negated = $this->matchOperator('not');
                $testToken = $this->advance();
                if ($testToken->type !== TokenType::Identifier && !($testToken->type === TokenType::Operator && $testToken->lexeme === 'matches')) {
                    throw new SyntaxErrorException('Expected test name after "is".');
                }
                $arguments = $this->parsePostfixArguments($context, $evaluate);
                if ($evaluate) {
                    $result = $this->invokeTest(strtolower($testToken->lexeme), $left, $arguments, $context);
                    $left = ExpressionValue::defined($negated ? !$result : $result);
                }
                continue;
            }

            break;
        }

        return $evaluate ? $left : ExpressionValue::undefined();
    }

    private function parseConcat(Context $context, bool $evaluate): ExpressionValue
    {
        $left = $this->parseAdditive($context, $evaluate);
        while ($this->matchOperator('~')) {
            $right = $this->parseAdditive($context, $evaluate);
            if ($evaluate) {
                $left = ExpressionValue::defined($this->string($left->value) . $this->string($right->value));
            }
        }

        return $evaluate ? $left : ExpressionValue::undefined();
    }

    private function parseAdditive(Context $context, bool $evaluate): ExpressionValue
    {
        $left = $this->parseMultiplicative($context, $evaluate);
        while ($this->current()->type === TokenType::Operator && in_array($this->current()->lexeme, ['+', '-'], true)) {
            $operator = $this->advance()->lexeme;
            $right = $this->parseMultiplicative($context, $evaluate);
            if (!$evaluate) {
                continue;
            }
            $leftNumber = $this->number($left->value);
            $rightNumber = $this->number($right->value);
            $left = ExpressionValue::defined($operator === '+' ? $leftNumber + $rightNumber : $leftNumber - $rightNumber);
        }

        return $evaluate ? $left : ExpressionValue::undefined();
    }

    private function parseMultiplicative(Context $context, bool $evaluate): ExpressionValue
    {
        $left = $this->parseUnary($context, $evaluate);
        while ($this->current()->type === TokenType::Operator && in_array($this->current()->lexeme, ['*', '/', '%'], true)) {
            $operator = $this->advance()->lexeme;
            $right = $this->parseUnary($context, $evaluate);
            if (!$evaluate) {
                continue;
            }
            $leftNumber = $this->number($left->value);
            $rightNumber = $this->number($right->value);
            if (($operator === '/' || $operator === '%') && $rightNumber == 0.0) {
                throw new SyntaxErrorException('Division by zero.');
            }
            $left = ExpressionValue::defined(match ($operator) {
                '*' => $leftNumber * $rightNumber,
                '/' => $leftNumber / $rightNumber,
                '%' => (int) $leftNumber % (int) $rightNumber,
            });
        }

        return $evaluate ? $left : ExpressionValue::undefined();
    }

    private function parseUnary(Context $context, bool $evaluate): ExpressionValue
    {
        if ($this->matchOperator('not')) {
            $value = $this->parseUnary($context, $evaluate);
            return $evaluate ? ExpressionValue::defined(!self::truthy($value->value)) : ExpressionValue::undefined();
        }
        if ($this->matchOperator('-')) {
            $value = $this->parseUnary($context, $evaluate);
            return $evaluate ? ExpressionValue::defined(-$this->number($value->value)) : ExpressionValue::undefined();
        }
        if ($this->matchOperator('+')) {
            $value = $this->parseUnary($context, $evaluate);
            return $evaluate ? ExpressionValue::defined($this->number($value->value)) : ExpressionValue::undefined();
        }

        return $this->parsePostfix($context, $evaluate);
    }

    private function parsePostfix(Context $context, bool $evaluate): ExpressionValue
    {
        $value = $this->parsePrimary($context, $evaluate);
        while ($this->matchOperator('|')) {
            $filterToken = $this->advance();
            if ($filterToken->type !== TokenType::Identifier && !($filterToken->type === TokenType::Operator && $filterToken->lexeme === 'matches')) {
                throw new SyntaxErrorException('Expected filter name after "|".');
            }
            $arguments = $this->parsePostfixArguments($context, $evaluate);
            if ($evaluate) {
                $value = ExpressionValue::defined($this->invokeFilter(strtolower($filterToken->lexeme), $value, $arguments, $context));
            }
        }

        return $evaluate ? $value : ExpressionValue::undefined();
    }

    /** @return list<mixed> */
    private function parsePostfixArguments(Context $context, bool $evaluate): array
    {
        if ($this->current()->type !== TokenType::LeftParen) {
            return [];
        }
        $this->advance();
        $arguments = [];
        if ($this->current()->type === TokenType::RightParen) {
            $this->advance();
            return $arguments;
        }

        while (true) {
            $argument = $this->parseTernary($context, $evaluate);
            if ($evaluate) {
                $arguments[] = $argument->defined ? $argument->value : '';
            }
            if ($this->current()->type === TokenType::Comma) {
                $this->advance();
                continue;
            }
            if ($this->current()->type !== TokenType::RightParen) {
                throw new SyntaxErrorException('Expected "," or ")" in argument list.');
            }
            $this->advance();
            break;
        }

        return $arguments;
    }

    private function parsePrimary(Context $context, bool $evaluate): ExpressionValue
    {
        $token = $this->advance();
        if (!$evaluate) {
            return match ($token->type) {
                TokenType::LeftParen => $this->parseParenthesized($context, false),
                TokenType::LeftBracket => $this->parseArrayLiteral($context, false),
                TokenType::LeftBrace => $this->parseMapLiteral($context, false),
                default => ExpressionValue::undefined(),
            };
        }

        return match ($token->type) {
            TokenType::Variable => $context->has((string) $token->literal)
                ? ExpressionValue::defined($context->get((string) $token->literal))
                : ExpressionValue::undefined(),
            TokenType::Number, TokenType::String, TokenType::Regex => ExpressionValue::defined($token->literal),
            TokenType::Identifier => ExpressionValue::defined($token->literal),
            TokenType::LeftParen => $this->parseParenthesized($context, true),
            TokenType::LeftBracket => $this->parseArrayLiteral($context, true),
            TokenType::LeftBrace => $this->parseMapLiteral($context, true),
            default => throw new SyntaxErrorException(sprintf('Unexpected token "%s" in expression.', $token->lexeme)),
        };
    }

    private function parseArrayLiteral(Context $context, bool $evaluate): ExpressionValue
    {
        $values = [];
        if ($this->current()->type === TokenType::RightBracket) {
            $this->advance();
            return $evaluate ? ExpressionValue::defined([]) : ExpressionValue::undefined();
        }

        $count = 0;
        while (true) {
            if (++$count > $this->collectionLimit) {
                throw new SyntaxErrorException('Array literal exceeds the expression collection limit.');
            }
            $value = $this->parseTernary($context, $evaluate);
            if ($evaluate) {
                $values[] = $value->defined ? $value->value : null;
            }
            if ($this->current()->type === TokenType::Comma) {
                $this->advance();
                if ($this->current()->type === TokenType::RightBracket) {
                    $this->advance();
                    break;
                }
                continue;
            }
            if ($this->current()->type !== TokenType::RightBracket) {
                throw new SyntaxErrorException('Expected "," or "]" in array literal.');
            }
            $this->advance();
            break;
        }

        return $evaluate ? ExpressionValue::defined($values) : ExpressionValue::undefined();
    }

    private function parseMapLiteral(Context $context, bool $evaluate): ExpressionValue
    {
        $values = [];
        if ($this->current()->type === TokenType::RightBrace) {
            $this->advance();
            return $evaluate ? ExpressionValue::defined([]) : ExpressionValue::undefined();
        }

        $count = 0;
        while (true) {
            if (++$count > $this->collectionLimit) {
                throw new SyntaxErrorException('Map literal exceeds the expression collection limit.');
            }
            $keyToken = $this->advance();
            $key = match ($keyToken->type) {
                TokenType::String => (string) $keyToken->literal,
                TokenType::Identifier => $keyToken->lexeme,
                TokenType::Number => $keyToken->literal,
                default => throw new SyntaxErrorException('Map literal keys must be strings, identifiers, or numbers.'),
            };
            if ($this->current()->type !== TokenType::Colon) {
                throw new SyntaxErrorException('Map literal requires ":" after each key.');
            }
            $this->advance();
            $value = $this->parseTernary($context, $evaluate);
            if ($evaluate) {
                $values[$key] = $value->defined ? $value->value : null;
            }
            if ($this->current()->type === TokenType::Comma) {
                $this->advance();
                if ($this->current()->type === TokenType::RightBrace) {
                    $this->advance();
                    break;
                }
                continue;
            }
            if ($this->current()->type !== TokenType::RightBrace) {
                throw new SyntaxErrorException('Expected "," or "}" in map literal.');
            }
            $this->advance();
            break;
        }

        return $evaluate ? ExpressionValue::defined($values) : ExpressionValue::undefined();
    }

    private function parseParenthesized(Context $context, bool $evaluate): ExpressionValue
    {
        $value = $this->parseTernary($context, $evaluate);
        if ($this->current()->type !== TokenType::RightParen) {
            throw new SyntaxErrorException('Missing closing parenthesis.');
        }
        $this->advance();
        return $evaluate ? $value : ExpressionValue::undefined();
    }

    /** @param list<mixed> $arguments */
    private function invokeFilter(string $name, ExpressionValue $input, array $arguments, Context $context): mixed
    {
        if ($this->securityPolicy !== null && !$this->securityPolicy->allowsFilter($name)) {
            throw new SecurityException(sprintf('Filter "%s" is not allowed by the security policy.', $name));
        }
        $filter = $this->filters[$name] ?? null;
        if ($filter === null) {
            throw new SyntaxErrorException(sprintf('Undefined filter "%s".', $name));
        }

        try {
            return $filter->apply($context, $input->value, $arguments, $input->defined);
        } catch (XtScriptException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PluginException(sprintf('Filter "%s" failed: %s', $name, $exception->getMessage()), previous: $exception);
        }
    }

    /** @param list<mixed> $arguments */
    private function invokeTest(string $name, ExpressionValue $input, array $arguments, Context $context): bool
    {
        if ($this->securityPolicy !== null && !$this->securityPolicy->allowsTest($name)) {
            throw new SecurityException(sprintf('Test "%s" is not allowed by the security policy.', $name));
        }
        $test = $this->tests[$name] ?? null;
        if ($test === null) {
            throw new SyntaxErrorException(sprintf('Undefined test "%s".', $name));
        }

        try {
            return $test->matches($context, $input->value, $arguments, $input->defined);
        } catch (XtScriptException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PluginException(sprintf('Test "%s" failed: %s', $name, $exception->getMessage()), previous: $exception);
        }
    }

    private function contains(mixed $haystack, mixed $needle): bool
    {
        if ($haystack instanceof Traversable) {
            $count = 0;
            foreach ($haystack as $value) {
                if (++$count > $this->collectionLimit) {
                    throw new SyntaxErrorException('Iterable exceeds the expression collection limit.');
                }
                if ($value === $needle) {
                    return true;
                }
            }
            return false;
        }
        if (is_array($haystack)) {
            return in_array($needle, $haystack, true);
        }
        if (is_string($haystack) || is_int($haystack) || is_float($haystack)) {
            return str_contains((string) $haystack, (string) $needle);
        }

        return false;
    }

    private function number(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return strpbrk($value, '.eE') === false ? (int) $value : (float) $value;
        }

        throw new SyntaxErrorException(sprintf('Expected numeric value, got %s.', get_debug_type($value)));
    }

    private function string(mixed $value): string
    {
        return match (true) {
            $value === null, $value === false => '',
            $value === true => '1',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => throw new SyntaxErrorException(sprintf('Expected stringable value, got %s.', get_debug_type($value))),
        };
    }

    private function matchOperator(string $operator): bool
    {
        if ($this->isOperator($operator)) {
            $this->advance();
            return true;
        }
        return false;
    }

    private function isOperator(string $operator): bool
    {
        return $this->current()->type === TokenType::Operator && $this->current()->lexeme === $operator;
    }

    private function peekOperator(string $operator): bool
    {
        $token = $this->tokens[$this->position + 1] ?? null;
        return $token instanceof Token && $token->type === TokenType::Operator && $token->lexeme === $operator;
    }

    private function current(): Token
    {
        return $this->tokens[$this->position];
    }

    private function advance(): Token
    {
        return $this->tokens[$this->position++];
    }
}
