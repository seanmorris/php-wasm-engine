import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {chromium} from '@playwright/test';

test('GPU heights and road materials agree with PHP on cliffs, banks, intersections and world edges',async()=>{
	const cases=JSON.parse(execFileSync('php',[fileURLToPath(new URL('elevation-reference.php',import.meta.url))],{maxBuffer:16<<20}));
	const source=name=>readFileSync(new URL('../shaders/'+name,import.meta.url),'utf8');
	const browser=await chromium.launch({executablePath:process.env.CHROMIUM || '/usr/bin/chromium',args:['--use-angle=swiftshader','--enable-unsafe-swiftshader']});
	try {
		const page=await browser.newPage();
		const errors=await page.evaluate(({cases,vert,frag})=>{
			const canvas=document.createElement('canvas'); canvas.width=32; canvas.height=1;
			const gl=canvas.getContext('webgl2',{antialias:false});
			const program=gl.createProgram();
			for(const [type,text] of [[gl.VERTEX_SHADER,vert],[gl.FRAGMENT_SHADER,frag]]) {
				const shader=gl.createShader(type); gl.shaderSource(shader,text); gl.compileShader(shader);
				if(!gl.getShaderParameter(shader,gl.COMPILE_STATUS)) throw Error(gl.getShaderInfoLog(shader));
				gl.attachShader(program,shader);
			}
			gl.linkProgram(program); gl.useProgram(program);
			const loc=name=>gl.getUniformLocation(program,name);
			gl.disable(gl.DITHER); gl.bindTexture(gl.TEXTURE_2D,gl.createTexture());
			gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MIN_FILTER,gl.NEAREST);
			gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MAG_FILTER,gl.NEAREST);
			const target=gl.createTexture(), fbo=gl.createFramebuffer();
			const permutation=gl.getParameter(gl.TEXTURE_BINDING_2D);
			gl.bindTexture(gl.TEXTURE_2D,target); gl.texStorage2D(gl.TEXTURE_2D,1,gl.RGBA8,32,1);
			gl.bindFramebuffer(gl.FRAMEBUFFER,fbo); gl.framebufferTexture2D(gl.FRAMEBUFFER,gl.COLOR_ATTACHMENT0,gl.TEXTURE_2D,target,0);
			const ink=gl.createTexture();
			gl.bindTexture(gl.TEXTURE_2D,ink); gl.texStorage2D(gl.TEXTURE_2D,1,gl.RGBA8,32,1);
			gl.framebufferTexture2D(gl.FRAMEBUFFER,gl.COLOR_ATTACHMENT1,gl.TEXTURE_2D,ink,0);
			gl.drawBuffers([gl.COLOR_ATTACHMENT0,gl.COLOR_ATTACHMENT1]);
			gl.bindTexture(gl.TEXTURE_2D,permutation); gl.viewport(0,0,32,1);
			gl.uniform1i(loc('uPacked'),1); gl.uniform1f(loc('uStep'),1); gl.uniform4f(loc('uRegion'),16384,0,1,0);
			return cases.map(({recipe:r,perm,samples,atlas})=>{
				gl.uniform4f(loc('uRegion'),r.worldSize / 2,0,1,0);
				if(atlas) {
					gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D,gl.createTexture());
					gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MIN_FILTER,gl.NEAREST);
					gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MAG_FILTER,gl.NEAREST);
					const bytes=Uint8Array.from(atob(atlas.bytes), c => c.charCodeAt(0));
					gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA32F,atlas.width,atlas.height,0,gl.RGBA,gl.FLOAT,new Float32Array(bytes.buffer));
					gl.uniform1i(loc('uRoadAtlas'),1);
					gl.uniform4i(loc('uRoadGrid'),atlas.directory,atlas.blocksAcross,atlas.minTile,atlas.cell);
					gl.activeTexture(gl.TEXTURE0);
				}
				gl.texImage2D(gl.TEXTURE_2D,0,gl.R8,256,1,0,gl.RED,gl.UNSIGNED_BYTE,new Uint8Array(perm));
				for(const [name,keys] of Object.entries({uShape:['baseBias','rolling','directionalRise','ridgeHeight'],
					uProfile:['ridgeRoundness','wallHeight','floorWidth','wallWidth'],uField:['heightScale','baseFrequency','warpAmplitude','warpFrequency'],
					uRock:['secondary','terraceSpacing','terraceStrength','bedding'],uDetail:['detailAmplitude','persistence','lacunarity','damping']}))
					gl.uniform4fv(loc(name),keys.map(k=>r[k]));
				gl.uniform2f(loc('uFieldResidual'),r.baseFrequency-Math.fround(r.baseFrequency),r.warpFrequency-Math.fround(r.warpFrequency));
				const erosion = 22*r.baseFrequency;
				gl.uniform2f(loc('uErosion'),erosion,erosion-Math.fround(erosion));
				gl.uniform1f(loc('uSlopeScale'),r.slopeScale); gl.uniform1i(loc('uOctaves'),r.detailOctaves);
				let error=0;
				for(const [x,z,reference,inkRow] of samples) {
					gl.uniform2i(loc('uOriginCell'),x,z); gl.drawArrays(gl.TRIANGLES,0,3);
					const bytes=new Uint8Array(128); gl.readBuffer(gl.COLOR_ATTACHMENT0); gl.readPixels(0,0,32,1,gl.RGBA,gl.UNSIGNED_BYTE,bytes);
					const values=new Float32Array(bytes.buffer);
					reference.forEach((h,i)=>{error=Math.max(error,Math.abs(h-values[i]));});
					if(inkRow) {
						gl.readBuffer(gl.COLOR_ATTACHMENT1); gl.readPixels(0,0,32,1,gl.RGBA,gl.UNSIGNED_BYTE,bytes);
						const expected = Uint8Array.from(atob(inkRow), c => c.charCodeAt(0));
						for(let i = 0; i < 128; i += 4) {
							if(bytes[i] !== expected[i]) throw Error('Material differs at ' + [x+i/4,z]);
							const mark = row => (row[i+1]<<16)|(row[i+2]<<8)|row[i+3];
							const gpu = mark(bytes), php = mark(expected);
							const phase = Math.abs((gpu&2047)-(php&2047));
							if(Boolean(gpu)!==Boolean(php) || Math.abs((gpu>>11)-(php>>11))>1 || Math.min(phase,2048-phase)>1)
								throw Error('Road marking differs at ' + [x+i/4,z,gpu,php]);
						}
					}
				}
				return [r.seed,r.preset,r.version,error];
			});
		},{cases,vert:source('shadows.vert'),frag:source('elevation.frag')});
		assert.ok(errors.every(e=>e[3]<=.005),JSON.stringify(errors));
	} finally {await browser.close();}
});

