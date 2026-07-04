<?php declare(strict_types=1);

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

namespace Latte\Linting;

use Latte;
use Latte\Compiler\Position;
use Nette;
use function in_array, strlen;
use const DIRECTORY_SEPARATOR, PHP_BINARY, STDERR;


/**
 * Validates Latte template syntax and runs registered checks over each template.
 */
class Linter
{
	/** @var string[] */
	public array $excludedDirs = ['.*', '*.tmp', 'temp', 'vendor', 'node_modules'];

	/** @var Check[] */
	private array $checks = [];

	/** @var Check[]|null */
	private ?array $resolvedChecks = null;

	/** php binary used to lint generated code, set when the default engine is built */
	private ?string $phpBinary = null;


	public function __construct(
		private ?Latte\Engine $engine = null,
		private readonly bool $debug = false,
		private readonly bool $strict = false,
		/** @var resource|null  stream for error output; defaults to STDERR */
		private $output = null,
	) {
	}


	/**
	 * Registers a check run over every linted template. Built-in checks run first.
	 */
	public function addCheck(Check $check): static
	{
		$this->checks[] = $check;
		$this->resolvedChecks = null;
		return $this;
	}


	public function scanDirectory(string $path): bool
	{
		echo "Scanning $path\n";
		return $this->scanFiles($this->getFiles($path));
	}


	/**
	 * @param  iterable<\Stringable>  $files
	 */
	public function scanFiles(iterable $files): bool
	{
		$this->initialize();

		$counter = 0;
		$errors = 0;
		foreach ($files as $file) {
			$file = (string) $file;
			echo preg_replace('~\.?[/\\\]~A', '', $file), "\x0D";
			$errors += $this->lintLatte($file) ? 0 : 1;
			echo str_pad('...', strlen($file)), "\x0D";
			$counter++;
		}

		echo "Done (checked $counter files, found errors in $errors)\n";
		return !$errors;
	}


	private function createEngine(): Latte\Engine
	{
		$engine = new Latte\Engine;
		$this->phpBinary = PHP_BINARY; // the Linter validates the generated PHP itself (compile() is not used)
		$engine->setFeature(Latte\Feature::StrictParsing, $this->strict);
		$engine->addExtension(new Latte\Essential\TranslatorExtension(null));

		if (class_exists(Nette\Bridges\ApplicationLatte\UIExtension::class)) {
			$engine->addExtension(new Nette\Bridges\ApplicationLatte\UIExtension(null));
		}

		if (class_exists(Nette\Bridges\CacheLatte\CacheExtension::class)) {
			$engine->addExtension(new Nette\Bridges\CacheLatte\CacheExtension(new Nette\Caching\Storages\DevNullStorage));
		}

		if (class_exists(Nette\Bridges\FormsLatte\FormsExtension::class)) {
			$engine->addExtension(new Nette\Bridges\FormsLatte\FormsExtension);
		}

		if (class_exists(Nette\Bridges\AssetsLatte\LatteExtension::class)) {
			$engine->addExtension(new Nette\Bridges\AssetsLatte\LatteExtension(new Nette\Assets\Registry));
		}

		return $engine;
	}


	public function getEngine(): Latte\Engine
	{
		$this->engine ??= $this->createEngine();
		return $this->engine;
	}


	/**
	 * @return Check[]
	 */
	private function getChecks(): array
	{
		if ($this->resolvedChecks === null) {
			$engine = $this->getEngine();
			$this->resolvedChecks = array_merge(
				[new SymbolCheck($engine)],
				$this->checks,
			);
		}

		return $this->resolvedChecks;
	}


