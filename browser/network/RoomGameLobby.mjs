import {useOverlayTheme, useStyle} from '../OverlayTheme.mjs';
import {ChatLog, DisplayNames, StatusLine, Ticker, copyText, node, personRow, timeLeft} from '../ui/LobbyKit.mjs';
import {chooseMatchSettings} from '../launcher/MatchSettingsDialog.mjs';
import {authorizeWithPopup} from './OAuthPopup.mjs';

const GAME_EVENT = 'org.seanmorris.phonics.game.v1';
const CHAT_EVENT = 'org.seanmorris.phonics.chat.v1';
const ROOM_MESSAGE = 'm.room.message';
const RELATION = 'org.seanmorris.phonics.game.v1';
// The Matrix event family stays stable; the envelope version gates incompatible seed commitments.
const PROTOCOL_VERSION = 2;
const DEFAULT_SEED_TIMEOUT_MS = 120_000;
const MAX_TIMER_DELAY_MS = 2_147_483_647;
// Preserve the existing wire encoding: the exchange ends 60 seconds after commit_deadline.
const LEGACY_REVEAL_GRACE_MS = 60_000;
const UNKNOWN_ROOT_RETRY = 60_000;
const UNKNOWN_ROOT_LIMIT = 1024;
const MAX_ACTION_BATCH = 256;
// A waiting player claims a timeout this long after the deadline, so a step already in flight lands first.
const CLAIM_GRACE_MS = 5_000;
// When a claim has not ended the match (clock skew, a lost publish), look again after this long.
const CLAIM_RETRY_MS = 15_000;
// The in-game sidebar shows the step countdown only in its last minute, so quick automatic steps never flash a clock.
const CLOCK_WARNING_MS = 60_000;
const encoder = new TextEncoder;

// Public, versioned templates. Both fixed-width operands stay literal: never evaluate their sum.
const SEED_PHRASES = Object.freeze([
	'that would be {n}+{m}'
	, 'pair {n} with {m}'
	, 'take {n}, then {m}'
	, 'between {n} and {m}'
	, 'first {n}; second {m}'
	, 'join {n} to {m}'
	, 'keep {n} beside {m}'
	, 'combine {n} with {m}'
]);

const event = (type, detail) => new CustomEvent(type, {detail});

const bytes64 = bytes => {
	let value = '';

	for(const byte of bytes) value += String.fromCharCode(byte);

	return btoa(value).replaceAll('+', '-').replaceAll('/', '_').replace(/=+$/, '');
};

const from64 = value => Uint8Array.from(
	atob(String(value).replaceAll('-', '+').replaceAll('_', '/'))
	, character => character.charCodeAt(0)
);

const validSeedValue = value => typeof value === 'string' && /^[A-Za-z0-9_-]{43}$/.test(value)
	&& bytes64(from64(value)) === value;

const validSeedMaterial = value => value && validSeedValue(value.contribution) && validSeedValue(value.nonce)
	&& Number.isInteger(value.phrase) && value.phrase >= 0 && value.phrase < SEED_PHRASES.length;

const seedStorageKey = (session, player) => `phonics.game.seed:v2:${session.root}:${player}`;

const seedTimeoutMs = game => game.seedTimeoutMs === undefined ? DEFAULT_SEED_TIMEOUT_MS : game.seedTimeoutMs;

/** Per-step deadline in milliseconds, or null when the game lets players take as long as they like. */
const actionTimeoutMs = game => game.actionTimeoutMs ?? null;

/**
 * Whether a state step arrived after the owed player's deadline. The deadline runs from the server timestamp of
 * the last step that moved the match forward, so every reader judges the same event the same way.
 */
const stepLate = (session, game, item) => {
	const timeout = actionTimeoutMs(game);

	if(timeout === null || !Number.isSafeInteger(session.clockSince)) return false;

	return !Number.isSafeInteger(item.origin_server_ts) || item.origin_server_ts >= session.clockSince + timeout;
};

/** Restart the step deadline from an accepted step's server timestamp; clocks never run backwards. */
const advanceClock = (session, item) => {
	if(!Number.isSafeInteger(item.origin_server_ts)) return;

	session.clockSince = Math.max(session.clockSince ?? item.origin_server_ts, item.origin_server_ts);
};

const seedEventInTime = (session, item) => Number.isSafeInteger(item.origin_server_ts)
	&& item.origin_server_ts < session.seedDeadline;