test('surface color patterns are distinct, masked, and anchored to world coordinates', async() => {
	const vertex = readFileSync(new URL('../shaders/shadows.vert', import.meta.url), 'utf8');
	const layers = readFileSync(new URL('../shaders/surface-layers.glsl', import.meta.url), 'utf8');
	const browser = await chromium.launch({
		executablePath: process.env.CHROMIUM || '/usr/bin/chromium',
		args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
	});

	try {
		const page = await browser.newPage();
		const results = await page.evaluate(({vertex, layers}) => {
			const canvas = document.createElement('canvas');
			const width = canvas.width = 128, height = canvas.height = 64;
			const gl = canvas.getContext('webgl2', {antialias:false});
			const program = gl.createProgram();

			const fragment = `#version 300 es
				precision highp float;
				precision highp int;
				${layers}
				uniform float uShift,uHeight,uFootprint,uRise,uNormalY,uSampleStep;
				out vec4 color;
				void main() {
					vec2 xz=(gl_FragCoord.xy+vec2(uShift,0.))*uSampleStep;
					vec3 pos = vec3(xz.x, uHeight+uRise*xz.y, xz.y);
					vec3 normal = vec3(sqrt(1.-uNormalY*uNormalY), uNormalY, 0.);
					color = vec4(layerSurface(pos,normal,uFootprint,0.),1.);
				}`;
			for(const [type, source] of [[gl.VERTEX_SHADER, vertex], [gl.FRAGMENT_SHADER, fragment]]) {
				const shader = gl.createShader(type);
				gl.shaderSource(shader, source);
				gl.compileShader(shader);
				if(!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) throw Error(gl.getShaderInfoLog(shader));
				gl.attachShader(program, shader);
			}
			gl.linkProgram(program);
			if(!gl.getProgramParameter(program,gl.LINK_STATUS)) throw Error(gl.getProgramInfoLog(program));
			gl.useProgram(program);
			gl.disable(gl.DITHER);
			const location = name => gl.getUniformLocation(program, name);
			gl.uniform1i(location('uLayerCount'), 2);
			gl.uniform3fv(location('uLayerColor[0]'), [.25,.3,.22, .4,.45,.3]);
			gl.uniform3fv(location('uLayerPatternColor[0]'), [.25,.3,.22, .8,.1,.05]);
			gl.uniform4fv(location('uLayerRange[0]'), [-100,100,0,1, -10,10,0,1]);
			gl.uniform4fv(location('uLayerBlend[0]'), [1,.05,0,100, 1,.05,0,100]);
			gl.uniform4fv(location('uLayerMode[0]'), [-1,0,0,0, -1,0,0,0]);
			gl.uniform2f(location('uPaletteOffset'), 230, 410);
			gl.uniform1f(location('uNormalY'), 1);
			gl.uniform1f(location('uRise'), 0);
			gl.uniform1f(location('uSampleStep'), 1);

			function sample(kind, shift = 0, elevation = 0, footprint = .1, amount = 1, scale = 12, stretch = 4) {
				gl.uniform1f(location('uShift'), shift);
				gl.uniform1f(location('uHeight'), elevation);
				gl.uniform1f(location('uFootprint'), footprint);
				gl.uniform4fv(location('uLayerPattern[0]'), [0,0,20,4, kind,amount,scale,stretch]);
				gl.drawArrays(gl.TRIANGLES,0,3);
				const data = new Uint8Array(width*height*4);
				gl.readPixels(0,0,width,height,gl.RGBA,gl.UNSIGNED_BYTE,data);
				return data.filter((_,i) => i%4===0);
			}

			const solid = sample(0);
			const patterned = [1,2,3].map(kind => sample(kind));
			const moved = sample(2, 7);
			const sampleOutside = sample(0, 0, 30);
			const masked = sample(2,0,30).every((value,i) => value === sampleOutside[i]);
			const filtered = sample(3,0,0,20).every((value,i) => value === solid[i]);
			const zero = sample(1,0,0,.1,0).every((value,i) => value === solid[i]);

			// Render the same half-meter patch at each filter width. Single-scale dots give
			// identical images here, even across a dot's edge.
			gl.uniform1f(location('uSampleStep'), 1/256);
			const stipple = (footprint, shift = 0) => sample(5,shift,0,footprint,1,1.6);
			const widths = [.045,.016,.005,.0018];
			const closeups = widths.map(footprint => stipple(footprint));
			const bandsVisible = closeups.slice(1).map((pixels, index) =>
				pixels.filter((value, i) => Math.abs(value-closeups[index][i]) > 1).length > .08*pixels.length);
			const near = closeups.at(-1);
			const stableGrain = stipple(widths.at(-1),7).every((value,i) => i%width >= width-7 || value === near[i+7]);
			const distantGrain = stipple(.5).every((value,i) => value === solid[i]);
			gl.uniform1f(location('uSampleStep'), 1);

			gl.uniform1f(location('uNormalY'), 0);
			gl.uniform1f(location('uRise'), .125);
			const wallStreaks = sample(2), wallWithPartings = sample(4);
			const resolvedPartings = wallWithPartings.filter((value,i) => value - wallStreaks[i] > 3).length > width*height*.01;

			// Look across several exposed flow tops. A fixed interval would reproduce the same
			// marks when the face moves vertically by one mean spacing (32 m × .375 = 12 m).
			gl.uniform4fv(location('uLayerRange[0]'), [-1000,1000,0,1, -1000,1000,0,1]);
			gl.uniform1f(location('uSampleStep'), 2);
			gl.uniform1f(location('uRise'), 1);
			const contacts = (shift, elevation, footprint) => sample(10,shift,elevation,footprint,1,32,.375);
			const blank = sample(0), face = contacts(0,0,.2), nextBed = contacts(0,12,.2);
			const marks = face.filter((v,i) => v > blank[i]+4).length;
			const sparseContacts = marks > .005*face.length && marks < .2*face.length;
			const irregularContacts = face.filter((v,i) => v > blank[i]+4 && nextBed[i] <= blank[i]+4).length > marks*.5;
			const anchoredContacts = contacts(7,0,.2).every((v,i) => i%width >= width-7 || v === face[i+7]);
			const distantContacts = contacts(0,0,12).every((v,i) => v === blank[i]);
			gl.uniform1f(location('uSampleStep'), 1);
			gl.uniform1f(location('uRise'), .55);
			// Each vertical traverse covers nearly three mean flow thicknesses. Some
			// traverses expose the weathered tops, while adjacent rock can bury every one.
			const interruptedContacts = [0,48,-48].some(elevation => {
				const columns = new Uint8Array(width);
				contacts(0,elevation,.2).forEach((v,i) => { if(v > blank[i]+4) columns[i%width]++; });
				return columns.includes(0) && columns.some(count => count >= 2);
			});
			gl.uniform1f(location('uRise'), 0);

			gl.uniform4fv(location('uLayerRange[0]'), [-100,100,0,1, 0,8,.2,1]);
			gl.uniform4fv(location('uLayerBlend[0]'), [1,.05,0,100, 1,.1,0,100]);
			gl.uniform4fv(location('uLayerMode[0]'), [-1,0,0,0, -1,0,20,0]);
			gl.uniform1f(location('uNormalY'), 0);
			const contactLeft = sample(0,0,-10.05)[0], contactRight = sample(0,0,-9.95)[0];
			const cyclic = Math.abs(contactLeft-contactRight) < 5 && sample(0,0,10.05)[0] === contactRight;
			const bed = sample(0,0,-6)[0], outsideBed = sample(0,0,2)[0];
			gl.uniform1f(location('uNormalY'), 1);
			gl.uniform1f(location('uRise'), 20);
			const filteredMask = sample(0,0,-6).every(value => value === outsideBed);

			gl.uniform1i(location('uLayerCount'), 3);
			gl.uniform3fv(location('uLayerColor[0]'), [.25,.3,.22, .45,.2,.1, .45,.2,.1]);
			gl.uniform4fv(location('uLayerRange[0]'), [-100,100,0,1, 0,10,.2,1, 10,20,.2,1]);
			gl.uniform4fv(location('uLayerBlend[0]'), [1,.05,0,100, 1,.1,0,100, 1,.1,0,100]);
			gl.uniform4fv(location('uLayerMode[0]'), [-1,0,0,0, -1,0,20,1, -1,0,20,2]);
			gl.uniform1f(location('uNormalY'), 0);
			gl.uniform1f(location('uRise'), .5);
			const section = sample(0,0,-10);
			const noFalseContacts = new Set(section).size === 1 && section[0] > outsideBed;

			return {
				visible: patterned.map(pixels => new Set(pixels).size > 5),
				unique: new Set(patterned.map(pixels => pixels.join(','))).size,
				anchored: moved.every((value,i) => i%width >= width-7 || value === patterned[1][i+7]),
				bandsVisible, stableGrain, distantGrain, masked, filtered, zero, cyclic, filteredMask, resolvedPartings, noFalseContacts,
				sparseContacts, irregularContacts, interruptedContacts, anchoredContacts, distantContacts,
				distinctBed: bed > outsideBed,
			};
		},{vertex,layers});
		assert.deepEqual(results, {
			visible:[true,true,true], unique:3, anchored:true, bandsVisible:[true,true,true], stableGrain:true, distantGrain:true,
			masked:true, filtered:true, zero:true,
			cyclic:true, filteredMask:true, distinctBed:true, resolvedPartings:true, noFalseContacts:true,
			sparseContacts:true, irregularContacts:true, interruptedContacts:true, anchoredContacts:true, distantContacts:true,
		});
	} finally {
		await browser.close();
	}
});

