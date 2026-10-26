import {NetworkError, networkFailure} from './Errors.mjs';
import {
	canonicalJson
	, ContentManifest
	, ResourceOffer
	, ResourceRequirement
	, ResourceResolution
	, ResolvedResource
	, sha256
} from './Values.mjs';

const originPolicy = values => {
	const entries = typeof values === 'string' ? [values] : [...values];

	return {
		any: entries.includes('*')
		, origins: new Set(entries.filter(value => value !== '*').map(value => new URL(value).origin))
	};
};

/** Acquire, verify, and bind world-declared resource slots without interpreting their format. */
export class ResourceNegotiator
{

	constructor({world, store, signal, policy = {}, role = 'host', onProgress = () => {}, inspectors = null})
	{
		if(!world?.resourceSlots || !world?.sources) throw new TypeError('Resource negotiation requires a world');

		if(!store?.scope) throw new TypeError('Resource negotiation requires a ResourceStore');

		if(!['host','guest'].includes(role)) throw new TypeError('Resource negotiation role must be host or guest');

		this.world = world;
		this.scope = store.scope(signal);
		this.signal = signal;
		this.policy = policy;
		this.role = role;
		this.onProgress = onProgress;
		this.inspectors = inspectors;
		this.resolved = new Map;
		this.objectUrls = new Set;
		this.closed = false;

		const origins = originPolicy(
			policy.dataOrigins === undefined ? [new URL(world.sourceUrl).origin] : policy.dataOrigins
		);

		this.allowAnyOrigin = origins.any;
		this.origins = origins.origins;

		this.maximumBytes = policy.maxResourceBytes ?? 256 * 1024 * 1024;
	}

	/** Create and verify a local offer for a slot this peer is permitted to fill. */
	async offer(slotId, url, metadata = {})
	{
		this.requireOpen();
		const slot = this.slot(slotId);
		const requirement = this.requirement(slot, metadata.stackIndex);

		if(slot.authority === 'world') this.requireWorldDefault(slotId, slot, url);
		else if(slot.authority !== 'either' && slot.authority !== this.role)
		throw new NetworkError('resource_authority', `${this.role} cannot fill resource slot ${slotId}`);

		if(slot.distribution === 'local') return this.offerLocal(slotId, url, metadata);

		const sourceUrl = this.authorize(url, slotId);
		this.progress('loading', slotId, sourceUrl);

		try
		{
			const bytes = await this.scope.read(sourceUrl, {retry: true});
			this.validateBytes(slotId, slot, sourceUrl, bytes, null, this.filename(url));
			const integrity = await sha256(bytes);

			this.validateExpected(slotId, slot, integrity, bytes.byteLength);

			const inspected = await this.inspect(slotId, slot, bytes, {
				sourceUrl
				, filename: this.filename(url)
				, role: metadata.role ?? this.roles(slotId)[0] ?? 'data'
			}, requirement
			);

			const offer = new ResourceOffer({
				slot: slotId
				, url: sourceUrl
				, integrity
				, bytes: bytes.byteLength
				, role: metadata.role ?? this.roles(slotId)[0] ?? 'data'
				, adapter: metadata.adapter ?? ''
				, order: metadata.order ?? 0
				, label: metadata.label ?? slot.label ?? slotId
				, distribution: 'retrievable'
				, ...(requirement ? {requirement: this.safeRequirement(requirement)} : {})
			});

			const resolution = this.resolution(offer, integrity, bytes.byteLength, inspected);
			this.remember(new ResolvedResource(offer, {sourceUrl, resolution}), slot);

			this.progress('verified', slotId, sourceUrl, bytes.byteLength);

			return offer;
		}
		catch(error)
		{
			throw networkFailure('resource_acquisition', `Cannot offer slot ${slotId}`, error, {
				declaring: this.world.sourceUrl
				, chain: [this.world.sourceUrl, `slot:${slotId}`, sourceUrl]
			});
		}
	}

