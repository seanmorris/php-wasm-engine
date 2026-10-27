import {properties} from './PackageGraph.mjs';

/** Neutral world-document type understood by this runtime. */
export const WORLD_TYPE = 'phonics-world';

const object = (value, message) => {
	if(!value || typeof value !== 'object' || Array.isArray(value)) throw new Error(message);

	return value;
};

const reference = (value, message) => {
	if(typeof value !== 'string' || !value) throw new Error(message);

	return value;
};

const identifier = (value, message) => {
	reference(value, message);

	if(!/^[A-Za-z][A-Za-z0-9_.-]*$/.test(value)) throw new Error(message);

	return value;
};

const stringList = (value, message) => {
	if(!Array.isArray(value) || value.some(item => typeof item !== 'string' || !item)) throw new Error(message);

	return [...value];
};

const normalizeRequirement = (input, {declaring, slotId, label, suffix = ''}) => {
	const path = suffix ? `${slotId}${suffix}` : `${slotId}.requirement`;

	object(input, `${declaring}: resource slot ${path} must be an object`);

	const requirementId = identifier(
		input.id ?? slotId
		, `${declaring}: invalid requirement ID for resource slot ${slotId}`
	);

	const equivalence = input.equivalence ?? 'integrity';

	if(!['integrity','profile'].includes(equivalence))
	throw new Error(`${declaring}: resource slot ${slotId} requirement equivalence must be integrity or profile`);

	const names = stringList(
		input.names ?? []
		, `${declaring}: resource slot ${slotId} requirement names must contain strings`
	);

	const inspector = reference(input.inspector, `${declaring}: resource slot ${slotId} requirement has no inspector`);

	const criteria = structuredClone(
		object(input.criteria ?? {}, `${declaring}: resource slot ${slotId} requirement criteria must be an object`)
	);

	const profileRequires = stringList(
		input.profileRequires ?? []
		, `${declaring}: resource slot ${slotId}.requirement.profileRequires must contain slot IDs`
	).map(
		required => identifier(required, `${declaring}: resource slot ${slotId} has invalid profile guard ${required}`)
	);

	if(equivalence !== 'profile' && profileRequires.length)
	throw new Error(`${declaring}: resource slot ${slotId} can guard only profile equivalence`);

	const candidateIds = new Set;

	const candidates = (input.candidates ?? []).map((candidate, index) => {
		object(candidate, `${declaring}: resource slot ${slotId} candidate ${index} must be an object`);

		const candidateId = identifier(candidate.id, `${declaring}: invalid candidate ID for resource slot ${slotId}`);

		if(candidateIds.has(candidateId))
		throw new Error(`${declaring}: duplicate candidate ${candidateId} in resource slot ${slotId}`);

		candidateIds.add(candidateId);

		const candidateNames = stringList(
			candidate.names ?? []
			, `${declaring}: resource slot ${slotId} candidate ${candidateId} names must contain strings`
		);

		const candidateCriteria = structuredClone(
			object(
				candidate.criteria ?? {}
				, `${declaring}: resource slot ${slotId} candidate ${candidateId} criteria must be an object`
			)
		);

		let url;

		if(candidate.url !== undefined)
		url = new URL(
			reference(
				candidate.url
				, `${declaring}: resource slot ${slotId} candidate ${candidateId} has an invalid URL`
			)
			, declaring
		).href;

		if(candidate.integrity !== undefined && !/^sha256-[A-Za-z0-9+/]{43}=$/.test(candidate.integrity))
		throw new Error(`${declaring}: invalid integrity for resource slot ${slotId} candidate ${candidateId}`);

		if(candidate.bytes !== undefined && (!Number.isSafeInteger(candidate.bytes) || candidate.bytes < 0))
		throw new Error(`${declaring}: invalid byte length for resource slot ${slotId} candidate ${candidateId}`);

		return {
			...structuredClone(candidate)
			, id: candidateId
			, names: candidateNames
			, criteria: candidateCriteria
			, ...(url ? {url} : {})
		};
	});

	if(!names.length && !candidates.some(candidate => candidate.names.length))
	throw new Error(`${declaring}: resource slot ${slotId} requirement must name at least one acceptable file`);

	return {
		...structuredClone(input)
		, id: requirementId
		, label: String(input.label ?? label ?? requirementId)
		, names
		, inspector
		, equivalence
		, criteria
		, profileRequires
		, candidates
	};
};

