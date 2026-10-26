#!/usr/bin/env node
import fs from 'node:fs/promises';
import path from 'node:path';
import {spawn} from 'node:child_process';
import {importPackage, validateWorld, validateValue} from '../lib/authoring.mjs';
const [command, ...args] = process.argv.slice(2);

try
{
	if(command === 'import') console.log(JSON.stringify(await importPackage(args[0], args[1])));
	else if(command === 'validate')
	{
		const world = JSON.parse(await fs.readFile(args[0], 'utf8'));
		const records = [];

		for(const file of await fs.readdir(args[1]))
			if(file.endsWith('.manifest.json'))
			records.push(JSON.parse(await fs.readFile(path.join(args[1], file), 'utf8')));
		const {resolve} = validateWorld(world, records);
		const classes = records.flatMap(r => r.manifest.tiled?.classes ?? []);

		const visit = value => {
			if(!value || typeof value !== 'object') return;

			if(value.properties)
			{
				const properties = Object.fromEntries(value.properties.map(p => [p.name, p.value]));

				if(properties['phonics.entity']) resolve(properties['phonics.entity']);

				const definition = classes.find(c => c.name === (value.class || value.type));

				for(const member of definition?.members ?? [])
					if(member.name in properties) validateValue(member, properties[member.name], args[0]);
			}

			for(const child of Object.values(value)) if(Array.isArray(child)) child.forEach(visit);
		};

		for(const entry of world.maps ?? [])
		{
			if(/^https?:/.test(entry.fileName))
			{
				const response = await fetch(entry.fileName);

				if(!response.ok) throw new Error(`HTTP ${response.status}: ${entry.fileName}`);

				visit(await response.json());
				continue;
			}

			visit(JSON.parse(await fs.readFile(path.resolve(path.dirname(args[0]), entry.fileName), 'utf8')));
		}

		console.log('World and entity properties are valid');
	}
	else if(command === 'open')
	{
		const url = new URL(args[0]);

		if(!['https:', 'http:'].includes(url.protocol)) throw new Error('Preview must use HTTP(S)');

		const executable = process.platform === 'darwin'
			? 'open'
			: process.platform === 'win32' ? 'explorer.exe' : 'xdg-open';

		const child = spawn(executable, [url.href], {stdio: 'ignore', detached: true});
		child.unref();
	}
	else throw new Error('Usage: tiled-phonics import URL CACHE | validate WORLD CACHE | open PREVIEW_URL');
}
catch(error)
{
	console.error(error.message);
	process.exitCode = 1;
}
