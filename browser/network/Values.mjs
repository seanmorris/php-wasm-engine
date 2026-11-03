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

/**
 * Serialize plain protocol data with stable object-key order.
 * @param {*} value JSON-compatible data; objects with toJSON() are serialized through it.
 * @returns {string} JSON text with plain-object keys sorted at every depth.
 * @throws {TypeError} For undefined, functions, symbols, or non-finite numbers.
 */
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

/**
 * Calculate an SRI-shaped SHA-256 identity over bytes or UTF-8 text.
 * @param {string|ArrayBuffer|ArrayLike<number>} value Text encoded as UTF-8, or bytes copied into a Uint8Array.
 * @returns {Promise<string>} `sha256-` followed by the base64 digest.
 */
export async function sha256(value)
{
	const bytes = typeof value === 'string' ? new TextEncoder().encode(value) : new Uint8Array(value);
	const digest = await globalThis.crypto.subtle.digest('SHA-256', bytes);
	let binary = '';

	for(const byte of new Uint8Array(digest)) binary += String.fromCharCode(byte);

	return `sha256-${btoa(binary)}`;
}

/**
 * Opaque identity whose kind prevents primitive strings from crossing service boundaries unnoticed.
 *
 * The base validates and freezes opaque identifiers once, providing canonical string and JSON forms to its
 * exported semantic subclasses.
 */
class Identifier
{
	/** Validated canonical identifier string. */
	value;


	/** Validate and freeze one opaque identifier value. */
	constructor(value, label)
	{
		this.value = identity(value, label);
		Object.freeze(this);
	}

	/** Return the canonical string representation. */
	toString() { return this.value; }

	/** Serialize the identifier as its canonical scalar value. */
	toJSON() { return this.value; }
}

/**
 * Opaque PeerId value; its string form is the validated identifier.
 *
 * Using a distinct type keeps lobby identities, WebRTC peers, and match participants from being confused
 * with arbitrary strings.
 */
export class PeerId extends Identifier
{

	/**
	 * Validate and freeze one identifier.
	 * @param {string} value 1–128 characters: a letter or digit, then letters, digits, `_`, `.`, `:` or `-`.
	 * @throws {TypeError} When the value is empty or malformed.
	 */
	constructor(value) { super(value, 'PeerId'); }
}

/**
 * Opaque SessionId value; its string form is the validated identifier.
 *
 * The opaque value identifies one lobby or match lifetime independently of the room or provider that carries
 * signaling.
 */
export class SessionId extends Identifier
{

	/**
	 * Validate and freeze one identifier.
	 * @param {string} value 1–128 characters: a letter or digit, then letters, digits, `_`, `.`, `:` or `-`.
	 * @throws {TypeError} When the value is empty or malformed.
	 */
	constructor(value) { super(value, 'SessionId'); }
}

/**
 * Opaque ResourceSlotId value; its string form is the validated identifier.
 *
 * The opaque value names one ordered application resource requirement without exposing file paths as
 * protocol identity.
 */
export class ResourceSlotId extends Identifier
{

	/**
	 * Validate and freeze one identifier.
	 * @param {string} value 1–128 characters: a letter or digit, then letters, digits, `_`, `.`, `:` or `-`.
	 * @throws {TypeError} When the value is empty or malformed.
	 */
	constructor(value) { super(value, 'ResourceSlotId'); }
}

/**
 * One discoverable archive which may satisfy a local resource requirement.
 *
 * The immutable value describes one potential archive by URL, content identity, size, and provenance for
 * later acquisition.
 *
 * @property {string} [url] Canonical retrievable source URL, when the candidate declares one.
 * @property {string} [integrity] SHA-256 SRI identity, when known before acquisition.
 * @property {number} [bytes] Exact byte length, when known before acquisition.
 */
export class ResourceCandidate
{
	/** Identifier unique within the containing resource requirement. */
	id;

	/** Human-readable candidate label. */
	label;

	/** Accepted source file names for local candidate matching. */
	names;

	/** Immutable inspector criteria used for local matching. */
	criteria;

