import {NetworkError} from './Errors.mjs';

const idPattern = /^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/;
const integrityPattern = /^sha256-[A-Za-z0-9+/]{43}=$/;

const requiredString = (value, label) => {
	if(typeof value !== 'string' || !value) throw new TypeError(`${label} must be a non-empty string`);

	return value;
};

const identity = (value, label) => {
	requiredString(value, label);

	if(!idPattern.test(value)) throw new TypeError(`${label} is invalid`);

	return value;
};

const stringList = (value, label, {empty = true} = {}) => {
	if(!Array.isArray(value) || (!empty && value.length === 0)
		|| value.some(item => typeof item !== 'string' || !item)
	)
	throw new TypeError(`${label} must contain non-empty strings`);

	return Object.freeze([...value]);
};

const plainObject = (value, label) => {
	if(!value || Object.getPrototypeOf(value) !== Object.prototype)
	throw new TypeError(`${label} must be a plain object`);

	return Object.freeze(structuredClone(value));
};

const resourceUrl = (value, label) => {
	const parsed = new URL(requiredString(value, label));

	if(!['https:', 'http:', 'file:'].includes(parsed.protocol) || parsed.username || parsed.password)
	throw new TypeError(`${label} must be an HTTP(S) or file URL without embedded credentials`);

	return parsed.href;
};

/** Serialize plain protocol data with stable object-key order. */
export function canonicalJson(value)
{

	const normalize = item => {
		if(Array.isArray(item)) return item.map(normalize);

		if(item && typeof item.toJSON === 'function') return normalize(item.toJSON());

		if(item && Object.getPrototypeOf(item) === Object.prototype)
		return Object.fromEntries(Object.keys(item).sort().map(key => [key, normalize(item[key])]));

		if(item === undefined || typeof item === 'function' || typeof item === 'symbol')
		throw new TypeError('Canonical protocol data must be JSON-compatible');

		if(typeof item === 'number' && !Number.isFinite(item))
		throw new TypeError('Canonical protocol numbers must be finite');

		return item;
	};

	return JSON.stringify(normalize(value));
}

/** Calculate an SRI-shaped SHA-256 identity over bytes or UTF-8 text. */
export async function sha256(value)
{
	const bytes = typeof value === 'string' ? new TextEncoder().encode(value) : new Uint8Array(value);
	const digest = await globalThis.crypto.subtle.digest('SHA-256', bytes);
	let binary = '';

	for(const byte of new Uint8Array(digest)) binary += String.fromCharCode(byte);

	return `sha256-${btoa(binary)}`;
}

/** Opaque identity whose kind prevents primitive strings from crossing service boundaries unnoticed. */
class Identifier
{

	constructor(value, label)
	{
		this.value = identity(value, label);
		Object.freeze(this);
	}

	toString() { return this.value; }

	toJSON() { return this.value; }
}

export class PeerId extends Identifier
{

	constructor(value) { super(value, 'PeerId'); }
}

export class SessionId extends Identifier
{

	constructor(value) { super(value, 'SessionId'); }
}

export class ResourceSlotId extends Identifier
{

	constructor(value) { super(value, 'ResourceSlotId'); }
}

/** One discoverable archive which may satisfy a local resource requirement. */
export class ResourceCandidate
{

	constructor({id, label = '', names = [], criteria = {}, url = null, integrity = null, bytes = null})
	{
		if(integrity !== null && !integrityPattern.test(integrity))
		throw new TypeError('ResourceCandidate.integrity must be SHA-256 SRI');

		if(bytes !== null && (!Number.isSafeInteger(bytes) || bytes < 0))
		throw new TypeError('ResourceCandidate.bytes must be non-negative');

		Object.assign(this, {
			id: identity(id, 'ResourceCandidate.id')
			, label: String(label || id)
			, names: stringList(names, 'ResourceCandidate.names')
			, criteria: plainObject(criteria, 'ResourceCandidate.criteria')
			, ...(url === null ? {} : {url: resourceUrl(url, 'ResourceCandidate.url')})
			, ...(integrity === null ? {} : {integrity})
			, ...(bytes === null ? {} : {bytes})
		});

		Object.freeze(this);
	}

	toJSON() { return {...this}; }
}

