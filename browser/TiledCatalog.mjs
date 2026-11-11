/** Validate a portable Tiled class catalog before resolving any component code. */
export function tiledCatalog(data, source)
{
	if(data?.schemaVersion !== 1 || !Array.isArray(data.classes) || !data.bindings || Array.isArray(data.bindings))
	throw new Error(`${source}: invalid Tiled class catalog`);

	const classes = new Map();

	for(const definition of data.classes)
	{
		if(!definition?.name || classes.has(definition.name) || !Array.isArray(definition.members))
		throw new Error(`${source}: invalid or duplicate Tiled class ${definition?.name}`);

		classes.set(definition.name, definition);
	}

	const files = new Set();

	const expand = (name, overrides = {}, origin = source, chain = []) => {
		if(chain.includes(name)) throw new Error(`${source}: Tiled class cycle at ${name}`);

		const definition = classes.get(name);

		if(!definition) throw new Error(`${source}: unknown Tiled class ${name}`);

		const members = new Set();

		for(const member of definition.members)
		{
			if(!member.name || members.has(member.name))
			throw new Error(`${source}: duplicate member ${name}.${member.name}`);

			members.add(member.name);
			const overridden = Object.hasOwn(overrides, member.name);
			const value = overridden ? overrides[member.name] : member.value;
			const declaring = overridden ? origin : new URL(definition.source ?? source, source).href;

			if(member.type === 'class')
			{
				if(value != null && (typeof value !== 'object' || Array.isArray(value)))
				throw new Error(`${source}: invalid class member ${name}.${member.name}`);

				expand(member.propertyType ?? member.propertytype, value ?? {}, declaring, [...chain, name]);
				continue;
			}

			const valid = ['int', 'object'].includes(member.type)
				? Number.isInteger(value)
				: member.type === 'float'
					? typeof value === 'number' && Number.isFinite(value)
					: member.type === 'bool'
						? typeof value === 'boolean'
						: ['string', 'color', 'file'].includes(member.type) && typeof value === 'string';

			if(!valid) throw new Error(`${source}: invalid ${member.type} member ${name}.${member.name}`);

			if(member.type === 'file' && value) files.add(new URL(value, declaring).href);
		}
	};

	for(const name of classes.keys()) expand(name);

	for(const [name, reference] of Object.entries(data.bindings))
	if(!classes.has(name) || typeof reference !== 'string' || !reference)
	throw new Error(`${source}: invalid component binding ${name}`);

	return {classes, files: [...files], bindings: Object.values(data.bindings)};
}
