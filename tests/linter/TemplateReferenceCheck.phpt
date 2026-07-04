<?php

/**
 * Test: Latte\Linting\TemplateReferenceCheck validates template references.
 */

declare(strict_types=1);

use Latte\Linting\Issue;
use Latte\Linting\TemplateReferenceCheck;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


function runRefCheck(Latte\Engine $engine, string $file): array
{
	$check = new TemplateReferenceCheck($engine);
	$ast = $engine->parse($engine->getLoader()->getContent($file));
	return array_map(
		fn(Issue $i) => $i->message . ($i->position ? ' ' . $i->position : ''),
		[...$check->check($ast, $file)],
	);
}


$engine = new Latte\Engine;
$engine->setLoader(new Latte\Loaders\FileLoader(__DIR__ . '/templates/refs'));


// valid references and statically undeterminable ones stay silent
Assert::same([], runRefCheck($engine, 'ok.latte'));


// missing files are reported
Assert::same([
	"Missing template 'missingA.latte' on line 1 at column 10",
	"Missing template 'missingB.latte' on line 2 at column 9",
	"Missing template 'missingC.latte' on line 3 at column 8",
	"Missing template 'missingD.latte' on line 4 at column 10",
	"Missing template 'missingE.latte' on line 5 at column 19",
], runRefCheck($engine, 'bad.latte'));


// missing parent in {extends}
Assert::same([
	"Missing template 'gone.latte' on line 1 at column 10",
], runRefCheck($engine, 'bad_extends.latte'));


// a loader that cannot resolve references (StringLoader without a map) reports nothing
$string = new Latte\Engine;
$string->setLoader(new Latte\Loaders\StringLoader);
Assert::same([], runRefCheck($string, "{include 'whatever.latte'}\n{include foo from 'whatever.latte'}"));


// StringLoader with a map resolves references like a filesystem loader
$mapped = new Latte\Engine;
$mapped->setLoader(new Latte\Loaders\StringLoader([
	'main' => "{include 'child.latte'}\n{include 'missing.latte'}",
	'child.latte' => 'hello',
]));
Assert::same([
	"Missing template 'missing.latte' on line 2 at column 10",
], runRefCheck($mapped, 'main'));