	/**
	 * Validate and freeze one candidate archive description.
	 * @param {object} candidate Candidate fields.
	 * @param {string} candidate.id Identifier unique within its requirement.
	 * @param {string} [candidate.label] Display label; defaults to the ID.
	 * @param {string[]} [candidate.names] Acceptable file names.
	 * @param {object} [candidate.criteria] Plain inspector criteria object.
	 * @param {string|null} [candidate.url] HTTP(S) or file URL without credentials.
	 * @param {string|null} [candidate.integrity] SHA-256 SRI string.
	 * @param {number|null} [candidate.bytes] Non-negative byte length.
	 * @throws {TypeError} When a field is invalid.
	 */
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

	/**
	 * Serialize the frozen fields.
	 * @returns {object} Shallow copy of this value's own fields.
	 */
	toJSON() { return {...this}; }
}

/**
 * Content-qualified description of data each peer must acquire independently.
 *
 * A requirement combines a stable slot with ordering, optionality, content constraints, and the candidate
 * set each peer may resolve independently.
 */
export class ResourceRequirement
{
	/** Stable resource-slot identifier. */
	id;

	/** Human-readable requirement label. */
	label;

	/** Accepted source file names for this requirement. */
	names;

	/** Resource inspector reference used to profile local candidates. */
	inspector;

	/** Equivalence rule used to compare peer resources. */
	equivalence;

	/** Immutable inspector criteria required of a match. */
	criteria;

	/** Guard slots required before profile equivalence can be accepted. */
	profileRequires;

	/** Immutable candidate archives that may satisfy this requirement. */
	candidates;

	/**
	 * Validate and freeze one content requirement and its candidates.
	 * @param {object} requirement Requirement fields.
	 * @param {string} requirement.id Requirement identifier.
	 * @param {string} [requirement.label] Display label; defaults to the ID.
	 * @param {string[]} [requirement.names] Acceptable file names.
	 * @param {string} requirement.inspector Resource-inspector reference.
	 * @param {string} [requirement.equivalence] `integrity` (default) or `profile`.
	 * @param {object} [requirement.criteria] Plain inspector criteria object.
	 * @param {string[]} [requirement.profileRequires] Guard slot IDs; allowed only for profile equivalence.
	 * @param {Array<ResourceCandidate|object>} [requirement.candidates] Candidates with unique IDs.
	 * @throws {TypeError} When a field is invalid or candidate IDs conflict.
	 */
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

	/**
	 * Serialize the frozen fields.
	 * @returns {object} Shallow copy of this value's own fields.
	 */
	toJSON() { return {...this}; }
}

/**
 * Immutable remote/local acquisition offer for one world-owned resource slot.
 *
 * The offer is safe to exchange because construction validates the selected candidate and transport metadata
 * against its declared requirement.
 *
 * @property {string} [url] Canonical source URL for a retrievable offer.
 * @property {ResourceRequirement} [requirement] Local matching requirement for a local-only offer.
 */
export class ResourceOffer
{
	/** Stable resource slot satisfied by this offer. */
	slot;

	/** SHA-256 SRI identity of the offered bytes. */
	integrity;

	/** Exact byte length of the offered resource. */
	bytes;

	/** Resource role interpreted by the application. */
	role;

	/** Optional content-adapter reference. */
	adapter;

	/** Non-negative placement order within the application stack. */
	order;

	/** Human-readable resource label. */
	label;

	/** Acquisition mode: retrievable or local. */
	distribution;

	/**
	 * Validate and freeze one acquisition offer.
	 * @param {object} offer Offer fields.
	 * @param {string} offer.slot Resource slot ID.
	 * @param {string|null} [offer.url] HTTP(S) or file URL; required for retrievable offers, forbidden for local ones.
	 * @param {string} offer.integrity SHA-256 SRI string.
	 * @param {number} offer.bytes Non-negative byte length.
	 * @param {string} [offer.role] Resource role identifier; defaults to `data`.
	 * @param {string} [offer.adapter] Optional adapter reference.
	 * @param {number} [offer.order] Non-negative placement order; defaults to 0.
	 * @param {string} [offer.label] Display label.
	 * @param {string} [offer.distribution] `retrievable` or `local`; defaults from whether a URL is present.
	 * @param {ResourceRequirement|object|null} [offer.requirement] Content requirement; required for local offers.
	 * @throws {TypeError} When a field is invalid or inconsistent with the distribution.
	 */
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

