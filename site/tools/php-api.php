<?php
declare(strict_types=1);

/**
 * Emit one package's public PHP surface as JSON for the documentation builder.
 *
 * The optional argument is a repository-relative package directory. Omitting it
 * preserves the original core-Phonics command interface.
 */

$root = dirname(__DIR__, 2);
$argument = $argv[1] ?? '.';
$packageRoot = realpath($root . '/' . $argument);

if($packageRoot === false || !is_file($packageRoot . '/phonics.json'))
{
	throw new InvalidArgumentException("Cannot find a Phonics package at $argument");
}

require_once $root . '/autoload.php';

$manifest = json_decode(file_get_contents($packageRoot . '/phonics.json'), true, flags: JSON_THROW_ON_ERROR);

foreach($manifest['php']['bootstrap'] ?? [] as $bootstrap)
{
	$path = $packageRoot . '/' . $bootstrap;

	if(is_file($path)) require_once $path;
}

/** Parse the small PHPDoc vocabulary used by the framework and game packages. */
function apiDoc(string|false $comment): array
{
	if(!$comment) return ['summary' => '', 'description' => '', 'params' => [], 'return' => null, 'throws' => []];

	$lines = preg_split('/\R/', substr($comment, 3, -2));
	$body = [];

	foreach($lines as $line)
	{
		$line = ltrim(preg_replace('/^\s*\*\s?/', '', $line));

		// Compact package docs commonly put several PHPDoc tags on one line.
		// Split only the vocabulary understood below so prose containing an @
		// character is left intact.
		foreach(preg_split('/\s+(?=[@\\\\](?:param|return|throws|var|deprecated|see)\b)/i', $line) as $part)
			$body[] = $part;
	}

	$description = [];
	$tags = [];
	$current = null;

	foreach($body as $line)
	{
		if(preg_match('/^(?:@|\\\\)(\w+)\s*(.*)$/', $line, $match))
		{
			$current = count($tags);
			$tags[] = ['name' => strtolower($match[1]), 'value' => trim($match[2])];
		}
		elseif($current !== null && trim($line) !== '')
		{
			$tags[$current]['value'] .= ' ' . trim($line);
		}
		elseif($current === null)
		{
			$description[] = rtrim($line);
		}
	}

	$text = trim(implode("\n", $description));
	$paragraphs = preg_split('/\n\s*\n/', $text, 2);
	$result = [
		'summary' => trim($paragraphs[0] ?? '')
		, 'description' => trim($paragraphs[1] ?? '')
		, 'params' => []
		, 'return' => null
		, 'throws' => []
	];

	foreach($tags as $tag)
	{
		if($tag['name'] === 'param'
			&& preg_match('/^(\S+)\s+(?:&)?(?:\.\.\.)?\$(\w+)\s*(.*)$/', $tag['value'], $match)
		){
			$result['params'][$match[2]] = ['type' => $match[1], 'description' => trim($match[3])];
		}
		elseif($tag['name'] === 'return' && preg_match('/^(\S+)\s*(.*)$/', $tag['value'], $match))
		{
			$result['return'] = ['type' => $match[1], 'description' => trim($match[2])];
		}
		elseif($tag['name'] === 'throws' && preg_match('/^(\S+)\s*(.*)$/', $tag['value'], $match))
		{
			$result['throws'][] = ['type' => $match[1], 'description' => trim($match[2])];
		}
		elseif(in_array($tag['name'], ['var','deprecated','see'], true))
		{
			$result[$tag['name']][] = $tag['value'];

			if($tag['name'] === 'var'
				&& $result['summary'] === ''
				&& preg_match('/^\S+\s+(.+)$/', $tag['value'], $match)
			){
				$result['summary'] = trim($match[1]);
			}
		}
	}

	return $result;
}

/** Render a reflection type without losing nullable, union, or intersection syntax. */
function apiType(?ReflectionType $type): string
{
	if(!$type) return '';

	if($type instanceof ReflectionNamedType)
	{
		$name = $type->getName();

		return $type->allowsNull() && !in_array($name, ['mixed','null'], true) ? '?' . $name : $name;
	}

	$separator = $type instanceof ReflectionIntersectionType ? '&' : '|';

	return implode($separator, array_map(apiType(...), $type->getTypes()));
}

