import {NetworkError} from './Errors.mjs';
import {NetworkBridge} from './NetworkBridge.mjs';
import {
	decodeJsonPayload
	, decodePacket
	, encodeJsonPacket
	, encodePacket
	, MessageFamily
	, SequenceWindow
} from './Protocol.mjs';
import {MatchDescriptor} from './Values.mjs';

const event = (type, detail) => new CustomEvent(type, {detail});

const handshakeFamilies = new Set([
	MessageFamily.hello
	, MessageFamily.compatibility
	, MessageFamily.resourceReady
	, MessageFamily.gameReady
	, MessageFamily.start
	, MessageFamily.ping
	, MessageFamily.pong
	, MessageFamily.diagnostics
	, MessageFamily.disconnect
]);

/** Match/resource handshake plus bounded gameplay bridge for one LobbyController. */
export class NetworkSession extends EventTarget
{

	constructor(
		{
			controller
			, negotiator
			, world
			, packages = []
			, bridge = new NetworkBridge
			, now = () => performance.now()
			, startDelay = 500
		} = {}
	){
		super();

		if(!controller || !negotiator || !world)
		throw new TypeError('NetworkSession requires lobby, resources, and world');

		this.controller = controller;
		this.negotiator = negotiator;
		this.world = world;
		this.packages = packages;
		this.bridge = bridge;
		this.now = now;
		this.startDelay = startDelay;
		this.closed = false;
		this.resetMatch();

		controller.prepareInvitation = payload => this.prepareInvitation(payload);
		controller.addEventListener('matchend', ({detail}) => this.endMatch(detail));

		controller.addEventListener('transport', ({detail}) => {
			this.open(detail).catch(error => {
				if(this.transport === detail.transport) this.fail(error);
			});
		});

		controller.addEventListener('message', ({detail}) => {
			if(this.closed) return;

			if(!this.transport)
			{
				if(controller.match) this.pendingMessages.push(detail);

				return;
			}

			const transport = this.transport;

			this.receive(detail).catch(error => {
				if(this.transport === transport) this.fail(error);
			});
		});
	}

	resetMatch()
	{
		clearTimeout(this.startTimer);
		this.startTimer = null;
		this.matchController?.abort();
		this.matchController = null;
		this.transport = null;
		this.match = null;
		this.descriptor = null;
		this.descriptorDigest = null;
		this.boundWorld = null;
		this.sequence = 0;
		this.received = new Map([['control', new SequenceWindow], ['realtime', new SequenceWindow]]);
		this.localResourcesReady = false;
		this.remoteResourcesReady = false;
		this.localGameReady = false;
		this.localGameReadySent = false;
		this.remoteGameReady = false;
		this.clockOffset = 0;
		this.rtt = null;
		this.peerRtt = null;
		this.pingSent = null;
		this.peerClockReady = false;
		this.started = false;
		this.pendingMessages = [];
	}

	async prepareInvitation({resources = {}, settings = {}, start = null} = {})
	{
		const offers = await this.prepareResources(resources);
		const selectedStart = this.selectedStart(start ?? settings.map ?? this.world.start);
		this.requireMap(selectedStart, this.negotiator.maps());

		const descriptor = new MatchDescriptor({
			game: this.world.game
			, start: selectedStart
			, packages: this.packages
			, content: offers
			, settings
			, players: this.world.network?.players?.max ?? 2
		});

		const digest = await descriptor.digest();
		const summaries = offers.map(({url, ...offer}) => offer);

		return {
			publicPayload: {
				game: descriptor.game
				, start: descriptor.start
				, settings: descriptor.settings
				, content: summaries
				, descriptorDigest: digest
			}
			, privatePayload: {descriptor, descriptorDigest: digest, offers}
		};
	}

	/** Resolve a local content selection through the same ordered path used by online matches. */
	async prepareLocalWorld({resources = {}, settings = {}, start = null} = {})
	{
		await this.prepareResources(resources);
		const selectedStart = this.selectedStart(start ?? settings.map ?? this.world.start);
		this.requireMap(selectedStart, this.negotiator.maps());
		this.boundWorld = this.negotiator.bindWorld({start: selectedStart});
		this.boundWorld.settings = structuredClone(settings);

		this.dispatchEvent(event('resourcesready', {
			world: this.boundWorld
			, descriptor: null
			, role: 'local'
		}));

		return this.boundWorld;
	}

