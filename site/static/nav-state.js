// Remember which navigation groups a visitor opened or closed.
//
// Load this file as a blocking head script (SCRIPTS). It then applies the
// remembered state to each <details data-id-path> as the parser inserts it,
// before the browser paints, so a collapsed group never flashes open. When it
// is loaded late or deferred it still corrects the page at DOMContentLoaded.
// A group that contains the current page always stays open.

(function() {

	/**
	 * Build the local-storage key for one stable navigation path.
	 *
	 * @param {string} idPath Stable path emitted by the menu renderer.
	 * @returns {string} Storage key for the remembered state.
	 */
	function storageKey(idPath)
	{
		return 'openMenu-' + idPath;
	}

	/**
	 * Read a remembered disclosure state without making storage availability mandatory.
	 *
	 * @param {string} idPath Stable path emitted by the menu renderer.
	 * @param {boolean} fallback State to use when no valid stored value exists.
	 * @returns {boolean} Remembered or fallback disclosure state.
	 */
	function readState(idPath, fallback)
	{
		try
		{
			const stored = localStorage.getItem(storageKey(idPath));
			const parsed = stored === null ? fallback : JSON.parse(stored);

			return typeof parsed === 'boolean' ? parsed : fallback;
		}
		catch(error)
		{
			return fallback;
		}
	}

	/**
	 * Persist one disclosure state when browser storage is available.
	 *
	 * @param {string} idPath Stable path emitted by the menu renderer.
	 * @param {boolean} isOpen Whether the disclosure should remain open.
	 * @returns {void}
	 */
	function writeState(idPath, isOpen)
	{
		try
		{
			localStorage.setItem(storageKey(idPath), JSON.stringify(isOpen));
		}
		catch(error)
		{
			// Storage may be unavailable; the page still works without memory.
		}
	}

	/**
	 * Report whether a disclosure contains the current page marker.
	 *
	 * @param {HTMLDetailsElement} details Disclosure to inspect.
	 * @returns {boolean} Whether the current page appears inside the disclosure.
	 */
	function holdsCurrentPage(details)
	{
		return details.hasAttribute('data-contains-current')
			|| details.querySelector('a[aria-current="page"], a.active-link') !== null;
	}

	/**
	 * Open every disclosure ancestor of a current-page link.
	 *
	 * @param {Element} node Current-page marker inside the navigation tree.
	 * @returns {void}
	 */
	function openAncestors(node)
	{
		let details = node.closest('details[data-id-path]');

		while(details)
		{
			details.setAttribute('open', '');
			details = details.parentElement && details.parentElement.closest('details[data-id-path]');
		}
	}

	/**
	 * Apply the remembered state to one disclosure while preserving the current-page path.
	 *
	 * @param {HTMLDetailsElement} details Disclosure to update.
	 * @returns {void}
	 */
	function apply(details)
	{
		const idPath = details.getAttribute('data-id-path');

		if(!idPath) return;

		if(holdsCurrentPage(details))
		{
			details.setAttribute('open', '');

			return;
		}

		if(readState(idPath, details.hasAttribute('open')))
		{
			details.setAttribute('open', '');
		}
		else
		{
			details.removeAttribute('open');
		}
	}

	/**
	 * Apply navigation state to disclosures and current-page links in one inserted subtree.
	 *
	 * @param {Node} node Inserted DOM subtree.
	 * @returns {void}
	 */
	function applyWithin(node)
	{
		if(node.nodeType !== 1) return;

		if(node.matches('details[data-id-path]')) apply(node);

		for(const details of node.querySelectorAll('details[data-id-path]')) apply(details);

		if(node.matches('a[aria-current="page"], a.active-link')) openAncestors(node);

		for(const link of node.querySelectorAll('a[aria-current="page"], a.active-link')) openAncestors(link);
	}

	/**
	 * Apply navigation state as the HTML parser inserts new nodes.
	 *
	 * @param {MutationRecord[]} records Child-list mutations reported by the observer.
	 * @returns {void}
	 */
	function applyMutations(records)
	{
		for(const record of records)
		{
			for(const node of record.addedNodes) applyWithin(node);
		}
	}

	/** Apply final navigation state and stop observing after parsing completes. */
	function finishInitialState()
	{
		observer.disconnect();
		applyWithin(document.documentElement);
	}

	/**
	 * Remember a clicked disclosure's next state.
	 *
	 * @param {MouseEvent} event Document click event.
	 * @returns {void}
	 */
	function rememberClick(event)
	{
		let target = event.target;

		while(target && target.getAttribute)
		{
			if(target.tagName === 'UL')
			{
				event.stopPropagation();

				return;
			}

			const idPath = target.getAttribute('data-id-path');

			if(idPath)
			{
				writeState(idPath, !target.hasAttribute('open'));

				return;
			}

			target = target.parentNode;
		}
	}

	const observer = new MutationObserver(applyMutations);

	observer.observe(document.documentElement, {childList: true, subtree: true});

	document.addEventListener('DOMContentLoaded', finishInitialState);
	document.addEventListener('click', rememberClick);
})();
