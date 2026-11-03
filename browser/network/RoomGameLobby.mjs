import {authorizeWithPopup} from './OAuthPopup.mjs';

const GAME_EVENT = 'org.seanmorris.phonics.game.v1';
const CHAT_EVENT = 'org.seanmorris.phonics.chat.v1';
const ROOM_MESSAGE = 'm.room.message';
const RELATION = 'org.seanmorris.phonics.game.v1';
const UNKNOWN_ROOT_RETRY = 60_000;
const UNKNOWN_ROOT_LIMIT = 1024;
const encoder = new TextEncoder;

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

const validAction = action => {
	if(!action || typeof action !== 'object') return false;

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

const validOffer = (content, game) => content?.v === 1 && content.kind === 'offer'
	&& typeof content.session_id === 'string' && typeof content.game === 'object'
	&& content.game.id === game.id && content.game.protocol === game.protocol
	&& content.game.rules === game.rules && content.game.application === game.application
	&& content.game.runtime === game.runtime && Number.isSafeInteger(content.expires_at);

/**
 * Durable, deterministic two-player room protocol.
 *
 * Matrix supplies identity, persistence, ordering metadata, and chat. The protocol supplies explicit action
 * sequence numbers, commit/reveal seeding, state acknowledgements, resumable checkpoints, and conflict freezing.
 */
export class RoomGameProtocol extends EventTarget
{

	/** Create a room-game coordinator over one connected lobby provider. */
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
		this.provider = provider;
		this.game = Object.freeze({...game});
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
	async create({target = null} = {})
	{
		this.requireConnected();

		if(this.selected) throw new Error('Leave the current table before opening another');

		const sessionId = crypto.randomUUID();
		const content = {
			v: 1
			, kind: 'offer'
			, session_id: sessionId
			, host: this.profile().id
			, target: target || null
			, created_at: Date.now()
			, expires_at: Date.now() + 15 * 60_000
			, game: {...this.game}
		};

		const url = acceptUrl(this.location, sessionId);
		const displayName = this.profile().displayName ?? this.profile().id;
		const verb = target ? `challenged ${target} to` : 'opened';
		const callToAction = target ? 'Accept challenge' : 'Join table';
		const body = target
			? `${displayName} challenged ${target} to ${this.game.title ?? this.game.id}. Accept: ${url}`
			: `${displayName} opened a ${this.game.title ?? this.game.id} table. Join: ${url}`;

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
			)}. <a href="${escapeHtml(
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
			&& !['complete','frozen'].includes(session.status)
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

	/** Attach the application adapter that executes confirmed deterministic actions. */
	attachGame(adapter)
	{
		if(this.adapter) throw new Error('A game adapter is already attached');

		this.adapter = adapter;
		const selected = this.sessions.get(this.selected);

		if(selected) void this.startIfPossible(selected);

		return Object.freeze({
			submitAction: (action, preHash) => this.submitAction(action, preHash)
			, reportReady: hash => this.reportReady(hash)
			, reportSettled: result => this.reportSettled(result)
			, resign: reason => this.resign(reason)
			, leave: () => this.leave()
			, snapshot: () => this.snapshot()
		});
	}

	/** Publish the next quantized action from the current actor. */
	async submitAction(action, preHash)
	{
		const session = this.currentPlayer();
		const me = this.profile().id;

		if(!validAction(action)) throw new Error('The game supplied an invalid quantized action');

		// The application bridge can observe the durable action before it receives the
		// acknowledgement for its local outbox entry. Treat that exact retry as success.
		if(session.pending?.actor === me && session.pending.pre_hash === preHash
			&& stable(session.pending.action) === stable(action)
		) return;

		if(session.status !== 'active' || session.turn !== me || session.pending)
		throw new Error('It is not your turn');

		if(preHash !== session.hash) throw new Error('Local state differs from the confirmed turn state');

		const sequence = session.sequence + 1;

		await this.child(session, 'action', {
			sequence
			, actor: me
			, pre_hash: preHash
			, action: structuredClone(action)
		}, `action:${sequence}:${me}`
		);
	}

	/** Publish this player's initial state hash after seeded setup. */
	async reportReady(hash)
	{
		const session = this.currentPlayer();

		await this.child(
			session
			, 'ready'
			, {player: this.profile().id, hash: String(hash)}
			, `ready:${this.profile().id}`
		);
	}

	/** Acknowledge one settled action and supply the actor's resumable checkpoint. */
	async reportSettled({sequence, hash, nextActor, outcome = null, checkpoint = null})
	{
		const session = this.currentPlayer();
		const me = this.profile().id;

		if(!session.pending || sequence !== session.pending.sequence) throw new Error('No matching action is settling');

		const body = {
			player: me
			, sequence
			, hash: String(hash)
			, next_actor: String(nextActor)
			, outcome: outcome === null ? null : structuredClone(outcome)
		};

		if(session.pending.actor === me)
		{
			if(typeof checkpoint !== 'string' || !checkpoint)
			throw new Error('Acting player must include a checkpoint');

			body.checkpoint = checkpoint;
		}

		await this.child(session, 'settled', body, `settled:${sequence}:${me}`);
	}

	/** End this player's active match with a durable resignation reason. */
	async resign(reason = 'resigned')
	{
		const session = this.currentPlayer();

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

		return session;
	}

	/** Publish an idempotent protocol child related to a table root. */
	async child(session, kind, body, transaction)
	{
		const content = {
			v: 1
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
				, ready: new Map
				, settles: new Map
				, earlySettles: new Map
				, actions: new Map
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

		if(session.events.has(item.event_id) || content.session_id !== session.offer.session_id || content.v !== 1)
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
				await this.child(session, 'seat', {
					players: [session.offer.host, item.sender]
					, commit_deadline: Date.now() + 60_000
				}, `seat:${item.sender}`
				);

				break;

			case 'seat':
				if(item.sender !== session.offer.host || session.seats || !Array.isArray(content.players)
					|| content.players.length !== 2 || content.players[0] !== session.offer.host
					|| content.players[0] === content.players[1] || !Number.isSafeInteger(content.commit_deadline)
				) break;

				session.seats = [...content.players];
				session.commitDeadline = content.commit_deadline;
				session.status = 'committing';

				if(session.seats.includes(me)) await this.commit(session);

				break;

			case 'commit':
				if(!session.seats?.includes(item.sender) || content.player !== item.sender
					|| !/^[a-f0-9]{64}$/.test(content.commit) || session.commits.has(item.sender)
				) break;

				session.commits.set(item.sender, content.commit);

				if(session.commits.size === 2 && session.seats.includes(me)) await this.reveal(session);

				break;

			case 'reveal':
				if(!session.seats?.includes(item.sender) || content.player !== item.sender
					|| session.reveals.has(item.sender)
				) break;

				try
				{
					const contribution = from64(content.contribution);

					if(contribution.length !== 32) break;

					const expected = await this.commitDigest(session, item.sender, content.contribution);

					if(expected !== session.commits.get(item.sender))
					return this.freeze(session, 'Invalid seed reveal');

					session.reveals.set(item.sender, content.contribution);
				}
				catch
				{
					break;
				}

				if(session.reveals.size === 2) await this.seed(session);

				break;

			case 'ready':
				if(!session.seats?.includes(item.sender) || content.player !== item.sender
					|| typeof content.hash !== 'string'
				) break;

				session.ready.set(item.sender, content.hash);

				if(session.ready.size === 2)
				{
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

					void this.provider.setActivity({state: 'active', root, game: this.game.id}).catch(
						error => this.fail(error)
					);
				}

				break;

			case 'action':
				if(session.actions.has(content.sequence))
				{
					if(stable(session.actions.get(content.sequence)) !== stable(content))
					return this.freeze(session, 'Conflicting duplicate action');

					break;
				}

				if(!session.seats?.includes(item.sender) || content.actor !== item.sender
					|| !Number.isSafeInteger(content.sequence) || content.sequence !== session.sequence + 1
					|| content.actor !== session.turn || content.pre_hash !== session.hash
						|| !validAction(content.action)
				) break;

				session.actions.set(content.sequence, structuredClone(content));
				session.pending = structuredClone(content);
				session.settles.set(content.sequence, new Map);

				if(this.selected === root) this.adapter?.action?.(structuredClone(content));

				for(const early of session.earlySettles.get(content.sequence)?.values() ?? [])
				{
					if(early.player === content.actor && (typeof early.checkpoint !== 'string' || !early.checkpoint))
					return this.freeze(session, 'Acting player omitted its checkpoint');

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
					if(content.sequence === session.sequence + 1)
					{
						const early = session.earlySettles.get(content.sequence) ?? new Map;

						if(!early.has(item.sender)) early.set(item.sender, structuredClone(content));

						session.earlySettles.set(content.sequence, early);
					}

					break;
				}

				if(item.sender === session.pending.actor && (typeof content.checkpoint !== 'string'
					|| !content.checkpoint || content.checkpoint.length > 1024 * 1024
				))
				break;

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

				session.status = 'complete';
				session.outcome = {
					winner: session.seats.find(player => player !== item.sender)
					, reason: content.reason
				};

				if(this.selected === root) this.adapter?.stop?.(structuredClone(session.outcome));

				break;
		}

		if(!replay) this.dispatchEvent(event('change', this.snapshot()));
	}

	/** Create, retain, and publish this player's hidden seed commitment. */
	async commit(session)
	{
		const me = this.profile().id;
		const key = `phonics.game.seed:${session.root}:${me}`;
		let contribution = this.storage?.getItem(key);

		if(!contribution)
		{
			const bytes = new Uint8Array(32);
			crypto.getRandomValues(bytes);
			contribution = bytes64(bytes);

			try
			{
				this.storage?.setItem(key, contribution);
			}
			catch
			{
				/* A live tab still retains the value in memory. */
			}
		}

		session.localContribution = contribution;
		const value = await this.commitDigest(session, me, contribution);
		await this.child(session, 'commit', {player: me, commit: value}, `commit:${me}`);
		this.armRevealDeadline(session);
	}

	/** Hash one length-framed, domain-separated seed commitment. */
	commitDigest(session, player, contribution)
	{
		return digest(
			'Phonics deterministic game seed commitment v1'
			, session.root
			, session.offer.session_id
			, player
			, contribution
		);
	}

	/** Reveal the locally retained contribution after both commitments exist. */
	async reveal(session)
	{
		const me = this.profile().id;

		if(session.reveals.has(me)) return;

		const contribution = session.localContribution
			?? this.storage?.getItem(`phonics.game.seed:${session.root}:${me}`);

		if(!contribution) return this.freeze(session, 'Seed contribution was lost before reveal');

		await this.child(session, 'reveal', {player: me, contribution}, `reveal:${me}`);
	}

	/** Schedule deterministic forfeiture when a seated player withholds its reveal. */
	armRevealDeadline(session)
	{
		clearTimeout(session.deadlineTimer);
		const delay = Math.max(0, session.commitDeadline - Date.now()) + 60_000;

		session.deadlineTimer = setTimeout(() => {
			if(session.reveals.size < 2 && session.seats?.includes(this.profile().id))
			void this.resign('seed reveal timeout');
		}, delay
		);
	}

	/** Derive the shared seed and breaker from verified contributions. */
	async seed(session)
	{
		if(session.seed) return this.startIfPossible(session);

		const pairs = [...session.reveals].sort(([a], [b]) => a.localeCompare(b));
		const promises = [...session.commits].sort(([a], [b]) => a.localeCompare(b));
		const product = new Uint8Array(32);

		for(const [, contribution] of pairs)
		{
			const bytes = from64(contribution);

			for(let index = 0; index < product.length; index++) product[index] ^= bytes[index];
		}

		session.seed = await digest(
			'Phonics deterministic game seed v1'
			, session.root
			, this.game.runtime
			, this.game.application
			, this.game.rules
			, ...pairs.flat()
		);

		session.seedHandshake = {
			session: session.root
			, hashPromises: Object.fromEntries(promises)
			, contributions: Object.fromEntries(pairs)
			, commutativeProduct: bytes64(product)
			, seed: session.seed
		};

		console.log('Phonics deterministic seed handshake', session.seedHandshake);

		const breakerDigest = await digest('Phonics deterministic game breaker v1', session.seed, ...session.seats);
		session.turn = session.seats[parseInt(breakerDigest.slice(0, 2), 16) % session.seats.length];
		session.status = 'ready';
		clearTimeout(session.deadlineTimer);

		try { this.storage?.removeItem(`phonics.game.seed:${session.root}:${this.profile().id}`); } catch { }

		await this.startIfPossible(session);
	}

	/** Start or restore the attached application when table state is sufficient. */
	async startIfPossible(session)
	{
		if(this.selected !== session.root || !session.seed || session.started || !this.adapter) return;

		session.started = true;
		const confirmed = [...session.settles.entries()].filter(([, values]) => values.size === 2).at(-1);
		let checkpoint = null;

		if(confirmed)
		checkpoint = [...confirmed[1].values()].find(value => typeof value.checkpoint === 'string')?.checkpoint ?? null;

		this.adapter.start?.({
			root: session.root
			, sessionId: session.offer.session_id
			, role: session.localRole
			, players: [...session.seats]
			, localPlayer: session.localRole === 'player' ? this.profile().id : null
			, seed: session.seed
			, breaker: session.turn
			, sequence: session.sequence
			, hash: session.hash
			, checkpoint
			, rules: this.game.rules
			, handshake: structuredClone(session.seedHandshake ?? null)
		});

		if(session.pending) this.adapter.action?.(structuredClone(session.pending));
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
		session.outcome = structuredClone(content.outcome);

		this.confirmAdapter(session);

		if(session.outcome)
		{
			session.status = 'complete';

			if(this.selected === session.root) this.adapter?.stop?.(structuredClone(session.outcome));
		}
	}

	/** Freeze a divergent session and notify the attached game and UI. */
	freeze(session, reason)
	{
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
				, mine: Boolean(me) && session.offer.host === me
				, forMe: Boolean(me) && session.offer.target === me
				, declinable: session.status === 'open' && !session.seats && Boolean(me) && session.offer.target === me
				, link: me && session.offer.host === me ? acceptUrl(this.location, session.offer.session_id) : null
				, expiresAt: session.offer.expires_at
				, resumable: Boolean(session.seats?.includes(me)) && !['complete','expired'].includes(session.status)
				, spectatable: Boolean(session.seats) && !['expired'].includes(session.status)
				, sequence: session.sequence
				, turn: session.turn ?? null
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

		for(const session of this.sessions.values()) clearTimeout(session.deadlineTimer);
		this.provider.removeEventListener('gameevent', this.onGame);
		this.provider.removeEventListener('presence', this.onPresence);
		this.provider.removeEventListener('chat', this.onChat);
	}
}

