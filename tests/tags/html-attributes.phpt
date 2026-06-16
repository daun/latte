<?php

/**
 * Test: types of unorthodox html attributes
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader);


Assert::match(
	'<button hx-get="/url"></button>',
	$latte->renderToString('<button hx-get="/url"></button>'),
);


Assert::match(
	'<button x-on:click="close()"></button>',
	$latte->renderToString('<button x-on:click="close()"></button>'),
);


Assert::match(
	'<button @click="close"></button>',
	$latte->renderToString('<button @click="close"></button>'),
);