const hex = bytes => [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');

const stable = value => JSON.stringify(
	value
	, (_, item) => item && typeof item === 'object' && !Array.isArray(item)
		? Object.fromEntries(Object.entries(item).sort(([a], [b]) => a.localeCompare(b)))
		: item
);

const framed = (...values) => {
	const parts = values.map(value => encoder.encode(String(value)));
	const size = parts.reduce((total, part) => total + 4 + part.length, 0);
	const result = new Uint8Array(size), view = new DataView(result.buffer);
	let offset = 0;

	for(const part of parts)
	{
		view.setUint32(offset, part.length, false);
		offset += 4;
		result.set(part, offset);
		offset += part.length;
	}

	return result;
};

const digest = async (...values) => hex(new Uint8Array(await crypto.subtle.digest('SHA-256', framed(...values))));

const relation = root => ({rel_type: RELATION, event_id: root});

const escapeHtml = value => String(value)
	.replaceAll('&', '&amp;')
	.replaceAll('<', '&lt;')
	.replaceAll('>', '&gt;')
	.replaceAll('"', '&quot;');

const acceptUrl = (location, sessionId) => {
	const url = new URL(location?.href ?? 'https://localhost/');
	const parameters = new URLSearchParams(url.hash.slice(1));
	parameters.set('phonics-game', sessionId);
	url.hash = parameters.toString();

	return url.href;
};

const acceptedSession = location => {
	try
	{
		return new URLSearchParams(location?.hash?.slice(1) ?? '').get('phonics-game');
	}
	catch
	{
		return null;
	}
};

const jsonAction = (value, budget, depth = 0) => {
	if(depth > 16 || budget.nodes++ > 8192) return false;

	if(value === null || typeof value === 'string' || typeof value === 'boolean') return true;

	if(typeof value === 'number') return Number.isSafeInteger(value);

	if(Array.isArray(value)) return value.length <= 4096 && value.every(item => jsonAction(item, budget, depth + 1));

	if(!value || typeof value !== 'object' || Object.getPrototypeOf(value) !== Object.prototype) return false;

	for(const [key, item] of Object.entries(value))
	{
		if(!key || key.length > 128 || ['__proto__','prototype','constructor'].includes(key)
			|| !jsonAction(item, budget, depth + 1)
		) return false;
	}

	return true;
};

const validActionConfiguration = game => Array.isArray(game.actionTypes) && game.actionTypes.length > 0
	&& game.actionTypes.length <= 64
	&& new Set(game.actionTypes).size === game.actionTypes.length
	&& game.actionTypes.every(type => typeof type === 'string' && /^[a-z][a-z0-9-]{0,63}$/.test(type))
	&& Number.isSafeInteger(game.maximumActionBytes ?? 8192)
	&& (game.maximumActionBytes ?? 8192) >= 256 && (game.maximumActionBytes ?? 8192) <= 49152;

const checkpointMode = game => game.checkpointMode ?? 'actor';
const actionBatches = game => game.actionBatches ?? false;

const validAction = (action, game) => {
	if(!action || typeof action !== 'object') return false;
	const types = game.actionTypes;

	if(types !== undefined)
	{
		const maximum = game.maximumActionBytes ?? 8192;

		return validActionConfiguration(game) && types.includes(action.type)
			&& jsonAction(action, {nodes: 0}) && encoder.encode(stable(action)).length <= maximum;
	}

	if(action.type === 'shot')
	return Number.isInteger(action.aim_udeg)
		&& action.aim_udeg >= -180000000 && action.aim_udeg <= 180000000
		&& Number.isInteger(action.power_bp) && action.power_bp >= 500 && action.power_bp <= 10000
		&& Number.isInteger(action.top_bp) && action.top_bp >= -10000 && action.top_bp <= 10000
		&& Number.isInteger(action.side_bp) && action.side_bp >= -10000 && action.side_bp <= 10000;

	if(action.type === 'place')
	return Number.isInteger(action.x_um) && Math.abs(action.x_um) <= 2000000
		&& Number.isInteger(action.y_um) && Math.abs(action.y_um) <= 2000000;

	return false;
};

/** Largest serialized table-settings object a host may attach to an offer. */
const MAXIMUM_SETTINGS_BYTES = 4096;

/** Table settings are an optional plain JSON object chosen by the host; the game interprets them. */
const validSettings = settings => settings === undefined || settings === null
	|| (typeof settings === 'object' && !Array.isArray(settings) && jsonAction(settings, {nodes: 0})
		&& encoder.encode(stable(settings)).length <= MAXIMUM_SETTINGS_BYTES
	);

const validOffer = (content, game) => content?.v === PROTOCOL_VERSION && content.kind === 'offer'
	&& validSettings(content.settings)
	&& typeof content.session_id === 'string' && typeof content.game === 'object'
	&& content.game.id === game.id && content.game.protocol === game.protocol
	&& content.game.rules === game.rules && content.game.application === game.application
	&& content.game.runtime === game.runtime && seedTimeoutMs(content.game) === game.seedTimeoutMs
	&& stable(content.game.actionTypes ?? null) === stable(game.actionTypes ?? null)
	&& (content.game.maximumActionBytes ?? null) === (game.maximumActionBytes ?? null)
	&& checkpointMode(content.game) === checkpointMode(game)
	&& actionBatches(content.game) === actionBatches(game)
	&& actionTimeoutMs(content.game) === actionTimeoutMs(game)
	&& Number.isSafeInteger(content.expires_at);

/**
 * Durable, deterministic two-player room protocol.
 *
 * Matrix supplies identity, persistence, ordering metadata, and chat. The protocol supplies explicit action
 * sequence numbers, commit/reveal seeding, state acknowledgements, resumable checkpoints, and conflict freezing.
 */
export class RoomGameProtocol extends EventTarget
{

	/** Create a room-game coordinator; game.seedTimeoutMs sets the total seed exchange budget (default 120,000 ms). */
	constructor(
		{
			provider
			, game
			, session
			, storage = globalThis.sessionStorage
			, signal
			, location = globalThis.location
			, history = globalThis.history
		}
	){
		super();
		const timeout = seedTimeoutMs(game);

		if(!Number.isSafeInteger(timeout) || timeout < 1 || timeout > MAX_TIMER_DELAY_MS)
		throw new RangeError(`game.seedTimeoutMs must be an integer from 1 to ${MAX_TIMER_DELAY_MS} milliseconds`);

		if(game.actionTypes !== undefined && !validActionConfiguration(game))
		throw new RangeError('game.actionTypes requires 1 to 64 unique action names and a 256 to 49152 byte limit');

		if(game.actionTypes === undefined && game.maximumActionBytes !== undefined)
		throw new RangeError('game.maximumActionBytes requires game.actionTypes');

		if(!['actor','history'].includes(checkpointMode(game)))
		throw new RangeError("game.checkpointMode must be 'actor' or 'history'");

		if(typeof actionBatches(game) !== 'boolean')
		throw new RangeError('game.actionBatches must be boolean');

		const stepTimeout = actionTimeoutMs(game);

		if(stepTimeout !== null
			&& (!Number.isSafeInteger(stepTimeout) || stepTimeout < 1 || stepTimeout > MAX_TIMER_DELAY_MS)
		)
		throw new RangeError(`game.actionTimeoutMs must be an integer from 1 to ${MAX_TIMER_DELAY_MS} milliseconds`);

		this.provider = provider;
		this.game = Object.freeze({
			...game
			, seedTimeoutMs: timeout
			, checkpointMode: checkpointMode(game)
			, actionBatches: actionBatches(game)
			, actionTimeoutMs: stepTimeout
		});
		this.session = session;
		this.storage = storage;
		this.signal = signal;
		this.location = location;
		this.history = history;
		this.acceptSession = acceptedSession(location);
		this.sessions = new Map;
		this.peers = [];
		this.selected = null;
		this.connected = false;
		this.closed = false;
		this.adapter = null;
		this.loadingHistory = false;
		this.pendingGameEvents = [];
		this.rootLoads = new Map;
		this.unavailableRoots = new Map;

		this.onGame = ({detail}) => {
			if(this.loadingHistory)
			{
				this.pendingGameEvents.push(detail.event);

				return;
			}

			void this.consume(detail.event, {loadUnknown: false}).catch(error => this.fail(error));
		};

		this.onPresence = ({detail}) => {
			this.peers = detail.peers ?? [];
			this.dispatchEvent(event('change', this.snapshot()));
		};

		this.onChat = ({detail}) => this.dispatchEvent(event('chat', detail));
		provider.addEventListener('gameevent', this.onGame);
		provider.addEventListener('presence', this.onPresence);
		provider.addEventListener('chat', this.onChat);
		signal?.addEventListener('abort', () => this.close(), {once: true});
	}

	/** Return the authenticated local room profile. */
	profile() { return this.provider.profile(); }

	/** Connect the provider, replay durable room history, and accept any linked challenge. */
	async connect()
	{
		if(this.connected) return this.profile();

		this.loadingHistory = true;

		try
		{
			const profile = await this.provider.connect();
			this.connected = true;

			const timeline = await this.provider.roomHistory({
				types: [ROOM_MESSAGE, GAME_EVENT, CHAT_EVENT]
				, limit: 1000
			});

			await this.replay(timeline);

			while(this.pendingGameEvents.length)
			await this.replay(this.pendingGameEvents.splice(0));

			this.loadingHistory = false;

			for(const session of this.sessions.values())
			{
				await this.resumeSeedExchange(session);
				this.armActionDeadline(session);
			}

			await this.acceptLinkedSession();
			this.dispatchEvent(event('change', this.snapshot()));

			return profile;
		}
		finally
		{
			this.loadingHistory = false;
		}
	}

	/** Replay one bounded timeline without recursively fetching roots for unrelated old children. */
	async replay(timeline)
	{
		const ordered = timeline.map((item, order) => ({item, order}));
		ordered.sort((a, b) => a.item.origin_server_ts - b.item.origin_server_ts || a.order - b.order);

		for(const {item} of ordered)
		{
			const content = item.type === ROOM_MESSAGE ? item.content?.[GAME_EVENT] : item.content;

			if(validOffer(content, this.game)) await this.consume(item, {replay: true, loadUnknown: false});
		}

		for(const {item} of ordered)
		{
			if(item.type === ROOM_MESSAGE && !item.content?.[GAME_EVENT])
			{
				if(item.content?.msgtype === 'm.text' && typeof item.content.body === 'string')
				this.dispatchEvent(event('chat', {
					from: item.sender
					, text: item.content.body
					, sentAt: item.content['org.seanmorris.phonics.sent_at'] ?? item.origin_server_ts
				}));

				continue;
			}

			if(item.type === CHAT_EVENT)
			{
				if(typeof item.content?.text === 'string')
				this.dispatchEvent(event('chat', {
					from: item.sender
					, text: item.content.text
					, sentAt: item.content.sentAt
				}));

				continue;
			}

			const content = item.type === ROOM_MESSAGE ? item.content?.[GAME_EVENT] : item.content;

			if(!validOffer(content, this.game))
			await this.consume(item, {replay: true, loadUnknown: false});
		}
	}

	/** Publish and locally select an open table or direct challenge. */
	async create({target = null, settings = null} = {})
	{
		this.requireConnected();

		if(this.selected) throw new Error('Leave the current table before opening another');

		if(!validSettings(settings)) throw new Error('Table settings must be a plain JSON object of at most 4 KB');

		const settingsProblem = settings === null ? null : this.settingsProblem(settings);

		if(settingsProblem) throw new Error(settingsProblem);

		const sessionId = crypto.randomUUID();
		const content = {
			v: PROTOCOL_VERSION
			, kind: 'offer'
			, session_id: sessionId
			, host: this.profile().id
			, target: target || null
			, created_at: Date.now()
			, expires_at: Date.now() + 15 * 60_000
			, game: {...this.game}
			, ...(settings === null ? {} : {settings: structuredClone(settings)})
		};

		const url = acceptUrl(this.location, sessionId);
		const displayName = this.profile().displayName ?? this.profile().id;
		const verb = target ? `challenged ${target} to` : 'opened';
		const callToAction = target ? 'Accept challenge' : 'Join table';
		// Matrix clients without Phonics still see the house rules in the readable message.
		const label = settings === null ? '' : this.settingsLabel(settings);
		const rules = label ? ` (${label})` : '';
		const body = target
			? `${displayName} challenged ${target} to ${this.game.title ?? this.game.id}${rules}. Accept: ${url}`
			: `${displayName} opened a ${this.game.title ?? this.game.id} table${rules}. Join: ${url}`;

		const message = {
			msgtype: 'm.text'
			, body
			, format: 'org.matrix.custom.html'
			, formatted_body: `${escapeHtml(
				displayName
			)} ${escapeHtml(
				verb
			)} ${escapeHtml(
				this.game.title ?? this.game.id
			)}${escapeHtml(rules)}. <a href="${escapeHtml(
				url
			)}">${callToAction}</a>`
			, [GAME_EVENT]: content
		};

		const result = await this.provider.gameOffer(message, `offer:${sessionId}`);
		const item = {
			event_id: result.event_id
			, type: ROOM_MESSAGE
			, sender: this.profile().id
			, origin_server_ts: content.created_at
			, content: message
		};

		await this.consume(item);
		this.select(result.event_id, 'player');
		await this.provider.setActivity({state: 'waiting', root: result.event_id, game: this.game.id});

		return result.event_id;
	}

	/** Join or resume the table named by the current page fragment. */
	async acceptLinkedSession()
	{
		if(!this.acceptSession) return;

		const session = [...this.sessions.values()].find(value => value.offer.session_id === this.acceptSession);

		if(!session) return;

		const me = this.profile().id;

		if(session.seats?.includes(me)) await this.resume(session.root);
		else if(session.offer.host === me && !session.seats)
		{
			this.select(session.root, 'player');
			await this.provider.setActivity({state: 'waiting', root: session.root, game: this.game.id});
		}
		else if(session.status === 'open' && !session.seats) await this.join(session.root);
		else return;

		this.clearAcceptLink();
	}

	/** Remove the consumed table identifier from the current history entry. */
	clearAcceptLink()
	{
		this.acceptSession = null;

		if(!this.location || !this.history?.replaceState) return;

		const url = new URL(this.location.href);
		const parameters = new URLSearchParams(url.hash.slice(1));
		parameters.delete('phonics-game');
		url.hash = parameters.toString();
		this.history.replaceState(this.history.state, '', url.href);
	}

	/** Request the second player seat at an eligible open table. */
	async join(root)
	{
		this.requireConnected();
		const session = await this.load(root);
		const me = this.profile().id;

		if(session.offer.target && session.offer.target !== me) throw new Error('This challenge is for another user');

		if(session.offer.host === me) throw new Error('You already opened this table');

		if(session.seats) throw new Error('This table already has two players');

		if(session.status !== 'open') throw new Error('This table is no longer open');

		const settingsProblem = this.settingsProblem(session.offer.settings ?? null);

		if(settingsProblem) throw new Error(settingsProblem);

		this.select(root, 'player');
		await this.child(session, 'join', {player: me}, `join:${me}`);
		await this.provider.setActivity({state: 'waiting', root, game: this.game.id});
	}

	/** Decline a challenge addressed to this account before it is seated. */
	async decline(root)
	{
		this.requireConnected();
		const session = await this.load(root);
		const me = this.profile().id;

		if(session.offer.target !== me) throw new Error('This challenge is for another user');

		if(session.seats) throw new Error('This table has already started');

		await this.child(session, 'cancel', {player: me, reason: 'declined'}, `cancel:${me}`);
		session.status = 'expired';
		session.closedBy = me;
		this.dispatchEvent(event('change', this.snapshot()));
	}

	/** Select an active table for read-only deterministic playback. */
	async spectate(root)
	{
		this.requireConnected();
		const session = await this.load(root);
		this.select(root, 'spectator');
		await this.provider.setActivity({state: 'spectating', root, game: this.game.id});
		await this.startIfPossible(session);
	}

	/** Restore this account's existing player seat from room history. */
	async resume(root)
	{
		this.requireConnected();
		const session = await this.load(root);

		if(!session.seats?.includes(this.profile().id)) throw new Error('This Matrix account is not seated here');

		this.select(root, 'player');

		await this.provider.setActivity(
			{state: session.status === 'active' ? 'active' : 'waiting', root, game: this.game.id}
		);

		await this.startIfPossible(session);
	}

	/** Select one table and local player or spectator role. */
	select(root, role)
	{
		if(this.selected && this.selected !== root) throw new Error('Only one table can be open in this browser');

		this.selected = root;
		const session = this.sessions.get(root);
		session.localRole = role;
		this.dispatchEvent(event('selected', {root, role}));
		this.dispatchEvent(event('change', this.snapshot()));
	}

	/**
	 * Leave the selected table: resign an unfinished seat, or cancel a table that has not been seated yet.
	 *
	 * Cancelling matters because the host's client seats the first joiner automatically; an abandoned open table
	 * would otherwise stay joinable and could seat a host who already left.
	 */
	async leave()
	{
		const session = this.sessions.get(this.selected);
		const me = this.connected ? this.profile().id : null;

		if(session?.localRole === 'player' && session.seats?.includes(me)
			&& !['complete','frozen','expired'].includes(session.status)
		)
		await this.child(session, 'resign', {player: me, reason: 'left'}, `resign:${me}`);
		else if(me && session?.localRole === 'player' && !session.seats && session.status === 'open')
		{
			await this.child(session, 'cancel', {player: me, reason: 'left'}, `cancel:${me}`);

			if(session.offer.host === me)
			{
				session.status = 'expired';
				session.closedBy = me;
			}
			else session.joiners = (session.joiners ?? []).filter(player => player !== me);
		}

		this.adapter?.stop?.({reason: 'left'});
		this.selected = null;
		await this.provider.setActivity(null);
		this.dispatchEvent(event('selected', null));
		this.dispatchEvent(event('change', this.snapshot()));
	}

	/**
	 * Explain why this build cannot play a table's settings, or return null when it can. Games without settings
	 * accept only tables without them; games that declare validSettings decide. An unsupported table is listed but
	 * cannot be joined.
	 */
	settingsProblem(settings)
	{
		const check = this.adapter?.validSettings;

		if(typeof check !== 'function') return settings === null ? null : 'This game does not use table settings';

		try
		{
			const verdict = check(settings);

			if(verdict === true) return null;

			return typeof verdict === 'string' && verdict
				? verdict
				: 'This table uses settings this version cannot play';
		}
		catch(error)
		{
			return error instanceof Error && error.message
				? error.message
				: 'This table uses settings this version cannot play';
		}
	}

	/** One readable line for a table's settings, supplied by the game; empty when it has none. */
	settingsLabel(settings)
	{
		if(settings === null || typeof this.adapter?.describeSettings !== 'function') return '';

		try
		{
			return String(this.adapter.describeSettings(settings) ?? '').slice(0, 200);
		}
		catch
		{
			return '';
		}
	}

	/** Attach the application adapter that executes confirmed deterministic actions. */
	attachGame(adapter)
	{
		if(this.adapter) throw new Error('A game adapter is already attached');

		this.adapter = adapter;
		const selected = this.sessions.get(this.selected);

		if(selected) void this.startIfPossible(selected);

		return Object.freeze({
			submitAction: (action, preHash, options) => this.submitAction(action, preHash, options)
			, reportReady: hash => this.reportReady(hash)
			, reportSettled: result => this.reportSettled(result)
			, dispute: reason => this.dispute(reason)
			, resign: reason => this.resign(reason)
			, leave: () => this.leave()
			, snapshot: () => this.snapshot()
		});
	}

	/** Publish the next quantized action and optionally mark the end of its aggregate settlement batch. */
	async submitAction(action, preHash, {settle = true} = {})
	{
		const session = this.currentPlayer();
		const me = this.profile().id;
		const batched = this.game.actionBatches;
		const previous = session.batch?.actions.at(-1) ?? session.pending;

		if(!validAction(action, this.game)) throw new Error('The game supplied an invalid quantized action');
		if(typeof settle !== 'boolean' || (!settle && !batched))
		throw new Error('The game supplied an invalid action settlement boundary');

		// The application bridge can observe the durable action before it receives the
		// acknowledgement for its local outbox entry. Treat that exact retry as success.
		if(previous?.actor === me && previous.pre_hash === preHash
			&& stable(previous.action) === stable(action)
			&& (!batched || previous.batch_end === settle)
		) return;

		if(session.status !== 'active' || session.pending || session.batch?.actions.length >= MAX_ACTION_BATCH
			|| (!session.batch && session.turn !== me)
		)
		throw new Error('It is not your turn');

		if(typeof preHash !== 'string' || (!session.batch && preHash !== session.hash))
		throw new Error('Local state differs from the confirmed turn state');

		const sequence = session.batch ? session.batch.actions.at(-1).sequence + 1 : session.sequence + 1;
		const body = {
			sequence
			, actor: me
			, pre_hash: preHash
			, action: structuredClone(action)
		};

		if(batched) body.batch_end = settle;

		await this.child(session, 'action', body, `action:${sequence}:${me}`);
	}

	/** Publish this player's initial state hash after seeded setup. */
	async reportReady(hash)
	{
		const session = this.currentPlayer();

		if(session.status !== 'ready') return;

		await this.child(
			session
			, 'ready'
			, {player: this.profile().id, hash: String(hash)}
			, `ready:${this.profile().id}`
		);
	}

	/** Validate a batch fragment locally or publish this player's report for the completed batch. */
	async reportSettled({sequence, hash, nextActor, outcome = null, checkpoint = null})
	{
		const session = this.currentPlayer();
		const me = this.profile().id;
		const action = session.actions.get(sequence);

		if(this.game.actionBatches && action?.batch_end === false)
		{
			if(!session.batch?.actions.some(value => value.sequence === sequence)
				|| typeof hash !== 'string' || !session.seats.includes(String(nextActor)) || outcome !== null
			) throw new Error('No matching batched action is advancing');

			// Both applications execute and hash every fragment. Only the final fragment publishes
			// the two durable reports that compare the complete transcript state. The fragment's next actor stays
			// local: it names who owes the next batch step for the step deadline.
			(session.fragmentActors ??= new Map).set(sequence, String(nextActor));
			this.reviewClaims(session);
			this.armActionDeadline(session);
			this.dispatchEvent(event('change', this.snapshot()));

			return false;
		}

		if(!session.pending || sequence !== session.pending.sequence) throw new Error('No matching action is settling');

		const body = {
			player: me
			, sequence
			, hash: String(hash)
			, next_actor: String(nextActor)
			, outcome: outcome === null ? null : structuredClone(outcome)
		};

		if(this.game.checkpointMode === 'actor' && session.pending.actor === me)
		{
			if(typeof checkpoint !== 'string' || !checkpoint)
			throw new Error('Acting player must include a checkpoint');

			body.checkpoint = checkpoint;
		}

		await this.child(session, 'settled', body, `settled:${sequence}:${me}`);

		return true;
	}

	/** Freeze the local view of a match whose application rejected the durable transcript. */
	dispute(reason = 'The game transcript could not be verified')
	{
		const session = this.currentPlayer();

		if(['complete','frozen','expired'].includes(session.status)) return;

		this.freeze(session, String(reason));
	}

	/** End this player's active match with a durable resignation reason. */
	async resign(reason = 'resigned')
	{
		const session = this.currentPlayer();

		if(!['committing','ready','active'].includes(session.status)) return;

		await this.child(
			session
			, 'resign'
			, {player: this.profile().id, reason: String(reason)}
			, `resign:${this.profile().id}`
		);
	}

	/** Return the selected session after verifying the local player seat. */
	currentPlayer()
	{
		const session = this.sessions.get(this.selected);

		if(!session || session.localRole !== 'player' || !session.seats?.includes(this.profile().id))
		throw new Error('This browser is not seated at a table');

		return session;
	}

	/** Load one root offer and all related protocol events from room history. */
	async load(root)
	{
		if(this.sessions.has(root)) return this.sessions.get(root);

		const retryAt = this.unavailableRoots.get(root);

		if(retryAt > Date.now()) throw new Error('Table offer is temporarily unavailable');

		this.unavailableRoots.delete(root);

		if(this.rootLoads.has(root)) return this.rootLoads.get(root);

		const task = this.loadRoot(root);
		this.rootLoads.set(root, task);

		try
		{
			return await task;
		}
		catch(error)
		{
			if(!this.sessions.has(root))
			{
				this.unavailableRoots.delete(root);
				this.unavailableRoots.set(root, Date.now() + UNKNOWN_ROOT_RETRY);

				if(this.unavailableRoots.size > UNKNOWN_ROOT_LIMIT)
				this.unavailableRoots.delete(this.unavailableRoots.keys().next().value);
			}

			throw error;
		}
		finally
		{
			this.rootLoads.delete(root);
		}
	}

	/** Fetch and validate one exact root without following relations from an invalid root event. */
	async loadRoot(root)
	{
		const offer = await this.provider.roomEvent(root);

		if(offer?.event_id !== root) throw new Error('Matrix returned the wrong table root event');

		await this.consume(offer, {replay: true, loadUnknown: false});
		const session = this.sessions.get(root);

		if(!session) throw new Error('The related event does not identify a valid table offer');

		for(const item of await this.provider.relations(root))
		await this.consume(item, {replay: true, loadUnknown: false});

		if(!this.loadingHistory)
		{
			await this.resumeSeedExchange(session);
			this.armActionDeadline(session);
		}

		return session;
	}

	/** Publish an idempotent protocol child related to a table root. */
	async child(session, kind, body, transaction)
	{
		const content = {
			v: PROTOCOL_VERSION
			, kind
			, session_id: session.offer.session_id
			, ...body
			, 'm.relates_to': relation(session.root)
		};

		const result = await this.provider.gameEvent(content, `${session.offer.session_id}:${transaction}`);

		return result.event_id;
	}

	/** Validate and apply one annotated offer or related game event. */
	async consume(item, {replay = false, loadUnknown = true} = {})
	{
		if(![GAME_EVENT, ROOM_MESSAGE].includes(item?.type) || typeof item.sender !== 'string' || !item.event_id)
		return;

		const content = item.type === ROOM_MESSAGE ? item.content?.[GAME_EVENT] : item.content;

		if(!content || typeof content !== 'object') return;

		if(validOffer(content, this.game))
		{
			if(content.host !== item.sender || content.expires_at <= content.created_at) return;

			this.unavailableRoots.delete(item.event_id);

			if(!this.sessions.has(item.event_id))
			this.sessions.set(item.event_id, {
				root: item.event_id
				, offer: structuredClone(content)
				, status: content.expires_at > Date.now() ? 'open' : 'expired'
				, sequence: 0
				, hash: null
				, commits: new Map
				, reveals: new Map
				, openings: new Map
				, ready: new Map
				, settles: new Map
				, earlySettles: new Map
				, actions: new Map
				, fragmentActors: new Map
				, claims: []
				, events: new Set([item.event_id])
			});

			if(!replay) this.dispatchEvent(event('change', this.snapshot()));

			return;
		}

		const root = content?.['m.relates_to']?.event_id;

		if(typeof root !== 'string') return;

		let session = this.sessions.get(root);

		if(!session)
		{
			if(!loadUnknown) return;

			try { session = await this.load(root); } catch { return; }
		}

		if(!session) return;

		if(session.seats && ['complete','frozen','expired'].includes(session.status)) return;

		if(session.events.has(item.event_id) || content.session_id !== session.offer.session_id
			|| content.v !== PROTOCOL_VERSION
		)
		return;

		session.events.add(item.event_id);
		const me = this.connected ? this.profile().id : null;

		switch(content.kind)
		{
			case 'join':
				if(session.status !== 'open') break;

				if(content.player !== item.sender || session.seats || item.sender === session.offer.host) break;

				if(session.offer.target && session.offer.target !== item.sender) break;

				session.joiners ??= [];

				if(!session.joiners.includes(item.sender)) session.joiners.push(item.sender);

				if(me === session.offer.host && session.joiners.length === 1)
				{
					const timeout = seedTimeoutMs(session.offer.game);
					const deadline = Date.now() + timeout;

					await this.child(session, 'seat', {
						players: [session.offer.host, item.sender]
						, seed_timeout_ms: timeout
						, seed_deadline: deadline
						, commit_deadline: deadline - LEGACY_REVEAL_GRACE_MS
					}, `seat:${item.sender}`
					);
				}

				break;

			case 'seat':
				if(item.sender !== session.offer.host || session.seats || !Array.isArray(content.players)
					|| content.players.length !== 2 || content.players[0] !== session.offer.host
					|| content.players[0] === content.players[1] || !Number.isSafeInteger(content.commit_deadline)
					|| !Number.isSafeInteger(content.commit_deadline + LEGACY_REVEAL_GRACE_MS)
				) break;

				// Older transcripts omit both explicit fields. New starting guns must agree with the offered budget
				// and the legacy deadline so every supported reader expires at the same instant.
				if((content.seed_deadline !== undefined || content.seed_timeout_ms !== undefined)
					&& (content.seed_timeout_ms !== seedTimeoutMs(session.offer.game)
						|| content.seed_deadline !== content.commit_deadline + LEGACY_REVEAL_GRACE_MS
					)
				) break;

				session.seats = [...content.players];
				session.commitDeadline = content.commit_deadline;
				session.seedDeadline = content.seed_deadline ?? content.commit_deadline + LEGACY_REVEAL_GRACE_MS;
				session.status = 'committing';

				if(!replay)
				{
					this.armRevealDeadline(session);

					if(session.seats.includes(me)) await this.commit(session);
				}

				break;

			case 'commit':
				if(session.status !== 'committing' || !session.seats?.includes(item.sender)
					|| content.player !== item.sender
					|| !seedEventInTime(session, item)
					|| !/^[a-f0-9]{64}$/.test(content.commit) || session.commits.has(item.sender)
				) break;

				session.commits.set(item.sender, content.commit);

				if(!replay && session.commits.size === 2 && session.seats.includes(me)) await this.reveal(session);

				break;

			case 'reveal':
				if(session.status !== 'committing' || !session.seats?.includes(item.sender)
					|| content.player !== item.sender
					|| !seedEventInTime(session, item)
					|| session.reveals.has(item.sender)
				) break;

				try
				{
					if(!validSeedMaterial(content)) return this.freeze(session, 'Invalid seed reveal');

					const expected = await this.commitDigest(session, item.sender, content);

					// A deadline, resignation, or invalid competing reveal may finish the exchange during hashing.
					if(session.status !== 'committing' || session.reveals.has(item.sender)) return;

					if(expected !== session.commits.get(item.sender))
					return this.freeze(session, 'Invalid seed reveal');

					session.reveals.set(item.sender, content.contribution);
					session.openings.set(item.sender, {nonce: content.nonce, phrase: content.phrase});

					// Both players owe their ready report from the moment the seed is complete.
					if(session.reveals.size === 2) advanceClock(session, item);
				}
				catch
				{
					if(session.status === 'committing') this.freeze(session, 'Invalid seed reveal');

					return;
				}

				if(session.reveals.size === 2) await this.seed(session);

				break;

			case 'ready':
				if(!['committing','ready'].includes(session.status)
					|| !session.seats?.includes(item.sender) || content.player !== item.sender
					|| typeof content.hash !== 'string'
					|| stepLate(session, this.game, item)
				) break;

				session.ready.set(item.sender, content.hash);
				advanceClock(session, item);
				this.confirmReady(session);

				break;

			case 'action':
				if(session.actions.has(content.sequence))
				{
					if(stable(session.actions.get(content.sequence)) !== stable(content))
					return this.freeze(session, 'Conflicting duplicate action');

					break;
				}

				if(!session.seats?.includes(item.sender) || content.actor !== item.sender || session.pending
					|| session.batch?.actions.length >= MAX_ACTION_BATCH
					|| !Number.isSafeInteger(content.sequence)
					|| content.sequence !== (session.batch ? session.batch.actions.at(-1).sequence + 1 : session.sequence + 1)
					|| (!session.batch && (content.actor !== session.turn || content.pre_hash !== session.hash))
					|| (session.batch && typeof content.pre_hash !== 'string')
					|| (this.game.actionBatches
						? typeof content.batch_end !== 'boolean'
						: content.batch_end !== undefined)
						|| !validAction(content.action, this.game)
						|| stepLate(session, this.game, item)
				) break;

				session.actions.set(content.sequence, structuredClone(content));
				advanceClock(session, item);
				session.batch ??= {actions: []};
				session.batch.actions.push(structuredClone(content));

				if(!this.game.actionBatches || content.batch_end)
				{
					session.pending = structuredClone(content);
					session.settles.set(content.sequence, new Map);
				}

				if(this.selected === root) this.adapter?.action?.(structuredClone(content));

				const raced = session.earlySettles.get(content.sequence)?.values() ?? [];

				for(const {content: early, item: earlyItem} of raced)
				{
					if(!session.pending) break;

					// A report that raced ahead of its action is judged against the action's deadline.
					if(stepLate(session, this.game, earlyItem)) continue;

					if(this.game.checkpointMode === 'actor' && early.player === content.actor
						&& (typeof early.checkpoint !== 'string' || !early.checkpoint)
					)
					return this.freeze(session, 'Acting player omitted its checkpoint');

					advanceClock(session, earlyItem);
					await this.settle(session, early.player, early);
				}

				session.earlySettles.delete(content.sequence);
				break;

			case 'settled':
				if(!Number.isSafeInteger(content.sequence) || !session.seats?.includes(item.sender)
					|| content.player !== item.sender
					|| typeof content.hash !== 'string' || !session.seats.includes(content.next_actor)
					|| (content.checkpoint !== undefined && (typeof content.checkpoint !== 'string'
						|| content.checkpoint.length > 1024 * 1024
					))
				) break;

				if(!session.pending || content.sequence !== session.pending.sequence)
				{
					if(content.sequence > session.sequence && content.sequence <= session.sequence + 4096)
					{
						const early = session.earlySettles.get(content.sequence) ?? new Map;

						if(!early.has(item.sender))
						early.set(item.sender, {
							content: structuredClone(content)
							, item: {origin_server_ts: item.origin_server_ts}
						});

						session.earlySettles.set(content.sequence, early);
					}

					break;
				}

				if(this.game.checkpointMode === 'actor' && item.sender === session.pending.actor && (typeof content.checkpoint !== 'string'
					|| !content.checkpoint || content.checkpoint.length > 1024 * 1024
				))
				break;

				if(stepLate(session, this.game, item)) break;

				advanceClock(session, item);
				await this.settle(session, item.sender, content);
				break;

			case 'cancel':
			{
				// Before seating, the host withdraws a table, a challenged player declines it, or a joiner withdraws
				// a pending join. Seated games end only through resign.
				if(content.player !== item.sender || session.seats) break;

				const {host, target} = session.offer;

				if(item.sender !== host && item.sender !== target)
				{
					session.joiners = (session.joiners ?? []).filter(player => player !== item.sender);
					break;
				}

				const wasOpen = session.status === 'open';
				session.status = 'expired';
				session.closedBy = item.sender;

				if(this.selected === root && item.sender !== me)
				{
					this.selected = null;
					this.dispatchEvent(event('selected', null));

					if(!replay && this.connected) await this.provider.setActivity(null);
				}

				if(!replay && wasOpen && me && item.sender !== me && (me === host || me === target
					|| session.joiners?.includes(me)
				))
				this.dispatchEvent(event('notice', {
					kind: item.sender === host ? 'closed' : 'declined'
					, root
					, player: item.sender
				}));

				break;
			}

			case 'resign':
				if(content.player !== item.sender || !session.seats?.includes(item.sender)) break;

				// Old clients could publish a resignation for the waiting player after the seed deadline.
				if(session.status === 'committing' && !seedEventInTime(session, item)) break;

				session.status = 'complete';
				clearTimeout(session.deadlineTimer);
				clearTimeout(session.actionTimer);
				session.outcome = {
					winner: session.seats.find(player => player !== item.sender)
					, reason: content.reason
				};

				if(this.selected === root) this.adapter?.stop?.(structuredClone(session.outcome));

				break;

			case 'timeout':
				// A waiting player claims the match after the other player missed a step deadline.
				if(actionTimeoutMs(this.game) === null || !['ready','active'].includes(session.status)
					|| content.player !== item.sender || !session.seats?.includes(item.sender)
					|| !session.seats.includes(content.against) || content.against === item.sender
					|| !['ready','action','settle'].includes(content.stage) || !Number.isSafeInteger(content.sequence)
					|| !Number.isSafeInteger(session.clockSince) || !Number.isSafeInteger(item.origin_server_ts)
					|| !stepLate(session, this.game, item)
				) break;

				session.claims ??= [];

				session.claims.push({
					player: content.player
					, against: content.against
					, stage: content.stage
					, sequence: content.sequence
				});

				this.reviewClaims(session);

				break;
		}

		if(!replay && ['ready','active'].includes(session.status)) this.armActionDeadline(session);

		if(!replay) this.dispatchEvent(event('change', this.snapshot()));
	}

	/** Resume only after history has restored existing commitments and reveals. */
	async resumeSeedExchange(session)
	{
		if(session.status !== 'committing') return;

		// Replay first, then expire incomplete history before publishing any new private material.
		this.armRevealDeadline(session);

		if(!this.connected || session.status !== 'committing' || !session.seats?.includes(this.profile().id)) return;

		if(!session.commits.has(this.profile().id)) await this.commit(session);

		if(session.status !== 'committing') return;

		if(session.commits.size === 2) await this.reveal(session);

		if(session.status === 'committing') this.armRevealDeadline(session);
	}

	/** Read the complete private opening, retaining it in memory when browser storage is unavailable. */
	localSeedMaterial(session)
	{
		if(session.localSeedMaterial) return session.localSeedMaterial;

		let stored;

		try { stored = this.storage?.getItem(seedStorageKey(session, this.profile().id)); } catch { }

		if(stored == null) return null;

		let material;

		try { material = JSON.parse(stored); } catch { }

		if(!validSeedMaterial(material)) throw new Error('Stored seed commitment opening is invalid');

		return session.localSeedMaterial = material;
	}

	/** Create, retain, and publish a commitment to a contribution, independent nonce, and random phrase. */
	async commit(session)
	{
		this.armRevealDeadline(session);

		if(this.closed || session.status !== 'committing') return;

		const me = this.profile().id;
		let material;

		try { material = this.localSeedMaterial(session); } catch(error) { return this.freeze(session, error.message); }

		if(!material)
		{
			if(session.commits.has(me)) return this.freeze(session, 'Seed commitment opening was lost before reveal');

			// Disjoint CSPRNG bytes supply two independent 256-bit values and an unbiased choice among eight phrases.
			const bytes = new Uint8Array(65);
			crypto.getRandomValues(bytes);
			material = {
				contribution: bytes64(bytes.subarray(0, 32))
				, nonce: bytes64(bytes.subarray(32, 64))
				, phrase: bytes[64] & 7
			};

			try
			{
				this.storage?.setItem(seedStorageKey(session, me), JSON.stringify(material));
			}
			catch
			{
				/* A live tab still retains the value in memory. */
			}
		}

		session.localSeedMaterial = material;
		const value = await this.commitDigest(session, me, material);
		this.armRevealDeadline(session);

		if(this.closed || session.status !== 'committing') return;

		await this.child(session, 'commit', {player: me, commit: value}, `commit:${me}`);

		if(session.status === 'committing') this.armRevealDeadline(session);
	}

	/** Bind both literal operands and the phrase choice to this player and table. */
	commitDigest(session, player, material)
	{
		if(!validSeedMaterial(material)) throw new Error('Invalid seed commitment opening');

		const phrase = SEED_PHRASES[material.phrase]
			.replace('{n}', material.contribution).replace('{m}', material.nonce);

		return digest(
			'Phonics deterministic game seed commitment v2'
			, session.root
			, session.offer.session_id
			, player
			, material.phrase
			, phrase
		);
	}

	/** Reveal the locally retained contribution after both commitments exist. */
	async reveal(session)
	{
		this.armRevealDeadline(session);
		const me = this.profile().id;

		if(this.closed || session.commits.size !== 2 || session.reveals.has(me) || session.status !== 'committing')
		return;

		let material;

		try { material = this.localSeedMaterial(session); } catch(error) { return this.freeze(session, error.message); }

		if(!material) return this.freeze(session, 'Seed commitment opening was lost before reveal');

		await this.child(session, 'reveal', {player: me, ...material}, `reveal:${me}`);
	}

	/** Expire an incomplete seed exchange at its original deadline without resigning either player. */
	armRevealDeadline(session)
	{
		clearTimeout(session.deadlineTimer);
		delete session.deadlineTimer;

		if(this.closed || session.status !== 'committing' || session.reveals.size === 2) return;

		const delay = Math.max(0, session.seedDeadline - Date.now());

		if(delay > 0)
		{
			session.deadlineTimer = setTimeout(
				() => this.armRevealDeadline(session)
				, Math.min(delay, MAX_TIMER_DELAY_MS)
			);

			return;
		}

		session.status = 'expired';
		session.error = 'The seed exchange timed out. No winner was declared. Leave this table to start a new game.';
		this.forgetSeedMaterial(session);

		if(this.selected === session.root)
		{
			this.adapter?.stop?.({reason: session.error, expired: true});

			if(this.connected) void this.provider.setActivity(null).catch(error => this.fail(error));
		}

		this.dispatchEvent(event('change', this.snapshot()));
	}

	/**
	 * Name the step the match waits for and who owes it: each missing ready report, each missing settlement report
	 * for a finished action, the turn holder's next action, or inside a batch the actor the local game computed.
	 * owed is null when only the game could know and this browser has not run that step.
	 */
	obligation(session)
	{
		if(session.status === 'ready' && session.seats)
		return {
			stage: 'ready'
			, sequence: session.sequence
			, owed: session.seats.filter(player => !session.ready.has(player))
		};

		if(session.status !== 'active') return null;

		if(session.pending)
		{
			const settled = session.settles.get(session.pending.sequence) ?? new Map;

			return {
				stage: 'settle'
				, sequence: session.pending.sequence
				, owed: session.seats.filter(player => !settled.has(player))
			};
		}

		if(session.batch)
		{
			const sequence = session.batch.actions.at(-1).sequence;
			const actor = session.fragmentActors?.get(sequence);

			return {stage: 'action', sequence, owed: actor ? [actor] : null};
		}

		return {stage: 'action', sequence: session.sequence, owed: session.turn ? [session.turn] : null};
	}

	/** The selected step deadline for presentation: when it ends and who owes the step, if known. */
	clock(session)
	{
		const timeout = actionTimeoutMs(this.game);

		if(timeout === null || !['ready','active'].includes(session.status)
			|| !Number.isSafeInteger(session.clockSince)
		) return null;

		const due = this.obligation(session);

		return {
			deadline: session.clockSince + timeout
			, stage: due?.stage ?? null
			, owed: due?.owed ? [...due.owed] : null
		};
	}

	/**
	 * Decide received timeout claims against the step the match still waits for. A claim names that step, the
	 * player who owes it, and a claimant who does not. Stale claims are dropped. Inside a batch only the game knows
	 * who owes, so a seated player waits for its own game's report; a spectator, which cannot check, accepts.
	 */
	reviewClaims(session)
	{
		if(!['ready','active'].includes(session.status))
		{
			session.claims = [];

			return;
		}

		const due = this.obligation(session);
		const me = this.connected ? this.profile().id : null;
		const playing = this.selected === session.root && session.localRole === 'player' && Boolean(this.adapter)
			&& session.seats.includes(me);

		const waiting = [];

		for(const claim of session.claims ?? [])
		{
			if(!due || claim.stage !== due.stage || claim.sequence !== due.sequence) continue;

			if(due.owed === null)
			{
				if(playing)
				{
					waiting.push(claim);
					continue;
				}
			}
			else if(!due.owed.includes(claim.against) || due.owed.includes(claim.player)) continue;

			this.timeoutWin(session, claim);

			return;
		}

		session.claims = waiting;
	}

	/** End a match for the claimant whose opponent missed a step deadline. */
	timeoutWin(session, claim)
	{
		clearTimeout(session.actionTimer);
		delete session.actionTimer;
		session.claims = [];
		session.status = 'complete';
		session.outcome = {winner: claim.player, loser: claim.against, reason: 'timeout', timeout: true};

		if(this.selected === session.root) this.adapter?.stop?.(structuredClone(session.outcome));
	}

	/**
	 * Watch the current step deadline. Once it passes, the waiting player's browser claims the match. A table where
	 * both players owe a step expires without a winner. The player who owes the step, and spectators, only wait.
	 */
	armActionDeadline(session)
	{
		clearTimeout(session.actionTimer);
		delete session.actionTimer;

		const timeout = actionTimeoutMs(this.game);

		if(this.closed || timeout === null || !['ready','active'].includes(session.status)
			|| !Number.isSafeInteger(session.clockSince)
		) return;

		const due = this.obligation(session);

		const delay = Math.max(
			session.clockSince + timeout + CLAIM_GRACE_MS - Date.now()
			, (session.lastClaimAt ?? -Infinity) + CLAIM_RETRY_MS - Date.now()
		);

		if(delay > 0)
		{
			const wait = Math.min(delay, MAX_TIMER_DELAY_MS);
			session.actionTimer = setTimeout(() => this.armActionDeadline(session), wait);

			return;
		}

		if(!due?.owed?.length) return;

		if(due.owed.length === session.seats.length)
		{
			session.status = 'expired';
			session.error = 'Both players missed the time limit. No winner was declared. '
				+ 'Leave this table to start a new game.';

			if(this.selected === session.root)
			{
				this.adapter?.stop?.({reason: session.error, expired: true});

				if(this.connected) void this.provider.setActivity(null).catch(error => this.fail(error));
			}

			this.dispatchEvent(event('change', this.snapshot()));

			return;
		}

		const me = this.connected ? this.profile().id : null;

		if(!me || this.selected !== session.root || session.localRole !== 'player' || !session.seats.includes(me)
			|| due.owed.includes(me)
		) return;

		// Claims are published at most once per retry period; a claim that lands too early is simply rejected.
		session.lastClaimAt = Date.now();
		session.actionTimer = setTimeout(() => this.armActionDeadline(session), CLAIM_RETRY_MS);

		void this.child(
			session
			, 'timeout'
			, {player: me, against: due.owed[0], stage: due.stage, sequence: due.sequence}
			, `timeout:${due.stage}:${due.sequence}:${session.lastClaimAt}`
		).catch(error => this.fail(error));
	}

	/** Discard the locally retained opening after success or expiry. */
	forgetSeedMaterial(session)
	{
		delete session.localSeedMaterial;

		try { this.storage?.removeItem(seedStorageKey(session, this.profile().id)); } catch { }
	}

	/** Derive the shared seed and breaker from verified contributions. */
	async seed(session)
	{
		if(session.seed) return this.startIfPossible(session);

		if(this.closed || session.status !== 'committing' || session.reveals.size !== 2) return;

		clearTimeout(session.deadlineTimer);

		const pairs = [...session.reveals].sort(([a], [b]) => a.localeCompare(b));
		const promises = [...session.commits].sort(([a], [b]) => a.localeCompare(b));
		const product = new Uint8Array(32);

		for(const [, contribution] of pairs)
		{
			const bytes = from64(contribution);

			for(let index = 0; index < product.length; index++) product[index] ^= bytes[index];
		}

		const seed = await digest(
			'Phonics deterministic game seed v1'
			, session.root
			, this.game.runtime
			, this.game.application
			, this.game.rules
			, ...pairs.flat()
		);

		const breakerDigest = await digest('Phonics deterministic game breaker v1', seed, ...session.seats);

		if(this.closed || session.status !== 'committing') return;

		session.seed = seed;
		session.seedHandshake = {
			session: session.root
			, commitmentVersion: PROTOCOL_VERSION
			, hashPromises: Object.fromEntries(promises)
			, contributions: Object.fromEntries(pairs)
			, commitmentOpenings: Object.fromEntries(pairs.map(([player]) => [player, session.openings.get(player)]))
			, commutativeProduct: bytes64(product)
			, seed: session.seed
		};

		console.log('Phonics deterministic seed handshake', session.seedHandshake);

		session.turn = session.seats[parseInt(breakerDigest.slice(0, 2), 16) % session.seats.length];
		session.status = 'ready';
		this.forgetSeedMaterial(session);

		await this.startIfPossible(session);
		this.confirmReady(session);
	}

	/** Buffer early readiness reports until both openings have produced a verified seed. */
	confirmReady(session)
	{
		if(session.status !== 'ready' || !session.seed || session.ready.size !== 2) return;

		const hashes = [...session.ready.values()];

		console.log('Phonics deterministic initial-state handshake', {
			session: session.root
			, reports: Object.fromEntries(session.ready)
		});

		if(hashes[0] !== hashes[1]) return this.freeze(session, 'Initial state hashes differ');

		session.hash = hashes[0];
		session.status = 'active';
		this.confirmAdapter(session);
		this.dispatchEvent(event('change', this.snapshot()));

		if(this.connected && this.selected === session.root)
		void this.provider.setActivity({state: 'active', root: session.root, game: this.game.id}).catch(
			error => this.fail(error)
		);
	}

	/** Start or restore the attached application when table state is sufficient. */
	async startIfPossible(session)
	{
		if(this.closed || !['ready','active','complete'].includes(session.status)) return;

		if(this.selected !== session.root || !session.seed || session.started || !this.adapter) return;

		session.started = true;
		const confirmed = [...session.settles.entries()].filter(([, values]) => values.size === 2).at(-1);
		let checkpoint = null;

		if(confirmed)
		checkpoint = [...confirmed[1].values()].find(value => typeof value.checkpoint === 'string')?.checkpoint ?? null;

		const actionHistory = this.game.checkpointMode === 'history'
			? [...session.actions.values()]
				.filter(value => value.sequence <= session.sequence)
				.sort((left, right) => left.sequence - right.sequence)
				.map(value => structuredClone(value))
			: null;

		this.adapter.start?.({
			root: session.root
			, sessionId: session.offer.session_id
			, role: session.localRole
			, players: [...session.seats]
			, localPlayer: session.localRole === 'player' ? this.profile().id : null
			, seed: session.seed
			, breaker: session.turn
			, settings: structuredClone(session.offer.settings ?? null)
			, sequence: session.sequence
			, hash: session.hash
			, checkpoint
			, history: actionHistory
			, rules: this.game.rules
			, handshake: structuredClone(session.seedHandshake ?? null)
		});

		if(session.batch)
		for(const action of session.batch.actions) this.adapter.action?.(structuredClone(action));
		else if(session.status === 'active') this.confirmAdapter(session);
	}

	/** Notify the attached game once for each newly confirmed room sequence. */
	confirmAdapter(session)
	{
		if(this.selected !== session.root || !this.adapter || session.hash === null) return;

		const key = `${session.sequence}:${session.hash}`;

		if(session.adapterConfirmation === key) return;

		session.adapterConfirmation = key;
		const settlements = session.settles.get(session.sequence);
		const values = settlements ? [...settlements.values()] : [];

		const checkpoint = values.find(value => typeof value.checkpoint === 'string')?.checkpoint ?? null;

		this.adapter.confirmed?.({
			sequence: session.sequence
			, hash: session.hash
			, nextActor: session.turn
			, outcome: session.outcome ?? null
			, checkpoint
		});
	}

	/** Reconcile matching player acknowledgements for one action sequence. */
	async settle(session, player, content)
	{
		const values = session.settles.get(content.sequence);

		if(values.has(player))
		{
			if(stable(values.get(player)) !== stable(content)) this.freeze(session, 'Conflicting settlement');

			return;
		}

		values.set(player, structuredClone(content));

		if(values.size < 2) return;

		const [first, second] = [...values.values()];

		const reports = {};

		for(const [reporter, value] of values)
		{
			let checkpointHash = null;

			try
			{
				if(typeof value.checkpoint === 'string')
				checkpointHash = hex(new Uint8Array(await crypto.subtle.digest('SHA-256', from64(value.checkpoint))));
			}
			catch
			{
				checkpointHash = 'invalid';
			}

			reports[reporter] = {
				sequence: value.sequence
				, hash: value.hash
				, nextActor: value.next_actor
				, outcome: value.outcome
				, checkpointLength: typeof value.checkpoint === 'string' ? value.checkpoint.length : 0
				, checkpointHash
			};
		}

		console.log('Phonics deterministic settlement handshake', {session: session.root, reports});

		if(first.hash !== second.hash || first.next_actor !== second.next_actor
			|| stable(first.outcome) !== stable(second.outcome)
		) return this.freeze(session, 'Players disagree about the settled state');

		session.sequence = content.sequence;
		session.hash = content.hash;
		session.turn = content.next_actor;
		session.pending = null;
		session.batch = null;
		session.fragmentActors?.clear();
		session.outcome = structuredClone(content.outcome);

		this.confirmAdapter(session);

		if(session.outcome)
		{
			session.status = 'complete';
			clearTimeout(session.actionTimer);

			if(this.selected === session.root) this.adapter?.stop?.(structuredClone(session.outcome));
		}
	}

	/** Freeze a divergent session and notify the attached game and UI. */
	freeze(session, reason)
	{
		clearTimeout(session.deadlineTimer);
		clearTimeout(session.actionTimer);
		session.status = 'frozen';
		session.error = String(reason);

		if(this.selected === session.root) this.adapter?.stop?.({reason: session.error, frozen: true});

		this.dispatchEvent(event('error', new Error(session.error)));
		this.dispatchEvent(event('change', this.snapshot()));
	}

	/** Forward an asynchronous provider or protocol failure to observers. */
	fail(error) { this.dispatchEvent(event('error', error)); }

	/** Return immutable lobby, people, table, and selected-session presentation state. */
	snapshot()
	{
		const me = this.connected ? this.profile().id : null;
		const selected = this.sessions.get(this.selected);

		return Object.freeze({
			connected: this.connected
			, profile: this.connected ? this.profile() : null
			, peers: structuredClone(this.peers)
			, selected: this.selected
			, playing: Boolean(selected?.seed && selected?.seats)
			, tables: [...this.sessions.values()].filter(
				session => session.root === this.selected || (!['complete','expired'].includes(session.status)
					&& (session.seats || session.offer.expires_at > Date.now())
				)
			).slice(-100).map(session => ({
				root: session.root
				, host: session.offer.host
				, target: session.offer.target
				, status: session.status
				, players: session.seats ? [...session.seats] : [session.offer.host]
				, open: session.status === 'open' && !session.seats
				, joinable: session.status === 'open' && !session.seats && session.offer.host !== me
					&& (!session.offer.target || session.offer.target === me)
					&& this.settingsProblem(session.offer.settings ?? null) === null
				, mine: Boolean(me) && session.offer.host === me
				, forMe: Boolean(me) && session.offer.target === me
				, declinable: session.status === 'open' && !session.seats && Boolean(me) && session.offer.target === me
				, link: me && session.offer.host === me ? acceptUrl(this.location, session.offer.session_id) : null
				, expiresAt: session.offer.expires_at
				, settings: structuredClone(session.offer.settings ?? null)
				, settingsLabel: this.settingsLabel(session.offer.settings ?? null)
				, settingsProblem: this.settingsProblem(session.offer.settings ?? null)
				, supported: this.settingsProblem(session.offer.settings ?? null) === null
				, resumable: Boolean(session.seats?.includes(me)) && !['complete','expired'].includes(session.status)
				, spectatable: Boolean(session.seats) && !['expired'].includes(session.status)
				, sequence: session.sequence
				, turn: session.turn ?? null
				, clock: this.clock(session)
				, outcome: structuredClone(session.outcome ?? null)
				, error: session.error ?? null
			}))
		});
	}

	/** Reject operations before connection or after protocol shutdown. */
	requireConnected()
	{
		if(!this.connected || this.closed) throw new Error('The room lobby is not connected');
	}

	/** Stop timers and detach every provider listener once. */
	close()
	{
		if(this.closed) return;

		this.closed = true;

		for(const session of this.sessions.values())
		{
			clearTimeout(session.deadlineTimer);
			clearTimeout(session.actionTimer);
		}
		this.provider.removeEventListener('gameevent', this.onGame);
		this.provider.removeEventListener('presence', this.onPresence);
		this.provider.removeEventListener('chat', this.onChat);
	}
}

/** Normalize a room game's canvas ratio; `auto` lets responsive games fill the available stage. */
export const gameAspectRatio = value => {
	if(String(value ?? '').trim().toLowerCase() === 'auto') return null;

	if(Number.isFinite(value) && value > 0) return Number(value);

	const match = String(value ?? '').match(/^\s*(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)\s*$/);
	const ratio = match ? Number(match[1]) / Number(match[2]) : 16 / 9;

	return Number.isFinite(ratio) && ratio > 0 ? ratio : 16 / 9;
};

/** Fit a game canvas to its room stage, or use the whole stage for a responsive game. */
export const gameCanvasSize = (width, height, aspectRatio) => {
	if(aspectRatio === null) return {width, height};

	const fittedWidth = Math.min(width, height * aspectRatio);

	return {width: fittedWidth, height: fittedWidth / aspectRatio};
};

/* Room layout only: the panel over the running game, its people rail, and the stage that takes the canvas
 * during a seated game. Tokens, controls, cards, rows, and chat come from the shared overlay theme. */
const ROOM_STYLE = `
.phonics-room-game{position:absolute;inset:0;display:none;z-index:5;background:var(--ui-scrim);
-webkit-backdrop-filter:blur(10px) saturate(.85);backdrop-filter:blur(10px) saturate(.85);overflow:hidden}
.phonics-room-game.is-open{display:grid;grid-template:"panel" minmax(0,1fr)/minmax(0,1fr);padding:clamp(.5rem,2.2vw,1.5rem)}
.phonics-room-stage{display:none;grid-area:stage;min-width:0;min-height:0;background:#000;align-items:center;justify-content:center;overflow:hidden}
.phonics-room-panel{grid-area:panel;justify-self:center;width:min(70rem,100%);min-height:0;max-height:100%;display:grid;
grid-template-rows:auto minmax(0,1fr);grid-template-columns:minmax(0,1fr);background:var(--ui-panel);border:1px solid var(--ui-line);border-radius:16px;
box-shadow:0 24px 64px rgba(0,0,0,.45);overflow:hidden}
.phonics-room-header{display:flex;align-items:center;gap:.9rem;padding:.85rem 1.1rem;border-bottom:1px solid var(--ui-line)}
.phonics-room-header h2{flex:1;min-width:0;margin:0;font-size:1.15rem;font-weight:700;letter-spacing:.01em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.phonics-room-header button{flex:none}
.phonics-room-body{display:grid;grid-template-columns:minmax(0,1fr) 19rem;min-height:0}
.phonics-room-main{display:flex;flex-direction:column;gap:1.1rem;min-width:0;min-height:0;padding:1rem 1.1rem;overflow:auto}
.phonics-room-main>*{flex:none}
.phonics-room-rail{min-width:0;min-height:0;padding:1rem;border-left:1px solid var(--ui-line);overflow:auto}
.phonics-room-main>.phonics-room-chat-section{flex:1 1 auto;min-height:14rem}
.phonics-room-match{display:none}
.phonics-room-rules{margin:0;color:var(--ui-muted);font-size:.85rem}.phonics-room-rules[hidden]{display:none}
.phonics-room-clock{margin:0;font-variant-numeric:tabular-nums;color:var(--ui-muted);font-size:.9rem}
.phonics-room-clock.is-urgent{color:var(--ui-danger-text);font-weight:700}.phonics-room-clock[hidden]{display:none}
.phonics-room-game:not(.is-connected) .phonics-room-needs-connection{display:none}
.phonics-room-game:not(.is-connected) .phonics-room-body{grid-template-columns:minmax(0,1fr)}
.phonics-room-game:not(.is-connected) .phonics-room-panel{width:min(34rem,100%);align-self:center}
.phonics-room-game.has-game{grid-template:"stage side" minmax(0,1fr)/minmax(0,1fr) clamp(16rem,24vw,21rem);padding:0;background:#000;-webkit-backdrop-filter:none;backdrop-filter:none}
.phonics-room-game.has-game .phonics-room-stage{display:flex}
.phonics-room-game.has-game .phonics-room-stage>canvas{width:auto!important;height:auto!important;max-width:100%!important;max-height:100%!important;aspect-ratio:16/9;object-fit:contain}
.phonics-room-game.has-game.has-responsive-game .phonics-room-stage>canvas{aspect-ratio:auto}
.phonics-room-game.has-game .phonics-room-panel{grid-area:side;width:auto;max-height:none;border-width:0 0 0 1px;border-radius:0;box-shadow:none}
.phonics-room-game.has-game .phonics-room-body{grid-template-columns:minmax(0,1fr);grid-template-rows:minmax(0,1fr) auto}
.phonics-room-game.has-game .phonics-room-rail{border-left:0;border-top:1px solid var(--ui-line);max-height:11rem}
.phonics-room-game.has-game .phonics-room-match{display:flex}
.phonics-room-game.has-game .phonics-room-header h2,.phonics-room-game.has-game .phonics-room-header button{display:none}
.phonics-room-game.has-game .phonics-room-lobby-only{display:none}
.phonics-room-game.has-game .phonics-room-main>.phonics-room-chat-section{min-height:0}
@media(max-width:42rem){
.phonics-room-game.is-open{padding:0}
.phonics-room-panel{width:100%;border-width:0;border-radius:0}
.phonics-room-header{padding:.7rem .85rem;gap:.6rem}.phonics-room-header h2{font-size:1rem}
.phonics-room-body{grid-template-columns:minmax(0,1fr);grid-template-rows:auto auto;overflow:auto}
.phonics-room-main{min-height:auto;overflow:visible;padding:.85rem}
.phonics-room-rail{min-height:auto;overflow:visible;border-left:0;border-top:1px solid var(--ui-line);padding:.85rem}
.phonics-room-main>.phonics-room-chat-section{flex:none;min-height:auto}
.phonics-room-game .phonics-ui-chat{min-height:12rem;max-height:45vh}
.phonics-room-game.has-game{grid-template:"stage" minmax(0,1.3fr) "side" minmax(0,1fr)/minmax(0,1fr)}
.phonics-room-game.has-game .phonics-room-panel{border-width:1px 0 0}
.phonics-room-game.has-game .phonics-room-body{overflow:hidden}.phonics-room-game.has-game .phonics-room-main{overflow:auto}
.phonics-room-game.has-game .phonics-room-rail{display:none}}
`;

const activityLabel = activity => ({
	waiting: 'Waiting at a table'
	, active: 'Playing'
	, spectating: 'Watching a game'
})[activity?.state] ?? 'In the lobby';

/** Accessible embedded lobby shell used by direct applications. */
export class EmbeddedRoomLobby
{

	/** Build the hidden accessible lobby shell around an application canvas. */
	constructor({protocol, canvas, title = 'Online Play', signal})
	{
		this.protocol = protocol;
		this.canvas = canvas;
		this.host = canvas.parentElement;
		this.disposed = false;
		this.loginPending = null;
		this.signal = signal;
		this.snapshot = protocol.snapshot();
		this.names = new DisplayNames;
		this.phase = 'idle';
		this.failed = false;
		this.confirmTimer = null;
		this.confirmingLeave = false;
		this.waiting = null;
		this.ticker = new Ticker(() => this.tickWaiting());
		this.matchClock = null;
		this.clockTicker = new Ticker(() => this.tickClock());
		this.releaseTheme = useOverlayTheme();
		this.releaseRoomStyle = useStyle('room-game', ROOM_STYLE);
		this.aspectRatio = gameAspectRatio(protocol.game.aspectRatio);
		this.canvasSizing = null;

		this.root = node('section', {class: 'phonics-room-game phonics-ui', 'aria-label': title});
		this.root.classList.toggle('has-responsive-game', this.aspectRatio === null);
		this.stage = node('div', {class: 'phonics-room-stage'});
		this.stageResize = new ResizeObserver(() => this.sizeStage());
		this.stageResize.observe(this.stage);
		this.panel = node('div', {class: 'phonics-room-panel'});

		// Header: title, connection and account, and the way back to the game's own menu.
		const header = node('header', {class: 'phonics-room-header'});
		this.statusLine = new StatusLine({className: 'phonics-room-status'});
		this.statusLine.set('Offline');
		this.status = this.statusLine.text;
		this.closeButton = node('button', {type: 'button', class: 'is-quiet'}, 'Back');
		header.append(node('h2', {}, title), this.statusLine.element, this.closeButton);

		const body = node('div', {class: 'phonics-room-body'});
		this.content = node('div', {class: 'phonics-room-main'});

		this.rail = node(
			'aside'
			, {class: 'phonics-room-rail phonics-ui-section phonics-room-needs-connection', 'aria-label': 'People'}
		);

		// In a game, the sidebar names the players and owns the only way out, which confirms before forfeiting.
		this.matchCard = node('div', {class: 'phonics-ui-card phonics-room-match'});
		this.matchTitle = node('strong');
		this.leaveButton = node('button', {type: 'button'}, 'Leave table');
		const matchRow = node('div', {class: 'phonics-ui-row'});
		matchRow.append(this.leaveButton);
		this.matchRules = node('p', {class: 'phonics-room-rules'});
		this.clockText = node('p', {class: 'phonics-room-clock', role: 'timer'});
		this.clockText.hidden = true;
		this.matchCard.append(this.matchTitle, this.matchRules, this.clockText, matchRow);

		this.connectingCard = node('div', {class: 'phonics-ui-card phonics-room-lobby-only'});

		this.connectingCard.append(
			node('strong', {}, 'Connecting to the lobby…')
			, node('p', {}, 'Loading tables and chat.')
		);

		this.signinCard = node('div', {class: 'phonics-ui-card phonics-room-lobby-only'});
		this.loginButton = node('button', {type: 'button', class: 'is-primary'}, 'Continue with Matrix');
		const signinRow = node('div', {class: 'phonics-ui-row'});
		signinRow.append(this.loginButton);

		this.signinCard.append(
			node('strong', {}, 'Play online with a Matrix account')
			, node(
				'p'
				, {}
				, 'Open tables, challenge people, and chat. Matrix is an open chat network, and accounts are free.'
			)
			, signinRow
			, node('p', {}, 'Sign-in opens in a pop-up window.')
		);

		this.waitingCard = node('div', {class: 'phonics-ui-card is-waiting phonics-room-lobby-only'});
		this.waitingTitle = node('strong');
		this.waitingText = node('p');

		this.linkInput = node(
			'input'
			, {type: 'text', readonly: '', class: 'phonics-ui-link', 'aria-label': 'Invite link'}
		);

		this.copyButton = node('button', {type: 'button'}, 'Copy link');
		this.linkRow = node('div', {class: 'phonics-ui-row'});
		this.linkRow.append(this.linkInput, this.copyButton);
		this.cancelButton = node('button', {type: 'button'}, 'Cancel table');
		const waitingRow = node('div', {class: 'phonics-ui-row'});
		waitingRow.append(this.cancelButton);
		this.waitingRules = node('p', {class: 'phonics-room-rules'});
		this.waitingCard.append(this.waitingTitle, this.waitingText, this.waitingRules, this.linkRow, waitingRow);

		const tables = node(
			'section'
			, {class: 'phonics-ui-section phonics-room-lobby-only phonics-room-needs-connection'}
		);

		const tablesHead = node('div', {class: 'phonics-ui-section-head'});
		this.openButton = node('button', {type: 'button', class: 'is-primary'}, 'Open a table');
		tablesHead.append(node('h3', {}, 'Tables'), this.openButton);
		this.tableList = node('ul', {class: 'phonics-ui-list'});

		this.tablesEmpty = node(
			'p'
			, {class: 'phonics-ui-empty'}
			, 'No open tables yet. Open one, or challenge someone who is online.'
		);

		tables.append(tablesHead, this.tableList, this.tablesEmpty);

		const chat = node(
			'section'
			, {class: 'phonics-ui-section phonics-room-chat-section phonics-room-needs-connection'}
		);

		this.chat = new ChatLog({names: this.names, self: () => this.snapshot.profile?.id ?? null});
		this.chatLog = this.chat.element;
		this.chatForm = node('form', {class: 'phonics-ui-chat-form'});

		this.chatInput = node(
			'input'
			, {type: 'text', maxlength: '1000', placeholder: 'Message the lobby', 'aria-label': 'Message the lobby'}
		);

		this.chatForm.append(this.chatInput, node('button', {type: 'submit'}, 'Send'));
		chat.append(node('div', {class: 'phonics-ui-section-head'}, ''), this.chatLog, this.chatForm);
		chat.firstChild.append(node('h3', {}, 'Lobby chat'));

		this.peerHeading = node('h3', {}, 'People');
		this.peerList = node('ul', {class: 'phonics-ui-list'});
		this.peersEmpty = node('p', {class: 'phonics-ui-empty'}, 'Nobody else is here yet.');
		this.rail.append(node('div', {class: 'phonics-ui-section-head'}), this.peerList, this.peersEmpty);
		this.rail.firstChild.append(this.peerHeading);

		this.content.append(this.matchCard, this.connectingCard, this.signinCard, this.waitingCard, tables, chat);
		body.append(this.content, this.rail);
		this.panel.append(header, body);
		this.root.append(this.stage, this.panel);
		const parent = this.host;
		parent.style.position ||= 'relative';
		parent.append(this.root);

		this.openButton.addEventListener('click', () => void this.run(() => this.openTable()));
		this.cancelButton.addEventListener('click', () => void this.run(() => protocol.leave()));
		this.leaveButton.addEventListener('click', () => void this.requestLeave());
		this.closeButton.addEventListener('click', () => void this.requestLeave({close: true}));
		this.loginButton.addEventListener('click', () => void this.login());
		this.copyButton.addEventListener('click', () => void this.copyLink());

		this.chatForm.addEventListener('submit', input => {
			input.preventDefault();
			const value = this.chatInput.value.trim();

			if(!value) return;

			this.chatInput.value = '';
			void this.run(() => protocol.provider.chat(value));
		});

		this.keydown = input => {
			if(input.key !== 'Escape' || this.root.classList.contains('has-game')) return;

			// A modal inside the lobby, such as the house-rules dialog, owns its own Escape.
			if(input.target instanceof Element && input.target.closest('dialog')) return;

			if(input.target === this.chatInput && this.chatInput.value)
			{
				this.chatInput.value = '';

				return;
			}

			input.preventDefault();
			void this.requestLeave({close: true});
		};

		this.root.addEventListener('keydown', this.keydown);

		this.change = ({detail}) => this.render(detail);

		this.message = ({detail}) => this.addChat(detail);

		this.error = ({detail}) => this.setStatus(detail?.message ?? String(detail), 'error');

		this.notice = ({detail}) => {
			const name = this.displayName(detail?.player);
			const text = detail?.kind === 'declined' ? `${name} declined your challenge.` : `${name} closed the table.`;
			this.addSystem(text);
			this.setStatus('Connected');
		};

		protocol.addEventListener('change', this.change);
		protocol.addEventListener('chat', this.message);
		protocol.addEventListener('error', this.error);
		protocol.addEventListener('notice', this.notice);
		signal?.addEventListener('abort', () => this.dispose(), {once: true});
		this.render(this.snapshot);
	}

	/** Name a Matrix account by its presence display name, or by its localpart until presence arrives. */
	displayName(id)
	{
		return this.names.name(id);
	}

	/** Show connection state beside the account; errors also tint the indicator. */
	setStatus(text, tone = null)
	{
		this.failed = tone === 'error';
		this.root.classList.toggle('is-failed', this.failed);
		this.statusLine.set(text, this.statusTone());
	}

	/** Tint the connection dot: failed, connected, connecting, or neutral. */
	statusTone()
	{
		if(this.failed) return 'error';

		if(this.snapshot.connected) return 'ok';

		return this.phase === 'connecting' ? 'busy' : null;
	}

	/** Fit the game canvas from stage dimensions rather than its self-changing framebuffer dimensions. */
	sizeStage()
	{
		if(!this.root.classList.contains('has-game')) return;

		const {width,height} = this.stage.getBoundingClientRect();

		if(width < 1 || height < 1) return;

		this.canvasSizing ??= ['width','height'].map(name => [
			name
			, this.canvas.style.getPropertyValue(name)
			, this.canvas.style.getPropertyPriority(name)
		]);

		const {width: fittedWidth, height: fittedHeight} = gameCanvasSize(width, height, this.aspectRatio);
		this.canvas.style.setProperty('width', `${fittedWidth}px`, 'important');
		this.canvas.style.setProperty('height', `${fittedHeight}px`, 'important');
	}

	/** Restore the application-owned canvas sizing when it leaves the lobby stage. */
	restoreCanvasSizing()
	{
		if(!this.canvasSizing) return;

		for(const [name,value,priority] of this.canvasSizing)
		{
			if(value) this.canvas.style.setProperty(name, value, priority);
			else this.canvas.style.removeProperty(name);
		}

		this.canvasSizing = null;
	}

	/** Reveal the lobby over the running game and connect room history. */
	async open()
	{
		this.root.classList.add('is-open');
		this.canvas.inert = false;
		this.phase = 'connecting';
		this.setStatus('Connecting…');
		this.render(this.protocol.snapshot());

		try
		{
			await this.protocol.connect();
			this.phase = 'ready';
			this.setStatus('Connected');
			this.render(this.protocol.snapshot());
			this.focusPrimary();
		}
		catch(error)
		{
			const signin = error.code === 'matrix_auth_required';
			this.phase = signin ? 'signin' : 'failed';

			// The sign-in card explains a missing session, so the header only needs a short state.
			this.setStatus(signin ? 'Signed out' : error.message, signin ? null : 'error');
			this.render(this.protocol.snapshot());
			this.focusPrimary();
			throw error;
		}
	}

	/** Move keyboard focus to the action that matters most in the current state. */
	focusPrimary()
	{
		const target = this.phase === 'signin'
			? this.loginButton
			: this.snapshot.selected && !this.snapshot.playing ? this.cancelButton : this.openButton;

		if(!target.hidden && !target.disabled) target.focus({preventScroll: true});
	}

	/** Authenticate in a popup, reconnect the room protocol, and retain the running game page. */
	login()
	{
		if(this.loginPending) return this.loginPending;

		this.loginButton.disabled = true;
		this.setStatus('Opening Matrix sign-in…');

		this.loginPending = authorizeWithPopup({
			begin: returnUrl => this.protocol.provider.beginOAuth(returnUrl)
			, complete: async callbackUrl => {
				const completed = await this.protocol.provider.completeOAuth(callbackUrl, {replaceHistory: false});

				if(!completed) throw new Error('Matrix returned no authorization response');

				await this.protocol.connect();
			}
			, signal: this.signal
		}).then(() => {
			this.phase = 'ready';
			this.setStatus('Connected');
		}).catch(error => {
			this.setStatus(error.message, 'error');
		}).finally(() => {
			this.loginPending = null;
			this.loginButton.disabled = false;
			this.render(this.protocol.snapshot());
			this.focusPrimary();
		});

		return this.loginPending;
	}

	/**
	 * Leave the current table, or the lobby itself, confirming first when that would forfeit a game in progress.
	 *
	 * @param {{close?: boolean}} [options] Close the lobby after leaving.
	 */
	async requestLeave({close = false} = {})
	{
		const selected = this.snapshot.tables.find(table => table.root === this.snapshot.selected);
		const forfeits = Boolean(selected?.resumable && ['committing','ready','active'].includes(selected.status));

		if(forfeits && !this.confirmingLeave)
		{
			this.confirmingLeave = true;
			this.leaveButton.textContent = 'Forfeit and leave?';
			this.leaveButton.classList.add('is-danger');
			this.leaveButton.focus({preventScroll: true});
			clearTimeout(this.confirmTimer);
			this.confirmTimer = setTimeout(() => this.resetLeave(), 4000);

			return;
		}

		this.resetLeave();

		if(close) await this.close();
		else await this.run(() => this.protocol.leave());
	}

	/** Return the leave control to its normal label after a confirmation is used or times out. */
	resetLeave()
	{
		clearTimeout(this.confirmTimer);
		this.confirmTimer = null;
		this.confirmingLeave = false;
		this.leaveButton.textContent = 'Leave table';
		this.leaveButton.classList.remove('is-danger');
	}

	/** Leave the selected table and return the canvas to its original host. */
	async close()
	{
		if(this.protocol.selected) await this.run(() => this.protocol.leave());

		this.root.classList.remove('is-open', 'has-game');
		this.restoreCanvasSizing();

		if(this.canvas.parentElement !== this.host) this.host.append(this.canvas);

		this.protocol.dispatchEvent(event('closed', {}));
	}

	/**
	 * Open a table, or challenge one person, after the host picks its house rules. Games that publish a settings
	 * form get a launcher-style dialog; cancelling it posts nothing. Games without one open with no settings.
	 */
	async openTable(target = null)
	{
		if(this.choosingSettings) return;

		const adapter = this.protocol.adapter;
		let settings = null;

		this.choosingSettings = true;
		this.openButton.disabled = true;

		try
		{
			const form = typeof adapter?.settingsForm === 'function' ? await adapter.settingsForm({target}) : null;

			if(form)
			{
				settings = await chooseMatchSettings({
					parent: this.root
					, form
					, problem: value => this.protocol.settingsProblem(value)
					, describe: value => this.protocol.settingsLabel(value)
					, submitLabel: target ? 'Send challenge' : 'Open table'
					, signal: this.signal
				});

				if(settings === null || this.disposed) return;
			}
		}
		finally
		{
			this.choosingSettings = false;

			if(!this.disposed) this.render(this.protocol.snapshot());
		}

		await this.protocol.create({target, settings});

		// Remembering the choice is a convenience; a failure there must not undo the table.
		try
		{
			adapter?.settingsChosen?.(structuredClone(settings));
		}
		catch
		{
			// The table is already open.
		}
	}

	/** Run a UI operation while presenting its failure in the live status region. */
	async run(operation)
	{
		try
		{
			await operation();
		}
		catch(error)
		{
			this.setStatus(error.message, 'error');
		}
	}

	/** Copy the invite link, falling back to selecting it where clipboard access is denied. */
	async copyLink()
	{
		this.copyButton.textContent = await copyText(this.linkInput.value, this.linkInput) ? 'Copied' : 'Press Ctrl+C';

		setTimeout(() => { this.copyButton.textContent = 'Copy link'; }, 2000);
	}

	/** Describe one table from the local player's point of view, with its house rules when it has any. */
	describe(table, me)
	{
		const {name, detail} = this.describeSeats(table, me);

		if(table.players.length < 2 && table.settingsProblem && table.status === 'open')
			return {name, detail: `Can’t join: ${table.settingsProblem}`};

		return {name, detail: table.settingsLabel ? `${detail} · ${table.settingsLabel}` : detail};
	}

	/** Name a table and its state from the local player's point of view. */
	describeSeats(table, me)
	{
		const host = this.displayName(table.host);
		const players = table.players.map(player => player === me ? 'You' : this.displayName(player));

		if(table.players.length === 2)
		{
			const state = {
				committing: 'Starting…'
				, ready: 'Starting…'
				, active: 'In progress'
				, frozen: 'Paused'
				, complete: 'Finished'
				, expired: 'Expired'
			}[table.status]
				?? 'Starting…';

			return {name: `${players[0]} vs ${players[1]}`, detail: state};
		}

		const minutes = Math.max(1, Math.round((table.expiresAt - Date.now()) / 60000));

		if(table.forMe) return {name: `${host} challenged you`, detail: 'Accept to start a game'};

		if(table.target)
		return {
			name: `${host} challenged ${this.displayName(table.target)}`
			, detail: 'Waiting for an answer'
		};

		return {name: `${host}’s table`, detail: `Open · expires in ${minutes} min`};
	}

	/** Render tables, controls, and people from one protocol snapshot. */
	render(snapshot)
	{
		this.snapshot = snapshot;
		const me = snapshot.profile?.id ?? null;

		// History often arrives before presence; relabel earlier messages once display names are known.
		if(this.names.learn(snapshot.peers)) this.chat.relabel();

		if(snapshot.connected && this.phase !== 'ready') this.phase = 'ready';

		this.root.classList.toggle('is-connected', Boolean(snapshot.connected));
		this.statusLine.setDetail(snapshot.connected && me ? `as ${this.displayName(me)}` : '');
		this.statusLine.set(this.status.textContent, this.statusTone());
		this.connectingCard.hidden = this.phase !== 'connecting';
		this.signinCard.hidden = this.phase !== 'signin' || snapshot.connected;
		this.chatInput.disabled = !snapshot.connected;

		// The game canvas stays live behind the frosted lobby and moves into the stage only for a seated game.
		const playing = Boolean(snapshot.playing);
		this.root.classList.toggle('has-game', playing);

		if(playing)
		{
			if(this.canvas.parentElement !== this.stage) this.stage.append(this.canvas);

			this.sizeStage();
		}
		else
		{
			this.restoreCanvasSizing();

			if(this.canvas.parentElement === this.stage) this.host.append(this.canvas);

			this.resetLeave();
		}

		const selected = snapshot.tables.find(table => table.root === snapshot.selected) ?? null;
		this.renderMatch(selected, me, playing);
		this.renderWaiting(selected, playing);
		this.openButton.disabled = Boolean(snapshot.selected) || !snapshot.connected || Boolean(this.choosingSettings);

		const listed = snapshot.tables.filter(table => table.root !== snapshot.selected);
		this.tableList.replaceChildren(...listed.map(table => this.tableItem(table, me)));
		this.tablesEmpty.hidden = listed.length > 0;

		const others = snapshot.peers.filter(peer => peer.id !== me);
		this.peerHeading.textContent = snapshot.peers.length ? `People · ${snapshot.peers.length} online` : 'People';
		this.peerList.replaceChildren(...snapshot.peers.map(peer => this.peerItem(peer, me, snapshot)));
		this.peersEmpty.hidden = others.length > 0;
	}

	/** Name the players, or the watched pairing, in the in-game sidebar. */
	renderMatch(selected, me, playing)
	{
		this.matchClock = playing && selected?.clock ? {...selected.clock, me, players: [...selected.players]} : null;
		this.clockTicker.sync();

		if(!playing || !selected) return;

		const spectating = !selected.players.includes(me);
		const [first, second] = selected.players.map(player => player === me ? 'You' : this.displayName(player));
		this.matchTitle.textContent = spectating ? `Watching ${first} vs ${second}` : `${first} vs ${second}`;
		this.setRules(this.matchRules, selected);

		if(!this.confirmingLeave) this.leaveButton.textContent = spectating ? 'Stop watching' : 'Leave table';
	}

	/** Show the waiting card with its invite link and countdown while a table is not yet seated. */
	renderWaiting(selected, playing)
	{
		const waiting = Boolean(selected) && !playing;
		this.waitingCard.hidden = !waiting;
		this.waiting = null;
		this.setRules(this.waitingRules, waiting ? selected : null);

		if(!waiting)
		{
			this.ticker.stop();

			return;
		}

		if(selected.status === 'expired')
		{
			this.ticker.stop();
			this.waitingTitle.textContent = 'Table expired';
			this.waitingText.textContent = selected.error ?? 'This table is no longer available.';
			this.linkRow.hidden = true;
			this.cancelButton.textContent = 'Leave table';

			return;
		}

		const target = selected.target ? this.displayName(selected.target) : null;

		if(selected.mine)
		{
			this.waitingTitle.replaceChildren(
				node('span', {class: 'phonics-ui-pulse', 'aria-hidden': 'true'})
				, target ? `Waiting for ${target} to accept` : 'Waiting for an opponent'
			);

			this.linkInput.value = selected.link ?? '';
			this.linkRow.hidden = !selected.link;
			this.cancelButton.textContent = 'Cancel table';

			this.waiting = {
				expiresAt: selected.expiresAt
				, text: target
					? 'They can accept from the lobby, or you can send them this link.'
					: 'Anyone in the lobby can join. To invite someone directly, send them this link.'
			};

			this.ticker.sync();
		}
		else
		{
			const spectating = !selected.players.includes(this.snapshot.profile?.id);

			this.ticker.stop();

			this.waitingTitle.replaceChildren(
				node('span', {class: 'phonics-ui-pulse', 'aria-hidden': 'true'})
				, spectating ? 'Loading the game…' : `Joining ${this.displayName(selected.host)}’s table…`
			);

			this.waitingText.textContent = spectating
				? 'The board appears as soon as the latest position loads.'
				: 'The game starts as soon as both players have agreed on a fair rack.';

			this.linkRow.hidden = true;
			this.cancelButton.textContent = 'Leave';
		}
	}

	/** Show a table's house rules in a card, or hide the line when it has none. */
	setRules(element, table)
	{
		element.hidden = !table?.settingsLabel;
		element.textContent = table?.settingsLabel ? `House rules: ${table.settingsLabel}` : '';
	}

	/**
	 * Show the step countdown in its last minute: whose step it is and how long remains, then who ran out of time
	 * while the waiting player's claim lands. Returns whether a step clock is still running.
	 */
	tickClock()
	{
		const clock = this.matchClock;

		if(!clock)
		{
			this.clockText.hidden = true;

			return false;
		}

		const remaining = clock.deadline - Date.now();
		const owed = clock.owed;
		const others = owed?.filter(player => player !== clock.me) ?? [];
		const mine = Boolean(owed?.includes(clock.me));
		const both = Boolean(owed) && owed.length === clock.players.length;
		const who = both ? 'both players' : others.length ? this.displayName(others[0]) : null;

		this.clockText.hidden = remaining > CLOCK_WARNING_MS;
		this.clockText.classList.toggle('is-urgent', mine && !both);

		if(remaining > 0)
		{
			const time = timeLeft(clock.deadline);

			this.clockText.textContent = mine && !both
				? `Your move · ${time} left`
				: who ? `Waiting for ${who} · ${time} left` : `Waiting · ${time} left`;
		}
		else
		{
			this.clockText.textContent = both
				? 'Both players are out of time.'
				: mine ? 'You ran out of time.' : who ? `${who} ran out of time.` : 'Time is up.';
		}

		return true;
	}

	/** Rewrite the waiting card's countdown; returns whether it is still running. */
	tickWaiting()
	{
		if(!this.waiting) return false;

		this.waitingText.textContent = `${this.waiting.text} Open for ${timeLeft(this.waiting.expiresAt)}.`;

		return true;
	}

	/** Build one table row with the actions this account may take on it. */
	tableItem(table, me)
	{
		const {name, detail} = this.describe(table, me);
		const actions = [];

		const button = (label, operation, primary = false) => {
			const control = node('button', {type: 'button', class: primary ? 'is-primary' : null}, label);
			control.addEventListener('click', () => void this.run(operation));
			actions.push(control);
		};

		if(table.joinable) button(table.forMe ? 'Accept' : 'Join', () => this.protocol.join(table.root), true);

		if(table.declinable) button('Decline', () => this.protocol.decline(table.root));

		if(table.resumable) button('Resume', () => this.protocol.resume(table.root), true);
		else if(table.spectatable) button('Watch', () => this.protocol.spectate(table.root));

		return personRow({
			id: table.host
			, name
			, detail
			, avatarName: this.displayName(table.host)
			, highlight: Boolean(table.forMe && table.joinable)
			, actions
		});
	}

	/** Build one person row with their activity and, when idle, a challenge action. */
	peerItem(peer, me, snapshot)
	{
		const name = this.displayName(peer.id);
		const actions = [];

		if(snapshot.connected && peer.id !== me && !snapshot.selected && !peer.activity)
		{
			const challenge = node('button', {type: 'button'}, 'Challenge');
			challenge.addEventListener('click', () => void this.run(() => this.openTable(peer.id)));
			actions.push(challenge);
		}

		return personRow({
			id: peer.id
			, name: peer.id === me ? `${name} (you)` : name
			, avatarName: name
			, detail: activityLabel(peer.activity)
			, actions
			, compact: true
		});
	}

	/** Append one interoperable Matrix text message to the room log. */
	addChat(message)
	{
		this.chat.add(message);
	}

	/** Append a lobby event, such as a declined challenge, in the room log's quieter style. */
	addSystem(text)
	{
		this.chat.system(text);
	}

	/** Detach the shell, restore the canvas, and release shared theme state once. */
	dispose()
	{
		if(this.disposed) return;

		this.disposed = true;
		this.chat.dispose();
		this.ticker.stop();
		this.clockTicker.stop();
		clearTimeout(this.confirmTimer);
		this.stageResize.disconnect();
		this.root.removeEventListener('keydown', this.keydown);

		this.protocol.removeEventListener('change', this.change);
		this.protocol.removeEventListener('chat', this.message);
		this.protocol.removeEventListener('error', this.error);
		this.protocol.removeEventListener('notice', this.notice);
		this.restoreCanvasSizing();
		this.host.append(this.canvas);
		this.root.remove();
		this.releaseRoomStyle();
		this.releaseTheme();
	}
}
