if(globalThis.hljs)
	for(const code of document.querySelectorAll('pre code'))
	{
		// Pandoc has already split PHP identifiers into syntax spans. Restore the
		// source text so Highlight.js can tokenize the complete identifier.
		code.textContent = code.textContent;
		globalThis.hljs.highlightElement(code);
	}