const node = (name, attributes = {}, text = '') => {
	const result = document.createElement(name);

	for(const [key, value] of Object.entries(attributes))
	{
		if(key === 'class') result.className = value;
		else result.setAttribute(key, value);
	}

	if(text) result.textContent = text;

	return result;
};

const gameAspectRatio = value => {
	if(Number.isFinite(value) && value > 0) return Number(value);

	const match = String(value ?? '').match(/^\s*(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)\s*$/);
	const ratio = match ? Number(match[1]) / Number(match[2]) : 16 / 9;

	return Number.isFinite(ratio) && ratio > 0 ? ratio : 16 / 9;
};

let themeCount = 0, theme = null;

const ROOM_STYLE = `
.phonics-room-game{--room-scrim:rgba(3,8,6,.66);--room-panel:rgba(10,18,15,.9);--room-raised:rgba(24,38,33,.94);
--room-line:rgba(232,212,160,.16);--room-text:#f2efe6;--room-muted:#a9b5ad;--room-accent:#d9a441;--room-accent-strong:#f0c46a;
--room-accent-text:#171106;--room-danger:#e0533d;--room-ok:#6bc78c;
position:absolute;inset:0;display:none;z-index:5;box-sizing:border-box;color:var(--room-text);
font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;background:var(--room-scrim);
-webkit-backdrop-filter:blur(10px) saturate(.85);backdrop-filter:blur(10px) saturate(.85);overflow:hidden}
.phonics-room-game *,.phonics-room-game *::before,.phonics-room-game *::after{box-sizing:border-box}
.phonics-room-game [hidden]{display:none!important}
.phonics-room-game.is-open{display:grid;grid-template:"panel" minmax(0,1fr)/minmax(0,1fr);padding:clamp(.5rem,2.2vw,1.5rem)}
.phonics-room-stage{display:none;grid-area:stage;min-width:0;min-height:0;background:#000;align-items:center;justify-content:center;overflow:hidden}
.phonics-room-panel{grid-area:panel;justify-self:center;width:min(70rem,100%);min-height:0;max-height:100%;display:grid;
grid-template-rows:auto minmax(0,1fr);grid-template-columns:minmax(0,1fr);background:var(--room-panel);border:1px solid var(--room-line);border-radius:16px;
box-shadow:0 24px 64px rgba(0,0,0,.45);overflow:hidden}
.phonics-room-header{display:flex;align-items:center;gap:.9rem;padding:.85rem 1.1rem;border-bottom:1px solid var(--room-line)}
.phonics-room-header h2{flex:1;min-width:0;margin:0;font-size:1.15rem;font-weight:700;letter-spacing:.01em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.phonics-room-account{display:flex;align-items:center;gap:.45rem;min-width:0;overflow:hidden;color:var(--room-muted);font-size:.85rem;white-space:nowrap}
.phonics-room-header button{flex:none}
.phonics-room-dot{flex:none;width:.55rem;height:.55rem;border-radius:50%;background:var(--room-muted)}
.phonics-room-game.is-connected .phonics-room-dot{background:var(--room-ok);box-shadow:0 0 0 3px rgba(107,199,140,.18)}
.phonics-room-game.is-failed .phonics-room-dot{background:var(--room-danger)}
.phonics-room-status{color:inherit}.phonics-room-me{color:var(--room-text);font-weight:600;overflow:hidden;text-overflow:ellipsis}
.phonics-room-body{display:grid;grid-template-columns:minmax(0,1fr) 19rem;min-height:0}
.phonics-room-main{display:flex;flex-direction:column;gap:1.1rem;min-width:0;min-height:0;padding:1rem 1.1rem;overflow:auto}
.phonics-room-main>*{flex:none}
.phonics-room-rail{min-width:0;min-height:0;padding:1rem;border-left:1px solid var(--room-line);overflow:auto}
.phonics-room-game h3{margin:0;font-size:.75rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--room-muted)}
.phonics-room-section{display:flex;flex-direction:column;gap:.55rem;min-height:0}
.phonics-room-section-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;min-height:2.5rem}
.phonics-room-game button{font:inherit;font-weight:600;color:var(--room-text);background:var(--room-raised);border:1px solid var(--room-line);
border-radius:10px;padding:.45rem .95rem;min-height:2.5rem;cursor:pointer;white-space:nowrap;transition:border-color .12s,background .12s,filter .12s}
.phonics-room-game button:hover:not(:disabled){border-color:rgba(217,164,65,.65)}
.phonics-room-game button:focus-visible,.phonics-room-game input:focus-visible{outline:2px solid var(--room-accent-strong);outline-offset:2px}
.phonics-room-game button:disabled{opacity:.5;cursor:default}
.phonics-room-game button.is-primary{background:linear-gradient(var(--room-accent-strong),var(--room-accent));color:var(--room-accent-text);border-color:transparent}
.phonics-room-game button.is-primary:hover:not(:disabled){filter:brightness(1.07)}
.phonics-room-game button.is-quiet{background:transparent}
.phonics-room-game button.is-danger{background:var(--room-danger);border-color:transparent;color:#fff}
.phonics-room-game input{font:inherit;color:var(--room-text);background:rgba(0,0,0,.32);border:1px solid var(--room-line);border-radius:10px;padding:.5rem .75rem;min-height:2.5rem;min-width:0}
.phonics-room-game input:disabled{opacity:.5}
.phonics-room-card{display:flex;flex-direction:column;gap:.65rem;padding:1rem 1.1rem;border:1px solid var(--room-line);border-radius:12px;background:var(--room-raised)}
.phonics-room-card strong{font-size:1.05rem}.phonics-room-card p{margin:0;color:var(--room-muted)}
.phonics-room-card .phonics-room-row{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.phonics-room-waiting{border-color:rgba(217,164,65,.55)}
.phonics-room-pulse{display:inline-block;width:.6rem;height:.6rem;margin-right:.5rem;border-radius:50%;background:var(--room-accent);animation:phonics-room-pulse 1.4s ease-in-out infinite}
@keyframes phonics-room-pulse{50%{opacity:.25;transform:scale(.7)}}
@media(prefers-reduced-motion:reduce){.phonics-room-pulse{animation:none}}
.phonics-room-link{flex:1 1 14rem;font-size:.85rem;color:var(--room-muted)}
.phonics-room-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.4rem}
.phonics-room-item{display:flex;align-items:center;gap:.7rem;min-height:3rem;padding:.45rem .6rem;border:1px solid transparent;border-radius:10px;background:rgba(255,255,255,.035)}
.phonics-room-item.is-for-you{border-color:rgba(217,164,65,.7);background:rgba(217,164,65,.1)}
.phonics-room-avatar{flex:none;display:grid;place-items:center;width:2rem;height:2rem;border-radius:50%;font-weight:700;font-size:.9rem;color:#0b1210}
.phonics-room-who{display:flex;flex-direction:column;flex:1;min-width:0}
.phonics-room-who b,.phonics-room-who span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.phonics-room-who span{color:var(--room-muted);font-size:.85rem}
.phonics-room-actions{display:flex;gap:.4rem;flex:none}
.phonics-room-game .phonics-room-item>button{min-height:2.1rem;padding:.3rem .7rem;font-size:.85rem}
.phonics-room-empty{margin:0;padding:.25rem 0;color:var(--room-muted);font-size:.9rem}
.phonics-room-main>.phonics-room-chat-section{flex:1 1 auto;min-height:14rem}
.phonics-room-chat{flex:1;min-height:8rem;display:flex;flex-direction:column;gap:.45rem;padding:.65rem .75rem;overflow:auto;border:1px solid var(--room-line);border-radius:12px;background:rgba(0,0,0,.28)}
.phonics-room-message{display:grid;grid-template-columns:auto 1fr;column-gap:.5rem;align-items:baseline;overflow-wrap:anywhere}
.phonics-room-message b{font-weight:700}.phonics-room-message time{color:var(--room-muted);font-size:.75rem;justify-self:end}
.phonics-room-message p{grid-column:1/-1;margin:0}
.phonics-room-message.is-own b{color:var(--room-accent-strong)}
.phonics-room-message.is-system{display:block;color:var(--room-muted);font-size:.88rem;font-style:italic}
.phonics-room-chat-form{display:flex;gap:.5rem}.phonics-room-chat-form input{flex:1}
.phonics-room-match{display:none}
.phonics-room-game:not(.is-connected) .phonics-room-needs-connection{display:none}
.phonics-room-game:not(.is-connected) .phonics-room-body{grid-template-columns:minmax(0,1fr)}
.phonics-room-game:not(.is-connected) .phonics-room-panel{width:min(34rem,100%);align-self:center}
.phonics-room-game.has-game{grid-template:"stage side" minmax(0,1fr)/minmax(0,1fr) clamp(16rem,24vw,21rem);padding:0;background:#000;-webkit-backdrop-filter:none;backdrop-filter:none}
.phonics-room-game.has-game .phonics-room-stage{display:flex}
.phonics-room-game.has-game .phonics-room-stage>canvas{width:auto!important;height:auto!important;max-width:100%!important;max-height:100%!important;aspect-ratio:16/9;object-fit:contain}
.phonics-room-game.has-game .phonics-room-panel{grid-area:side;width:auto;max-height:none;border-width:0 0 0 1px;border-radius:0;box-shadow:none}
.phonics-room-game.has-game .phonics-room-body{grid-template-columns:minmax(0,1fr);grid-template-rows:minmax(0,1fr) auto}
.phonics-room-game.has-game .phonics-room-rail{border-left:0;border-top:1px solid var(--room-line);max-height:11rem}
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
.phonics-room-rail{min-height:auto;overflow:visible;border-left:0;border-top:1px solid var(--room-line);padding:.85rem}
.phonics-room-main>.phonics-room-chat-section{flex:none;min-height:auto}
.phonics-room-chat{min-height:12rem;max-height:45vh}
.phonics-room-game button{min-height:2.75rem}
.phonics-room-game.has-game{grid-template:"stage" minmax(0,1.3fr) "side" minmax(0,1fr)/minmax(0,1fr)}
.phonics-room-game.has-game .phonics-room-panel{border-width:1px 0 0}
.phonics-room-game.has-game .phonics-room-body{overflow:hidden}.phonics-room-game.has-game .phonics-room-main{overflow:auto}
.phonics-room-game.has-game .phonics-room-rail{display:none}}
`;