	async prepareResources(resources)
	{
		this.negotiator.reset();
		const offers = [];
		let order = 0;
		const slots = this.orderedSlots();

		for(const [slotId, slot] of slots)
		if(slot.authority === 'world' && resources[slotId] !== undefined)
		throw new NetworkError('resource_authority', `Resource slot ${slotId} is fixed by its declaring world`);

		for(const [slotId, slot] of slots)
		{
			let selected = resources[slotId];
			selected = Array.isArray(selected) ? selected : selected === undefined || selected === '' ? [] : [selected];

			if(!selected.length)
			{
				const fallback = slot.defaultUrl
					|| slot.requirement?.candidates?.find(candidate => candidate.url)?.url;

				if(fallback) selected = [fallback];
			}

			if(!slot.multiple && selected.length > 1)
			throw new NetworkError('resource_conflict', `Resource slot ${slotId} accepts only one resource`);

			if(!selected.length)
			{
				if(slot.required)
				throw new NetworkError('resource_missing', `Resource slot ${slotId} requires a local file or URL`);

				continue;
			}

			for(const [stackIndex, source] of selected.entries())
			offers.push(await this.negotiator.offer(slotId, source, {
				role: this.negotiator.roles(slotId)[0] ?? 'data'
				, order: order++
				, stackIndex
				, label: this.negotiator.filename(source) || slot.label || slotId
			}));
		}

		return offers;
	}

	selectedStart(value)
	{
		if(typeof value === 'string') return {...this.world.start, scope: value.toUpperCase()};

		if(!value || typeof value !== 'object')
		throw new NetworkError('match_world', 'Selected start must name a map');

		const result = structuredClone(value);

		if(result.source !== this.world.start.source || typeof result.scope !== 'string' || !result.scope)
		throw new NetworkError('match_world', 'Selected map must belong to the declared world source');

		result.scope = result.scope.toUpperCase();

		return result;
	}

	requireMap(start, maps)
	{
		if(maps.length && !maps.includes(start.scope))
		throw new NetworkError('match_world', `Selected map ${start.scope} is not present in the WAD stack`);
	}

	/** Resource identity follows adapter stack precedence, independent of JSON object-key order. */
	orderedSlots()
	{
		const positions = new Map;
		let position = 0;

		for(const source of Object.values(this.world.sources ?? {}))
		for(const resource of source.resources ?? [])
		{
			if(resource.slot && !positions.has(resource.slot)) positions.set(resource.slot, position);

			position++;
		}

		return Object.entries(this.world.resourceSlots ?? {})
			.map((entry, declaration) => ({entry, declaration}))
			.sort((left, right) => {
				const leftPosition = positions.get(left.entry[0]) ?? Number.MAX_SAFE_INTEGER;
				const rightPosition = positions.get(right.entry[0]) ?? Number.MAX_SAFE_INTEGER;

				return leftPosition - rightPosition || left.declaration - right.declaration;
			})
			.map(value => value.entry);
	}

	async open({transport, match})
	{
		if(this.closed || transport.closed) return;

		this.transport = transport;
		this.match = match;
		this.matchController = new AbortController;
		this.bridge.attach(transport);
		this.controller.markLoading({stage: 'compatibility'});

		for(const message of this.pendingMessages.splice(0))
		{
			await this.receive(message);

			if(this.transport !== transport) return;
		}

		if(match.initiator)
		{
			const values = match.privatePayload;

			if(!values?.descriptor) throw new NetworkError('match_descriptor', 'Host has no prepared match descriptor');

			this.descriptor = values.descriptor;
			this.descriptorDigest = values.descriptorDigest;
			this.boundWorld = this.negotiator.bindWorld({start: this.descriptor.start});
			this.localResourcesReady = true;

			this.dispatchEvent(event('resourcesready', {
				world: this.boundWorld
				, descriptor: this.descriptor
				, role: 'host'
			}));

			this.send(MessageFamily.hello, {
				descriptor: this.descriptor
				, descriptorDigest: this.descriptorDigest
				, offers: values.offers
				, resolutions: this.negotiator.resolutions()
			});
		}
		else this.ping();
	}

	/** Signal that the game has activated the negotiated world and reached its tick-zero barrier. */
	gameReady()
	{
		if(!this.match || this.closed) return;

		if(!this.localResourcesReady) throw new NetworkError('match_state', 'Resources are not ready');

		if(this.localGameReady) return;

		this.localGameReady = true;
		this.updateReady();
	}