/** Content-qualified description of data each peer must acquire independently. */
export class ResourceRequirement
{

	constructor(
		{
			id
			, label = ''
			, names = []
			, inspector
			, equivalence = 'integrity'
			, criteria = {}
			, profileRequires = []
			, candidates = []
		}
	){
		if(!['integrity','profile'].includes(equivalence))
		throw new TypeError('ResourceRequirement.equivalence must be integrity or profile');

		if(equivalence !== 'profile' && profileRequires.length)
		throw new TypeError('Only profile equivalence can require guard slots');

		const values = candidates.map(
			candidate => candidate instanceof ResourceCandidate ? candidate : new ResourceCandidate(candidate)
		);

		const ids = new Set;

		for(const candidate of values)
		{
			if(ids.has(candidate.id)) throw new TypeError(`Conflicting resource candidate ${candidate.id}`);

			ids.add(candidate.id);
		}

		Object.assign(this, {
			id: identity(id, 'ResourceRequirement.id')
			, label: String(label || id)
			, names: stringList(names, 'ResourceRequirement.names')
			, inspector: requiredString(inspector, 'ResourceRequirement.inspector')
			, equivalence
			, criteria: plainObject(criteria, 'ResourceRequirement.criteria')
			, profileRequires: Object.freeze(
				stringList(profileRequires, 'ResourceRequirement.profileRequires')
					.map(value => new ResourceSlotId(value).value)
			)
			, candidates: Object.freeze(values)
		});

		Object.freeze(this);
	}

	toJSON() { return {...this}; }
}

/** Immutable remote/local acquisition offer for one world-owned resource slot. */
export class ResourceOffer
{

	constructor(
		{
			slot
			, url = null
			, integrity
			, bytes
			, role = 'data'
			, adapter = ''
			, order = 0
			, label = ''
			, distribution = url === null ? 'local' : 'retrievable'
			, requirement = null
		}
	){
		if(!integrityPattern.test(integrity)) throw new TypeError('ResourceOffer.integrity must be SHA-256 SRI');

		if(!Number.isSafeInteger(bytes) || bytes < 0) throw new TypeError('ResourceOffer.bytes must be non-negative');

		if(!Number.isSafeInteger(order) || order < 0) throw new TypeError('ResourceOffer.order must be non-negative');

		if(!['retrievable','local'].includes(distribution))
		throw new TypeError('ResourceOffer.distribution must be retrievable or local');

		if(distribution === 'retrievable' && url === null)
		throw new TypeError('Retrievable resource offers require a URL');

		if(distribution === 'local' && url !== null)
		throw new TypeError('Local resource offers cannot transmit a URL');

		if(distribution === 'local' && !requirement)
		throw new TypeError('Local resource offers require a content requirement');

		Object.assign(this, {
			slot: new ResourceSlotId(String(slot)).value
			, ...(url === null ? {} : {url: resourceUrl(url, 'ResourceOffer.url')})
			, integrity
			, bytes
			, role: identity(role, 'ResourceOffer.role')
			, adapter: adapter ? requiredString(adapter, 'ResourceOffer.adapter') : ''
			, order
			, label: String(label || '')
			, distribution
			, ...(requirement
				? {
					requirement: requirement instanceof ResourceRequirement
						? requirement
						: new ResourceRequirement(requirement)
				}
				: {}
			)
		});

		Object.freeze(this);
	}

	toJSON() { return {...this}; }
}

/** A verified offer whose bytes may have come from an equivalent local mirror. */
export class ResolvedResource
{

	constructor(offer, {sourceUrl = offer.url, mountPath = null, resolution = null} = {})
	{
		if(!(offer instanceof ResourceOffer)) offer = new ResourceOffer(offer);

		if(!sourceUrl) throw new TypeError('ResolvedResource.sourceUrl is required');

		Object.assign(this, {
			offer
			, sourceUrl: new URL(sourceUrl).href
			, mountPath: mountPath === null ? null : requiredString(mountPath, 'ResolvedResource.mountPath')
			, resolution: resolution instanceof ResourceResolution
				? resolution
				: new ResourceResolution(resolution ?? {
					slot: offer.slot
					, integrity: offer.integrity
					, bytes: offer.bytes
					, order: offer.order
				})
		});

		Object.freeze(this);
	}