const useTheme = () => {
	if(!theme)
	{
		theme = node('style');
		theme.dataset.phonicsRoomGame = '';
		theme.textContent = ROOM_STYLE;
		document.head.append(theme);
	}

	themeCount++;

	return () => {
		if(!--themeCount)
		{
			theme.remove();
			theme = null;
		} };
};

/** Readable fallback for a Matrix user identifier before presence supplies a display name. */
const localpart = id => String(id ?? '').replace(/^@/, '').split(':')[0] || String(id ?? '');

/** Stable, legible avatar tint for an identifier. */
const avatarColor = id => {
	let hash = 0;

	for(const character of String(id)) hash = (hash * 31 + character.codePointAt(0)) >>> 0;

	return `hsl(${hash % 360} 52% 66%)`;
};

const avatar = (id, name) => {
	const item = node(
		'span'
		, {class: 'phonics-room-avatar', 'aria-hidden': 'true'}
		, [...name][0]?.toUpperCase() ?? '?'
	);

	item.style.background = avatarColor(id);

	return item;
};

const activityLabel = activity => ({
	waiting: 'Waiting at a table'
	, active: 'Playing'
	, spectating: 'Watching a game'
})[activity?.state] ?? 'In the lobby';

const clock = new Intl.DateTimeFormat(undefined, {hour: 'numeric', minute: '2-digit'});

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
		this.chatFrame = null;
		this.loginPending = null;
		this.signal = signal;
		this.snapshot = protocol.snapshot();
		this.names = new Map;
		this.phase = 'idle';
		this.confirmTimer = null;
		this.confirmingLeave = false;
		this.countdown = null;
		this.releaseTheme = useTheme();
		this.aspectRatio = gameAspectRatio(protocol.game.aspectRatio);
		this.canvasSizing = null;

		this.root = node('section', {class: 'phonics-room-game', 'aria-label': title});
		this.stage = node('div', {class: 'phonics-room-stage'});
		this.stageResize = new ResizeObserver(() => this.sizeStage());
		this.stageResize.observe(this.stage);
		this.panel = node('div', {class: 'phonics-room-panel'});

		// Header: title, connection and account, and the way back to the game's own menu.
		const header = node('header', {class: 'phonics-room-header'});
		const account = node('div', {class: 'phonics-room-account'});
		this.status = node('output', {class: 'phonics-room-status', 'aria-live': 'polite'}, 'Offline');
		this.me = node('span', {class: 'phonics-room-me'});
		account.append(node('span', {class: 'phonics-room-dot', 'aria-hidden': 'true'}), this.status, this.me);
		this.closeButton = node('button', {type: 'button', class: 'is-quiet'}, 'Back');
		header.append(node('h2', {}, title), account, this.closeButton);

		const body = node('div', {class: 'phonics-room-body'});
		this.content = node('div', {class: 'phonics-room-main'});

		this.rail = node(
			'aside'
			, {class: 'phonics-room-rail phonics-room-section phonics-room-needs-connection', 'aria-label': 'People'}
		);

		// In a game, the sidebar names the players and owns the only way out, which confirms before forfeiting.
		this.matchCard = node('div', {class: 'phonics-room-card phonics-room-match'});
		this.matchTitle = node('strong');
		this.leaveButton = node('button', {type: 'button'}, 'Leave table');
		const matchRow = node('div', {class: 'phonics-room-row'});
		matchRow.append(this.leaveButton);
		this.matchCard.append(this.matchTitle, matchRow);

		this.connectingCard = node('div', {class: 'phonics-room-card phonics-room-lobby-only'});

		this.connectingCard.append(
			node('strong', {}, 'Connecting to the lobby…')
			, node('p', {}, 'Loading tables and chat.')
		);

		this.signinCard = node('div', {class: 'phonics-room-card phonics-room-lobby-only'});
		this.loginButton = node('button', {type: 'button', class: 'is-primary'}, 'Continue with Matrix');
		const signinRow = node('div', {class: 'phonics-room-row'});
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

		this.waitingCard = node('div', {class: 'phonics-room-card phonics-room-waiting phonics-room-lobby-only'});
		this.waitingTitle = node('strong');
		this.waitingText = node('p');

		this.linkInput = node(
			'input'
			, {type: 'text', readonly: '', class: 'phonics-room-link', 'aria-label': 'Invite link'}
		);

		this.copyButton = node('button', {type: 'button'}, 'Copy link');
		this.linkRow = node('div', {class: 'phonics-room-row'});
		this.linkRow.append(this.linkInput, this.copyButton);
		this.cancelButton = node('button', {type: 'button'}, 'Cancel table');
		const waitingRow = node('div', {class: 'phonics-room-row'});
		waitingRow.append(this.cancelButton);
		this.waitingCard.append(this.waitingTitle, this.waitingText, this.linkRow, waitingRow);

		const tables = node(
			'section'
			, {class: 'phonics-room-section phonics-room-lobby-only phonics-room-needs-connection'}
		);

		const tablesHead = node('div', {class: 'phonics-room-section-head'});
		this.openButton = node('button', {type: 'button', class: 'is-primary'}, 'Open a table');
		tablesHead.append(node('h3', {}, 'Tables'), this.openButton);
		this.tableList = node('ul', {class: 'phonics-room-list'});

		this.tablesEmpty = node(
			'p'
			, {class: 'phonics-room-empty'}
			, 'No open tables yet. Open one, or challenge someone who is online.'
		);

		tables.append(tablesHead, this.tableList, this.tablesEmpty);

		const chat = node(
			'section'
			, {class: 'phonics-room-section phonics-room-chat-section phonics-room-needs-connection'}
		);

		this.chatLog = node('div', {class: 'phonics-room-chat', role: 'log', 'aria-label': 'Lobby chat'});
		this.chatEmpty = node('p', {class: 'phonics-room-empty'}, 'No messages yet. Say hello.');
		this.chatLog.append(this.chatEmpty);
		this.chatForm = node('form', {class: 'phonics-room-chat-form'});

		this.chatInput = node(
			'input'
			, {type: 'text', maxlength: '1000', placeholder: 'Message the lobby', 'aria-label': 'Message the lobby'}
		);

		this.chatForm.append(this.chatInput, node('button', {type: 'submit'}, 'Send'));
		chat.append(node('div', {class: 'phonics-room-section-head'}, ''), this.chatLog, this.chatForm);
		chat.firstChild.append(node('h3', {}, 'Lobby chat'));

		this.peerHeading = node('h3', {}, 'People');
		this.peerList = node('ul', {class: 'phonics-room-list'});
		this.peersEmpty = node('p', {class: 'phonics-room-empty'}, 'Nobody else is here yet.');
		this.rail.append(node('div', {class: 'phonics-room-section-head'}), this.peerList, this.peersEmpty);
		this.rail.firstChild.append(this.peerHeading);

		this.content.append(this.matchCard, this.connectingCard, this.signinCard, this.waitingCard, tables, chat);
		body.append(this.content, this.rail);
		this.panel.append(header, body);
		this.root.append(this.stage, this.panel);
		const parent = this.host;
		parent.style.position ||= 'relative';
		parent.append(this.root);

		this.openButton.addEventListener('click', () => void this.run(() => protocol.create()));
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
		return this.names.get(id) ?? localpart(id);
	}

	/** Show connection state beside the account; errors also tint the indicator. */
	setStatus(text, tone = null)
	{
		this.status.textContent = text;
		this.root.classList.toggle('is-failed', tone === 'error');
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

		const fittedWidth = Math.min(width, height * this.aspectRatio);
		const fittedHeight = fittedWidth / this.aspectRatio;
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
		const link = this.linkInput.value;

		try
		{
			await navigator.clipboard.writeText(link);
			this.copyButton.textContent = 'Copied';
		}
		catch
		{
			this.linkInput.select();
			this.copyButton.textContent = 'Press Ctrl+C';
		}

		setTimeout(() => { this.copyButton.textContent = 'Copy link'; }, 2000);
	}

	/** Describe one table from the local player's point of view. */
	describe(table, me)
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

		const known = this.names.size;

		for(const peer of snapshot.peers) if(peer.displayName) this.names.set(peer.id, peer.displayName);

		// History often arrives before presence; relabel earlier messages once display names are known.
		if(this.names.size !== known)
		for(const label of this.chatLog.querySelectorAll('[data-sender]'))
		if(label.dataset.sender !== snapshot.profile?.id) label.textContent = this.displayName(label.dataset.sender);


		if(snapshot.connected && this.phase !== 'ready') this.phase = 'ready';

		this.root.classList.toggle('is-connected', Boolean(snapshot.connected));
		this.me.textContent = snapshot.connected && me ? `as ${this.displayName(me)}` : '';
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
		this.openButton.disabled = Boolean(snapshot.selected) || !snapshot.connected;

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
		if(!playing || !selected) return;

		const spectating = !selected.players.includes(me);
		const [first, second] = selected.players.map(player => player === me ? 'You' : this.displayName(player));
		this.matchTitle.textContent = spectating ? `Watching ${first} vs ${second}` : `${first} vs ${second}`;

		if(!this.confirmingLeave) this.leaveButton.textContent = spectating ? 'Stop watching' : 'Leave table';
	}

	/** Show the waiting card with its invite link and countdown while a table is not yet seated. */
	renderWaiting(selected, playing)
	{
		const waiting = Boolean(selected) && !playing;
		this.waitingCard.hidden = !waiting;
		clearInterval(this.countdown);
		this.countdown = null;

		if(!waiting) return;

		const target = selected.target ? this.displayName(selected.target) : null;

		if(selected.mine)
		{
			this.waitingTitle.replaceChildren(
				node('span', {class: 'phonics-room-pulse', 'aria-hidden': 'true'})
				, target ? `Waiting for ${target} to accept` : 'Waiting for an opponent'
			);

			this.linkInput.value = selected.link ?? '';
			this.linkRow.hidden = !selected.link;
			this.cancelButton.textContent = 'Cancel table';

			const tick = () => {
				const seconds = Math.max(0, Math.round((selected.expiresAt - Date.now()) / 1000));
				const time = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
				this.waitingText.textContent = (target
					? 'They can accept from the lobby, or you can send them this link.'
					: 'Anyone in the lobby can join. To invite someone directly, send them this link.'
				) + ` Open for ${time}.`;
			};

			tick();
			this.countdown = setInterval(tick, 1000);
		}
		else
		{
			const spectating = !selected.players.includes(this.snapshot.profile?.id);

			this.waitingTitle.replaceChildren(
				node('span', {class: 'phonics-room-pulse', 'aria-hidden': 'true'})
				, spectating ? 'Loading the game…' : `Joining ${this.displayName(selected.host)}’s table…`
			);

			this.waitingText.textContent = spectating
				? 'The board appears as soon as the latest position loads.'
				: 'The game starts as soon as both players have agreed on a fair rack.';

			this.linkRow.hidden = true;
			this.cancelButton.textContent = 'Leave';
		}
	}

	/** Build one table row with the actions this account may take on it. */
	tableItem(table, me)
	{
		const item = node('li', {class: 'phonics-room-item'});
		const {name, detail} = this.describe(table, me);
		const who = node('div', {class: 'phonics-room-who'});
		who.append(node('b', {}, name), node('span', {}, detail));
		item.classList.toggle('is-for-you', Boolean(table.forMe && table.joinable));
		item.append(avatar(table.host, this.displayName(table.host)), who);
		const actions = node('div', {class: 'phonics-room-actions'});

		const button = (label, operation, primary = false) => {
			const control = node('button', {type: 'button', class: primary ? 'is-primary' : ''}, label);
			control.addEventListener('click', () => void this.run(operation));
			actions.append(control);
		};

		if(table.joinable) button(table.forMe ? 'Accept' : 'Join', () => this.protocol.join(table.root), true);

		if(table.declinable) button('Decline', () => this.protocol.decline(table.root));

		if(table.resumable) button('Resume', () => this.protocol.resume(table.root), true);
		else if(table.spectatable) button('Watch', () => this.protocol.spectate(table.root));

		if(actions.childElementCount) item.append(actions);

		return item;
	}

	/** Build one person row with their activity and, when idle, a challenge action. */
	peerItem(peer, me, snapshot)
	{
		const item = node('li', {class: 'phonics-room-item'});
		const name = this.displayName(peer.id);
		const who = node('div', {class: 'phonics-room-who'});

		who.append(
			node('b', {}, peer.id === me ? `${name} (you)` : name)
			, node('span', {}, activityLabel(peer.activity))
		);

		item.append(avatar(peer.id, name), who);

		if(snapshot.connected && peer.id !== me && !snapshot.selected && !peer.activity)
		{
			const challenge = node('button', {type: 'button'}, 'Challenge');
			challenge.addEventListener('click', () => void this.run(() => this.protocol.create({target: peer.id})));
			item.append(challenge);
		}

		return item;
	}

	/** Append one interoperable Matrix text message to the room log. */
	addChat({from, text, sentAt})
	{
		const me = this.snapshot.profile?.id;
		const message = node('div', {class: 'phonics-room-message'});
		message.classList.toggle('is-own', from === me);
		const time = Number.isFinite(sentAt)
			? node('time', {datetime: new Date(sentAt).toISOString()}, clock.format(sentAt))
			: node('time');

		message.append(
			node('b', {'data-sender': from}, from === me ? 'You' : this.displayName(from))
			, time
			, node('p', {}, text)
		);

		this.appendLog(message);
	}

	/** Append a lobby event, such as a declined challenge, in the room log's quieter style. */
	addSystem(text)
	{
		this.appendLog(node('div', {class: 'phonics-room-message is-system'}, text));
	}

	/** Append a log entry, trim old history, and keep the newest line in view. */
	appendLog(entry)
	{
		this.chatEmpty.remove();

		if(this.chatLog.childElementCount >= 200) this.chatLog.firstElementChild.remove();

		this.chatLog.append(entry);

		this.chatFrame ??= requestAnimationFrame(() => {
			this.chatFrame = null;
			this.chatLog.scrollTop = this.chatLog.scrollHeight;
		});
	}

	/** Detach the shell, restore the canvas, and release shared theme state once. */
	dispose()
	{
		if(this.disposed) return;

		this.disposed = true;

		if(this.chatFrame !== null) cancelAnimationFrame(this.chatFrame);

		clearInterval(this.countdown);
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
		this.releaseTheme();
	}
}
