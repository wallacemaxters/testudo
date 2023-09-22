# Testudo

The Testudo (joke with "big forehead" in portuguese) is a code generator for common TestCases in Laravel.

## Install

```bash
composer require wallacemaxters/testudo
```

## Example

```bash
php artisan testudo:make-model-test Post
```

This command will be generate the file `tests/Unit/Models/PostTest.php`.

## Features
- Generate tests for your Model relationships
- Generate tests for your Model appended attributes 
- Generate test for your Model primaryKey name
- Generate test for your table name

## Future
- Generate common tests for controllers, based on defined routes.