test('advancing the clipmap grid exchanges matching height, normals, paint and road markings at its boundary', async() => {
	const grid = readFileSync(new URL('../shaders/clip-grid.glsl', import.meta.url), 'utf8');
	const terrain = readFileSync(new URL('../shaders/terrain.vert',import.meta.url),'utf8')
		.replace('// PATCH','#define CLIPMAP\n'+grid);
	const browser = await chromium.launch({
		executablePath: process.env.CHROMIUM || '/usr/bin/chromium',
		args: ['--use-angle=swiftshader','--enable-unsafe-swiftshader'],
	});
	try {
		const page = await browser.newPage();
		const result = await page.evaluate(terrain => {
			const canvas = document.createElement('canvas');
			canvas.width = canvas.height = 136;
			const gl = canvas.getContext('webgl2', {antialias:false});
			const program = gl.createProgram();
			const shaders = [
				[gl.VERTEX_SHADER,terrain],
				[gl.FRAGMENT_SHADER,`#version 300 es
					precision highp float;
					uniform bool uTestInk;
					in vec3 vWorld,vPaint0,vPaint1;
					in vec4 vRoadMark;
					in vec3 vNormal;
					out vec4 color;
					void main() {
						color = uTestInk ? vec4(vPaint0.y,vPaint1.y,.5+.5*vRoadMark.y,vRoadMark.w)
							: vec4(.5+.05*vWorld.y,.5+.5*normalize(vNormal).xz,1.);
					}`],
			];
			for(const [type, text] of shaders) {
				const shader = gl.createShader(type);
				gl.shaderSource(shader, text);
				gl.compileShader(shader);
				if(!gl.getShaderParameter(shader,gl.COMPILE_STATUS)) throw Error(gl.getShaderInfoLog(shader));
				gl.attachShader(program,shader);
			}
			gl.linkProgram(program);
			if(!gl.getProgramParameter(program,gl.LINK_STATUS)) throw Error(gl.getProgramInfoLog(program));
			gl.useProgram(program);
			gl.disable(gl.DITHER);
			const loc = name => gl.getUniformLocation(program,name);
			gl.uniformMatrix4fv(loc('uViewProjection'),false,[1/17,0,0,0, 0,0,0,0, 0,1/17,0,0, 0,0,0,1]);
			gl.uniform1i(loc('uElevations'),0);
			gl.uniform1i(loc('uInk'),1);
			gl.uniform1f(loc('uMorphWidth'),2.4);
			gl.uniform1i(loc('uTextureMask'),31);

			function texture(step) {
				const size = 32, heights = new Float32Array(size*size), inks = new Uint8Array(4*size*size);
				for(let z=-14;z<18;++z)
				for(let x=-14;x<18;++x) {
					const wx=x*step, wz=z*step;
					const i=(z&31)*size+(x&31);
					const type=(wx+wz)&3;
					inks[4*i]=[0,2,5,125][type];
					if(type>=2) {
						inks[4*i+1]=128;
						inks[4*i+2]=(wx+wz)&7;
						inks[4*i+3]=57;
					}
					heights[i] = 3*Math.sin(wx*.9+wz*.47)+2.5*Math.cos(wz*.86-wx*.53);
				}
				const texture=gl.createTexture();
				gl.bindTexture(gl.TEXTURE_2D,texture);
				gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MIN_FILTER,gl.NEAREST);
				gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MAG_FILTER,gl.NEAREST);
				gl.texImage2D(gl.TEXTURE_2D,0,gl.R32F,size,size,0,gl.RED,gl.FLOAT,heights);
				const ink=gl.createTexture();
				gl.bindTexture(gl.TEXTURE_2D,ink);
				gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MIN_FILTER,gl.NEAREST);
				gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MAG_FILTER,gl.NEAREST);
				gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA8,size,size,0,gl.RGBA,gl.UNSIGNED_BYTE,inks);
				return [texture,ink];
			}

			const coarse = texture(2), fine = texture(1);
			gl.activeTexture(gl.TEXTURE2);
			gl.bindTexture(gl.TEXTURE_2D,gl.createTexture());
			gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MIN_FILTER,gl.NEAREST);
			gl.texImage2D(gl.TEXTURE_2D,0,gl.R8,1,1,0,gl.RED,gl.UNSIGNED_BYTE,new Uint8Array([255]));
			gl.uniform1i(loc('uRoadsideMask'),2);
			gl.uniform3f(loc('uParentSafe'),0,0,40);
			let pad = 0;
			function draw(gridTexture, step, minX, minZ, span, hasParent) {
				gl.activeTexture(gl.TEXTURE0);
				gl.bindTexture(gl.TEXTURE_2D,gridTexture[0]);
				gl.activeTexture(gl.TEXTURE1);
				gl.bindTexture(gl.TEXTURE_2D,gridTexture[1]);
				gl.uniform1f(loc('uGridStep'),step);
				gl.uniform2f(loc('uEyeCell'),2/step,2/step);
				gl.uniform1i(loc('uHasParent'),hasParent);
				gl.uniform2i(loc('uRingMin'),minX,minZ);
				gl.uniform2i(loc('uDrawCell'),minX,minZ);
				gl.uniform1f(loc('uRoadsidePad'),hasParent ? pad : 0);
				gl.uniform2i(loc('uRingMax'),minX+span,minZ+span);
				gl.uniform1i(loc('uSpan'),hasParent ? span-2*pad : span);
				gl.drawArraysInstanced(gl.TRIANGLE_STRIP,0,2*(span+1),span);
			}

			function capture(fineX, fineZ) {
				gl.clear(gl.COLOR_BUFFER_BIT);
				draw(coarse,2,-10,-10,20,false);
				if(fineX !== null) draw(fine,1,fineX,fineZ,24,true);
				const samples = new Uint8Array(canvas.width*canvas.height*4);
				gl.readPixels(0,0,canvas.width,canvas.height,gl.RGBA,gl.UNSIGNED_BYTE,samples);
				return samples;
			}

			// The same eye can exchange the X edge, the Z edge, or both at this point.
			return [[false,0],[true,0],[false,4],[true,4]].map(([ink,extra]) => {
				pad = extra;
				gl.uniform1i(loc('uTestInk'),Number(ink));
				const parent = capture(null), before = capture(-12,-12);
				const moved = [capture(-10,-12), capture(-12,-10), capture(-10,-10)];
				let detailed = 0, largest = 0;
				for(let i = 0; i < before.length; i++) {
					if(Math.abs(before[i]-parent[i]) > 8) detailed++;
					for(const after of moved) largest = Math.max(largest, Math.abs(before[i]-after[i]));
				}
				return {ink,pad,detailed,largest};
			});
		},terrain);
		for(const sample of result) {
			// The same interpolant can round differently on smaller triangles.
			assert.ok(sample.largest<=2,JSON.stringify(sample));
			assert.ok(sample.detailed>100,JSON.stringify(sample));
		}
	} finally {
		await browser.close();
	}
});

