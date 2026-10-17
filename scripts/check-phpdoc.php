<?php
declare(strict_types=1);

/**
 * Find the token preceding a function's modifiers and optional attributes.
 *
 * @param array $tokens PHP tokens including whitespace and documentation comments.
 * @param int $index Function or arrow-function keyword index.
 * @return int Previous token index, or minus one at the start of the source.
 */
function phpDocPrefix(array $tokens, int $index): int
{
	$modifiers = [T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT];
	$index--;

	while($index >= 0)
	{
		if(is_array($tokens[$index]) && in_array($tokens[$index][0], $modifiers, true))
		{
			$index--;
			continue;
		}

		if($tokens[$index] === ']')
		{
			$depth = 1;

			for($open = $index - 1; $open >= 0; $open--)
			{
				if($tokens[$open] === ']') $depth++;

				if(($tokens[$open] === '[' || is_array($tokens[$open]) && $tokens[$open][0] === T_ATTRIBUTE
				) && --$depth === 0
				)
				break;
			}

			if($open >= 0 && is_array($tokens[$open]) && $tokens[$open][0] === T_ATTRIBUTE)
			{
				$index = $open - 1;
				continue;
			}
		}

		break;
	}

	return $index;
}

/**
 * Find documentation immediately before a closure's assignment target.
 *
 * @param array $tokens Parsed PHP tokens containing the assignment.
 * @param int $equals Index of the assignment operator preceding the closure.
 * @return string Attached PHPDoc, or an empty string when no direct assignment document exists.
 */
function phpDocAssignment(array $tokens, int $equals): string
{
	$depth = 0;
	$variable = false;

	for($index = $equals - 1; $index >= 0; $index--)
	{
		$token = $tokens[$index];

		if(is_array($token))
		{
			if($token[0] === T_DOC_COMMENT) return $depth === 0 && $variable ? $token[1] : '';

			if($token[0] === T_VARIABLE) $variable = true;

			if($depth || in_array(
				$token[0]
				, [
					T_VARIABLE
					, T_WHITESPACE
					, T_STRING
					, T_NAME_QUALIFIED
					, T_NAME_FULLY_QUALIFIED
					, T_OBJECT_OPERATOR
					, T_DOUBLE_COLON
				]
				, true
			))
			continue;

			return '';
		}

		if($token === ']')
		{
			$depth++;
			continue;
		}

		if($token === '[' && $depth)
		{
			$depth--;
			continue;
		}

		if($depth) continue;

		return '';
	}

	return '';
}

/**
 * Discover named functions, methods, closures, and arrow functions without executing source.
 *
 * @param string $source Complete PHP source to parse with the current interpreter.
 * @return list<array{name:string,line:int,doc:string,params:list<string>}> Function locations,
 * attached docblocks, and ordered parameter names.
 */
function phpDocFunctions(string $source): array
{
	$tokens = token_get_all($source, TOKEN_PARSE);
	$functions = [];

	foreach($tokens as $i => $token)
	{
		if(!is_array($token) || !in_array($token[0], [T_FUNCTION,T_FN], true))
		{
			continue;
		}

		$previous = phpDocPrefix($tokens, $i);

		$doc = $previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][
			0
		] === T_DOC_COMMENT
			? $tokens[$previous][1]
			: '';

		if($doc === '' && $previous >= 0 && $tokens[$previous] === '=') $doc = phpDocAssignment($tokens, $previous);

		$name = $token[0] === T_FN?'arrow function':'closure';
		$j = $i + 1;

		while($tokens[$j] !== '(')
		{
			$part = $tokens[$j++];

			if(is_array($part) && $part[0] !== T_WHITESPACE && $part[1] !== '&')
			{
				$name = $part[1];
			}
		}

		$params = [];
		$depth = 1;

		while($depth > 0)
		{
			$part = $tokens[++$j];

			if($part === '(')
			{
				$depth++;
			}
			elseif($part === ')')
			{
				$depth--;
			}
			elseif($depth === 1 && is_array($part) && $part[0] === T_VARIABLE)
			{
				$params[] = substr($part[1], 1);
			}
		}

		$functions[] = ['name' => $name,'line' => $token[2],'doc' => $doc,'params' => $params];
	}

	return $functions;
}

