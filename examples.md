# Examples

Nine complete grammars. Each one is short enough to read in a sitting and
close enough to a real language to be worth stealing from, and each page
carries the grammar in full, the PHP that runs it, and a note about the one
thing it is there to show.

| Example | What it shows |
|---------|---------------|
| [JSON](/docs/examples/json) | A rule with no reducer, and the shape of a comma-separated list that may be empty |
| [Cron Expression](/docs/examples/cron) | Asking "is this valid?" with `Mode::SyntaxCheck`, without building a value |
| [Composer Constraints](/docs/examples/composer-constraints) | Where a grammar stops and PHP begins |
| [URL](/docs/examples/url) | Naming every part of a format `parse_url()` decides quietly |
| [Graphviz DOT](/docs/examples/dot) | Throwing three kinds of comment away with `%skip` |
| [EBNF](/docs/examples/ebnf) | Reading a notation that spells half of itself two ways |
| [Rule Engine](/docs/examples/rules) | Property and method chains an evaluator can walk against anything |
| [PhpDoc Types](/docs/examples/phpdoc-types) | Generics, array shapes and conditionals - a type language in earnest |
| [Regular Expressions](/docs/examples/regex) | Reading PCRE itself, groups and all |

Start with [JSON](/docs/examples/json): it is the smallest of them, and the
two habits it shows turn up in every grammar after it.
[PhpDoc Types](/docs/examples/phpdoc-types) and
[Regular Expressions](/docs/examples/regex) are the two largest, and the ones
to read once a grammar of your own has started to get long.

Every example is a `.pp3` file plus a few lines of PHP, so the fastest way to
try one is to copy the grammar into a file and hand it to the compiler:

```php
use Phplrt\Compiler\Compiler;
use Phplrt\Source\FileSource;

$parser = new Compiler()
    ->load(FileSource::createFromPathname(__DIR__ . '/example.pp3'))
    ->getParser();
```

[Quick Start](/docs/start/quick-start) walks through that from the beginning.

> **25+ more grammars.** [phplrt/grammars](https://github.com/phplrt/grammars)
> collects ready to read grammars for real languages - JSON5, TSV, semantic
> versions, DQL, PHQL, JMS types, PSR-5 and Doctrine annotations, Symfony
> expressions, Go! AOP pointcuts, Praspel contracts and more - each with sample
> inputs and a test that keeps it honest.
