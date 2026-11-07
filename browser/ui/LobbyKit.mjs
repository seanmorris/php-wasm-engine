/**
 * Create an element with attributes and optional text.
 *
 * `class` sets the class name; `false`, `null` and `undefined` values are skipped and `true` writes an empty
 * attribute.
 * @param {string} name Tag name.
 * @param {Object<string, *>} [attributes] Attribute values.
 * @param {string} [text] Text content.
 * @returns {HTMLElement} The new element.
 */
export const node = (name, attributes = {}, text = '') => {
	const result = document.createElement(name);

	for(const [key, value] of Object.entries(attributes))
	{
		if(value === false || value === null || value === undefined) continue;

		if(key === 'class') result.className = value;
		else if(key === 'type') result.type = value;
		else result.setAttribute(key, value === true ? '' : value);
	}

	if(text) result.textContent = text;

	return result;
};

/**
 * Create a button, optionally with a shared variant and a click handler.
 * @param {string} label Visible label.
 * @param {object} [options] Presentation and behavior.
 * @param {'primary'|'quiet'|'danger'|'icon'|'small'|null} [options.variant] Shared button variant.
 * @param {function(MouseEvent): *} [options.onClick] Click handler.
 * @param {AbortSignal} [options.signal] Removes the click handler when aborted.
 * @param {Object<string, *>} [options.attributes] Extra attributes such as `aria-label`.
 * @returns {HTMLButtonElement} The new button.
 */
export const button = (label, {variant = null, onClick = null, signal, attributes = {}} = {}) => {
	const result = node('button', {type: 'button', class: variant ? `is-${variant}` : null, ...attributes}, label);

	if(onClick) result.addEventListener('click', onClick, {signal});

	return result;
};

/**
 * Readable fallback for a Matrix user identifier before presence supplies a display name.
 * @param {string} id Matrix user identifier such as `@alice:matrix.org`.
 * @returns {string} The localpart, or the identifier itself when it has none.
 */
/** Pointer, mouse and touch events that game runtimes watch on the whole document. */
const POINTER_INPUT = [
	'pointerdown', 'pointerup', 'pointercancel', 'mousedown', 'mouseup'
	, 'click', 'dblclick', 'auxclick', 'contextmenu', 'wheel'
	, 'touchstart', 'touchmove', 'touchend', 'touchcancel'
];

/**
 * Keep a modal's pointer input away from game handlers on the document. Emscripten SDL watches mouseup on the whole
 * document to catch releases outside its canvas, and cancels any release over the canvas area. Behind a modal that
 * cancels the modal's own releases, so a number spinner never sees its button come up and keeps stepping whenever
 * the pointer returns to it. The modal owns its pointer input, as it already owns its keys.
 * @param {HTMLElement} element Modal root.
 * @param {AbortSignal} [signal] Removes the listeners.
 * @returns {void}
 */
export const ownPointerInput = (element, signal) => {
	for(const type of POINTER_INPUT) element.addEventListener(type, event => event.stopPropagation(), {signal});
};

export const localpart = id => String(id ?? '').replace(/^@/, '').split(':')[0] || String(id ?? '');

/**
 * Stable, legible avatar tint for an identifier.
 * @param {string} id Identifier to hash.
 * @returns {string} CSS colour.
 */
export const avatarColor = id => {
	let hash = 0;

	for(const character of String(id)) hash = (hash * 31 + character.codePointAt(0)) >>> 0;

	return `hsl(${hash % 360} 52% 66%)`;
};

/**
 * Initial-letter avatar disc for one person.
 * @param {string} id Identifier that picks the tint.
 * @param {string} name Display name that supplies the initial.
 * @returns {HTMLSpanElement} Decorative avatar.
 */
export const avatar = (id, name) => {
	const item = node('span', {class: 'phonics-ui-avatar', 'aria-hidden': 'true'}, [...name][0]?.toUpperCase() ?? '?');
	item.style.background = avatarColor(id);

	return item;
};

