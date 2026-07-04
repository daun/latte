<?php

/**
 * Test: backward-compatible aliases Latte\Tools\Linter and Latte\Tools\LinterExtension.
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


Assert::true(class_exists(Latte\Tools\Linter::class));
Assert::true(class_exists(Latte\Tools\LinterExtension::class));


// the old usage pattern keeps working: the no-op extension is harmless and the aliased Linter runs
$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader);
$latte->addExtension(new Latte\Tools\LinterExtension);

$linter = new Latte\Tools\Linter($latte);
Assert::type(Latte\Linting\Linter::class, $linter);

ob_start();
$ok = $linter->scanFiles([]);
ob_end_clean();
Assert::true($ok);
