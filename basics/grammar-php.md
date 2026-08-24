# PHP in a Grammar

A grammar on its own only answers "is this valid?". To get a *value* out of a
parse - a number, an AST node, a configuration array - you attach PHP to a
rule. That piece of PHP is called a **reducer**, and it runs when the rule
matches.

This page is about writing one in a `.pp3` file: where the code goes, what the
compiler puts around it, and what happens to it when the parser is generated.
What a reducer is handed and what it should return is
[Results and Reducers](/docs/basics/reducers).

## A Block of Code

Put it between `->` and the rule body:

```pp3
Number -> { return (int) $children->value; }
  : <T_DIGIT>
  ;
```

Whatever it returns becomes the value of the rule. The code is ordinary PHP -
loops, conditionals, whatever you need:

```pp3
Name -> {
    $name = $children->value;

    if (\str_starts_with($name, '$')) {
        return new \App\Ast\VariableNode($offset, \substr($name, 1));
    }

    return new \App\Ast\ConstantNode($offset, $name);
}
  : <T_NAME>
  ;
```

Braces inside strings are safe - the block is read by a real PHP lexer, so
`"{"` is a string, not the end of the block.

An empty block (`-> {}`) is the same as writing no reducer at all.

## The Variables

Inside a code block, these are available:

| Variable    | What it is                                             |
|-------------|--------------------------------------------------------|
| `$children` | What the rule matched. **This is the important one.**  |
| `$ctx`      | The full `Context` object                              |
| `$token`    | The last token the rule read, or `null`                |
| `$offset`   | Where that token starts, in bytes                      |
| `$source`   | The source being parsed                                |
| `$content`  | The whole content of that source                       |
| `$rule`     | The id of the rule being reduced                       |

All except `$children` and `$ctx` are shorthands the compiler expands for
you - `$offset` becomes `$ctx->token->offset`, and so on. They are only
declared if you use them, so there is no cost to the ones you do not.

What `$children` holds depends on what the rule matched - a token, an array of
them, or whatever the reducers below it returned. See
[What `$children` Contains](/docs/basics/reducers#what-children-contains).

## In Generated Code

When you [generate a parser](/docs/basics/generation), reducers become real
methods, named after the rule they belong to:

```php
private static function reduceNumber(\Phplrt\Parser\Context $ctx, mixed $children): mixed
{
    return (float) $children->value;
}
```

Two practical consequences.

**Your code appears verbatim in the generated file.** It is debuggable and
steppable, and a syntax error in a reducer is a syntax error in that file -
so run the generator as part of your build, not at deploy time.

**A grammar file has no `use` statements**, so how a short class name resolves
depends on where the reducer ends up - the global namespace when the grammar
is read on the fly, the generated file's namespace when it is generated. The
safe answer is to write class names fully qualified:

```pp3
// ✔ works either way
Number -> { return new \App\Ast\NumberNode($offset, $children->value); }
```

If the fully qualified names make a big grammar unreadable, you can declare
the imports on the generated file instead:

```php
new Compiler()
    ->load(FileSource::createFromPathname(__DIR__ . '/grammar.pp3'))
    ->generate()
        ->withNamespaceName('App\Parser')
        ->withClassImport('App\Ast\NumberNode')
        ->save(__DIR__ . '/Parser.php');
```

```pp3
Number -> { return new NumberNode($offset, $children->value); }
```

The trade-off: that grammar now only works when generated. Pick one approach
per project rather than mixing them.