	/**
	 * Serialize the frozen fields.
	 * @returns {object} Shallow copy of this value's own fields.
	 */
	toJSON() { return {...this}; }
}

/**
 * A verified offer whose bytes may have come from an equivalent local mirror.
 *
 * The value joins a verified offer to acquired bytes and canonical provenance after local or peer-assisted
 * resolution succeeds.
 */
export class ResolvedResource
{
	/** Validated offer whose requirement was resolved. */
	offer;

	/** Canonical URL from which the verified bytes were acquired. */
	sourceUrl;

	/** Optional filesystem path at which the bytes were mounted. */
	mountPath;

	/** Compatibility evidence safe to exchange with the peer. */
	resolution;

	/**
	 * Pair an offer with the source that supplied its bytes.
	 * @param {ResourceOffer|object} offer Offer, or fields for a new ResourceOffer.
	 * @param {object} [options] Resolution details.
	 * @param {string} [options.sourceUrl] Absolute source URL; defaults to the offer URL and is required.
	 * @param {string|null} [options.mountPath] Optional mounted path.
	 * @param {ResourceResolution|object|null} [options.resolution] Evidence; defaults to the offer's slot,
	 * integrity, bytes, and order.
	 * @throws {TypeError} When no source URL is available or a field is invalid.
	 */
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

	/**
	 * Serialize the offer with its source URL and resolution evidence.
	 * @returns {object} Offer fields plus `sourceUrl` and `resolution`; the mount path is omitted.
	 */
	toJSON() { return {...this.offer, sourceUrl: this.sourceUrl, resolution: this.resolution}; }
}

/**
 * Safe compatibility evidence for a locally resolved resource; never contains its URL or bytes.
 *
 * It records the slot, hash, byte length, placement order, candidate identity, and inspector profile needed
 * to prove that one locally acquired resource matches the peer's offer.
 */
export class ResourceResolution
{
	/** Stable resource slot represented by this evidence. */
	slot;

	/** SHA-256 SRI identity of the verified bytes. */
	integrity;

	/** Exact byte length of the verified resource. */
	bytes;

	/** Non-negative placement order within the application stack. */
	order;

	/** Matched candidate identifier, or an empty string. */
	candidate;

	/** Immutable inspector profile used for equivalence. */
	profile;

	/**
	 * Validate and freeze compatibility evidence.
	 * @param {object} resolution Evidence fields.
	 * @param {string} resolution.slot Resource slot ID.
	 * @param {string} resolution.integrity SHA-256 SRI string.
	 * @param {number} resolution.bytes Non-negative byte length.
	 * @param {number} [resolution.order] Non-negative placement order; defaults to 0.
	 * @param {string} [resolution.candidate] Matched candidate ID, if any.
	 * @param {object} [resolution.profile] Plain inspector profile object.
	 * @throws {TypeError} When a field is invalid.
	 */
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

	/**
	 * Serialize the frozen fields.
	 * @returns {object} Shallow copy of this value's own fields.
	 */
	toJSON() { return {...this}; }
}

/**
 * Ordered content identity exchanged before either peer activates a source.
 *
 * It sorts immutable resource offers, rejects duplicate slot and order pairs, and provides a canonical digest
 * used to reject incompatible content before gameplay.
 */
export class ContentManifest
{
	/** Immutable resource offers sorted by placement order and slot. */
	resources;


	/**
	 * Sort offers by order then slot and reject duplicate slot/order pairs.
	 * @param {Array<ResourceOffer|object>} [resources] Offers, or fields for new ResourceOffers.
	 * @throws {TypeError} When an offer is invalid or two share a slot and order.
	 */
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