	async receive({channel, data})
	{
		if(!this.match || this.closed) return;

		const received = this.received.get(channel);

		if(!received) throw new NetworkError('packet_channel', `Unknown packet channel ${channel}`);

		const packet = decodePacket(data);

		if(!received.accept(packet.sequence)) return;

		if(!handshakeFamilies.has(packet.family))
		{
			this.bridge.push(channel, data);

			return;
		}

		const value = decodeJsonPayload(packet);

		switch(packet.family)
		{
			case MessageFamily.hello: await this.hello(value); break;
			case MessageFamily.compatibility: this.compatibility(value); break;
			case MessageFamily.resourceReady: this.resourceReady(value); break;
			case MessageFamily.gameReady: this.remoteGame(value); break;
			case MessageFamily.ping: this.pong(value); break;
			case MessageFamily.pong: this.clock(value); break;
			case MessageFamily.diagnostics: this.remoteDiagnostics(value); break;
			case MessageFamily.start: this.start(value); break;
			case MessageFamily.disconnect: this.controller.closeMatch(value.reason ?? 'peer disconnected'); break;
		}
	}

	async hello(value)
	{
		if(this.match.initiator) throw new NetworkError('match_role', 'Host received an unexpected hello');

		const {signal} = this.matchController;
		const descriptor = new MatchDescriptor(value.descriptor);
		const digest = await descriptor.digest();
		signal.throwIfAborted();

		if(digest !== value.descriptorDigest)
		throw new NetworkError('match_digest', 'Host match descriptor digest is invalid');

		if(descriptor.game !== this.world.game
			|| descriptor.start?.source !== this.world.start.source
			|| typeof descriptor.start?.scope !== 'string'
		)
		throw new NetworkError('match_world', 'Peer selected a different game or starting scope');

		const invitationDigest = this.match.invitation.payload?.descriptorDigest;

		if(invitationDigest && invitationDigest !== digest)
		throw new NetworkError('match_changed', 'Match descriptor changed after the invitation');

		const resources = this.match.localPayload?.resources ?? {};
		this.negotiator.reset();
		const resourceIndexes = new Map;

		for(const offer of value.offers ?? [])
		{
			const selected = resources[offer.slot];
			const choices = Array.isArray(selected) ? selected : selected ? [selected] : [];
			const index = resourceIndexes.get(offer.slot) ?? 0;
			resourceIndexes.set(offer.slot, index + 1);
			await this.negotiator.accept(offer, {source: choices[index] ?? null, stackIndex: index, signal});
			signal.throwIfAborted();
		}

		this.requireMap(descriptor.start, this.negotiator.maps());

		this.negotiator.verifyPeer(value.resolutions ?? []);

		this.descriptor = descriptor;
		this.descriptorDigest = digest;
		this.boundWorld = this.negotiator.bindWorld({start: descriptor.start});
		this.localResourcesReady = true;

		this.dispatchEvent(event('resourcesready', {
			world: this.boundWorld
			, descriptor: this.descriptor
			, role: 'guest'
		}));

		this.send(MessageFamily.compatibility, {
			ok: true
			, descriptorDigest: digest
			, resolutions: this.negotiator.resolutions()
		});

		this.send(MessageFamily.resourceReady, {descriptorDigest: digest});
	}

	compatibility(value)
	{
		if(!this.match.initiator || value.descriptorDigest !== this.descriptorDigest || value.ok !== true)
		throw new NetworkError('match_compatibility', 'Peer rejected the match descriptor');

		this.negotiator.verifyPeer(value.resolutions ?? []);
	}

	resourceReady(value)
	{
		this.requireDigest(value);
		this.remoteResourcesReady = true;
		this.dispatchEvent(event('peerresourcesready', {descriptor: this.descriptor}));
		this.updateReady();
	}

	remoteGame(value)
	{
		this.requireDigest(value);
		this.remoteGameReady = true;
		this.updateReady();
	}

	updateReady()
	{
		// The guest processes hello asynchronously while acquiring resources. Its acknowledgment
		// guarantees that the descriptor is installed before the host sends gameReady.
		if(this.localGameReady && !this.localGameReadySent && (!this.match.initiator || this.remoteResourcesReady))
		{
			this.localGameReadySent = true;
			this.send(MessageFamily.gameReady, {descriptorDigest: this.descriptorDigest});
		}

		if(!this.localGameReady || !this.remoteGameReady) return;

		if(this.controller.state === 'loading') this.controller.markReady({descriptor: this.descriptor});

		this.maybeStart();
	}

	maybeStart()
	{
		if(!this.match?.initiator || this.started || !this.localGameReady || !this.remoteGameReady
			|| !this.remoteResourcesReady || !this.peerClockReady
		) return;

		this.started = true;
		const hostTime = this.now() + this.startDelay;
		this.send(MessageFamily.start, {descriptorDigest: this.descriptorDigest, hostTime, tick: 0});
		this.scheduleStart(hostTime);
	}

	ping()
	{
		this.pingSent = this.now();
		this.send(MessageFamily.ping, {nonce: crypto.randomUUID(), sentAt: this.pingSent});
	}

