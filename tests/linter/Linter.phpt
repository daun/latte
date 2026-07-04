<?php

/**
 * Test: Latte\Linting\Linter reports on its own paths (syntax, checks, generated code).
 */

declare(strict_types=1);

use Latte\Linting\Linter;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/** @return array{bool, string} */
function lint(string $name, string $source): array
{
	$engine = new Latte\Engine;
	$engine->setLoader(new Latte\Loaders\StringLoader([$name => $source]));
	$output = fopen('php://memory', 'w+');
	$ok = (new Linter($engine, output: $output))->lintLatte($name);
	rewind($output);
	return [$ok, stream_get_contents($output)];
}


// valid template: passes with no output
[$ok, $output] = lint('valid', '<p>{$x}</p>');
Assert::true($ok);
Assert::same('', $output);


// syntax error: fails and writes an ERROR
[$ok, $output] = lint('broken', '{if $x}unclosed');
Assert::false($ok);
Assert::contains('[ERROR]', $output);
Assert::contains('broken', $output);


// a check warning is written but does not fail the file
[$ok, $output] = lint('warn', '{$x|unknownFilterXyz}');
Assert::true($ok);
Assert::contains('[WARNING]', $output);
Assert::contains('unknownFilterXyz', $output);


// invalid generated PHP is reported via the built-in php linter (default engine only)
if (!str_contains(PHP_BINARY, 'phpdbg')) {
	$output = fopen('php://memory', 'w+');
	$ok = (new Linter(output: $output))->lintLatte(__DIR__ . '/templates/badcodegen.latte');
	rewind($output);
	Assert::false($ok);
	Assert::contains('[ERROR]', stream_get_contents($output));
}