/** Produce compact PHP syntax for a public default value. */
function apiValue(mixed $value): string
{
	if($value instanceof UnitEnum) return $value::class . '::' . $value->name;

	$text = var_export($value, true);
	$text = preg_replace('/\barray \(/', '[', $text);
	$text = preg_replace('/\)(,?)(\n|$)/', ']$1$2', $text);
	$text = preg_replace('/\s+/', ' ', $text);
	$text = str_replace(['[ ', ' ]'], ['[', ']'], trim($text));

	return $text;
}

/** Find the first named declaration in a source file without matching comments. */
function apiDeclaration(string $source, string $file): string
{
	$tokens = token_get_all($source, TOKEN_PARSE);
	$namespace = '';
	$declarations = [T_CLASS, T_INTERFACE, T_TRAIT];

	if(defined('T_ENUM')) $declarations[] = T_ENUM;

	foreach($tokens as $index => $token)
	{
		if(!is_array($token)) continue;

		if($token[0] === T_NAMESPACE)
		{
			$namespace = '';

			for($next = $index + 1; isset($tokens[$next]); $next++)
			{
				$part = $tokens[$next];

				if($part === ';' || $part === '{') break;
				if(is_array($part) && in_array($part[0], [T_STRING,T_NAME_QUALIFIED,T_NS_SEPARATOR], true))
					$namespace .= $part[1];
			}
		}

		if(!in_array($token[0], $declarations, true)) continue;

		$previous = $index - 1;

		while($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) $previous--;
		if($token[0] === T_CLASS && $previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_NEW)
			continue;

		for($next = $index + 1; isset($tokens[$next]); $next++)
			if(is_array($tokens[$next]) && $tokens[$next][0] === T_STRING)
				return ($namespace === '' ? '' : $namespace . '\\') . $tokens[$next][1];
	}

	throw new RuntimeException("Cannot discover public declaration in $file");
}

/**
 * Locate each public property declaration, including promoted constructor parameters.
 *
 * ReflectionProperty has no start line, so the class body is scanned at brace depth one.
 */
function apiPropertyLines(string $source, int $classLine): array
{
	$tokens = token_get_all($source, TOKEN_PARSE);
	$lines = [];
	$depth = $parens = 0;
	$started = $modifier = false;
	$modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR];

	if(defined('T_READONLY')) $modifiers[] = T_READONLY;

	$line = 1;

	foreach($tokens as $token)
	{
		$text = is_array($token) ? $token[1] : $token;
		$line = is_array($token) ? $token[2] : $line;

		if(!$started)
		{
			$started = $depth = $text === '{' && $line >= $classLine ? 1 : 0;

			continue;
		}

		$opens = $text === '{'
			|| is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);

		if($opens) $depth++;
		elseif($text === '}' && --$depth === 0) break;
		elseif($depth !== 1) continue;
		elseif($text === '(') $parens++;
		elseif($text === ')') $parens--;
		elseif($text === ',' || $text === ';' || is_array($token) && $token[0] === T_FUNCTION) $modifier = false;
		elseif(is_array($token) && in_array($token[0], $modifiers, true)) $modifier = true;
		elseif(is_array($token) && $token[0] === T_VARIABLE && ($parens === 0 || $modifier))
			$lines[substr($token[1], 1)] ??= $token[2];
	}

	return $lines;
}

$files = array_values(array_filter(
	$manifest['php']['files'] ?? []
	, static fn(string $file): bool => str_starts_with($file, 'src/') && str_ends_with($file, '.php')
));
$classes = [];
$relativeRoot = str_replace('\\', '/', substr($packageRoot, strlen($root)));
$relativeRoot = trim($relativeRoot, '/');

