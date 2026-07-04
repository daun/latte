<?php declare(strict_types=1);

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

namespace Latte\Linting;

use Latte;
use Latte\Compiler\Node;
use Latte\Compiler\Nodes\Php;
use Latte\Compiler\Nodes\Php\Expression;
use Latte\Compiler\Nodes\TemplateNode;
use Latte\Compiler\NodeTraverser;
use function defined;


/**
 * Checks that filters, functions, classes, methods, constants and properties referenced in template
 * expressions exist.
 */
final class SymbolCheck implements Check
{
	public function __construct(
		private readonly Latte\Engine $engine,
	) {
	}


	public function check(TemplateNode $node, string $name): iterable
	{
		$issues = [];
		(new NodeTraverser)->traverse($node, function (Node $node) use (&$issues) {
			if ($issue = $this->inspect($node)) {
				$issues[] = $issue;
			}
		});
		return $issues;
	}


	private function inspect(Node $node): ?Issue
	{
		if ($node instanceof Php\FilterNode) {
			return $this->validateFilter($node);

		} elseif ($node instanceof Expression\FunctionCallNode && $node->name instanceof Php\NameNode) {
			return $this->validateFunction($node);

		} elseif ($node instanceof Expression\NewNode && $node->class instanceof Php\NameNode) {
			return $this->validateNewObject($node);

		} elseif ($node instanceof Expression\StaticMethodCallNode
			&& $node->class instanceof Php\NameNode
			&& $node->name instanceof Php\IdentifierNode
		) {
			return $this->validateStaticMethod($node);

		} elseif ($node instanceof Expression\ClassConstantFetchNode
			&& $node->class instanceof Php\NameNode
			&& $node->name instanceof Php\IdentifierNode
		) {
			return $node->name->name === 'class'
				? $this->validateClassType($node->class)
				: $this->validateClassConstant($node);

		} elseif ($node instanceof Expression\ConstantFetchNode) {
			return $this->validateConstant($node);

		} elseif ($node instanceof Expression\InstanceofNode && $node->class instanceof Php\NameNode) {
			return $this->validateInstanceof($node);

		} elseif ($node instanceof Expression\StaticPropertyFetchNode
			&& $node->class instanceof Php\NameNode
			&& $node->name instanceof Php\VarLikeIdentifierNode
		) {
			return $this->validateStaticProperty($node);
		}

		return null;
	}


	private function validateFilter(Php\FilterNode $node): ?Issue
	{
		$name = $node->name->name;
		$filters = $this->engine->getFilters();
		return isset($filters[$name])
			? null
			: new Issue("Unknown filter |$name", $node->position);
	}


	private function validateFunction(Expression\FunctionCallNode $node): ?Issue
	{
		assert($node->name instanceof Php\NameNode);
		$name = (string) $node->name;
		return function_exists($name)
			? null
			: new Issue("Unknown function $name()", $node->position);
	}


	private function validateNewObject(Expression\NewNode $node): ?Issue
	{
		assert($node->class instanceof Php\NameNode);
		$className = (string) $node->class;
		return class_exists($className)
			? null
			: new Issue("Unknown class $className", $node->position);
	}


	private function validateStaticMethod(Expression\StaticMethodCallNode $node): ?Issue
	{
		assert($node->class instanceof Php\NameNode);
		assert($node->name instanceof Php\IdentifierNode);
		$className = (string) $node->class;
		$methodName = $node->name->name;
		return method_exists($className, $methodName)
			? null
			: new Issue("Unknown method $className::$methodName()", $node->position);
	}


	private function validateClassType(Php\NameNode $node): ?Issue
	{
		$className = (string) $node;
		return class_exists($className) || interface_exists($className) || trait_exists($className)
			? null
			: new Issue("Unknown class $className", $node->position);
	}


	private function validateClassConstant(Expression\ClassConstantFetchNode $node): ?Issue
	{
		assert($node->class instanceof Php\NameNode);
		assert($node->name instanceof Php\IdentifierNode);
		$name = "{$node->class}::{$node->name->name}";
		return defined($name)
			? null
			: new Issue("Unknown class constant $name", $node->position);
	}


	private function validateConstant(Expression\ConstantFetchNode $node): ?Issue
	{
		$magic = ['__LINE__' => 1, '__FILE__' => 1, '__DIR__' => 1];
		$name = (string) $node->name;
		return defined($name) || isset($magic[$name])
			? null
			: new Issue("Unknown constant $name", $node->position);
	}


	private function validateInstanceof(Expression\InstanceofNode $node): ?Issue
	{
		assert($node->class instanceof Php\NameNode);
		$className = (string) $node->class;
		return class_exists($className) || interface_exists($className)
			? null
			: new Issue("Unknown class $className in instanceof", $node->position);
	}


	private function validateStaticProperty(Expression\StaticPropertyFetchNode $node): ?Issue
	{
		assert($node->class instanceof Php\NameNode);
		assert($node->name instanceof Php\VarLikeIdentifierNode);
		$className = (string) $node->class;
		$propertyName = $node->name->name;
		return property_exists($className, $propertyName)
			? null
			: new Issue("Unknown static property $className::\$$propertyName", $node->position);
	}
}