	/** Inspect a prospective selection for authoring UI without binding or publishing it. */
	async preview(slotId, source, metadata = {})
	{
		this.requireOpen();
		const slot = this.slot(slotId);
		const requirement = this.requirement(slot, metadata.stackIndex);
		const role = metadata.role ?? this.roles(slotId)[0] ?? 'data';
		let bytes, sourceUrl, filename, displayUrl;
		const isBlob = typeof Blob !== 'undefined' && source instanceof Blob;

		if(isBlob)
		{
			filename = String(source.name || this.requirementNames(requirement)[0] || `${slotId}.bin`);
			displayUrl = `local:${filename}`;
			bytes = new Uint8Array(await source.arrayBuffer());
			sourceUrl = displayUrl;
		}
		else
		{
			sourceUrl = this.authorizeLocal(source, slotId);
			filename = this.filename(sourceUrl);
			displayUrl = sourceUrl.startsWith('blob:') ? `local:${filename}` : sourceUrl;
			bytes = await this.scope.read(sourceUrl, {retry: true});
		}

		this.validateBytes(slotId, slot, displayUrl, bytes, null, filename);
		const integrity = await sha256(bytes);
		this.validateExpected(slotId, slot, integrity, bytes.byteLength);

		const inspected = await this.inspect(slotId, slot, bytes, {sourceUrl, filename, displayUrl, role}, requirement);

		this.validateCandidate(slotId, requirement, inspected, integrity, bytes.byteLength);

		return Object.freeze({
			slot: slotId
			, source
			, filename
			, displayUrl
			, integrity
			, bytes: bytes.byteLength
			, candidate: inspected.candidate
			, profile: Object.freeze(structuredClone(inspected.profile))
		});
	}

	/** Resolve a local-only selection and create an offer which exposes no acquisition URL. */
	async offerLocal(slotId, source, metadata = {})
	{
		const slot = this.slot(slotId);
		const requirement = this.requirement(slot, metadata.stackIndex);
		const acquired = await this.acquireLocal(slotId, slot, source, requirement);
		const role = metadata.role ?? this.roles(slotId)[0] ?? 'data';
		const integrity = await sha256(acquired.bytes);

		this.validateExpected(slotId, slot, integrity, acquired.bytes.byteLength);

		const inspected = await this.inspect(slotId, slot, acquired.bytes, {...acquired, role}, requirement);
		this.validateCandidate(slotId, requirement, inspected, integrity, acquired.bytes.byteLength);

		const offer = new ResourceOffer({
			slot: slotId
			, integrity
			, bytes: acquired.bytes.byteLength
			, role
			, adapter: metadata.adapter ?? ''
			, order: metadata.order ?? 0
			, label: metadata.label ?? slot.label ?? slotId
			, distribution: 'local'
			, requirement: this.safeRequirement(requirement)
		});

		const resolution = this.resolution(offer, integrity, acquired.bytes.byteLength, inspected);
		this.remember(new ResolvedResource(offer, {sourceUrl: acquired.sourceUrl, resolution}), slot);
		this.progress('verified', slotId, acquired.displayUrl, acquired.bytes.byteLength);

		return offer;
	}

	/** Fetch an offered resource, optionally from an equivalent authorized mirror. */
	async accept(value, {url = null, source = url, stackIndex = 0, signal} = {})
	{
		this.requireOpen();
		signal?.throwIfAborted();
		const offer = value instanceof ResourceOffer ? value : new ResourceOffer(value);
		const slot = this.slot(offer.slot);
		const requirement = this.requirement(slot, stackIndex);
		const expectedRoles = this.roles(offer.slot);

		this.validateOfferedRequirement(offer, requirement);

		if(slot.authority === 'world') this.requireWorldDefault(offer.slot, slot, offer.url);

		if(expectedRoles.length && !expectedRoles.includes(offer.role))
		throw new NetworkError(
			'resource_role'
			, `Resource slot ${offer.slot} cannot use role ${offer.role}`
			, {declaring: this.world.sourceUrl, details: {expectedRoles}}
		);

		if(offer.distribution === 'local') return this.acceptLocal(offer, slot, source, requirement, signal);

		const sourceUrl = this.authorize(source ?? offer.url, offer.slot);

		this.progress('loading', offer.slot, sourceUrl);

		try
		{
			const bytes = await this.scope.read(sourceUrl, {integrity: offer.integrity, retry: true});
			signal?.throwIfAborted();
			this.validateBytes(offer.slot, slot, sourceUrl, bytes, offer.bytes, this.filename(sourceUrl));
			this.validateExpected(offer.slot, slot, offer.integrity, bytes.byteLength);

			const inspected = await this.inspect(offer.slot, slot, bytes, {
				sourceUrl
				, filename: this.filename(sourceUrl)
				, role: offer.role
			}, requirement
			);

			this.validateCandidate(offer.slot, requirement, inspected, offer.integrity, bytes.byteLength);
			signal?.throwIfAborted();
			const resolution = this.resolution(offer, offer.integrity, bytes.byteLength, inspected);
			const resolved = new ResolvedResource(offer, {sourceUrl, resolution});
			this.remember(resolved, slot);
			this.progress('verified', offer.slot, sourceUrl, bytes.byteLength);

			return resolved;
		}
		catch(error)
		{
			throw networkFailure('resource_integrity', `Cannot resolve slot ${offer.slot}`, error, {
				declaring: this.world.sourceUrl
				, chain: [this.world.sourceUrl, `slot:${offer.slot}`, sourceUrl]
			});
		}
	}

