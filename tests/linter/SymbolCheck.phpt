<?php

/**
 * Test: Latte\Linting\SymbolCheck validates filters, functions, classes, methods, constants.
 */

declare(strict_types=1);

use Latte\Linting\Issue;
use Latte\Linting\SymbolCheck;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$engine = new Latte\Engine;
$check = new SymbolCheck($engine);

$run = function (string $file) use ($engine, $check): array {
	$ast = $engine->parse(file_get_contents(__DIR__ . '/templates/' . $file));
	return array_map(
		fn(Issue $i) => $i->message . ($i->position ? ' ' . $i->position : ''),
		[...$check->check($ast, $file)],
	);
};


// valid constructs produce no issues
Assert::same([], $run('known.latte'));


// unknown symbols are reported with position
Assert::same([
	'Unknown filter |unknownFilter on line 2 at column 7',
	'Unknown function unknownFunction() on line 3 at column 3',
	'Unknown function unknownFunction() on line 4 at column 3',
	'Unknown class UnknownClass on line 5 at column 13',
	'Unknown method DateTime::unknownMethod() on line 6 at column 3',
	'Unknown method DateTime::unknownMethod() on line 7 at column 3',
	'Unknown class constant DateTime::UNKNOWN_CONSTANT on line 8 at column 3',
	'Unknown constant UNKNOWN_GLOBAL_CONSTANT on line 9 at column 3',
	'Unknown class UnknownClass in instanceof on line 10 at column 5',
	'Unknown static property DateTime::$unknownProperty on line 11 at column 3',
	'Unknown class UnknownClass on line 12 at column 3',
], $run('unknown.latte'));