	/**
	 * Serialize the ordered offers.
	 * @returns {ResourceOffer[]} Sorted, frozen offer list.
	 */
	toJSON() { return this.resources; }

	/**
	 * Hash the canonical JSON form.
	 * @returns {Promise<string>} SHA-256 SRI string over canonicalJson(this).
	 */
	async digest() { return sha256(canonicalJson(this)); }
}

/**
 * Immutable compatibility and content identity for one match.
 *
 * It freezes protocol and application identity, selected game start, package revisions, ordered content,
 * settings, and player count as the handoff from the lobby to a network session.
 */
export class MatchDescriptor
{
	/** Negotiated protocol compatibility record. */
	protocol;

	/** Application descriptor negotiated for the current match. */
	application;

	/** Selected game and network mode identifier. */
	game;

	/** Selected application start scope and initialization data. */
	start;

	/** Package records participating in the current application. */
	packages;

	/** Verified resource manifest required by the match. */
	content;

	/** Immutable game-owned match settings. */
	settings;

	/** Immutable negotiated player assignments. */
	players;


	/**
	 * Validate and freeze one match description.
	 * @param {object} match Match fields.
	 * @param {string} [match.protocol] Semantic version `major.minor.patch`; defaults to `1.0.0`.
	 * @param {string} match.game Game export reference.
	 * @param {*} match.start Structured-cloneable start selector.
	 * @param {object[]} [match.packages] Package descriptions, each shallow-copied and frozen.
	 * @param {ContentManifest|Array<ResourceOffer|object>} [match.content] Content offers.
	 * @param {object} [match.settings] Structured-cloneable match settings.
	 * @param {number} [match.players] Integer player count from 2 through 16; defaults to 2.
	 * @throws {TypeError} When the protocol, game, player count, or content is invalid.
	 */
	constructor(
		{
			protocol = '1.0.0'
			, application = null
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

		this.application = application
			? Object.freeze({
				id: requiredString(application.id, 'Application identity')
				, code: requiredString(application.code, 'Application code digest')
			})
			: null;

		this.game = requiredString(game, 'MatchDescriptor.game');
		this.start = structuredClone(start);
		this.packages = Object.freeze(packages.map(item => Object.freeze({...item})));
		this.content = content instanceof ContentManifest ? content : new ContentManifest(content);
		this.settings = Object.freeze(structuredClone(settings));
		this.players = players;
		Object.freeze(this);
	}

	/**
	 * Serialize the match fields in a fixed order.
	 * @returns {object} Protocol, game, start, packages, content, settings, and players.
	 */
	toJSON()
	{
		return {
			protocol: this.protocol
			, ...(this.application ? {application: this.application} : {})
			, game: this.game
			, start: this.start
			, packages: this.packages
			, content: this.content
			, settings: this.settings
			, players: this.players
		};
	}

	/**
	 * Hash the canonical JSON form.
	 * @returns {Promise<string>} SHA-256 SRI string over canonicalJson(this).
	 */
	async digest() { return sha256(canonicalJson(this)); }
}

/**
 * Resolve and authorize the selected Matrix homeserver.
 * @param {{homeserver?: string, room?: string}} [worldOptions] World-declared lobby options.
 * @param {{homeserver?: string, room?: string}} [sessionOptions] Session overrides, preferred over world options.
 * @param {{signalingOrigins?: Iterable<string>}} [policy] Allowed signaling origins; defaults to the homeserver origin.
 * @returns {Readonly<{homeserver: string, room: string}>} Homeserver origin (default `https://matrix.org`) and
 * the game's explicitly declared lobby chat room.
 * @throws {NetworkError} With code matrix_config for an invalid URL or missing room, or signaling_origin for a
 * disallowed origin.
 */
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

	const room = sessionOptions.room ?? worldOptions.room;

	if(typeof room !== 'string' || !room.trim())
	throw new NetworkError('matrix_config', 'A Matrix-enabled game must declare its own lobby chat room');

	return Object.freeze({
		homeserver: homeserver.origin
		, room: room.trim()
	});
}
