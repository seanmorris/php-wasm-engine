import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import {createHash} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import {root} from './files.mjs';
const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-generated-'));

const hash = async file => createHash('sha256').update(await fs.readFile(file)).digest('hex');

try
{
	for(const [name,folder,command,args,targetName = name, generatedFiles = null] of [
		['sane-phonics','examples/assets','php',['examples/generate-assets.php']]
		, ['php-tris','worlds',process.execPath,['generate.mjs']]
		, ['php-tris','assets',process.execPath,['generate.mjs'],'php-tris-assets']
		, ['php-noid','worlds',process.execPath,['generate-stages.mjs']]
		, ['php-noid'
			, 'assets'
			, 'php'
			, ['generate-font.php']
			, 'php-noid-font'
			, ['font-sdl.png','font.json','font.png','font.tsj'
				, 'hud.tsj'
			]
		]
	]){
		const source = path.join(root, 'packages', name), target = path.join(directory, targetName);
		await fs.cp(source, target, {recursive: true,dereference: true});
		execFileSync(command, args, {cwd: target,stdio: 'pipe'});
		const before = generatedFiles ?? (await fs.readdir(path.join(source, folder))).sort();
		const after = generatedFiles ?? (await fs.readdir(path.join(target, folder))).sort();

		if(JSON.stringify(before)!==JSON.stringify(after)) throw Error(`Generated file list differs in ${name}`);

		for(const file of before)
			if(await hash(path.join(source, folder, file))!==await hash(path.join(target, folder, file)))
				throw Error(`Generated asset differs: ${name}/${folder}/${file}`);
	}

	console.log('Generated package worlds and assets match their generators.');
}
finally
{
	await fs.rm(directory, {recursive: true,force: true});
}