/** Normalize the optional multiplayer declaration without selecting concrete game behavior. */
export function normalizeNetwork(value, declaring)
{
	if(value === undefined) return null;

	object(value, `${declaring}: network must be an object`);
	const activation = value.activation ?? 'menu';

	if(!['menu','auto'].includes(activation))
	throw new Error(`${declaring}: network.activation must be menu or auto`);

	const players = object(value.players ?? {min: 2, max: 2}, `${declaring}: network.players must be an object`);
	const min = players.min ?? 2, max = players.max ?? min;

	if(!Number.isInteger(min) || !Number.isInteger(max) || min < 2 || max < min || max > 16)
	throw new Error(`${declaring}: network.players must contain an ordered range from 2 through 16`);

	const lobby = object(value.lobby, `${declaring}: network.lobby must be an object`);
	const transport = typeof value.transport === 'string'
		? {use: value.transport, options: {}}
		: object(value.transport, `${declaring}: network.transport must be a reference or object`);

	const provider = reference(lobby.provider, `${declaring}: network.lobby has no provider`);
	const use = reference(transport.use, `${declaring}: network.transport has no use reference`);

	const lobbyOptions = structuredClone(
		object(lobby.options ?? {}, `${declaring}: network.lobby.options must be an object`)
	);

	const transportOptions = structuredClone(
		object(transport.options ?? {}, `${declaring}: network.transport.options must be an object`)
	);

	if(lobbyOptions.homeserver !== undefined)
	{
		let homeserver;

		try
		{
			homeserver = new URL(lobbyOptions.homeserver);
		}
		catch
		{
			throw new Error(`${declaring}: network Matrix homeserver must be an absolute URL`);
		}

		if(!['https:', 'http:'].includes(homeserver.protocol) || homeserver.username || homeserver.password)
		throw new Error(`${declaring}: network Matrix homeserver must be an HTTP(S) origin without credentials`);

		lobbyOptions.homeserver = homeserver.origin;
	}

	return {
		...structuredClone(value)
		, activation
		, players: {min, max}
		, lobby: {...structuredClone(lobby), provider, options: structuredClone(lobbyOptions)}
		, transport: {...structuredClone(transport), use, options: structuredClone(transportOptions)}
	};
}

