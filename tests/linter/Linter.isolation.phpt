<?php

/**
 * Test: Latte\Linting\Linter isolates a throwing check.
 */

declare(strict_types=1);

use Latte\Compiler\Nodes\TemplateNode;
use Latte\Linting\Check;
use Latte\Linting\Linter;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$engine = new Latte\Engine;
$engine->setLoader(new Latte\Loaders\StringLoader(['tmpl' => 'hello world']));

$recorder = new class implements Check {
	public bool $ran = false;


	public function check(TemplateNode $node, string $name): iterable
	{
		$this->ran = true;
		return [];
	}
};

$linter = new Linter($engine);
$linter->addCheck(new class implements Check {
	public function check(TemplateNode $node, string $name): iterable
	{
		throw new RuntimeException('boom');
	}
});
$linter->addCheck($recorder);

// a throwing check makes the file fail but does not abort or mask the following checks
$ok = $linter->lintLatte('tmpl');
Assert::false($ok);
Assert::true($recorder->ran);