	/** Satisfy a name-only offer using bytes selected independently on this peer. */
	async acceptLocal(offer, slot, source, requirement, signal)
	{
		source ??= this.defaultSource(slot, requirement);

		if(!source)
		throw new NetworkError(
			'resource_missing'
			, `Resource slot ${offer.slot} requires ${this.requirementNames(offer.requirement).join(' or ')}`
			, {declaring: this.world.sourceUrl, chain: [this.world.sourceUrl, `slot:${offer.slot}`]}
		);

		const acquired = await this.acquireLocal(offer.slot, slot, source, requirement);
		signal?.throwIfAborted();
		const integrity = await sha256(acquired.bytes);

		this.validateExpected(offer.slot, slot, integrity, acquired.bytes.byteLength);

		const inspected = await this.inspect(
			offer.slot
			, slot
			, acquired.bytes
			, {...acquired, role: offer.role}
			, requirement
		);

		this.validateCandidate(offer.slot, requirement, inspected, integrity, acquired.bytes.byteLength);
		signal?.throwIfAborted();

		if(offer.requirement.equivalence === 'integrity' && integrity !== offer.integrity)
		throw new NetworkError('resource_integrity', `Resource slot ${offer.slot} does not match the host archive`);

		const resolution = this.resolution(offer, integrity, acquired.bytes.byteLength, inspected);
		const resolved = new ResolvedResource(offer, {sourceUrl: acquired.sourceUrl, resolution});
		this.remember(resolved, slot);
		this.progress('verified', offer.slot, acquired.displayUrl, acquired.bytes.byteLength);

		return resolved;
	}

	/** Resolve a slot's local default through the same integrity path used by peer offers. */
	async default(slotId, metadata = {})
	{
		const slot = this.slot(slotId);

		if(!slot.defaultUrl) return null;

		await this.offer(slotId, slot.defaultUrl, metadata);

		return this.resolved.get(slotId)?.at(-1) ?? null;
	}

	/** Return the deterministic manifest currently bound to this session. */
	manifest()
	{
		return new ContentManifest([...this.resolved.values()].flat().map(resource => resource.offer));
	}

	/** Return compatibility evidence stripped of every local or remote acquisition URL. */
	resolutions()
	{
		return [...this.resolved.values()].flat().map(resource => resource.resolution);
	}

	/** Validate the peer's safe resolution summary against this peer's bound content. */
	verifyPeer(values = [])
	{
		const remote = new Map(values.map(value => {
			const resolution = value instanceof ResourceResolution ? value : new ResourceResolution(value);

			return [`${resolution.slot}:${resolution.order}`, resolution];
		}));

		for(const resource of [...this.resolved.values()].flat())
		{
			const local = resource.resolution;
			const peer = remote.get(`${local.slot}:${local.order}`);

			if(!peer) throw new NetworkError('resource_missing', `Peer did not resolve resource slot ${local.slot}`);

			const requirement = resource.offer.requirement;

			if(!requirement || requirement.equivalence === 'integrity')
			{
				if(peer.integrity !== local.integrity || peer.bytes !== local.bytes)
				throw new NetworkError('resource_integrity', `Peer resolved different bytes for slot ${local.slot}`);
			}
			else
			{
				if(requirement.candidates.length
					&& !requirement.candidates.some(candidate => candidate.id === peer.candidate)
				)
				throw new NetworkError('resource_profile', `Peer used an unsupported candidate for slot ${local.slot}`);

				if(peer.integrity === local.integrity && peer.bytes !== local.bytes)
				throw new NetworkError('resource_size', `Peer reported a conflicting size for slot ${local.slot}`);

				if(peer.integrity !== local.integrity) this.verifyProfileGuards(requirement, remote, local.slot);
			}

			remote.delete(`${local.slot}:${local.order}`);
		}

		if(remote.size) throw new NetworkError('resource_conflict', 'Peer resolved undeclared match content');

		return true;
	}