/** Normalize world-owned slots that may receive session data before a content source opens. */
export function normalizeResourceSlots(value, declaring)
{
	const slots = object(value ?? {}, `${declaring}: resourceSlots must be an object`);
	const normalized = {};

	for(const [id, slot] of Object.entries(slots))
	{
		identifier(id, `${declaring}: invalid resource slot ID ${id}`);
		object(slot, `${declaring}: resource slot ${id} must be an object`);
		const type = slot.type ?? 'url';
		const authority = slot.authority ?? 'host';
		const distribution = slot.distribution ?? 'retrievable';

		if(type !== 'url') throw new Error(`${declaring}: resource slot ${id} has unsupported type ${type}`);

		if(!['host','guest','either','world'].includes(authority))
		throw new Error(`${declaring}: resource slot ${id} has invalid authority ${authority}`);

		if(!['retrievable','local'].includes(distribution))
		throw new Error(`${declaring}: resource slot ${id} has invalid distribution ${distribution}`);

		if(slot.maxBytes !== undefined && (!Number.isSafeInteger(slot.maxBytes) || slot.maxBytes <= 0))
		throw new Error(`${declaring}: resource slot ${id}.maxBytes must be a positive safe integer`);

		const accept = stringList(slot.accept ?? [], `${declaring}: resource slot ${id}.accept must contain strings`);
		let defaultUrl;

		if(slot.defaultUrl !== undefined)
		defaultUrl = resourceReference(slot.defaultUrl, declaring);

		if(slot.integrity !== undefined && !/^sha256-[A-Za-z0-9+/]{43}=$/.test(slot.integrity))
		throw new Error(`${declaring}: resource slot ${id} has invalid integrity`);

		if(slot.bytes !== undefined && (!Number.isSafeInteger(slot.bytes) || slot.bytes < 0))
		throw new Error(`${declaring}: resource slot ${id}.bytes must be a non-negative safe integer`);

		let requirement, stack;

		if(slot.requirement !== undefined)
		requirement = normalizeRequirement(slot.requirement, {declaring, slotId: id, label: slot.label});

		if(slot.stack !== undefined)
		{
			if(requirement) throw new Error(`${declaring}: resource slot ${id} cannot declare requirement and stack`);

			const input = object(slot.stack, `${declaring}: resource slot ${id}.stack must be an object`);

			if(slot.multiple !== true)
			throw new Error(`${declaring}: resource slot ${id}.stack requires multiple resources`);

			stack = {
				first: normalizeRequirement(input.first, {
					declaring
					, slotId: id
					, label: slot.label
					, suffix: '.stack.first'
				})
				, following: normalizeRequirement(input.following, {
					declaring
					, slotId: id
					, label: slot.label
					, suffix: '.stack.following'
				})
			};
		}

		if(distribution === 'local' && !requirement && !stack)
		throw new Error(`${declaring}: local resource slot ${id} must declare a content requirement`);

		if(authority === 'world' && distribution !== 'retrievable')
		throw new Error(`${declaring}: world-owned resource slot ${id} must be retrievable`);

		if(authority === 'world' && (!defaultUrl || !slot.integrity))
		throw new Error(`${declaring}: world-owned resource slot ${id} requires defaultUrl and integrity`);

		if(authority === 'world' && slot.multiple === true)
		throw new Error(`${declaring}: world-owned resource slot ${id} cannot accept multiple resources`);

		normalized[id] = {
			...structuredClone(slot)
			, type
			, authority
			, distribution
			, required: slot.required !== false
			, multiple: slot.multiple === true
			, integrityRequired: slot.integrityRequired ?? [requirement, stack?.first, stack?.following]
				.filter(Boolean).every(value => value.equivalence !== 'profile')
			, accept
			, ...(slot.integrity ? {integrity: slot.integrity} : {})
			, ...(slot.bytes === undefined ? {} : {bytes: slot.bytes})
			, ...(requirement ? {requirement} : {})
			, ...(stack ? {stack} : {})
			, ...(defaultUrl ? {defaultUrl, declaringUrl: declaring} : {})
		};
	}

	return normalized;
}

/**
 * Resolve a data-resource reference while leaving package export references for PackageGraph.
 * @param {string} value Resource URL or @alias/export reference.
 * @param {string} declaring Canonical URL of the resource containing the value.
 * @returns {string} Canonical URL or unchanged package export reference.
 */
export function resourceReference(value, declaring)
{
	reference(value, `${declaring}: expected a resource reference`);

	return value.startsWith('@') ? value : new URL(value, declaring).href;
}

/**
 * Normalize and validate a source-format-neutral world document.
 * @param {object} value Parsed world object.
 * @param {string} declaring Canonical URL containing the object.
 * @returns {object} Validated copy with canonical direct resource URLs.
 */