	public function lintLatte(string $file): bool
	{
		$engine = $this->getEngine();
		if ($this->debug) {
			echo $file, "\n";
		}

		try {
			$source = $engine->getLoader()->getContent($file);
		} catch (Latte\RuntimeException $e) {
			$this->writeError('ERROR', $file, $e->getMessage());
			return false;
		}

		if (str_starts_with($source, "\xEF\xBB\xBF")) {
			$this->writeError('WARNING', $file, 'contains BOM');
		}

		// parse once (under the handler so a deprecated construct in this file is reported, not leaked);
		// a syntax error is fatal and skips the rest
		$handler = $this->errorHandler($file);
		set_error_handler($handler);
		try {
			$node = $engine->parse($source);
		} catch (Latte\CompileException $e) {
			$this->writeCompileError($file, $e);
			return false;
		} finally {
			restore_error_handler();
		}

		$ok = true;

		// run checks on the as-written AST, handler-free: a reference check parsing other templates
		// must not misattribute their warnings here; a throwing check is isolated and cannot abort
		// the file or mask the others
		foreach ($this->getChecks() as $check) {
			try {
				foreach ($check->check($node, $file) as $issue) {
					$this->writeError('WARNING', $file . $this->formatPosition($issue->position), $issue->message);
				}
			} catch (\Throwable $e) {
				$this->writeError('ERROR', $file, 'check ' . $check::class . ' failed: ' . $e->getMessage());
				$ok = false;
			}
		}

		// apply passes and generate over the same AST (no re-parse): catches semantic pass errors,
		// invalid generated PHP and foreign deprecations/notices
		set_error_handler($handler);
		try {
			$engine->applyPasses($node);
			$code = $engine->generate($node, $file);
			if ($this->phpBinary !== null) {
				Latte\Compiler\PhpHelpers::checkCode($this->phpBinary, $code, "(compiled $file)");
			}
		} catch (Latte\CompileException $e) {
			$this->writeCompileError($file, $e);
			$ok = false;
		} catch (\Throwable $e) {
			$this->writeError('ERROR', $file, $e->getMessage());
			$ok = false;
		} finally {
			restore_error_handler();
		}

		return $ok;
	}


	private function errorHandler(string $file): \Closure
	{
		return function (int $severity, string $message) use ($file): bool {
			if (in_array($severity, [E_USER_DEPRECATED, E_USER_WARNING, E_USER_NOTICE], strict: true)) {
				$pos = preg_match('~on line (\d+)~', $message, $m) ? ':' . $m[1] : '';
				$label = $severity === E_USER_DEPRECATED ? 'DEPRECATED' : 'WARNING';
				$this->writeError($label, $file . $pos, $message);
				return true;
			}
			return false;
		};
	}


	private function writeCompileError(string $file, Latte\CompileException $e): void
	{
		if ($this->debug) {
			echo $e;
		}

		$this->writeError('ERROR', $file . $this->formatPosition($e->position), $e->getMessage());
	}


	private function formatPosition(?Position $position): string
	{
		return $position?->line
			? ':' . $position->line . ($position->column ? ':' . $position->column : '')
			: '';
	}


	private function initialize(): void
	{
		set_time_limit(0);

		if (PHP_SAPI !== 'cli') { // signal handling is only supported on the console
			return;
		}

		if (function_exists('pcntl_signal')) {
			pcntl_signal(SIGINT, function (): never {
				pcntl_signal(SIGINT, SIG_DFL);
				echo "Terminated\n";
				exit(1);
			});
		} elseif (function_exists('sapi_windows_set_ctrl_handler')) {
			sapi_windows_set_ctrl_handler(function (): never {
				echo "Terminated\n";
				exit(1);
			});
		}
	}


	private function getFiles(string $path): \Iterator
	{
		$it = match (true) {
			is_file($path) => new \ArrayIterator([$path]),
			is_dir($path) => $this->findLatteFiles($path),
			(bool) preg_match('~[*?]~', $path) => new \GlobIterator($path),
			default => throw new \InvalidArgumentException("File or directory '$path' not found."),
		};
		$it = new \CallbackFilterIterator($it, fn($file) => is_file((string) $file));
		return $it;
	}


	private function findLatteFiles(string $dir): \Generator
	{
		foreach (scandir($dir) as $name) {
			$path = ($dir === '.' ? '' : $dir . DIRECTORY_SEPARATOR) . $name;
			if ($name !== '.' && $name !== '..' && is_dir($path)) {
				foreach ($this->excludedDirs as $pattern) {
					if (fnmatch($pattern, $name)) {
						continue 2;
					}
				}
				yield from $this->findLatteFiles($path);

			} elseif (str_ends_with($name, '.latte')) {
				yield $path;
			}
		}
	}


	private function writeError(string $label, string $file, string $message): void
	{
		// STDERR is undefined outside the CLI SAPI
		$handle = $this->output ?? (defined('STDERR') ? STDERR : fopen('php://stderr', 'w'));
		if (!$handle) {
			return;
		}

		fwrite($handle, str_pad("[$label]", 13) . ' ' . $file . '    ' . $message . "\n");
	}
}