	verifyProfileGuards(requirement, remote, slotId)
	{
		for(const guardId of requirement.profileRequires)
		{
			const guards = this.resolved.get(guardId) ?? [];

			if(!guards.length)
			throw new NetworkError(
				'resource_profile'
				, `Resource slot ${slotId} needs exact guard slot ${guardId} before profiles may differ`
			);

			for(const guard of guards)
			{
				if(guard.offer.requirement?.equivalence === 'profile')
				throw new NetworkError('resource_profile', `Guard slot ${guardId} must use exact equivalence`);

				const local = guard.resolution;
				const peer = remote.get(`${local.slot}:${local.order}`);

				if(!peer || peer.integrity !== local.integrity || peer.bytes !== local.bytes)
				throw new NetworkError(
					'resource_profile'
					, `Resource slot ${slotId} cannot differ because guard slot ${guardId} is not exact`
				);
			}
		}
	}

	/** Produce an immutable world copy with every resource slot replaced by verified URLs. */
	bindWorld({start = null} = {})
	{
		this.requireOpen();
		const world = structuredClone(this.world);

		for(const [sourceId, source] of Object.entries(world.sources))
		{
			const resources = [];

			for(const placement of source.resources)
			{
				if(!placement.slot)
				{
					resources.push(placement);
					continue;
				}

				const values = this.resolved.get(placement.slot) ?? [];
				const slot = world.resourceSlots[placement.slot];

				if(!values.length && slot.required)
				throw new NetworkError(
					'resource_missing'
					, `${world.sourceUrl}: source ${sourceId} requires unresolved slot ${placement.slot}`
					, {
						declaring: world.sourceUrl
						, chain: [world.sourceUrl, `source:${sourceId}`
							, `slot:${placement
						.slot}`
						]
					}
				);

				for(const value of values)
				{
					const {slot: _slot, ...boundPlacement} = placement;

					resources.push({
						...boundPlacement
						, url: value.sourceUrl
						, sourceUrl: value.sourceUrl
						, ...(value.offer.url ? {offerUrl: value.offer.url} : {})
						, integrity: value.resolution.integrity
						, bytes: value.resolution.bytes
						, negotiated: true
						, ...(value.resolution.candidate ? {candidate: value.resolution.candidate} : {})
						, ...(value.offer.requirement ? {requirement: value.offer.requirement.toJSON()} : {})
					});
				}
			}

			source.resources = resources;
		}

		if(start !== null) world.start = structuredClone(start);

		return world;
	}

	/** Maps visible after applying archive-order overrides from all inspected resources. */
	maps()
	{
		const result = [];

		const values = [...this.resolved.values()].flat()
			.sort((left, right) => left.offer.order - right.offer.order);

		for(const value of values)
		for(const name of value.resolution.profile?.maps ?? [])
		{
			const index = result.indexOf(name);

			if(index >= 0) result.splice(index, 1);

			result.push(name);
		}

		return result;
	}

	/** Forget an abandoned setup while retaining the session's shared fetch cache. */
	reset()
	{
		this.requireOpen();
		this.resolved.clear();

		for(const url of this.objectUrls) URL.revokeObjectURL(url);

		this.objectUrls.clear();
	}

	close()
	{
		if(this.closed) return;

		this.closed = true;
		this.scope.close();
		this.resolved.clear();

		for(const url of this.objectUrls) URL.revokeObjectURL(url);

		this.objectUrls.clear();
	}

	slot(id)
	{
		return this.world.resourceSlots[id]
			?? (() => { throw new NetworkError('resource_slot', `Unknown resource slot ${id}`); })();
	}

	/** Select the validation contract for one position in an ordered resource slot. */
	requirement(slot, stackIndex = 0)
	{
		if(!slot.stack) return slot.requirement ?? null;

		if(!Number.isSafeInteger(stackIndex) || stackIndex < 0)
		throw new NetworkError('resource_order', 'Resource stack index must be a non-negative integer');

		return stackIndex === 0 ? slot.stack.first : slot.stack.following;
	}

	validateOfferedRequirement(offer, expected)
	{
		const actual = offer.requirement ?? null;
		const safe = expected ? this.safeRequirement(expected) : null;

		if(canonicalJson(actual) !== canonicalJson(safe))
		throw new NetworkError(
			'resource_profile'
			, `Resource slot ${offer.slot} does not use the world's requirement for this stack position`
		);
	}