export function normalizeWorld(value, declaring)
{
	object(value, `${declaring}: expected a world object`);

	if(value.type !== WORLD_TYPE || value.schemaVersion !== 1)
	throw new Error(`${declaring}: expected ${WORLD_TYPE} schemaVersion 1`);

	const imports = object(value.imports ?? {}, `${declaring}: imports must be an object`);
	const sources = object(value.sources, `${declaring}: sources must be an object`);
	const normalized = {
		...structuredClone(value)
		, imports: {}
		, sources: {}
		, init: structuredClone(object(value.init ?? {}, `${declaring}: init must be an object`))
		, resourceSlots: normalizeResourceSlots(value.resourceSlots, declaring)
		, network: normalizeNetwork(value.network, declaring)
		, bindings: []
		, transitions: []
		, sourceUrl: declaring
	};

	for(const [alias, url] of Object.entries(imports))
	{
		if(!/^[\w-]+$/.test(alias)) throw new Error(`${declaring}: invalid import alias ${alias}`);

		normalized.imports[alias] = new URL(reference(url, `${declaring}: invalid import ${alias}`), declaring).href;
	}

	reference(value.game, `${declaring}: missing game export`);

	for(const [id, source] of Object.entries(sources))
	{
		identifier(id, `${declaring}: invalid source ID ${id}`);

		object(source, `${declaring}: source ${id} must be an object`);
		reference(source.adapter, `${declaring}: source ${id} has no adapter`);
		const resources = source.resources ?? [];

		if(!Array.isArray(resources) || resources.length === 0)
		throw new Error(`${declaring}: source ${id} must declare resources`);

		normalized.sources[id] = {
			...structuredClone(source)
			, resources: resources.map((item, index) => {
				if(typeof item === 'string') item = {url: item};

				object(item, `${declaring}: invalid resource ${id}[${index}]`);

				const hasUrl = item.url !== undefined, hasSlot = item.slot !== undefined;

				if(hasUrl === hasSlot)
				throw new Error(`${declaring}: resource ${id}[${index}] must declare exactly one URL or slot`);

				if(hasSlot)
				{
					identifier(item.slot, `${declaring}: invalid resource slot in ${id}[${index}]`);

					if(!normalized.resourceSlots[item.slot])
					throw new Error(`${declaring}: resource ${id}[${index}] uses missing slot ${item.slot}`);

					if(item.integrity !== undefined)
					throw new Error(`${declaring}: slotted resource ${id}[${index}] cannot fix placement integrity`);

					return {...structuredClone(item), declaringUrl: declaring};
				}

				const url = resourceReference(item.url, declaring);

				if(item.integrity !== undefined && !/^sha256-[A-Za-z0-9+/]{43}=$/.test(item.integrity))
				throw new Error(`${declaring}: invalid integrity for ${id}[${index}]`);

				return {...structuredClone(item), url, declaringUrl: declaring};
			})
		};
	}

	for(const [id, slot] of Object.entries(normalized.resourceSlots))
	for(const requirement of [slot.requirement, slot.stack?.first, slot.stack?.following].filter(Boolean))
	for(const required of requirement.profileRequires ?? [])
	{
		const guard = normalized.resourceSlots[required];

		if(!guard) throw new Error(`${declaring}: resource slot ${id} requires missing guard slot ${required}`);

		if(required === id || [guard.requirement, guard.stack?.first, guard.stack?.following]
			.filter(Boolean).some(value => value.equivalence === 'profile')
		)
		throw new Error(`${declaring}: resource slot ${id} requires an exact guard slot`);

		for(const [sourceId, source] of Object.entries(normalized.sources))
		for(const [index, placement] of source.resources.entries())
		if(placement.slot === id
			&& !source.resources.slice(index + 1).some(resource => resource.slot === required)
		)
		throw new Error(`${declaring}: guard slot ${required} must follow resource slot ${id} in source ${sourceId}`);
	}

	if(!Array.isArray(value.bindings ?? [])) throw new Error(`${declaring}: bindings must be an array`);

	const bindingIds = new Set;

	for(const [index, binding] of (value.bindings ?? []).entries())
	{
		object(binding, `${declaring}: binding ${index} must be an object`);
		const id = reference(binding.id, `${declaring}: binding ${index} has no ID`);

		if(bindingIds.has(id)) throw new Error(`${declaring}: duplicate binding ${id}`);

		bindingIds.add(id);
		reference(binding.use, `${declaring}: binding ${id} has no system set`);

		normalized.bindings.push({
			...structuredClone(binding)
			, on: object(binding.on ?? {}, `${declaring}: binding ${id} selector must be an object`)
			, options: object(binding.options ?? {}, `${declaring}: binding ${id} options must be an object`)
			, after: [...(binding.after ?? [])]
		});
	}

	for(const binding of normalized.bindings)
	for(const dependency of binding.after)
	if(!bindingIds.has(dependency))
	throw new Error(`${declaring}: binding ${binding.id} depends on missing ${dependency}`);

	const start = object(value.start, `${declaring}: missing start scope`);

	if(!sources[start.source]) throw new Error(`${declaring}: unknown start source ${start.source}`);

	reference(start.scope, `${declaring}: missing start scope ID`);
	normalized.start = structuredClone(start);

	if(value.view !== undefined)
	{
		object(value.view, `${declaring}: view must be an object`);

		for(const key of ['width','height'])
		if(value.view[key] !== undefined && (!Number.isInteger(value.view[key]) || value.view[key] <= 0))
		throw new Error(`${declaring}: view.${key} must be a positive integer`);
	}

	if(!Array.isArray(value.transitions ?? [])) throw new Error(`${declaring}: transitions must be an array`);

	for(const [index, transition] of (value.transitions ?? []).entries())
	{
		object(transition, `${declaring}: transition ${index} must be an object`);
		const id = reference(transition.id, `${declaring}: transition ${index} has no ID`);
		const from = object(transition.from, `${declaring}: transition ${id} has no origin`);
		const to = object(transition.to, `${declaring}: transition ${id} has no destination`);

		for(const [label, endpoint] of [['origin',from],['destination',to]])
		{
			if(!sources[endpoint.source])
			throw new Error(`${declaring}: transition ${id} has unknown ${label} source ${endpoint.source}`);

			reference(endpoint.scope, `${declaring}: transition ${id} ${label} has no scope`);
		}

		normalized.transitions.push(structuredClone(transition));
	}

	return normalized;
}