/**
 * One list row naming a person or a game, with a detail line and trailing actions.
 * @param {object} options Row content.
 * @param {string} options.id Identifier that picks the avatar tint.
 * @param {string} options.name Bold first line.
 * @param {string|Node} [options.detail] Muted second line.
 * @param {string} [options.avatarName] Name whose initial the avatar shows; defaults to `name`.
 * @param {boolean} [options.highlight] Mark the row as addressed to the viewer.
 * @param {HTMLElement[]} [options.actions] Buttons placed at the end of the row.
 * @param {boolean} [options.compact] Use small buttons, as in a sidebar of people.
 * @returns {HTMLLIElement} The row.
 */
export const personRow = (
	{
		id
		, name
		, detail = ''
		, avatarName = name
		, highlight = false
		, actions = []
		, compact = false
	}
) => {
	const item = node('li', {class: 'phonics-ui-item'});
	const who = node('div', {class: 'phonics-ui-who'});
	const second = node('span');
	second.append(detail);
	who.append(node('b', {}, name), second);
	item.classList.toggle('is-for-you', highlight);
	item.append(avatar(id, avatarName), who);

	if(actions.length)
	{
		const group = node('div', {class: compact ? 'phonics-ui-actions is-compact' : 'phonics-ui-actions'});
		group.append(...actions);
		item.append(group);
	}

	return item;
};

/**
 * Format the time left before a deadline as minutes and seconds.
 * @param {number} deadline Epoch milliseconds.
 * @param {number} [now] Current epoch milliseconds.
 * @returns {string} `m:ss`, never negative.
 */
export const timeLeft = (deadline, now = Date.now()) => {
	const seconds = Math.max(0, Math.round((deadline - now) / 1000));

	return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
};

/**
 * Copy text to the clipboard, selecting a fallback field where clipboard access is denied.
 * @param {string} text Text to copy.
 * @param {HTMLInputElement|null} [field] Field holding the same text, selected when copying fails.
 * @returns {Promise<boolean>} Whether the clipboard accepted the text.
 */
export const copyText = async (text, field = null) => {
	try
	{
		await navigator.clipboard.writeText(text);

		return true;
	}
	catch
	{
		field?.select();

		return false;
	}
};

/** Display names learned from lobby presence, falling back to Matrix localparts. */
export class DisplayNames
{
	/** Display names indexed by identifier. */
	names = new Map;

	/**
	 * Record every presence display name that differs from its raw identifier.
	 * @param {Array<{id: string, displayName?: string}>} peers Presence entries.
	 * @returns {boolean} Whether any name changed.
	 */
	learn(peers)
	{
		let changed = false;

		for(const peer of peers ?? [])
		if(peer?.displayName && peer.displayName !== peer.id && this.names.get(peer.id) !== peer.displayName)
		{
			this.names.set(peer.id, peer.displayName);
			changed = true;
		}

		return changed;
	}

	/**
	 * Name one identifier for people to read.
	 * @param {string} id Identifier.
	 * @returns {string} Learned display name, or the localpart.
	 */
	name(id)
	{
		return this.names.get(id) ?? localpart(id);
	}
}

/** Connection dot, live status text, and an optional bold detail such as the signed-in name. */
export class StatusLine
{

	/**
	 * Build the status line.
	 * @param {object} [options] Presentation.
	 * @param {string} [options.className] Extra class for the live text, kept for tests and themes.
	 */
	constructor({className = ''} = {})
	{
		this.element = node('div', {class: 'phonics-ui-status'});
		this.dot = node('span', {class: 'phonics-ui-dot', 'aria-hidden': 'true'});
		this.text = node('output', {class: className || null, 'aria-live': 'polite'});
		this.detail = node('b');
		this.element.append(this.dot, this.text, this.detail);
	}

	/**
	 * Show a status and tint the dot.
	 * @param {string} text Status for people to read.
	 * @param {'ok'|'busy'|'error'|null} [tone] Dot tint; null leaves it neutral.
	 * @returns {void}
	 */
	set(text, tone = null)
	{
		this.text.textContent = text;

		if(tone) this.dot.dataset.tone = tone;
		else delete this.dot.dataset.tone;
	}