	roles(slotId)
	{
		return [...new Set(
			Object.values(this.world.sources)
				.flatMap(source => source.resources)
				.filter(resource => resource.slot === slotId)
				.map(resource => resource.role ?? 'data')
		)];
	}

	authorize(value, slotId)
	{
		let url;

		try
		{
			url = new URL(value, this.world.sourceUrl);
		}
		catch(error)
		{
			throw new NetworkError('resource_url', `Invalid URL for slot ${slotId}`, {cause: error});
		}

		if(!['https:', 'http:', 'file:'].includes(url.protocol) || url.username || url.password)
		throw new NetworkError('resource_url', `Unsupported URL for slot ${slotId}`);

		if(url.protocol !== 'file:' && !this.allowAnyOrigin && !this.origins.has(url.origin))
		throw new NetworkError('data_origin', `Resource origin is not allowed for slot ${slotId}: ${url.origin}`);

		return url.href;
	}

	validateBytes(slotId, slot, url, bytes, offeredBytes = null, filename = '')
	{
		const limit = Math.min(slot.maxBytes ?? this.maximumBytes, this.maximumBytes);

		if(bytes.byteLength > limit)
		throw new NetworkError('resource_size', `Resource slot ${slotId} exceeds ${limit} bytes`);

		if(offeredBytes !== null && bytes.byteLength !== offeredBytes)
		throw new NetworkError('resource_size', `Resource slot ${slotId} byte length does not match its offer`);

		const extensionRules = slot.accept.filter(rule => rule.startsWith('.'));

		if(extensionRules.length && !extensionRules.some(rule => filename.toLowerCase().endsWith(rule.toLowerCase())))
		throw new NetworkError('resource_type', `Resource slot ${slotId} does not accept ${filename || url}`);
	}

	validateExpected(slotId, slot, integrity, bytes)
	{
		if(slot.integrity && slot.integrity !== integrity)
		throw new NetworkError('resource_integrity', `Resource slot ${slotId} does not match its world integrity`);

		if(slot.bytes !== undefined && slot.bytes !== bytes)
		throw new NetworkError('resource_size', `Resource slot ${slotId} does not match its world byte length`);
	}

	requireWorldDefault(slotId, slot, value)
	{
		const actual = this.authorize(value, slotId);
		const expected = this.authorize(slot.defaultUrl, slotId);

		if(actual !== expected)
		throw new NetworkError('resource_authority', `Resource slot ${slotId} is fixed by its declaring world`);
	}

	async acquireLocal(slotId, slot, source, requirement = this.requirement(slot))
	{
		this.requireOpen();
		let bytes, sourceUrl, filename, displayUrl;
		const isBlob = typeof Blob !== 'undefined' && source instanceof Blob;

		if(isBlob)
		{
			filename = String(source.name || this.requirementNames(requirement)[0] || `${slotId}.bin`);
			displayUrl = `local:${filename}`;
			this.progress('loading', slotId, displayUrl);
			bytes = new Uint8Array(await source.arrayBuffer());

			if(typeof URL.createObjectURL !== 'function')
			throw new NetworkError('resource_local', 'This browser cannot mount a selected local file');

			sourceUrl = URL.createObjectURL(source);
			this.objectUrls.add(sourceUrl);
		}
		else
		{
			if(typeof source !== 'string' || !source)
			throw new NetworkError('resource_missing', `Resource slot ${slotId} requires a local file or URL`);

			sourceUrl = this.authorizeLocal(source, slotId);
			filename = this.filename(sourceUrl);
			displayUrl = sourceUrl.startsWith('blob:') ? `local:${filename}` : sourceUrl;
			this.progress('loading', slotId, displayUrl);
			bytes = await this.scope.read(sourceUrl, {retry: true});
		}

		this.validateBytes(slotId, slot, displayUrl, bytes, null, filename);

		return {bytes, sourceUrl, filename, displayUrl};
	}

