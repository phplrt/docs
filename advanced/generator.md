# Custom Generator

A PHP parser is not the only thing a compiled grammar is good for. An editor
plugin wants the token list; a syntax highlighter wants the pattern; a client
in another language wants the rules. All of that is in `CompilerResult`, and
the interface for turning it into a file has one method:

```php
public function generate(
    CompilerResult $result,
    OutputContext $context = new OutputContext(),
): string;
```

Return a string, and everything else - `withNamespaceName()`, `save()`, creating
directories - keeps working as before.

Here is a generator that writes the token list down as JSON, for a
highlighter that needs to know the words of a language but not its grammar:

```php
use Phplrt\Compiler\CompilerResult;
use Phplrt\Compiler\Generator\OutputContext;
use Phplrt\Compiler\Generator\OutputGeneratorInterface;

final readonly class TokenListGenerator implements OutputGeneratorInterface
{
    public function generate(
        CompilerResult $result,
        OutputContext $context = new OutputContext(),
    ): string {
        $tokens = [];

        foreach ($result->lexer->tokens as $id => $definition) {
            $tokens[] = [
                'id' => $id,
                'name' => $result->lexer->names[$id] ?? null,
                'channel' => $result->lexer->channels[$id] ?? 'Default',
            ];
        }

        return \json_encode([
            'name' => $context->class ?? 'grammar',
            'pattern' => $result->lexer->pattern,
            'tokens' => $tokens,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
    }
}
```

Pass it to `generate()` in place of the default:

```php
new Compiler()
    ->load(FileSource::createFromPathname(__DIR__ . '/grammar.pp3'))
    ->generate(new TokenListGenerator())
        ->withClassName('Calculator')
        ->save(__DIR__ . '/calculator.json');
```

Given this grammar:

```pp3
%skip  T_WHITESPACE  \s++
%skip  T_COMMENT     //[^\n]*+

%token T_NUMBER      \d++
%token T_PLUS        \+

Sum -> { return \array_sum($children); }
  : <T_NUMBER> (::T_PLUS:: <T_NUMBER>)*
  ;
```

you get `calculator.json`:

```json
{
    "name": "Calculator",
    "pattern": "/\\G(?|(?:(?:\\s++)(*MARK:0))|(?:(?:\\/\\/[^\\n]*+)(*MARK:1))|(?:(?:\\d++)(*MARK:2))|(?:(?:\\+)(*MARK:3))|(?:(?:[^\\s]++)(*MARK:4)))/Ssum",
    "tokens": [
        {
            "id": 0,
            "name": "T_WHITESPACE",
            "channel": "Hidden"
        },
        {
            "id": 1,
            "name": "T_COMMENT",
            "channel": "Hidden"
        },
        {
            "id": 2,
            "name": "T_NUMBER",
            "channel": "Default"
        },
        {
            "id": 3,
            "name": "T_PLUS",
            "channel": "Default"
        },
        {
            "id": 4,
            "name": null,
            "channel": "Unknown"
        }
    ]
}
```

Two things the output shows that the grammar does not say out loud: the
`%skip` tokens are ordinary tokens on the `Hidden` channel, and token `#4` is
the catch-all the compiler appends so that unrecognized input becomes an
`Unknown` token instead of an error.

## What You Get To Work With

```php
$result->lexer->tokens;      // array<int, TokenDefinition>
$result->lexer->names;       // array<int, non-empty-string>
$result->lexer->channels;    // array<int, non-empty-string>
$result->lexer->pattern;     // the compiled regex
$result->lexer->transitions; // which tokens enter a nested lexer
$result->lexer->lexers;      // the nested lexers themselves

$result->parser->grammar;    // list<RuleInterface> - the rules, by id
$result->parser->initial;    // where parsing starts
$result->parser->reducers;   // array<int, ReducerInterface>
$result->parser->constants;  // ['Sum' => 0, ...] - named rules
$result->parser->lookahead;
$result->parser->kept;
```

`OutputContext` carries what the caller asked for - `$context->namespace`,
`$context->class`, `$context->imports`. Use what makes sense for your format
and ignore the rest.

## Reporting Problems

Not every grammar can be written into every format. Throw a
`GeneratorException` when yours cannot express something, so the failure
arrives as a compiler error rather than as broken output:

```php
use Phplrt\Compiler\Exception\GeneratorException;

if ($result->lexer->lexers !== []) {
    throw new class ('Nested lexers cannot be written as JSON')
        extends GeneratorException {};
}
```

## Adjusting The PHP Output Instead

If you only want to change how the *PHP* looks, you do not need a generator
of your own. `PhpOutputGenerator` renders [Twig](https://twig.symfony.com/)
templates from the compiler's `resources/php/` directory, and they are split
per fragment - the file layout, the parser body, the grammar table, the
reducer methods, the lexer and its states - so a change is usually confined
to one small template.