foreach($files as $file)
{
	$source = file_get_contents($packageRoot . '/' . $file);
	$name = apiDeclaration($source, $file);
	$class = new ReflectionClass($name);
	$constructor = $class->getConstructor();
	$constructorDoc = $constructor ? apiDoc($constructor->getDocComment()) : null;
	$propertyLines = apiPropertyLines($source, $class->getStartLine());
	$properties = [];

	foreach($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property)
	{
		if($property->getDeclaringClass()->getName() !== $name) continue;

		$doc = apiDoc($property->getDocComment());

		if($doc['summary'] === '' && $property->isPromoted() && isset($constructorDoc['params'][$property->getName()]))
		{
			$doc['summary'] = $constructorDoc['params'][$property->getName()]['description'];
			$doc['var'] = [$constructorDoc['params'][$property->getName()]['type']];
		}

		$properties[] = [
			'name' => $property->getName()
			, 'type' => apiType($property->getType())
			, 'static' => $property->isStatic()
			, 'readonly' => method_exists($property, 'isReadOnly') && $property->isReadOnly()
			, 'default' => $property->hasDefaultValue() ? apiValue($property->getDefaultValue()) : null
			, 'doc' => $doc
			, 'line' => $propertyLines[$property->getName()] ?? $class->getStartLine()
		];
	}

	$constants = [];

	foreach($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant)
	{
		if($constant->getDeclaringClass()->getName() !== $name) continue;
		if(method_exists($constant, 'isEnumCase') && $constant->isEnumCase()) continue;

		$constants[] = [
			'name' => $constant->getName()
			, 'value' => apiValue($constant->getValue())
			, 'doc' => apiDoc($constant->getDocComment())
			, 'line' => method_exists($constant, 'getStartLine') ? $constant->getStartLine() : $class->getStartLine()
		];
	}

	$methods = [];

	foreach($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method)
	{
		if($method->getDeclaringClass()->getName() !== $name) continue;

		$doc = apiDoc($method->getDocComment());
		$params = [];

		foreach($method->getParameters() as $parameter)
		{
			$default = null;

			if($parameter->isDefaultValueAvailable())
			{
				$default = $parameter->isDefaultValueConstant()
					? $parameter->getDefaultValueConstantName()
					: apiValue($parameter->getDefaultValue());
			}

			$params[] = [
				'name' => $parameter->getName()
				, 'type' => apiType($parameter->getType())
				, 'byReference' => $parameter->isPassedByReference()
				, 'variadic' => $parameter->isVariadic()
				, 'default' => $default
				, 'doc' => $doc['params'][$parameter->getName()] ?? null
			];
		}

		$methods[] = [
			'name' => $method->getName()
			, 'static' => $method->isStatic()
			, 'final' => $method->isFinal()
			, 'abstract' => $method->isAbstract()
			, 'returnsReference' => $method->returnsReference()
			, 'returnType' => apiType($method->getReturnType())
			, 'params' => $params
			, 'doc' => $doc
			, 'line' => $method->getStartLine()
		];
	}

	$cases = [];

	if($class->isEnum())
	{
		foreach((new ReflectionEnum($name))->getCases() as $case)
		{
			$cases[] = [
				'name' => $case->getName()
				, 'value' => $case instanceof ReflectionEnumBackedCase ? apiValue($case->getBackingValue()) : null
				, 'doc' => apiDoc($case->getDocComment())
				, 'line' => method_exists($case, 'getStartLine') ? $case->getStartLine() : $class->getStartLine()
			];
		}
	}

	$sourceFile = ($relativeRoot === '' ? '' : $relativeRoot . '/') . $file;
	$parent = $class->getParentClass();
	$enum = $class->isEnum() ? new ReflectionEnum($name) : null;

	$classes[] = [
		'name' => $name
		, 'shortName' => $class->getShortName()
		, 'namespace' => $class->getNamespaceName()
		, 'kind' => $class->isInterface() ? 'interface' : ($class->isTrait() ? 'trait' : ($class->isEnum() ? 'enum' : 'class'))
		, 'final' => $class->isFinal()
		, 'abstract' => $class->isAbstract()
		, 'readonly' => method_exists($class, 'isReadOnly') && $class->isReadOnly()
		, 'parent' => $parent ? $parent->getName() : null
		, 'interfaces' => $class->getInterfaceNames()
		, 'traits' => $class->getTraitNames()
		, 'enumBackingType' => $enum?->getBackingType()?->getName()
		, 'file' => $sourceFile
		, 'line' => $class->getStartLine()
		, 'doc' => apiDoc($class->getDocComment())
		, 'properties' => $properties
		, 'constants' => $constants
		, 'cases' => $cases
		, 'methods' => $methods
	];
}

usort($classes, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
$aliases = [];

if(is_file($packageRoot . '/legacy.php'))
{
	$aliases = require $packageRoot . '/legacy.php';
	ksort($aliases);
}

$autoload = array_keys($manifest['php']['autoload'] ?? []);
$slug = $packageRoot === $root ? 'phonics' : basename($packageRoot);

echo json_encode([
	'name' => $manifest['name'] ?? $slug
	, 'slug' => $slug
	, 'version' => $manifest['version'] ?? ''
	, 'directory' => $relativeRoot
	, 'autoload' => $autoload
	, 'classes' => $classes
	, 'aliases' => $aliases
], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