	async inspect(slotId, slot, bytes, details, requirement = this.requirement(slot))
	{
		if(!requirement) return {candidate: '', profile: {}};

		if(typeof this.inspectors !== 'function')
		throw new NetworkError('resource_inspector', `No inspector service can validate resource slot ${slotId}`);

		let inspector;

		try
		{
			inspector = this.inspectors(requirement.inspector);
		}
		catch(error)
		{
			throw new NetworkError(
				'resource_inspector'
				, `Cannot create inspector ${requirement.inspector} for slot ${slotId}`
				, {cause: error}
			);
		}

		const operation = typeof inspector === 'function' ? inspector : inspector?.inspect?.bind(inspector);

		if(typeof operation !== 'function')
		throw new NetworkError('resource_inspector', `Invalid inspector ${requirement.inspector}`);

		let result;

		try
		{
			result = await operation(bytes, {
				slot: slotId
				, role: details.role
				, filename: details.filename
				, sourceUrl: details.sourceUrl
				, requirement: structuredClone(requirement)
			});
		}
		catch(error)
		{
			throw networkFailure('resource_profile', `Resource slot ${slotId} is incompatible`, error, {
				declaring: this.world.sourceUrl
				, chain: [this.world.sourceUrl, `slot:${slotId}`, details.displayUrl ?? details.sourceUrl]
			});
		}

		if(!result || typeof result !== 'object' || Array.isArray(result)
			|| typeof (result.candidate ?? '') !== 'string'
			|| !result.profile || typeof result.profile !== 'object' || Array.isArray(result.profile)
		)
		throw new NetworkError('resource_profile', `Inspector returned an invalid profile for slot ${slotId}`);

		return {candidate: result.candidate ?? '', profile: structuredClone(result.profile)};
	}

	validateCandidate(slotId, requirement, inspected, integrity, bytes)
	{
		if(!requirement) return;

		const value = new ResourceRequirement(requirement);
		const candidate = value.candidates.find(item => item.id === inspected.candidate);

		if(value.candidates.length && !candidate)
		throw new NetworkError('resource_profile', `Resource slot ${slotId} did not match an accepted candidate`);

		if(candidate?.integrity && candidate.integrity !== integrity)
		throw new NetworkError(
			'resource_integrity'
			, `Resource slot ${slotId} candidate ${candidate.id} has the wrong digest`
		);

		if(candidate?.bytes !== undefined && candidate.bytes !== bytes)
		throw new NetworkError(
			'resource_size'
			, `Resource slot ${slotId} candidate ${candidate.id} has the wrong byte length`
		);
	}

	resolution(offer, integrity, bytes, inspected)
	{
		return new ResourceResolution({
			slot: offer.slot
			, integrity
			, bytes
			, order: offer.order
			, candidate: inspected.candidate
			, profile: inspected.profile
		});
	}

	defaultSource(slot, requirement = this.requirement(slot))
	{
		const slotDefault = !slot.stack || requirement === slot.stack.first ? slot.defaultUrl : null;

		return slotDefault ?? requirement?.candidates?.find(candidate => candidate.url)?.url ?? null;
	}

	requirementNames(requirement)
	{
		return [...new Set([
			...(requirement?.names ?? [])
			, ...(requirement?.candidates ?? []).flatMap(candidate => candidate.names ?? [])
		])];
	}

	safeRequirement(requirement)
	{
		return new ResourceRequirement({
			...requirement
			, candidates: (requirement.candidates ?? []).map(candidate => {
				const {url: _url, ...safe} = candidate;

				return safe;
			})
		});
	}

	filename(value)
	{
		if(typeof value !== 'string') return String(value?.name ?? '');

		try
		{
			const name = new URL(value, this.world.sourceUrl).pathname.split('/').pop();

			return decodeURIComponent(name || '');
		}
		catch
		{
			return value;
		}
	}

	authorizeLocal(value, slotId)
	{
		let url;

		try
		{
			url = new URL(value, this.world.sourceUrl);
		}
		catch(error)
		{
			throw new NetworkError('resource_url', `Invalid URL for slot ${slotId}`, {cause: error});
		}

		if(url.protocol === 'blob:') return url.href;

		return this.authorize(url, slotId);
	}

	progress(state, slot, url, bytes = 0)
	{
		this.onProgress(Object.freeze({state, slot, url, bytes}));
	}

	remember(resolved, slot)
	{
		const items = this.resolved.get(resolved.offer.slot) ?? [];

		if(!slot.multiple && items.length)
		throw new NetworkError('resource_conflict', `Resource slot ${resolved.offer.slot} accepts only one resource`);

		items.push(resolved);
		items.sort((a, b) => a.offer.order - b.offer.order);
		this.resolved.set(resolved.offer.slot, items);
	}

	requireOpen()
	{
		this.signal?.throwIfAborted();

		if(this.closed) throw new NetworkError('resource_closed', 'Resource negotiator is closed');
	}
}
