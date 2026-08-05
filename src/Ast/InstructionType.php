<?php

declare(strict_types=1);

namespace XtScript\Ast;

enum InstructionType: string
{
    case Text = 'text';
    case Assign = 'assign';
    case Delete = 'delete';
    case Get = 'get';
    case GetOrDefault = 'get_or_default';
    case Print = 'print';
    case PrintRaw = 'print_raw';
    case Return = 'return';
    case Call = 'call';
    case Include = 'include';
    case Extends = 'extends';
    case Block = 'block';
    case Parent = 'parent';
    case Component = 'component';
    case Capture = 'capture';
    case Cache = 'cache';
    case With = 'with';
    case Do = 'do';
    case Once = 'once';
    case Apply = 'apply';
    case AutoEscape = 'autoescape';
    case Beautify = 'beautify';
    case Minify = 'minify';
    case Import = 'import';
    case Verbatim = 'verbatim';
    case Push = 'push';
    case Prepend = 'prepend';
    case Stack = 'stack';
    case If = 'if';
    case Foreach = 'foreach';
    case Switch = 'switch';
    case Break = 'break';
    case Continue = 'continue';
    case Function = 'function';
    case Label = 'label';
    case Goto = 'goto';
    case CustomTag = 'custom_tag';
    case CustomBlockTag = 'custom_block_tag';
}