	toJSON() { return {...this.offer, sourceUrl: this.sourceUrl, resolution: this.resolution}; }
}

/** Safe compatibility evidence for a locally resolved resource; never contains its URL or bytes. */
export class ResourceResolution
{

	constructor({slot, integrity, bytes, order = 0, candidate = '', profile = {}})
	{
		if(!integrityPattern.test(integrity)) throw new TypeError('ResourceResolution.integrity must be SHA-256 SRI');

		if(!Number.isSafeInteger(bytes) || bytes < 0)
		throw new TypeError('ResourceResolution.bytes must be non-negative');

		if(!Number.isSafeInteger(order) || order < 0)
		throw new TypeError('ResourceResolution.order must be non-negative');

		Object.assign(this, {
			slot: new ResourceSlotId(String(slot)).value
			, integrity
			, bytes
			, order
			, candidate: candidate ? identity(candidate, 'ResourceResolution.candidate') : ''
			, profile: plainObject(profile, 'ResourceResolution.profile')
		});

		Object.freeze(this);
	}

	toJSON() { return {...this}; }
}

/** Ordered content identity exchanged before either peer activates a source. */
export class ContentManifest
{

	constructor(resources = [])
	{
		const values = resources.map(
			resource => resource instanceof ResourceOffer ? resource : new ResourceOffer(resource)
		);

		this.resources = Object.freeze(values.sort((a, b) => a.order - b.order || a.slot.localeCompare(b.slot)));

		const keys = new Set;

		for(const resource of this.resources)
		{
			const key = `${resource.slot}:${resource.order}`;

			if(keys.has(key)) throw new TypeError(`Conflicting resource offer ${key}`);

			keys.add(key);
		}

		Object.freeze(this);
	}

	toJSON() { return this.resources; }

	async digest() { return sha256(canonicalJson(this)); }
}

/** Immutable compatibility and content identity for one match. */
export class MatchDescriptor
{

	constructor(
		{
			protocol = '1.0.0'
			, game
			, start
			, packages = []
			, content = []
			, settings = {}
			, players = 2
		}
	) {
		if(!/^\d+\.\d+\.\d+$/.test(protocol)) throw new TypeError('Invalid match protocol version');

		if(!Number.isInteger(players) || players < 2 || players > 16) throw new TypeError('Invalid match player count');

		this.protocol = protocol;
		this.game = requiredString(game, 'MatchDescriptor.game');
		this.start = structuredClone(start);
		this.packages = Object.freeze(packages.map(item => Object.freeze({...item})));
		this.content = content instanceof ContentManifest ? content : new ContentManifest(content);
		this.settings = Object.freeze(structuredClone(settings));
		this.players = players;
		Object.freeze(this);
	}

	toJSON()
	{
		return {
			protocol: this.protocol
			, game: this.game
			, start: this.start
			, packages: this.packages
			, content: this.content
			, settings: this.settings
			, players: this.players
		};
	}

	async digest() { return sha256(canonicalJson(this)); }
}

/** Resolve and authorize the selected Matrix homeserver. */
export function matrixConfiguration(worldOptions = {}, sessionOptions = {}, policy = {})
{
	const value = sessionOptions.homeserver ?? worldOptions.homeserver ?? 'https://matrix.org';
	let homeserver;

	try
	{
		homeserver = new URL(value);
	}
	catch(error)
	{
		throw new NetworkError('matrix_config', `Invalid Matrix homeserver: ${value}`, {cause: error});
	}

	if(!['https:', 'http:'].includes(homeserver.protocol) || homeserver.username || homeserver.password)
	throw new NetworkError('matrix_config', 'Matrix homeserver must be HTTP(S) without embedded credentials');

	homeserver = new URL(homeserver.origin);
	const origins = policy.signalingOrigins === undefined
		? new Set([homeserver.origin])
		: new Set([...policy.signalingOrigins].map(origin => new URL(origin).origin));

	if(!origins.has(homeserver.origin))
	throw new NetworkError('signaling_origin', `Matrix homeserver origin is not allowed: ${homeserver.origin}`);

	return Object.freeze({
		homeserver: homeserver.origin
		, room: sessionOptions.room ?? worldOptions.room ?? '#phonics:matrix.org'
	});
}