/**
 * Load a launchable package manifest, neutral world, or legacy Tiled world.
 * @param {object} scope ResourceStore scope providing read().
 * @param {string|URL} input Canonical or resolvable input URL.
 * @param {string[]} [chain] Internal indirection chain for diagnostics.
 * @returns {Promise<{world: object, url: string, launchUrl: string, legacy: boolean,
 * packageUrl?: string, package?: object}>} Normalized launch resource.
 */
export async function readWorldResource(scope, input, chain = [])
{
	const url = new URL(input).href;

	if(chain.includes(url)) throw new Error(`World resource cycle: ${[...chain, url].join(' -> ')}`);

	let data;

	try
	{
		data = JSON.parse(new TextDecoder().decode(await scope.read(url)));
	}
	catch(error)
	{
		throw new Error(`${url}: ${error.message}`, {cause: error});
	}

	if(data.type === WORLD_TYPE)
	return {world: normalizeWorld(data, url), url, launchUrl: chain[0] ?? url, legacy: false};

	if(data.type === 'world')
	{
		const config = properties(data.properties);

		if(typeof config['phonics.game'] !== 'string')
		throw new Error(`${url}: expected a Tiled world with phonics.game`);

		return {world: {...data, sourceUrl: url}, url, launchUrl: chain[0] ?? url, legacy: true};
	}

	if(data.schemaVersion === 1 && typeof data.name === 'string' && typeof data.version === 'string')
	{
		if(typeof data.world === 'string')
		{
			const loaded = await readWorldResource(scope, new URL(data.world, url), [...chain, url]);

			return {...loaded, launchUrl: chain[0] ?? url, packageUrl: url, package: data};
		}

		if(data.world && typeof data.world === 'object' && !Array.isArray(data.world))
		return {
			world: normalizeWorld(data.world, url)
			, url
			, launchUrl: chain[0] ?? url
			, legacy: false
			, packageUrl: url
			, package: data
		};

		throw new Error(`${url}: package has no launchable world`);
	}

	throw new Error(`${url}: expected phonics.json, ${WORLD_TYPE}, or a legacy Tiled world`);
}