/**
 * Separate a PHPDoc type from its description while allowing nested callable and array types.
 *
 * @param string $tag Return-tag text after the @return keyword.
 * @return array{0:string,1:string} Type and description; the description is empty if absent.
 */
function phpDocTypeDescription(string $tag): array
{
	$tag = trim($tag);
	$depth = 0;

	for($i = 0; $i < strlen($tag); $i++)
	{
		if(str_contains('([{<', $tag[$i]))
		{
			$depth++;
		}
		elseif(str_contains(')]}>', $tag[$i]))
		{
			$depth--;
		}
		elseif($depth === 0 && ctype_space($tag[$i]))
		{
			return [substr($tag, 0, $i),trim(substr($tag, $i))];
		}
	}

	return [$tag,''];
}

/**
 * Check a function's summary and its parameter/return type descriptions.
 *
 * @param array{name:string,line:int,doc:string,params:list<string>} $function Parsed function
 * metadata and attached PHPDoc.
 * @return list<string> Documentation errors, empty when the function satisfies the contract.
 */
function phpDocErrors(array $function): array
{
	if($function['doc'] === '')
	{
		return ['missing PHPDoc'];
	}

	$clean = preg_replace('/^\h*\*\h?/m', '', substr($function['doc'], 3, -2));
	$errors = [];

	if(!preg_match('/[a-z]/i', explode('@', $clean, 2)[0]))
	{
		$errors[] = 'missing function description';
	}

	preg_match_all('/^\h*@param\h+(.+?)\h+(?:&)?(?:\.\.\.)?\$(\w+)\h+(\S[^\r\n]*)/m', $clean, $params, PREG_SET_ORDER);
	$names = [];

	foreach($params as $param)
	{
		$names[] = $param[2];

		if(!preg_match('/[a-z]/i', $param[3]))
		{
			$errors[] = 'missing parameter description for $' . $param[2];
		}
	}

	if($names !== $function['params'])
	{
		$errors[] = 'document every parameter in order with its type and description';
	}

	preg_match_all('/^\h*@return\h+([^\r\n]+)/m', $clean, $returns);

	if(count($returns[1]) !== 1)
	{
		$errors[] = 'require one @return type and description';
	}
	else
	{
		[$type,$description] = phpDocTypeDescription($returns[1][0]);

		if($type === '' || !preg_match('/[a-z]/i', $description))
		{
			$errors[] = 'missing return type or description';
		}
	}

	return $errors;
}

if(realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

$paths = array_slice($argv, 1)?:[dirname(__DIR__) . '/src',dirname(__DIR__) . '/autoload.php'];
$files = [];

foreach($paths as $path)
{
	if(is_dir($path))
	{
		foreach(new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
		) as $file
		){
			if($file->isFile() && $file->getExtension() === 'php')
			{
				$files[] = $file->getPathname();
			}
		}
	}
	else
	{
		$files[] = $path;
	}
}

$files = array_unique($files);
sort($files);
$count = 0;
$failures = 0;

foreach($files as $file)
{
	try
	{
		$source = @file_get_contents($file);

		if($source === false)
		{
			throw new RuntimeException('Cannot read source file');
		}

		foreach(phpDocFunctions($source) as $function)
		{
			$count++;

			foreach(phpDocErrors($function) as $error)
			{
				$failures++;
				fwrite(STDERR, $file . ':' . $function['line'] . ' ' . $function['name'] . ': ' . $error . "\n");
			}
		}
	}
	catch(Throwable $error)
	{
		$failures++;
		fwrite(STDERR, $file . ': ' . $error->getMessage() . "\n");
	}
}

echo "PHPDoc: $count functions, $failures failures\n";
exit($failures?1:0);