	pong(value)
	{
		this.send(MessageFamily.pong, {
			nonce: value.nonce
			, originSentAt: value.sentAt
			, receivedAt: this.now()
			, sentAt: this.now()
		});
	}

	clock(value)
	{
		const received = this.now();
		const rtt = Math.max(0, received - value.originSentAt - (value.sentAt - value.receivedAt));

		this.rtt = rtt;
		this.clockOffset = ((value.receivedAt - value.originSentAt) + (value.sentAt - received)) / 2;
		this.peerClockReady = true;
		this.send(MessageFamily.diagnostics, {clockReady: true, rtt, clockOffset: this.clockOffset});
	}

	remoteDiagnostics(value)
	{
		if(value.clockReady)
		{
			this.peerClockReady = true;
			this.peerRtt = value.rtt;
		}

		this.maybeStart();
	}

	start(value)
	{
		this.requireDigest(value);
		this.scheduleStart(value.hostTime - this.clockOffset);
	}

	scheduleStart(localTime)
	{
		const match = this.match;

		if(!match || this.closed) return;

		if(this.controller.state === 'ready') this.controller.markStarting({tick: 0});

		const delay = Math.max(0, localTime - this.now());
		clearTimeout(this.startTimer);

		this.startTimer = setTimeout(() => {
			if(this.closed || this.match !== match) return;

			this.controller.markPlaying({tick: 0, descriptor: this.descriptor});
			this.dispatchEvent(event('start', {tick: 0, descriptor: this.descriptor}));
		}, delay
		);
	}

	send(family, value)
	{
		if(!this.transport) throw new NetworkError('match_transport', 'Match transport is not open');

		return this.transport.send('control', encodeJsonPacket({family, sequence: ++this.sequence}, value));
	}

	/** Send one gameplay packet while sharing the handshake sequence space. */
	sendGameplay(family, value, {channel = 'realtime', tick = 0, schema = 1} = {})
	{
		if(handshakeFamilies.has(family))
		throw new NetworkError('packet_family', 'Gameplay code cannot originate handshake packets');

		if(!['control','realtime'].includes(channel))
		throw new NetworkError('packet_channel', `Unknown gameplay channel ${channel}`);

		const payload = typeof value === 'string' || value instanceof Uint8Array
			? value
			: JSON.stringify(value);

		const packet = encodePacket({
			family
			, sequence: ++this.sequence
			, tick
			, schema
			, payload
		});

		// PHP may finish its current step before consuming the disconnect action.
		// Keep that normal shutdown (including a data-channel send race) out of Vrzno.
		if(this.closed || !this.transport) return false;

		if(this.transport.closed)
		{
			this.controller.closeMatch('peer disconnected');

			return false;
		}

		try
		{
			return this.transport.send(channel, packet);
		}
		catch(error)
		{
			if(!['transport_closed', 'channel_closed'].includes(error?.code)
				&& error?.name !== 'InvalidStateError'
			) throw error;

			this.controller.closeMatch(error.message);

			return false;
		}
	}

	/** Return the stable role and slot assignment consumed by the PHP simulation. */
	matchState()
	{
		if(!this.match || !this.descriptor) return null;

		const host = Boolean(this.match.initiator);

		return Object.freeze({
			role: host ? 'host' : 'guest'
			, localSlot: host ? 1 : 2
			, remoteSlot: host ? 2 : 1
			, localPeer: this.controller.profile?.id ?? ''
			, remotePeer: this.match.peerId ?? ''
			, descriptorDigest: this.descriptorDigest
			, descriptor: this.descriptor
		});
	}

	requireDigest(value)
	{
		if(!this.descriptorDigest || value.descriptorDigest !== this.descriptorDigest)
		throw new NetworkError('match_digest', 'Peer message names another match descriptor');
	}

	fail(error)
	{
		if(this.closed) return;

		this.dispatchEvent(event('error', error));
		this.controller.closeMatch(error?.message ?? 'network session failed');
	}

	/** Notify the peer and end this match without closing the lobby or PHP runtime. */
	leaveMatch(reason = 'left match')
	{
		try { if(this.transport) this.send(MessageFamily.disconnect, {reason}); } catch {}

		this.controller.closeMatch(reason, {local: true});
	}

	endMatch({match = this.match, reason = 'peer disconnected', local = false} = {})
	{
		this.resetMatch();
		this.bridge.detach();

		if(match) this.dispatchEvent(event('disconnect', {match, reason, local}));
	}

	close()
	{
		if(this.closed) return;

		this.leaveMatch('session closed');
		this.closed = true;

		this.bridge.close();
		this.negotiator.close();
		this.controller.close();
	}
}
