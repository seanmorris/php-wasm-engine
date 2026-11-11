/** Portable Tiled project classes. This module is shared by the editor and packaging CLI. */
export function classCatalog(definitions, records = [], previousBindings = {})
{
	const classes = definitions.filter(value => !value.type || value.type === 'class').map(value => Object.assign({
		name: value.name
		, members: (value.members || []).map(member => Object.assign({}, member))
	}, value.source ? {source: value.source} : {}
	));

	const byName = new Map();

	for(const definition of classes)
	{
		if(byName.has(definition.name)) throw new Error(`Duplicate Tiled class ${definition.name}`);

		byName.set(definition.name, definition);
	}

	const visit = (name, chain = []) => {
		if(chain.includes(name)) throw new Error(`Tiled class cycle: ${[...chain, name].join(' -> ')}`);

		const definition = byName.get(name);

		if(!definition) throw new Error(`Unknown Tiled class ${name}`);

		const members = new Set();

		for(const member of definition.members)
		{
			if(members.has(member.name)) throw new Error(`Duplicate member ${name}.${member.name}`);

			members.add(member.name);

			if(member.type === 'class') visit(member.propertyType || member.propertytype, [...chain, name]);
		}
	};

	for(const name of byName.keys()) visit(name);
	const bindings = {};

	for(const record of records)
	for(const [name, entry] of Object.entries(record.manifest.exports || {}))
	{
		if(entry.type !== 'component' || !entry.class || !byName.has(entry.class)) continue;

		const reference = record.source + '#' + name;

		if(bindings[entry.class] && bindings[entry.class] !== reference)
		throw new Error(`Conflicting component ${entry.class}`);

		bindings[entry.class] = reference;
	}

	// Local projects can ship explicit bindings without an imported-package cache.
	// Keep those bindings when saving defaults; cached exports supersede older references.
	for(const [name, reference] of Object.entries(previousBindings))
	{
		if(!byName.has(name) || bindings[name]) continue;

		if(typeof reference !== 'string' || !reference) throw new Error(`Invalid component binding ${name}`);

		bindings[name] = reference;
	}

	return {schemaVersion: 1, classes, bindings};
}
