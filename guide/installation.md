# Installation

Phplrt is installed with [composer](https://getcomposer.org/doc/00-intro.md).

## Requirements

  * PHP 8.4 or above
  * [PCRE Extension](https://php.net/manual/en/book.pcre.php)
  * [Mbstring Extension](https://www.php.net/manual/en/mbstring.installation.php) (recommended)

## Everything At Once

```bash
composer require phplrt/phplrt
```

This installs **everything**: the runtime, the builders, the grammar compiler
and what a code generator needs along with it - a console, a template engine
and a code printer.

Use it to try phplrt out in a single file. DO NOT USE it in an application:
the whole toolchain ends up in production, where only the runtime belongs.

## Only What You Need

Install the runtime where the parser runs, and the compiler only where
grammars are built:

```bash
composer require phplrt/runtime
composer require phplrt/compiler --dev
```

```json
{
    "require": {
        "phplrt/runtime": "^4.0"
    },
    "require-dev": {
        "phplrt/compiler": "^4.0"
    }
}
```

A generated parser is plain PHP that refers to the runtime alone, so the
compiler never has to be shipped. It is needed in production only when the
grammar file itself is read on every run - fine for a script or a one-off
tool, slower for anything else.

`phplrt/runtime` is a metapackage. Name the pieces yourself if you prefer:

| Package                  | What it does                                                    |
|--------------------------|-----------------------------------------------------------------|
| `phplrt/source`          | Reads source code from files, strings and streams               |
| `phplrt/position`        | Converts an offset into a line and a column, and back           |
| `phplrt/lexer`           | Splits source code into tokens                                  |
| `phplrt/parser`          | Recognizes tokens against a grammar and builds a result         |
| `phplrt/exception`       | Renders errors with a snippet of the code around them           |
| `phplrt/lexer-builder`   | Describes a lexer in PHP and compiles it                        |
| `phplrt/parser-builder`  | Describes a grammar in PHP and compiles it                      |
| `phplrt/compiler`        | Reads `*.pp2` or `*.pp3` grammar files and generates PHP code   |

## Autoloading

As with any composer package, include the autoloader:

```php
require __DIR__ . '/vendor/autoload.php';
```
