<?php

/**
 * razymod/apps — typed expression error.
 *
 * Every failure in the expression pipeline (lexer, parser, type checker,
 * evaluator) is one of these, never a bare PHP error: callers switch on
 * getErrorCode(), which is a stable string listed below. The offset is the
 * byte position in the source text when the error came from the source
 * (parse / type check), or -1 at evaluation time.
 */

declare(strict_types=1);

namespace razymod\apps\Expression;

use RuntimeException;

final class ExpressionError extends RuntimeException
{
    /** Source text is not valid UTF-8 or is empty. */
    public const ENCODING = 'expr.encoding';

    /** Source text is longer than the expression_length cap. */
    public const LENGTH = 'expr.length';

    /** Unexpected character or token, or unterminated string. */
    public const SYNTAX = 'expr.syntax';

    /** AST deeper than the expression_depth cap. */
    public const DEPTH = 'expr.depth';

    /** AST has more nodes than the expression_nodes cap. */
    public const NODES = 'expr.nodes';

    /** String or number literal longer than its cap. */
    public const LITERAL = 'expr.literal';

    /** A function name that is not in the closed list. */
    public const UNKNOWN_FUNCTION = 'expr.unknown_function';

    /** Wrong number of arguments for a function. */
    public const ARITY = 'expr.arity';

    /** Operand or argument of the wrong type, or wrong result type for the site. */
    public const TYPE = 'expr.type';

    /** A field path that does not resolve in the entity scope. */
    public const UNKNOWN_FIELD = 'expr.unknown_field';

    /** A text literal compared with a select / status field is not one of its options. */
    public const UNKNOWN_OPTION = 'expr.unknown_option';

    /** Computed fields that reference each other in a loop. */
    public const CYCLE = 'expr.cycle';

    /** A function or field that cannot run as a SQL predicate where one is required. */
    public const NOT_SQL = 'expr.not_sql';

    /** Evaluation used more steps than the eval_steps budget. */
    public const BUDGET = 'expr.budget';

    /** A string or list produced at evaluation time is over its cap. */
    public const SIZE = 'expr.size';

    /** Division or MOD by zero. */
    public const DIV_ZERO = 'expr.div_zero';

    /** A value that does not fit (bad date, NaN / infinity, out-of-range argument). */
    public const VALUE = 'expr.value';

    private string $errorCode;

    private int $offset;

    public function __construct(string $errorCode, string $message, int $offset = -1)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->offset = $offset;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }
}
