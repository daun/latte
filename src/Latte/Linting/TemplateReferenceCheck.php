<?php declare(strict_types=1);

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

namespace Latte\Linting;

use Latte;
use Latte\Compiler\Node;
use Latte\Compiler\Nodes\Php\Scalar;
use Latte\Compiler\Nodes\TemplateNode;
use Latte\Compiler\NodeTraverser;
use Latte\Essential\Nodes as EssentialNodes;
use Latte\Sandbox\Nodes\SandboxNode;


/**
 * Checks that statically named templates referenced by {include}, {import}, {extends}/{layout},
 * {embed}, {sandbox} and {include block from} exist. Needs a loader that can resolve references
 * (i.e. not a bare StringLoader), otherwise it silently reports nothing.
 */
final class TemplateReferenceCheck implements Check
{
	public function __construct(
		private readonly Latte\Engine $engine,
	) {
	}


	public function check(TemplateNode $node, string $name): iterable
	{
		$issues = [];
		(new NodeTraverser)->traverse($node, function (Node $node) use ($name, &$issues) {
			if ($node instanceof EssentialNodes\IncludeFileNode && $node->file instanceof Scalar\StringNode) {
				$this->checkTemplateExists($node->file, $name, $issues);

			} elseif ($node instanceof EssentialNodes\ImportNode && $node->file instanceof Scalar\StringNode) {
				$this->checkTemplateExists($node->file, $name, $issues);

			} elseif ($node instanceof EssentialNodes\ExtendsNode && $node->extends instanceof Scalar\StringNode) {
				$this->checkTemplateExists($node->extends, $name, $issues);

			} elseif (
				$node instanceof EssentialNodes\EmbedNode
				&& $node->mode === 'file'
				&& $node->name instanceof Scalar\StringNode
			) {
				$this->checkTemplateExists($node->name, $name, $issues);

			} elseif ($node instanceof SandboxNode && $node->file instanceof Scalar\StringNode) {
				$this->checkTemplateExists($node->file, $name, $issues);

			} elseif ($node instanceof EssentialNodes\IncludeBlockNode && $node->from instanceof Scalar\StringNode) {
				$this->checkTemplateExists($node->from, $name, $issues);
			}
		});
		return $issues;
	}


	/**
	 * Reports an Issue when a statically named template does not exist. Returns its resolved name, or null.
	 * @param  list<Issue>  $issues
	 */
	private function checkTemplateExists(Scalar\StringNode $node, string $referringName, array &$issues): ?string
	{
		$loader = $this->engine->getLoader();
		try {
			$name = $loader->getReferredName($node->value, $referringName);
		} catch (Latte\TemplateNotFoundException) {
			return null; // loader cannot resolve references (e.g. StringLoader without map)
		}

		try {
			$loader->getContent($name);
			return $name;
		} catch (Latte\TemplateNotFoundException) {
			$issues[] = new Issue("Missing template '{$node->value}'", $node->position);
		} catch (Latte\RuntimeException) {
			// unreadable file or outside base directory - not the linter's concern
		}

		return null;
	}
}