	/**
	 * Show or clear the bold detail beside the status.
	 * @param {string} text Detail text; empty hides it.
	 * @returns {void}
	 */
	setDetail(text)
	{
		this.detail.textContent = text;
		this.detail.hidden = !text;
	}
}

/** Re-run one callback every second while it reports pending deadlines. */
export class Ticker
{

	/**
	 * Create a stopped ticker.
	 * @param {function(): boolean} callback Re-renders countdowns; returns whether any remain.
	 */
	constructor(callback)
	{
		this.callback = callback;
		this.timer = null;
	}

	/**
	 * Run the callback now, then keep running it each second only while it returns true.
	 * @returns {void}
	 */
	sync()
	{
		const pending = this.callback();

		if(pending && this.timer === null) this.timer = setInterval(() => { if(!this.callback()) this.stop(); }, 1000);
		else if(!pending) this.stop();
	}

	/**
	 * Stop ticking.
	 * @returns {void}
	 */
	stop()
	{
		clearInterval(this.timer);
		this.timer = null;
	}
}

const clock = new Intl.DateTimeFormat(undefined, {hour: 'numeric', minute: '2-digit'});

/** Chat and lobby-event log with sender names, times, history trimming, and an empty state. */
export class ChatLog
{

	/**
	 * Build an empty log.
	 * @param {object} options Log behavior.
	 * @param {DisplayNames} options.names Directory used to label senders.
	 * @param {function(): (string|null)} options.self Returns the local identifier, labelled "You".
	 * @param {string} [options.label] Accessible name of the log.
	 * @param {string} [options.empty] Text shown before the first entry.
	 * @param {number} [options.limit] Entries kept before the oldest are trimmed.
	 */
	constructor({names, self, label = 'Lobby chat', empty = 'No messages yet. Say hello.', limit = 200})
	{
		this.names = names;
		this.self = self;
		this.limit = limit;
		this.frame = null;
		this.element = node('div', {class: 'phonics-ui-chat', role: 'log', 'aria-label': label});
		this.empty = node('p', {class: 'phonics-ui-empty'}, empty);
		this.element.append(this.empty);
	}

	/**
	 * Append one chat message.
	 * @param {{from: string, text: string, sentAt?: number}} message Chat event detail.
	 * @returns {void}
	 */
	add({from, text, sentAt})
	{
		const own = from === this.self();
		const entry = node('div', {class: 'phonics-ui-message'});
		entry.classList.toggle('is-own', own);

		const time = Number.isFinite(sentAt)
			? node('time', {datetime: new Date(sentAt).toISOString()}, clock.format(sentAt))
			: node('time');

		entry.append(node('b', {'data-sender': from}, own ? 'You' : this.names.name(from)), time, node('p', {}, text));
		this.append(entry, own);
	}

	/**
	 * Append a lobby event, such as a declined invitation, in the quieter system style.
	 * @param {string} text Event description.
	 * @returns {void}
	 */
	system(text)
	{
		this.append(node('div', {class: 'phonics-ui-message is-system'}, text));
	}

	/**
	 * Relabel earlier senders once presence supplies their display names.
	 * @returns {void}
	 */
	relabel()
	{
		for(const label of this.element.querySelectorAll('[data-sender]'))
		if(label.dataset.sender !== this.self()) label.textContent = this.names.name(label.dataset.sender);
	}

	/**
	 * Append an entry, trim old history, and follow new lines only while the reader is at the bottom.
	 * @param {HTMLElement} entry Rendered entry.
	 * @param {boolean} [follow] Scroll to it regardless of the reader's position.
	 * @returns {void}
	 */
	append(entry, follow = false)
	{
		const log = this.element;
		const atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 48;
		this.empty.remove();

		if(log.childElementCount >= this.limit) log.firstElementChild.remove();

		log.append(entry);

		if(!follow && !atBottom) return;

		this.frame ??= requestAnimationFrame(() => {
			this.frame = null;
			log.scrollTop = log.scrollHeight;
		});
	}

	/**
	 * Cancel a pending scroll.
	 * @returns {void}
	 */
	dispose()
	{
		if(this.frame !== null) cancelAnimationFrame(this.frame);

		this.frame = null;
	}
}
