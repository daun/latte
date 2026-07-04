<?php declare(strict_types=1);

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

namespace Latte\Linting;

use Latte\Compiler\Nodes\TemplateNode;


/**
 * A linter check inspects a parsed template and reports problems as Issues.
 * Checks are registered with the Linter via Linter::addCheck().
 */
interface Check
{
	/**
	 * @param  string  $name  name of the linted template as passed to the loader
	 * @return iterable<Issue>
	 */
	public function check(TemplateNode $node, string $name): iterable;
}
