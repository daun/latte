<?php declare(strict_types=1);

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

namespace Latte\Linting;

use Latte\Compiler\Position;


/**
 * A problem reported by a Check.
 */
final class Issue
{
	public function __construct(
		public string $message,
		public ?Position $position = null,
	) {
	}
}