test('steep roadside vertices retain their fine height and rejoin the parent continuously', async() => {
	const grid = readFileSync(new URL('../shaders/clip-grid.glsl', import.meta.url), 'utf8');
	const browser = await chromium.launch({
		executablePath: process.env.CHROMIUM || '/usr/bin/chromium',
		args: ['--use-angle=swiftshader','--enable-unsafe-swiftshader'],
	});
	try {
		const page = await browser.newPage();
		const samples = await page.evaluate(grid => {
			const gl = document.createElement('canvas').getContext('webgl2');
			const program = gl.createProgram();
			for(const [type, source] of [
				[gl.VERTEX_SHADER, `#version 300 es
					precision highp float;
					precision highp int;
					${grid}
					uniform ivec2 sampleCell;
					out vec3 measured;
					void main(){
						ivec2 c=clipStitchedCell(sampleCell);
						float m=clipMorph(c);
						measured=vec3(m,clipHeight(c,m),float(c.y));
						gl_Position=vec4(0,0,0,1);
					}`],
				[gl.FRAGMENT_SHADER,'#version 300 es\nprecision highp float; out vec4 color; void main(){color=vec4(0);}'],
			]) {
				const shader = gl.createShader(type);
				gl.shaderSource(shader,source);
				gl.compileShader(shader);
				if(!gl.getShaderParameter(shader,gl.COMPILE_STATUS)) throw Error(gl.getShaderInfoLog(shader));
				gl.attachShader(program,shader);
			}
			gl.transformFeedbackVaryings(program,['measured'],gl.INTERLEAVED_ATTRIBS);
			gl.linkProgram(program);
			if(!gl.getProgramParameter(program,gl.LINK_STATUS)) throw Error(gl.getProgramInfoLog(program));
			gl.useProgram(program);
			const loc = name => gl.getUniformLocation(program,name);
			function texture(unit, size, values) {
				gl.activeTexture(gl.TEXTURE0+unit);
				gl.bindTexture(gl.TEXTURE_2D,gl.createTexture());
				gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MIN_FILTER,gl.NEAREST);
				gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MAG_FILTER,gl.NEAREST);
				gl.texImage2D(gl.TEXTURE_2D,0,gl.R32F,size,size,0,gl.RED,gl.FLOAT,values);
			}
			// Odd columns sit a meter above the parent interpolation. Row 16 is flat.
			const heights = new Float32Array(128*128);
			for(let z=-64; z<64; z++) for(let x=-64; x<64; x++)
				heights[(z&127)*128+(x&127)] = z<10 ? x+(x&1) : 0;
			texture(0,128,heights);
			texture(1,1,new Float32Array([1]));
			texture(2,1,new Float32Array([0]));
			gl.uniform1i(loc('uElevations'),0);
			gl.uniform1i(loc('uRoadsideMask'),1);
			gl.uniform1i(loc('uTextureMask'),127);
			gl.uniform1i(loc('uHasParent'),1);
			gl.uniform1i(loc('uSpan'),76);
			gl.uniform1f(loc('uMorphWidth'),7.6);
			gl.uniform1f(loc('uGridStep'),1);
			gl.uniform2i(loc('uRingMin'),-60,-60);
			gl.uniform2i(loc('uRingMax'),60,60);
			gl.uniform3f(loc('uParentSafe'),0,0,90);
			const out = gl.createBuffer();
			gl.bindBuffer(gl.TRANSFORM_FEEDBACK_BUFFER,out);
			gl.bufferData(gl.TRANSFORM_FEEDBACK_BUFFER,12,gl.STREAM_READ);
			gl.bindBufferBase(gl.TRANSFORM_FEEDBACK_BUFFER,0,out);
			gl.enable(gl.RASTERIZER_DISCARD);
			function sample(x,z) {
				gl.uniform2i(loc('sampleCell'),x,z);
				gl.beginTransformFeedback(gl.POINTS);
				gl.drawArrays(gl.POINTS,0,1);
				gl.endTransformFeedback();
				const data = new Float32Array(3);
				gl.getBufferSubData(gl.TRANSFORM_FEEDBACK_BUFFER,0,data);
				return Array.from(data);
			}
			const original = sample(35,0);
			gl.uniform1f(loc('uRoadsidePad'),22);
			const bank = sample(35,0), flat = sample(35,16), outer = sample(57,0);
			const gradient = [35,39,43,47,51,55,57].map(x=>sample(x,0)[0]);
			const join = sample(60,3);
			gl.uniform1i(loc('uRoadsideMask'),2);
			const offRoad = sample(35,0);
			gl.uniform1i(loc('uRoadsideMask'),1);
			gl.uniform3f(loc('uParentSafe'),-20,0,56);
			const parentTransition = sample(35,0);
			return {original,bank,flat,outer,gradient,join,offRoad,parentTransition,error:gl.getError()};
		},grid);
		assert.equal(samples.error,0);
		assert.deepEqual(samples.original.slice(0,2),[1,35]);
		assert.deepEqual(samples.bank.slice(0,2),[0,36]);
		assert.deepEqual(samples.offRoad,samples.original);
		assert.equal(samples.flat[0],1);
		assert.equal(samples.parentTransition[0],1);
		assert.deepEqual(samples.outer.slice(0,2),[1,57]);
		assert.equal(samples.join[2],2);
		assert.ok(samples.gradient.every((v,i,a)=>i===0 || v>=a[i-1]));
		assert.ok(samples.gradient.filter(v=>v>0 && v<1).length>=3);
	} finally {
		await browser.close();
	}
});
