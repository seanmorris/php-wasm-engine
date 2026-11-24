import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync, readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {chromium} from '@playwright/test';

// Run directly with: node --test packages/terrain-phonics/tests/gpu.test.mjs
// Requires PHP and Chromium (CHROMIUM overrides the executable; otherwise system or Playwright Chromium).
test('terrain WebGL2 caches and analytic water agree with their numeric references', {timeout: 60_000}, async () => {
	const fixture = fileURLToPath(new URL('./gpu-fixture.php', import.meta.url));
	const data = JSON.parse(execFileSync('php', [fixture], {encoding: 'utf8'}));

	const names = ['atmosphere-cache.vert'
		, 'atmosphere-cache.frag'
		, 'atmosphere-aerial.frag'
		, 'atmosphere.glsl'
		, 'patch-grid.glsl'
		, 'water-functions.glsl'
		, 'water-optics.glsl'
		, 'spot-lights.glsl'
		, 'shadows.vert'
		, 'shadows.frag'
		, 'triangle-shadows.frag'

		, 'stars.vert', 'stars.frag'
		, 'sky.vert'
		, 'sky.frag'
		, 'water.vert'
		, 'water.frag'
		, 'terrain.vert'
		, 'joined-material.glsl'
		, 'terrain.frag'
		, 'material-noise.glsl'
		, 'material-fields.glsl'
		, 'material-cache.frag'
		, 'surface-layers.glsl'
		, 'surface-samples.glsl'
	];

	const shaders = Object.fromEntries(names.map(name => [name
		, readFileSync(new URL(`../shaders/${name}`, import.meta.url), 'utf8')
	]));
	shaders['terrain.frag'] = shaders['terrain.frag'].replace('// REGIONS', shaders['joined-material.glsl']).replace('// LAYERS', shaders['surface-layers.glsl'])
		.replace('// MATERIAL NOISE', shaders['material-noise.glsl'])
		.replace('// MATERIAL STACK',readFileSync(new URL('./uniform-material.glsl',import.meta.url),'utf8')
			.replace('// RELIEF FIELDS',shaders['material-fields.glsl']));

	const browser = await chromium.launch({
		executablePath: process.env.CHROMIUM ?? (existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined)
		, headless: true
		, args: ['--no-sandbox', '--use-angle=swiftshader', '--enable-unsafe-swiftshader']
	});

	try
	{
		const page = await browser.newPage();

		const result = await page.evaluate(({data, shaders}) => {
			const canvas = document.createElement('canvas');
			canvas.width = 256;
			canvas.height = 128;
			const gl = canvas.getContext('webgl2', {antialias: false, preserveDrawingBuffer: true});

			if(!gl) throw new Error('WebGL2 is unavailable');

			const includes = [
				['// PATCH', 'patch-grid']
				, ['// OPTICS', 'water-optics']
				, ['// SPOT LIGHTS', 'spot-lights']
			];

			const compile = (type, source) => {
				const shader = gl.createShader(type);
				let text = source;

				for(const [marker, name] of includes) text = text.replace(marker, shaders[`${name}.glsl`]);

				gl.shaderSource(shader, text);
				gl.compileShader(shader);

				if(!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) throw new Error(gl.getShaderInfoLog(shader));

				return shader;
			};

			const program = (vertex, fragment, varyings = null) => {
				const linked = gl.createProgram();
				const stages = [compile(gl.VERTEX_SHADER, vertex), compile(gl.FRAGMENT_SHADER, fragment)];

				for(const stage of stages) gl.attachShader(linked, stage);

				if(varyings) gl.transformFeedbackVaryings(linked, varyings, gl.INTERLEAVED_ATTRIBS);

				gl.linkProgram(linked);

				for(const stage of stages) gl.deleteShader(stage);

				if(!gl.getProgramParameter(linked, gl.LINK_STATUS)) throw new Error(gl.getProgramInfoLog(linked));

				return linked;
			};

			const parameters = linked => {
				gl.useProgram(linked);

				for(const [key, value] of Object.entries(data.uniforms))
				{
					const location = gl.getUniformLocation(linked, `u${key[0].toUpperCase()}${key.slice(1)}`);

					if(Array.isArray(value))
					{
						if(value.length === 3) gl.uniform3fv(location, value);
						else if(value.length === 2) gl.uniform2fv(location, value);
					}
					else gl.uniform1f(location, value);
				}

				gl.uniform1i(gl.getUniformLocation(linked, 'uTransmittance'), 0);
				gl.uniform4f(gl.getUniformLocation(linked, 'uWaterOptics'), .85, .85, .035, .65);
				gl.uniform3f(gl.getUniformLocation(linked, 'uWaterTint'), .02, .17, .18);
			};

			const appearance = (linked, uniforms) => {
				const ink=['uRockColor','uGrassColor','uSandColor','uSnowColor','uRoadColor'].flatMap(key=>uniforms[key]);
				gl.uniform3fv(gl.getUniformLocation(linked,'uPaintColor[0]'),ink);
				for(const [name, values] of Object.entries(uniforms))
				{
					const location = gl.getUniformLocation(linked, name);

					// Only the canyon and mountain palettes are longer than a vec4; both are arrays of vec3.
					if(values.length === 1) gl.uniform1f(location, values[0]);
					else gl[`uniform${values.length > 4 ? 3 : values.length}fv`](location, values);
				}
			};

			const stackUniforms = (linked, inputs) => {
				for(const [name,values] of Object.entries(inputs)) {
					const loc=gl.getUniformLocation(linked,name);
					if(name==='uLayerCount') gl.uniform1i(loc,values[0]);
					else if(values.length===1) gl.uniform1f(loc,values[0]);
					else {
						const width=name.endsWith('[0]') ? (name.includes('Color') ? 3 : 4) : values.length;
						gl[`uniform${width}fv`](loc,values);
					}
				}
			};

			const texture = (unit, width, height, bytes, filter = gl.NEAREST) => {
				const created = gl.createTexture();
				gl.activeTexture(gl.TEXTURE0 + unit);
				gl.bindTexture(gl.TEXTURE_2D, created);
				gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, filter);
				gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, filter);
				gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
				gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
				gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA8, width, height, 0, gl.RGBA, gl.UNSIGNED_BYTE, bytes);

				return created;
			};

			const framebuffer = gl.createFramebuffer();

			const target = (unit, width, height, filter = gl.NEAREST) => {
				const created = texture(unit, width, height, null, filter);
				gl.bindFramebuffer(gl.FRAMEBUFFER, framebuffer);
				gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, created, 0);

				if(gl.checkFramebufferStatus(gl.FRAMEBUFFER) !== gl.FRAMEBUFFER_COMPLETE)
				throw new Error('RGBA8 cache framebuffer is incomplete');

				gl.viewport(0, 0, width, height);
			};

			const pixel = (channels = 3) => {
				gl.viewport(0, 0, 1, 1);
				gl.drawArrays(gl.TRIANGLES, 0, 3);
				const bytes = new Uint8Array(4);
				gl.readPixels(0, 0, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, bytes);

				return Array.from(bytes).slice(0,channels);
			};

			const vertex = shaders['atmosphere-cache.vert'];

			const integrate = source => source.replace('// ATMOSPHERE', shaders['atmosphere.glsl']);
			const header = '#version 300 es\nprecision highp float;\nprecision highp int;\n';
			gl.bindVertexArray(gl.createVertexArray());
			texture(0, 64, 16, Uint8Array.from(atob(data.lut), c => c.charCodeAt(0)));
			const skyCache = program(vertex, integrate(shaders['atmosphere-cache.frag']));

			for(const [unit, altitude] of [[2, 100], [3, 0]])
			{
				target(unit, 256, 128);
				parameters(skyCache);
				gl.uniform2f(gl.getUniformLocation(skyCache, 'uSkyCacheSize'), 256, 128);
				gl.uniform1f(gl.getUniformLocation(skyCache, 'uSkyCacheAltitude'), altitude);
				gl.drawArrays(gl.TRIANGLES, 0, 3);
			}

			gl.bindFramebuffer(gl.FRAMEBUFFER, null);

			// Neutral incident irradiance makes RGB scattering and extinction independently measurable.
			const bands = program(vertex, `${header}${shaders['atmosphere.glsl']}
				uniform float uTestDistance, uTestEye, uTestUp; out vec4 color;
				void main() {
					vec3 t, light;
					terrainIntegrateAtmosphere(vec3(0.,uTestUp,0.),uTestEye,uTestDistance,true,t,light);
					color = vec4(t.r,light.r,light.b,1.);
				}`);
			parameters(bands);
			texture(7,1,1,new Uint8Array([255,255,255,255]));
			const at = key => gl.getUniformLocation(bands,key);
			gl.uniform1i(at('uTransmittance'),7);
			gl.uniform2f(at('uTransmittanceSize'),1,1);
			gl.uniform3f(at('uRayleigh'),0,0,0);
			gl.uniform1f(at('uMie'),0);
			gl.uniform1f(at('uSunIntensity'),4*Math.PI);
			gl.uniform1f(at('uAirLayerCount'),2);
			gl.uniform3f(at('uAirBand[0]'),100,120,.005);
			gl.uniform3f(at('uAirBand[1]'),105,130,.002);
			gl.uniform3f(at('uAirColor[0]'),1,0,0);
			gl.uniform3f(at('uAirColor[1]'),0,0,1);
			// An overhead sun keeps the reference simple; sunlight reaching each sample is dimmed by the layers above it.
			gl.uniform3f(at('uSunDirection'),0,1,0);
			const bandSamples = [];
			for(const [eye,up,distance] of [[0,1,50],[0,1,110],[0,1,10000],[200,-1,200]]) {
				gl.uniform1f(at('uTestEye'),eye);
				gl.uniform1f(at('uTestUp'),up);
				gl.uniform1f(at('uTestDistance'),distance);
				bandSamples.push(pixel());
			}

			// Sunlight through a thin authored layer, with the real clear-air texture: the layer is too thin for the
			// texture's altitude rows, so the shaders must add it exactly.
			const thin = program(vertex, `${header}${shaders['atmosphere.glsl']}
				uniform float uTestAltitude, uTestMu; out vec4 color;
				void main() { color = vec4(terrainTransmittance(uTestAltitude, uTestMu), 1.); }`);
			parameters(thin);
			gl.uniform1f(gl.getUniformLocation(thin,'uAirLayerCount'),1);
			gl.uniform3fv(gl.getUniformLocation(thin,'uAirBand[0]'),data.thinLayer.band);
			const thinLayerSamples = data.thinLayer.samples.map(([altitude, mu]) => {
				gl.uniform1f(gl.getUniformLocation(thin,'uTestAltitude'),altitude);
				gl.uniform1f(gl.getUniformLocation(thin,'uTestMu'),mu);
				return pixel();
			});

			const skySample = program(vertex, `${header}${data.source}
				uniform vec3 uTestRay; uniform float uTestAltitude; out vec4 color;
				void main() { color = vec4(terrainToneMap(skyRadiance(uTestRay, uTestAltitude)), 1.0); }`
			);

			parameters(skySample);
			gl.uniform1i(gl.getUniformLocation(skySample, 'uSkyCacheEye'), 2);
			gl.uniform1i(gl.getUniformLocation(skySample, 'uSkyCacheWater'), 3);
			gl.uniform2f(gl.getUniformLocation(skySample, 'uSkyCacheSize'), 256, 128);
			gl.uniform2f(gl.getUniformLocation(skySample, 'uSkyCacheAltitudes'), 100, 0);
			const skyResults = [];

			for(const altitude of [0, 100])
			for(const ray of [[0, 1, 0]
				, [1, .5, 0]
				, [-1, .2, 1]
				, [1, .03, 0]
				, [-1, .01, 1]
				, data.uniforms
				.sunDirection
			]){
				const colors = [];

				for(const ready of [0, 1])
				{
					gl.uniform1i(gl.getUniformLocation(skySample, 'uSkyCacheReady'), ready);
					gl.uniform3fv(gl.getUniformLocation(skySample, 'uTestRay'), ray);
					gl.uniform1f(gl.getUniformLocation(skySample, 'uTestAltitude'), altitude);
					colors.push(pixel());
				}

				skyResults.push({altitude, ray, colors});
			}

			const aerialCache = program(vertex, integrate(shaders['atmosphere-aerial.frag']));

			const buildAerialCache = (altitude, band) => {
				target(4, 512, 17, gl.LINEAR);
				const radiance = texture(5, 512, 17, null, gl.LINEAR);
				gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT1, gl.TEXTURE_2D, radiance, 0);
				gl.drawBuffers([gl.COLOR_ATTACHMENT0, gl.COLOR_ATTACHMENT1]);
				parameters(aerialCache);
				if(band) {
					gl.uniform1f(gl.getUniformLocation(aerialCache,'uAirLayerCount'),1);
					gl.uniform3fv(gl.getUniformLocation(aerialCache,'uAirBand[0]'),band);
					gl.uniform3f(gl.getUniformLocation(aerialCache,'uAirColor[0]'),.7,.55,.4);
				}
				gl.uniform3f(gl.getUniformLocation(aerialCache, 'uAerialCacheGrid'), 32, 16, 16);
				gl.uniform1f(gl.getUniformLocation(aerialCache, 'uAerialCacheMaxDistance'), 90000);
				gl.uniform1f(gl.getUniformLocation(aerialCache, 'uAerialCacheAltitude'), altitude);
				gl.drawArrays(gl.TRIANGLES, 0, 3);
				gl.drawBuffers([gl.COLOR_ATTACHMENT0]);
				gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT1, gl.TEXTURE_2D, null, 0);

				gl.bindFramebuffer(gl.FRAMEBUFFER, null);
			};
			buildAerialCache(100);

			const solar = program(vertex,header+data.source+`
				uniform float altitude; uniform bool useTable; out vec4 color;
				void main() {
					vec3 direct=useTable ? terrainCachedSunlight(altitude) : terrainSunlight(altitude);
					color=vec4(terrainToneMap(direct*.15),1.);
				}`);
			parameters(solar);
			gl.uniform1i(gl.getUniformLocation(solar,'uAerialCacheTransmittance'),4);
			gl.uniform1i(gl.getUniformLocation(solar,'uAerialCacheRadiance'),5);
			gl.uniform3f(gl.getUniformLocation(solar,'uAerialCacheGrid'),32,16,16);
			gl.uniform1f(gl.getUniformLocation(solar,'uAerialCacheAltitude'),100);
			gl.uniform1i(gl.getUniformLocation(solar,'uAerialCacheReady'),1);
			for(const height of [-157,-155.75,-4,.5,76.25,100,349.99,355.5,5200]) {
				gl.uniform1f(gl.getUniformLocation(solar,'altitude'),height);
				gl.uniform1i(gl.getUniformLocation(solar,'useTable'),0);
				const direct=pixel();
				gl.uniform1i(gl.getUniformLocation(solar,'useTable'),1);
				if(pixel().some((v,i)=>Math.abs(v-direct[i])>1))
					throw Error('Solar table differs from the analytic light at '+height);
			}

			const aerialSample = program(vertex, `${header}${data.source}
				uniform vec3 uTestRay; uniform float uTestAltitude; uniform float uTestDistance; out vec4 color;
				void main() { color = vec4(terrainToneMap(terrainAtmosphere(uTestRay, uTestAltitude,
					uTestDistance, vec3(.15, .3, .1))), 1.0); }`
			);

			parameters(aerialSample);
			gl.uniform1i(gl.getUniformLocation(aerialSample, 'uAerialCacheTransmittance'), 4);
			gl.uniform1i(gl.getUniformLocation(aerialSample, 'uAerialCacheRadiance'), 5);
			gl.uniform3f(gl.getUniformLocation(aerialSample, 'uAerialCacheGrid'), 32, 16, 16);
			gl.uniform1f(gl.getUniformLocation(aerialSample, 'uAerialCacheMaxDistance'), 90000);
			gl.uniform1f(gl.getUniformLocation(aerialSample, 'uAerialCacheAltitude'), 100);
			const aerialResults = [];

			for(const altitude of [100, 120])
			for(const distance of [0, 1, 100, 1000, 10000, 45000, 90000, 95000])
			for(const ray of [[1, 0, 0], [1, .02, 0], [0, -.1, 1], [-1, .3, 1]])
			{
				const colors = [];

				for(const ready of [0, 1])
				{
					gl.uniform1i(gl.getUniformLocation(aerialSample, 'uAerialCacheReady'), ready);
					gl.uniform3fv(gl.getUniformLocation(aerialSample, 'uTestRay'), ray);
					gl.uniform1f(gl.getUniformLocation(aerialSample, 'uTestAltitude'), altitude);
					gl.uniform1f(gl.getUniformLocation(aerialSample, 'uTestDistance'), distance);
					colors.push(pixel());
				}

				aerialResults.push({altitude, distance, ray, colors});
			}

			// The default canyon clamps to sea-level density. A visible wall spans both sides of eye level.
			buildAerialCache(0);
			gl.useProgram(aerialSample);
			gl.uniform1f(gl.getUniformLocation(aerialSample, 'uAerialCacheAltitude'), 0);
			gl.uniform1f(gl.getUniformLocation(aerialSample, 'uTestAltitude'), -380);
			const canyonHaze = [];
			for(const ready of [0,1])
			{
				gl.uniform1i(gl.getUniformLocation(aerialSample, 'uAerialCacheReady'), ready);
				const colors = [];
				for(const [elevation,distance] of [[-.001,0],[-.001,4000],[.001,4000]])
				{
					gl.uniform3f(gl.getUniformLocation(aerialSample, 'uTestRay'), 1,elevation,0);
					gl.uniform1f(gl.getUniformLocation(aerialSample, 'uTestDistance'), distance);
					colors.push(pixel());
				}
				canyonHaze.push(colors);
			}

			// A wall spanning eye level at -990 ft. Compare the same wall through clear air,
			// a sea-level layer above it, and canyon dust that fills the sightline.
			const dustViews = [];
			for(const bottom of [null,0,-600]) {
				const band = bottom===null ? null : [bottom,620,.038/1000];
				const bucket = band ? -302 : 0;
				buildAerialCache(bucket,band);
				gl.useProgram(aerialSample);
				const u = name => gl.getUniformLocation(aerialSample,name);
				gl.uniform1f(u('uAerialCacheAltitude'),bucket);
				gl.uniform1f(u('uTestAltitude'),-990*.3048);
				gl.uniform1f(u('uAirLayerCount'),band ? 1 : 0);
				if(band) {
					gl.uniform3fv(u('uAirBand[0]'),band);
					gl.uniform3f(u('uAirColor[0]'),.7,.55,.4);
				}
				dustViews.push([0,1].map(ready => {
					gl.uniform1i(u('uAerialCacheReady'),ready);
					return [[-.001,0],[-.001,500],[-.001,4000],[.001,4000]].map(([slope,distance]) => {
						gl.uniform3f(u('uTestRay'),1,slope,0);
						gl.uniform1f(u('uTestDistance'),distance);
						return pixel();
					});
				}));
			}

			// Capture shader floats directly, using core WebGL2 transform feedback rather than a float render target.
			const waterSource = shaders['water-functions.glsl'];

			const waterProgram = program(
				`${header}${waterSource}
				uniform vec2 uTestPosition; uniform float uTestTime;
				out vec4 vSample; out vec3 vNormal; out vec4 vFrame;
				void main() { vSample = terrainWaterSample(uTestPosition, uTestTime);
					vFrame = terrainFrameWakes(uTestPosition, vec2(0), vec2(0), true);
					vFrame.xyz += terrainDirectionalSample(uTestPosition, uTestTime).xyz;
					vNormal = terrainWaterNormal(vSample); gl_Position = vec4(0, 0, 0, 1); gl_PointSize = 1.0; }`
				, `${header}out vec4 color; void main() { color = vec4(0); }`
				, ['vSample', 'vNormal', 'vFrame']
			);

			const feedback = gl.createTransformFeedback();
			const outputBuffer = gl.createBuffer();
			gl.bindTransformFeedback(gl.TRANSFORM_FEEDBACK, feedback);
			gl.bindBuffer(gl.TRANSFORM_FEEDBACK_BUFFER, outputBuffer);
			gl.bufferData(gl.TRANSFORM_FEEDBACK_BUFFER, 11 * 4, gl.DYNAMIC_READ);
			gl.bindBufferBase(gl.TRANSFORM_FEEDBACK_BUFFER, 0, outputBuffer);
			gl.useProgram(waterProgram);
			gl.enable(gl.RASTERIZER_DISCARD);
			const waterResults = [];

			for(const scenario of data.waterCases)
			{
				const values = scenario.uniforms;
				gl.uniform1f(gl.getUniformLocation(waterProgram, 'uWaterLevel'), values.level);
				gl.uniform1i(gl.getUniformLocation(waterProgram, 'uWaveCount'), values.waveCount);
				gl.uniform1i(gl.getUniformLocation(waterProgram, 'uWakeCount'), values.wakeCount);

				values.waveVectors.forEach(
					(v, i) => gl.uniform4fv(gl.getUniformLocation(waterProgram, `uWaveVectors[${i}]`), v)
				);

				values.waveDynamics.forEach(
					(v, i) => gl.uniform2fv(gl.getUniformLocation(waterProgram, `uWaveDynamics[${i}]`), v)
				);

				values.wakeVectors.forEach(
					(v, i) => gl.uniform4fv(gl.getUniformLocation(waterProgram, `uWakeVectors[${i}]`), v)
				);

				for(const sample of scenario.samples)
				{
					gl.uniform2fv(gl.getUniformLocation(waterProgram, 'uTestPosition'), sample.position);
					gl.uniform1f(gl.getUniformLocation(waterProgram, 'uTestTime'), sample.time);
					gl.uniform1i(gl.getUniformLocation(waterProgram, 'uFrameWakeCount'), sample.packets.length);
					if(sample.packets.length)
					{
						gl.uniform4fv(gl.getUniformLocation(waterProgram, 'uFrameWakes[0]'), sample.packets.flat());
						gl.uniform2fv(gl.getUniformLocation(waterProgram, 'uJetVelocities[0]'), sample.jets.flat());
						gl.uniform4fv(gl.getUniformLocation(waterProgram, 'uWakeBounds'), sample.bounds);
					}
					gl.beginTransformFeedback(gl.POINTS);
					gl.drawArrays(gl.POINTS, 0, 1);
					gl.endTransformFeedback();

					const actual = new Float32Array(11);
					gl.getBufferSubData(gl.TRANSFORM_FEEDBACK_BUFFER, 0, actual);
					waterResults.push({name: scenario.name, ...sample, actual: Array.from(actual)});
				}
			}

			gl.disable(gl.RASTERIZER_DISCARD);
			gl.bindTransformFeedback(gl.TRANSFORM_FEEDBACK, null);

			const shadowProgram = program(shaders['shadows.vert'], shaders['shadows.frag']);
			const heightTexture = gl.createTexture();
			gl.activeTexture(gl.TEXTURE1);
			gl.bindTexture(gl.TEXTURE_2D, heightTexture);
			gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST);
			gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.NEAREST);
			const shadowResults = [];

			for(const [name, sun, ridge] of [['flat', [1, .001, 0], false]
				, ['ridge', [1, .1, 0], true]
				, ['reversed', [-1, .1, 0], true], ['night', [1, -.1, 0], false]
				, ['near rim', [1,.6,0], true]
			]){
				const side = name === 'near rim' ? 257 : 33;
				const heights = new Float32Array(side * side);

				if(name === 'near rim')
					for(let z = 0; z < side; z++) heights[z*side+68] = 42;
				else if(ridge)
				for(let z = 0; z < 33; z++) for(let x = 16; x <= 18; x++) heights[z * 33 + x] = 300;

				gl.activeTexture(gl.TEXTURE1);
				gl.bindTexture(gl.TEXTURE_2D, heightTexture);
				gl.texImage2D(gl.TEXTURE_2D, 0, gl.R32F, side, side, 0, gl.RED, gl.FLOAT, heights);
				target(6, 256, 256);
				gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
				gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
				gl.useProgram(shadowProgram);
				gl.uniform1i(gl.getUniformLocation(shadowProgram, 'uTerrainHeights'), 1);
				gl.uniform1f(gl.getUniformLocation(shadowProgram, 'uTerrainShadowExtent'), 2048);
				gl.uniform1f(gl.getUniformLocation(shadowProgram, 'uTerrainShadowSize'), 256);
				gl.uniform2f(gl.getUniformLocation(shadowProgram, 'uShadowHeightRange'), -400,800);

				gl.uniform3fv(
					gl.getUniformLocation(shadowProgram, 'uShadowSunDirection')
					, sun.map(v => v / Math.hypot(...sun))
				);

				gl.drawArrays(gl.TRIANGLES, 0, 3);
				const visibility = [];

				for(const x of [64, 192])
				{
					const bytes = new Uint8Array(4);
					gl.readPixels(x, 128, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, bytes);
					visibility.push(bytes[0]);
				}

				shadowResults.push({name, visibility});
			}
			// Two faces in the same shadow texel can fall on opposite sides of the projected rim.
			const mountainShadow = shaders['terrain.frag'].split('float mountainShadow')[1].split('#ifdef')[0];
			const receiver = program(vertex, header + `
				uniform highp sampler2D uTerrainShadowMap;
				uniform vec3 uEye;
				const vec2 uShadowReceiverRange=vec2(100.,300.);
				const float uCraft=0.;
				const vec3 uSunDirection=vec3(1.,.6,0.);
				const int uTerrainShadowReady=1;
				const float uTerrainShadowExtent=2048.;
				const vec2 uShadowHeightRange=vec2(-400.,800.);
				out vec4 color;
				float mountainShadow` + mountainShadow + `
				void main(){color=vec4(vec3(mountainShadow(vec3(-508.,gl_FragCoord.x < 1. ? 0. : 48.,4.))),1.);}`);
			target(7,2,1);
			gl.useProgram(receiver);
			gl.uniform1i(gl.getUniformLocation(receiver,'uTerrainShadowMap'),6);
			gl.uniform3f(gl.getUniformLocation(receiver,'uEye'),-508,100,4);
			gl.drawArrays(gl.TRIANGLES,0,3);
			const ledges = new Uint8Array(8);
			gl.readPixels(0,0,2,1,gl.RGBA,gl.UNSIGNED_BYTE,ledges);
			shadowResults.push({name:'ledges',visibility:[ledges[0],ledges[4]]});
			for(const [name,distance] of [['transition',200],['distant',400]])
			{
				gl.uniform3f(gl.getUniformLocation(receiver,'uEye'),-508+distance,100,4);
				gl.drawArrays(gl.TRIANGLES,0,3);
				gl.readPixels(0,0,2,1,gl.RGBA,gl.UNSIGNED_BYTE,ledges);
				shadowResults.push({name,visibility:[ledges[0],ledges[4]]});
			}

			// A wall only one meter thick crosses the interior of an eight-meter terrain triangle.
			// Run both production passes against a signed, toroidal lattice in each storage format.
			const adaptive = program(shaders['shadows.vert'], shaders['triangle-shadows.frag']);
			const adaptiveResults = [];
			const sampleFunction = shaders['terrain.frag'].split('float localSunlight')[1].split('#endif')[0];
			const sampleTriangle = program(vertex, header + `
				uniform highp sampler2D uShadowTriangles;
				uniform vec3 uEye;
				uniform vec3 uShadowGrid;
				uniform float uCraft;
				out vec4 color;
				float localSunlight` + sampleFunction + `
				void main() {
					vec2 samples[6] = vec2[6](vec2(1),vec2(7,.2),vec2(8./3.),vec2(4,2),vec2(6,1.99),vec2(6,2.01));
					int i = int(gl_FragCoord.x);
					color = vec4(localSunlight(samples[i],i == 3 ? 10. : .01,1.),0.,0.,1.);
				}`);
			const local = name => gl.getUniformLocation(adaptive,name);
			for(const packed of [false,true])
			for(const stripe of [false,true]) {
				const field = new Float32Array(64*64).fill(-350);
				for(let z = 0; z < 64; z++) {
					if(!stripe || z === 2 || z === 3) field[z*64+(stripe ? 8 : 10)] += stripe ? 12 : 6;
				}
				texture(10,64,64,null);
				if(packed) gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA8,64,64,0,gl.RGBA,gl.UNSIGNED_BYTE,new Uint8Array(field.buffer));
				else gl.texImage2D(gl.TEXTURE_2D,0,gl.R32F,64,64,0,gl.RED,gl.FLOAT,field);
				// Keep the far field below every sun ray.
				const distant = new Float32Array(4).fill(-1000);
				texture(11,2,2,null);
				if(packed) gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA8,2,2,0,gl.RGBA,gl.UNSIGNED_BYTE,new Uint8Array(distant.buffer));
				else gl.texImage2D(gl.TEXTURE_2D,0,gl.R32F,2,2,0,gl.RED,gl.FLOAT,distant);
				gl.useProgram(adaptive);
				gl.uniform1i(local('uCaster'),10);
				gl.uniform1i(local('uOverview'),11);
				gl.uniform1i(local('uPacked'),+packed);
				gl.uniform1f(local('uCasterStep'),1);
				gl.uniform1f(local('uReach'),24);
				gl.uniform1f(local('uExtent'),64);
				gl.uniform3f(local('uSun'),Math.SQRT1_2,Math.SQRT1_2,0);
				gl.uniform3f(local('uShadowGrid'),-64,-64,8);
				target(12,17,17);
				gl.uniform1i(local('uVertices'),10);
				gl.uniform1i(local('uRefine'),0);
				gl.drawArrays(gl.TRIANGLES,0,3);
				target(13,32,16);
				gl.uniform1i(local('uVertices'),12);
				gl.uniform1i(local('uRefine'),1);
				gl.drawArrays(gl.TRIANGLES,0,3);
				const pixel = new Uint8Array(4);
				gl.readPixels(16,8,1,1,gl.RGBA,gl.UNSIGNED_BYTE,pixel);
				target(14,6,1);
				gl.useProgram(sampleTriangle);
				gl.uniform1i(gl.getUniformLocation(sampleTriangle,'uShadowTriangles'),13);
				gl.uniform3f(gl.getUniformLocation(sampleTriangle,'uShadowGrid'),-64,-64,8);
				gl.drawArrays(gl.TRIANGLES,0,3);
				const rendered = new Uint8Array(24);
				gl.readPixels(0,0,6,1,gl.RGBA,gl.UNSIGNED_BYTE,rendered);
				adaptiveResults.push({record:[...pixel], pixels:[...rendered.filter((_,i)=>i%4===0)]});
			}
			// A shadowed point in the blend region stays at the same shade when the cache
			// snaps eight cells, even if the eye crosses the boundary by only a millimeter.
			texture(13,128,64,new Uint8Array(128*64*4));
			target(14,6,1);
			gl.useProgram(sampleTriangle);
			for(const [eye,left] of [[-20.001,-56],[-19.999,-48]])
			{
				gl.uniform3f(gl.getUniformLocation(sampleTriangle,'uEye'),eye,0,0);
				gl.uniform3f(gl.getUniformLocation(sampleTriangle,'uShadowGrid'),left,-32,1);
				gl.drawArrays(gl.TRIANGLES,0,3);
				const pixels = new Uint8Array(8);
				gl.readPixels(0,0,2,1,gl.RGBA,gl.UNSIGNED_BYTE,pixels);
				shadowResults.push({name:'snap '+left,visibility:[pixels[0],pixels[4]]});
			}
			gl.bindFramebuffer(gl.FRAMEBUFFER, null);

			// Actual surface fragments must receive zero radiance when their only light source has zero irradiance.
			const surfaceVertex = `${header}
				out vec3 vRelative, vWorld, vNormal, vPaint0, vPaint1; out vec4 vRoadMark; out float vSplinePaint; out vec2 vForkMark; out float vGround;
				uniform float uTestGround, uGroundGradient;
				void main() { vec2 p = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
					vRelative = vec3(0, -5, -5); vWorld = vec3(0); vNormal = vec3(0, 1, 0);
					vPaint0 = vec3(0, 1, 0); vPaint1 = vec3(0); vForkMark=vec2(0.); vSplinePaint=0.; vRoadMark = vec4(0); vGround = uTestGround + (p.x - 0.5) * uGroundGradient;
					gl_Position = vec4(p * 2.0 - 1.0, 0, 1); }`;

			const illuminationResults = [];
			let inactiveAppearance = null;

			for(const name of ['terrain', 'water'])
			{
				const surface = program(
					surfaceVertex
					, shaders[`${name}.frag`].replace('// ATMOSPHERE', data.source).replace('// WATER', waterSource)
				);

				parameters(surface);

				// The compiler drops a uniform the shader stops reading, and uploads to it then do nothing.
				if(name === 'terrain')
				{
					inactiveAppearance = ['uPaintColor[0]','uSnowline','uSlopeBlend','uMountainAmounts','uMountainColors[0]']
						.filter(uniform => gl.getUniformLocation(surface, uniform) === null);
				}

				if(name === 'terrain') appearance(surface, data.appearance);

				gl.uniform3f(gl.getUniformLocation(surface, 'uEye'), 0, 5, 5);
				gl.uniform1f(gl.getUniformLocation(surface, 'uTestGround'), -3);
				const colors = [];

				// The sky's fill is integrated from sunlight on the CPU, so it goes dark with the sun.
				for(const intensity of [0, 20])
				{
					gl.uniform1f(gl.getUniformLocation(surface, 'uSunIntensity'), intensity);
					const fill = intensity ? data.uniforms.skyAmbient : [0, 0, 0];
					gl.uniform3fv(gl.getUniformLocation(surface, 'uSkyAmbient'), fill);
					colors.push(pixel());
				}

				// The test vertex paints grass, so a red grass color must turn the lit fragment red.
				if(name === 'terrain')
				{
					appearance(surface, data.redGrass);
					colors.push(pixel());
					appearance(surface, data.appearance);
				}

				gl.uniform1f(gl.getUniformLocation(surface, 'uSunIntensity'), 0);
				gl.uniform3fv(gl.getUniformLocation(surface, 'uSkyAmbient'), [0, 0, 0]);
				gl.uniform3fv(gl.getUniformLocation(surface, 'uNightAmbient'), data.nightAmbient);
				illuminationResults.push({name, colors, night: pixel()});
			}

			// One row of banded rock on a vertical face, with the strata phase advancing by a fixed step per pixel.
			// Each step puts the bands on a whole DFT bin and one of their odd harmonics just past the pixel rate.
			const bandVertex = `${header}
				out vec3 vRelative, vWorld, vNormal, vPaint0, vPaint1; out vec4 vRoadMark; out float vSplinePaint; out vec2 vForkMark;
				uniform float uBandStep;
				void main() { vec2 p = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
					float rise = p.x * 256.0 * uBandStep;
					vRelative = vec3(-50, rise, 0); vWorld = vec3(0, 500.0 + rise, 0);
					vNormal = vec3(1, 0, 0); vPaint0 = vec3(0); vPaint1 = vec3(0); vForkMark=vec2(0.); vSplinePaint=0.; vRoadMark = vec4(0);
					gl_Position = vec4(p * 2.0 - 1.0, 0, 1); }`;

			const bandProgram = program(bandVertex, shaders['terrain.frag'].replace('// ATMOSPHERE', data.source));
			parameters(bandProgram);
			gl.uniform3f(gl.getUniformLocation(bandProgram, 'uEye'), 0, 3000, 0);
			target(7, 256, 1);
			const bandStep = gl.getUniformLocation(bandProgram, 'uBandStep');
			const bandResults = [];

			for(const uniforms of data.bands)
			for(const bin of [43, 26, 19, 15])
			{
				const bytes = new Uint8Array(256 * 4);

				appearance(bandProgram, uniforms);
				gl.uniform1f(bandStep, 2 * Math.PI * bin / 256 / uniforms.uBanding[0]);
				gl.drawArrays(gl.TRIANGLES, 0, 3);
				gl.readPixels(0, 0, 256, 1, gl.RGBA, gl.UNSIGNED_BYTE, bytes);
				const red = Array.from({length: 256}, (_, i) => bytes[i * 4]);
				bandResults.push({sharpness: uniforms.uBanding[2], bin, red});
			}

			// The same rows on a mountain face, which is granite without strata.
			gl.uniform1f(gl.getUniformLocation(bandProgram, 'uTerrainStyle'), 1);
			appearance(bandProgram, data.bands[0]);
			const mountainBands = [];

			for(const bin of [43, 26, 19, 15])
			{
				const bytes = new Uint8Array(256 * 4);

				gl.uniform1f(bandStep, 2 * Math.PI * bin / 256 / data.bands[0].uBanding[0]);
				gl.drawArrays(gl.TRIANGLES, 0, 3);
				gl.readPixels(0, 0, 256, 1, gl.RGBA, gl.UNSIGNED_BYTE, bytes);
				mountainBands.push({bin, red: Array.from({length: 256}, (_, i) => bytes[i * 4])});
			}

			// A mountain face 200 m across at the given slope, seen square on at under a meter per pixel, so every
			// patch noise is at full strength. It lies 150 m below the snowline, at the default treeline, where talus
			// still shows between the trees.
			const amountVertex = `${header}
				out vec3 vRelative, vWorld, vNormal, vPaint0, vPaint1; out vec4 vRoadMark; out float vSplinePaint; out vec2 vForkMark;
				uniform float uFaceSlope;
				void main() { vec2 p = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
					vec2 s = p * 2.0 - 1.0;
					float c = 1.0 - uFaceSlope, a = sqrt(1.0 - c * c);
					vNormal = vec3(a, c, 0);
					vec3 offset = s.x * vec3(0, 0, 100) + s.y * 25.0 * vec3(-c, a, 0);
					vWorld = vec3(-1200, 190, 3000) + offset;
					vRelative = offset - 150.0 * vNormal; vPaint0 = vec3(0); vPaint1 = vec3(0); vForkMark=vec2(0.); vSplinePaint=0.; vRoadMark = vec4(0);
					gl_Position = vec4(s, 0, 1); }`;

			const amountProgram = program(amountVertex, shaders['terrain.frag'].replace('// ATMOSPHERE', data.source));
			parameters(amountProgram);
			gl.uniform1f(gl.getUniformLocation(amountProgram, 'uTerrainStyle'), 1);
			gl.uniform3f(gl.getUniformLocation(amountProgram, 'uEye'), 0, 3000, 0);
			target(7, 256, 64);
			gl.deleteProgram(program(amountVertex,data.tiltShader.replace('// ATMOSPHERE',data.source)));
			const authoredProgram = program(amountVertex, data.ridgeShader.replace('// ATMOSPHERE', data.source));
			const mountainAmounts = [];

			for(const {material, slope, amount, cases, stacks} of data.mountainAmounts)
			{
				gl.useProgram(amountProgram);
				gl.uniform1f(gl.getUniformLocation(amountProgram, 'uFaceSlope'), slope);

				const images = cases.map(uniforms => {
					const bytes = new Uint8Array(256 * 64 * 4);
					appearance(amountProgram, uniforms);
					gl.drawArrays(gl.TRIANGLES, 0, 3);
					gl.readPixels(0, 0, 256, 64, gl.RGBA, gl.UNSIGNED_BYTE, bytes);

					return bytes;
				});

				// Both programs see the same position, normal, lighting and material parameters.
				parameters(authoredProgram);
				gl.uniform3f(gl.getUniformLocation(authoredProgram,'uEye'),0,3000,0);
				gl.uniform1f(gl.getUniformLocation(authoredProgram,'uFaceSlope'),slope);
				let maxError=0;
				for(const [index,inputs] of stacks.entries()) {
					stackUniforms(authoredProgram,inputs);
					gl.drawArrays(gl.TRIANGLES,0,3);
					const pixels=new Uint8Array(256*64*4);
					gl.readPixels(0,0,256,64,gl.RGBA,gl.UNSIGNED_BYTE,pixels);
					for(let i=0;i<pixels.length;i++) maxError=Math.max(maxError,Math.abs(pixels[i]-images[index][i]));
				}

				// A patch in either color differs by far more than 8 steps, while a mix of talus into granite at a
				// weight of 1 can keep a rounding error from the talus color and move a pixel by one step.
				let differing = 0;

				for(let i = 0; i < images[0].length; i += 4)
					if([0, 1, 2].some(c => Math.abs(images[0][i + c] - images[1][i + c]) > 8)) differing++;

				mountainAmounts.push({material, amount, maxError, share: differing / (256 * 64)});
			}

			// Sample the old 3 m talus grid at eight pixels per cell. Moving 3.6 cm
			// across a former edge should not switch the material of an entire cell.
			const fieldSource = header + shaders['surface-layers.glsl'] + shaders['material-noise.glsl'] + `
				uniform vec4 uSlopeBlend,uHeightBlend;
				uniform vec2 uSnowline,uMountainZones;
				uniform vec3 uMountainAmounts;`;
			const rockProbe = program(vertex, fieldSource + `
				uniform float uShift,uAspect,uOblique;
				out vec4 color;
				void main() {
					vec2 point=gl_FragCoord.xy-.5;
					point.y+=(point.y-32.)*uOblique;
					vec2 gridPos=point/8.+vec2(-623.+uShift,912.);
					vec2 xz=3.*mat2(.8,-.6,.6,.8)*gridPos;
					vec3 pos=vec3(xz.x,240.+.1*(point.y+.5),xz.y), relative=vec3(0.,-80.,0.);
					vec3 normal=normalize(vec3(.55+uAspect,1.,.55)), weights=pow(abs(normal),vec3(4.));
					weights/=dot(weights,vec3(1.));
					float slope=1.-normal.y, footprint=.375, water=0., macro=landVariation(xz,footprint);
					float snowline=uSnowline.x+25.*macro, stipple=0.;
					${shaders['material-fields.glsl']}
					color=vec4(.5*blocks*boulders,scree,.6*tone,.5+2.*blockTilt.z);
				}`);
			gl.useProgram(rockProbe);
			appearance(rockProbe, data.appearance);
			function rockSample(shift, aspect, oblique = 0) {
				gl.uniform1f(gl.getUniformLocation(rockProbe,'uShift'),shift);
				gl.uniform1f(gl.getUniformLocation(rockProbe,'uAspect'),aspect);
				gl.uniform1f(gl.getUniformLocation(rockProbe,'uOblique'),oblique);
				gl.drawArrays(gl.TRIANGLES,0,3);
				const pixels = new Uint8Array(256*64*4);
				gl.readPixels(0,0,256,64,gl.RGBA,gl.UNSIGNED_BYTE,pixels);
				return pixels;
			}
			const left = rockSample(-.006,-.002), right = rockSample(.006,-.002), otherAspect = rockSample(-.006,.002);
			// Foreshorten the view about the middle row. Its world points, normals,
			// and filter width stay fixed while the neighboring pixels move.
			const oblique = rockSample(-.006,-.002,2);
			const stone = [], screeValues = [], reliefValues = [], edgeJumps = [], aspectJumps = [];
			let centerSum = 0, edgeSum = 0, reliefJump = 0;
			for(let y=0;y<64;y++) for(let x=0;x<256;x++) {
				const i=4*(y*256+x);
				stone.push(left[i]);
				screeValues.push(left[i+1]);
				reliefValues.push(left[i+3]);
				if(y===32) reliefJump=Math.max(reliefJump,Math.abs(left[i+3]-oblique[i+3]));
				if(x%8===0) edgeJumps.push(Math.abs(left[i+1]-right[i+1]));
				if(y%8===4 && x%8===4) centerSum+=left[i];
				if(y%8===4 && x%8===0) edgeSum+=left[i];
				aspectJumps.push(Math.abs(left[i+2]-otherAspect[i+2]));
			}
			const percentile = (values,p) => values.sort((a,b)=>a-b)[Math.floor(p*values.length)];
			const rockDetail = {
				centerEdge: Math.abs(centerSum-edgeSum)/(32*8), edgeJump: percentile(edgeJumps,.95),
				aspectJump: percentile(aspectJumps,.95), toneRange: percentile(stone,.95)-percentile(stone,.05),
				screeRange: percentile(screeValues,.95)-percentile(screeValues,.05), reliefJump,
				reliefRange: percentile(reliefValues,.95)-percentile(reliefValues,.05),
			};

			// Sample one exposed face at walking distance, far beyond the coarsest
			// pigment, with half-sized slabs, and finally with the pattern turned off.
			const pigmentProbe = program(vertex,header + shaders['surface-layers.glsl'] + `
				out vec4 color;
				void main() {
					vec3 pos=vec3(-1200.,100.+gl_FragCoord.y*.34,1600.+gl_FragCoord.x*.34);
					vec3 n=vec3(1.,0.,0.);
					color=vec4(layerPatternWeight(0,pos,n,.04,0.,0.),layerPatternWeight(0,pos,n,80.,0.,0.),
						layerPatternWeight(1,pos,n,.04,0.,0.),layerPatternWeight(2,pos,n,.04,0.,0.));
				}`);
			gl.useProgram(pigmentProbe);
			gl.uniform4fv(gl.getUniformLocation(pigmentProbe,'uLayerPattern[0]'),[
				9,1,13,23/13, 9,1,6.5,23/13, 9,0,13,23/13,
			]);
			gl.drawArrays(gl.TRIANGLES,0,3);
			const pigments=new Uint8Array(256*64*4);
			gl.readPixels(0,0,256,64,gl.RGBA,gl.UNSIGNED_BYTE,pigments);
			const near=[], far=[], resized=[], off=[];
			for(let i=0;i<pigments.length;i+=4) {
				near.push(pigments[i]); far.push(pigments[i+1]);
				resized.push(Math.abs(pigments[i]-pigments[i+2])); off.push(pigments[i+3]);
			}
			const granitePigment = {localRange:percentile(near,.95)-percentile(near,.05),
				distantRange:Math.max(...far)-Math.min(...far),resizedDelta:percentile(resized,.75),off:Math.max(...off)};

			// Across a 512 m hillside, compare the forest, bare rock and scree cover of
			// one distant pixel with the mean of the finer samples at the same location.
			const hillside = program(vertex, fieldSource + `
				#define MATERIAL_STACK
				uniform float uMeters,uBearing;
				uniform vec2 uFaceStep;
				uniform int uInspect;
				out vec4 color;
				void main() {
					vec2 local=gl_FragCoord.xy*uMeters;
					mat2 faceTurn=mat2(cos(uBearing),sin(uBearing),-sin(uBearing),cos(uBearing));
					vec3 facePoint=vec3(local.x,uFaceStep*local.y);
					facePoint.xz=faceTurn*facePoint.xz;
					vec3 pos=vec3(-2705.,157.,1779.)+facePoint;
					vec3 normal=normalize(vec3(0.,uFaceStep.y,-uFaceStep.x));
					normal.xz=faceTurn*normal.xz;
					vec3 relative=-80.*normal;
					vec3 weights=pow(abs(normal),vec3(4.));
					weights/=dot(weights,vec3(1.));
					float slope=1.-normal.y, water=0., footprint=uMeters*length(uFaceStep);
					float macro=landVariation(pos.xz,footprint), snowline=uSnowline.x+25.*macro, stipple=0.;
					${shaders['material-fields.glsl']}
					color=vec4(trees,bare,scree,min(1.,length(lift)));
					if(uInspect==1) color=vec4(length(lift-normal*dot(normal,lift)),2.*length(facet),3.*length(blockTilt),.5*jointTint.r);
					if(uInspect==2) color=vec4(.5+gully,.5+rill,0.,1.);
				}`);
			gl.useProgram(hillside);
			appearance(hillside,data.appearance);
			const fieldScale = gl.getUniformLocation(hillside, 'uFieldScale');
			const faceStep = gl.getUniformLocation(hillside, 'uFaceStep');
			gl.uniform2f(faceStep, .62, 1);
			gl.uniform4f(fieldScale, 48, 3, 13, 23);
			function hillsidePixels(meters, width = 512/meters, height = 128/meters) {
				gl.uniform1f(gl.getUniformLocation(hillside, 'uMeters'), meters);
				gl.viewport(0, 0, width, height);
				gl.drawArrays(gl.TRIANGLES, 0, 3);
				const pixels = new Uint8Array(4*width*height);
				gl.readPixels(0, 0, width, height, gl.RGBA, gl.UNSIGNED_BYTE, pixels);
				return pixels;
			}
			const hillsideCover = [];
			for(const seed of [[0,0], [0x4567,0x1234]]) {
				gl.uniform2fv(gl.getUniformLocation(hillside, 'uPaletteOffset'), seed);
				const reference = hillsidePixels(2);
				for(const meters of [4, 8, 16, 32]) {
					const coarse = hillsidePixels(meters), width = 512/meters, factor = meters/2;
					for(const [channel, material] of ['forest','rock','scree'].entries()) {
						const errors = [], levels = [];
						for(let y=0; y<128/meters; y++) for(let x=0; x<width; x++) {
							let sum = 0;
							for(let j=0; j<factor; j++) for(let i=0; i<factor; i++)
								sum += reference[4*((factor*y+j)*256+factor*x+i)+channel];
							const cover = coarse[4*(width*y+x)+channel];
							levels.push(cover);
							errors.push(Math.abs(cover - sum/(factor*factor)));
						}
						hillsideCover.push({seed, meters, material, p95Error: percentile(errors,.95),
							range: percentile(levels,.95)-percentile(levels,.05)});
					}
				}
			}
			gl.uniform4f(fieldScale, 1, 3, 13, 23);
			const unresolvedRibs = hillsidePixels(8).filter((_, i) => i%4 === 3);
			const maxUnresolvedTilt = Math.max(...unresolvedRibs);
			gl.uniform4f(fieldScale, 48, 3, 13, 23);
			const inspect = gl.getUniformLocation(hillside, 'uInspect');
			gl.uniform1i(inspect, 1);
			const rockDamping = gl.getUniformLocation(hillside, 'uRockSlopeDamping');
			const slopeRelief = [];
			for(const degrees of [0,30,50,72,90]) {
				const angle = degrees*Math.PI/180;
				gl.uniform2f(faceStep, Math.sin(angle), Math.cos(angle));
				const sample = damping => {
					gl.uniform1f(rockDamping, damping/.45**2);
					return hillsidePixels(.25,128,64);
				};
				const full = sample(0), damped = sample(2), strong = sample(20);
				const totals = pixels => [0,1,2].map(channel => {
					let sum = 0;
					for(let i=channel; i<pixels.length; i+=4) sum+=pixels[i];
					return sum;
				});
				const sameTint = full.every((v,i) => i%4 !== 3 || v === damped[i] && v === strong[i]);
				slopeRelief.push({degrees,full:totals(full),damped:totals(damped),strong:totals(strong),sameTint});
			}
			// The two image axes span equal distances across and down the face.
			// Turning the face in the world should turn the drainage with it.
			const drainage = [];
			gl.uniform1i(inspect, 2);
			for(const seed of [[0,0], [0x4567,0x1234]]) {
				gl.uniform2fv(gl.getUniformLocation(hillside, 'uPaletteOffset'), seed);
				for(const degrees of [30,55]) for(const bearing of [0,.73,2.32]) {
					const angle = degrees*Math.PI/180;
					gl.uniform2f(faceStep, Math.sin(angle), Math.cos(angle));
					gl.uniform1f(gl.getUniformLocation(hillside, 'uBearing'), bearing);
					const width = 256, height = 64, step = 2;
					const pixels = hillsidePixels(2,width,height);
					for(const [channel,field] of ['gully','rill'].entries()) {
						let across = 0, downhill = 0;
						for(let y=0; y<height-step; y++) for(let x=0; x<width-step; x++) {
							const i = 4*(y*width+x)+channel;
							across += Math.abs(pixels[i+4*step]-pixels[i]);
							downhill += Math.abs(pixels[i+4*width*step]-pixels[i]);
						}
						const count = (width-step)*(height-step);
						drainage.push({field,seed,degrees,bearing,across:across/count,downhill:downhill/count});
					}
				}
			}
			gl.bindFramebuffer(gl.FRAMEBUFFER, null);

			// View one face from several directions under a known sky, then cover it with paint, snow or water.
			const iceVertex = `${header}
				out vec3 vRelative, vWorld, vNormal, vPaint0, vPaint1; out vec4 vRoadMark; out float vSplinePaint; out vec2 vForkMark;
				uniform vec3 uView; uniform float uRoad,uGrade,uMark,uLateral; uniform vec2 uTestFork;
				uniform vec2 uPixelSpan, uWorldOrigin;
				uniform float uDashAngle;
				void main() {
					vec2 p=vec2(float((gl_VertexID << 1) & 2),float(gl_VertexID & 2));
					vec2 span=uPixelSpan*(p-.5);
					vec3 offset=vec3(span.x,uGrade*span.x,span.y);
					vWorld=vec3(uWorldOrigin.x,10.,uWorldOrigin.y)+offset;
					vRelative=offset-uView;
					// A smoothed lighting normal can remain level at a sharp road cut.
					vNormal=vec3(0.,1.,0.);
					vec2 phase=vec2(cos(uDashAngle),sin(uDashAngle));
					phase+=span.x*(6.2831853/97.536)*vec2(-phase.y,phase.x);
					vForkMark=uTestFork; vSplinePaint=uRoad*(uMark+uTestFork.y); vRoadMark=uMark*vec4(uLateral+span.y/8.,phase,1.); vPaint0=vec3(0.); vPaint1=vec3(0.,uRoad,0.); gl_Position=vec4(2.*p-1.,0.,1.);
				}`;
			const ice = program(iceVertex,data.finishShader.replace('// ATMOSPHERE',data.source));
			parameters(ice);
			stackUniforms(ice,data.finishInputs);
			const iceLoc = name => gl.getUniformLocation(ice,name);
			gl.uniform2f(iceLoc('uPixelSpan'),.1,.1);
			gl.uniform1f(iceLoc('uDashAngle'),.098175);
			texture(14,1,1,new Uint8Array([64,68,75,255]));
			gl.uniform1i(iceLoc('uSkyCacheReady'),1);
			gl.uniform2f(iceLoc('uSkyCacheSize'),1,1);
			gl.uniform1i(iceLoc('uSkyCacheEye'),14);
			gl.uniform1i(iceLoc('uSkyCacheWater'),14);
			gl.uniform3f(iceLoc('uNightAmbient'),0,0,0);
			gl.uniform3f(iceLoc('uEye'),0,11,0);
			gl.uniform1f(iceLoc('uSunIntensity'),0);
			const sampleIce = (view,strength=1,roughness=0) => {
				gl.uniform3fv(iceLoc('uView'),view);
				gl.uniform2fv(iceLoc('uLayerFinish[0]'),[strength,strength*roughness**2,0,0]);
				return pixel()[0];
			};
			const iceSunY=.4, iceSunZ=Math.sqrt(1-iceSunY**2);
			const mirror=[0,iceSunY,-iceSunZ];
			const iceSamples={normal:sampleIce([0,1,0]), grazing:sampleIce([Math.sqrt(.99),.1,0])};
			gl.uniform3f(iceLoc('uSunDirection'),0,iceSunY,iceSunZ);
			gl.uniform1f(iceLoc('uSunIntensity'),5);
			iceSamples.glint=sampleIce(mirror);
			iceSamples.frost=sampleIce(mirror,1,.85);
			iceSamples.turned=sampleIce([iceSunZ,iceSunY,0]);
			const offDisc=[Math.sin(.19)*iceSunZ,iceSunY,-Math.cos(.19)*iceSunZ];
			iceSamples.shoulder=[sampleIce(offDisc),sampleIce(offDisc,1,.38)];
			gl.uniform1i(iceLoc('uTerrainShadowReady'),1);
			gl.uniform1f(iceLoc('uTerrainShadowExtent'),32);
			gl.uniform2f(iceLoc('uShadowHeightRange'),100,0);
			iceSamples.shaded=sampleIce(mirror);
			gl.uniform1i(iceLoc('uTerrainShadowReady'),0);
			gl.uniform1f(iceLoc('uRoad'),1);
			iceSamples.road=[sampleIce(mirror),sampleIce(mirror,0)];
			gl.uniform1f(iceLoc('uMark'),1);
			gl.uniform1f(iceLoc('uLateral'),1.3);
			iceSamples.gradedShoulder=sampleIce(mirror);
			gl.uniform1f(iceLoc('uMark'),0);
			gl.uniform1f(iceLoc('uGrade'),6);
			iceSamples.wall=sampleIce(mirror);
			gl.uniform1f(iceLoc('uRoad'),0);
			iceSamples.bareWall=sampleIce(mirror);
			gl.uniform1f(iceLoc('uGrade'),0);
			gl.uniform1f(iceLoc('uWaterLevel'),15);
			iceSamples.submerged=[sampleIce(mirror),sampleIce(mirror,0)];
			gl.uniform1f(iceLoc('uWaterLevel'),0);
			gl.uniform4f(iceLoc('uLayerEffect[1]'),1,0,1,1);
			iceSamples.covered=[sampleIce(mirror),sampleIce(mirror,0)];

			// Isolate the nighttime dash and shoulder emissions on the same face.
			gl.uniform1f(iceLoc('uSunIntensity'),0);
			gl.uniform3f(iceLoc('uSkyAmbient'),0,0,0);
			gl.uniform3f(iceLoc('uSunDirection'),0,-1,0);
			gl.uniform1f(iceLoc('uRoad'),1);
			gl.uniform1f(iceLoc('uMark'),1);
			const stripeCuts = [];
			for(const lateral of [0,.95625]) {
				gl.uniform1f(iceLoc('uLateral'),lateral);
				stripeCuts.push([0,.35,2.4,6,-6].map(grade => {
					gl.uniform1f(iceLoc('uGrade'),grade);
					return sampleIce(mirror,0);
				}));
			}

			// A triangle leaving the shared pavement can have one painted vertex
			// exactly on its centerline. Its dash must not fill the triangle.
			gl.uniform1f(iceLoc('uGrade'),0);
			gl.uniform1f(iceLoc('uMark'),.7);
			gl.uniform1f(iceLoc('uLateral'),0);
			gl.uniform2f(iceLoc('uTestFork'),.12,.3);
			const junctionInk=sampleIce(mirror,0);
			gl.uniform2f(iceLoc('uTestFork'),0,0);
			gl.uniform1f(iceLoc('uMark'),1);
			// Move a driver's elongated pixel along the same dash, then across the stripe.
			gl.uniform1f(iceLoc('uGrade'),0);
			const dashSweep = (along, across, offset = 0) => {
				gl.uniform2f(iceLoc('uPixelSpan'),along,across);
				gl.uniform1f(iceLoc('uLateral'),offset/8);
				return [.25,.5,.75,.99].map(phase => {
					gl.uniform1f(iceLoc('uDashAngle'),phase*(2*Math.PI/16));
					return sampleIce(mirror,0);
				});
			};
			const dashPixels = {near:dashSweep(.2,.1), cycle:dashSweep(6.096,.1), several:dashSweep(18,.1),
				subpixel:dashSweep(12,.8), beside:dashSweep(12,.8,.65), across:dashSweep(.2,6.096)};

			// Sample the edges of both kinds of paint at the origin and out on Salt Pan Endurance.
			const paintEdges = [[0,0],[-4939.65,-10514.84],[12400,25700]].map(origin => {
				gl.uniform2fv(iceLoc('uWorldOrigin'),origin);
				gl.uniform2f(iceLoc('uPixelSpan'),.006,.001);
				gl.uniform1f(iceLoc('uDashAngle'),.25*(2*Math.PI/16));
				return [.20,8.-.35+.12].map(edge => [-.001,0,.001].map(delta => {
					gl.uniform1f(iceLoc('uLateral'),(edge+delta)/8.);
					return sampleIce(mirror,0);
				}));
			});

			// Draw the real surface over two known opaque colors. Their difference isolates transmission.
			const surface = program(surfaceVertex, shaders['water.frag'].replace('// ATMOSPHERE', data.source)
				.replace('// WATER', waterSource));
			parameters(surface);
			gl.uniform3f(gl.getUniformLocation(surface, 'uEye'), 0, 5, 5);
			gl.enable(gl.BLEND);
			gl.blendFuncSeparate(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA, gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
			const backdropContrast = [];
			for(const [transparency, reflection, foam, ground, gradient = 0, level = 0] of [
				[0,0,0,-3], [.5,0,0,-3], [1,0,0,-3], [1,1,0,-3], [1,0,1,-.15], [1,0,1,-3],
				// A bright, opaque surface at and just inside the water/terrain intersection.
				[0,1,1,.01], [0,1,1,0], [0,1,1,-.01], [0,1,1,-.09], [0,1,1,-.2],
				[0,1,1,-.2,.4],
				// The analytic wave sample can crest above the displaced triangle between mesh vertices.
				[0,1,1,0,0,.06]
			])
			{
				gl.uniform4f(gl.getUniformLocation(surface, 'uWaterOptics'), transparency, reflection, 0, foam);
				gl.uniform1f(gl.getUniformLocation(surface, 'uTestGround'), ground);
				gl.uniform1f(gl.getUniformLocation(surface, 'uGroundGradient'), gradient);
				gl.uniform1f(gl.getUniformLocation(surface, 'uWaterLevel'), level);
				const reds = [];
				for(const red of [0, 1])
				{
					gl.clearColor(red, 0, 0, 1);
					gl.clear(gl.COLOR_BUFFER_BIT);
					reds.push(pixel()[0]);
				}
				backdropContrast.push(reds[1] - reds[0]);
			}
			gl.disable(gl.BLEND);
			const column = program(vertex, header + shaders['water-optics.glsl'] + `
				uniform vec3 uOffset; out vec4 color;
				void main(){ color = vec4(waterColumnLight(vec3(1,0,0), uOffset, 2., 0., 1.), 1.); }`);
			gl.useProgram(column);
			gl.uniform3f(gl.getUniformLocation(column, 'uWaterTint'), 0, 1, 0);
			gl.uniform4f(gl.getUniformLocation(column, 'uWaterOptics'), 1, 0, .1, 0);
			const waterPaths = [];
			for(const [y,z] of [[-1,0], [-3,0], [-12,0], [-12,12]])
			{
				gl.uniform3f(gl.getUniformLocation(column,'uOffset'), 0, y, z);
				waterPaths.push(pixel());
			}

			const visualSource = shaders['water.frag'].replace('// ATMOSPHERE', data.source)
				.replace('// WATER', waterSource).replace('void main()', 'void waterSurfaceMain()');

			const visualProgram = program(surfaceVertex, `${visualSource}
				uniform float uVisualPixelSpan, uVisualOffset;
				void main() { vec2 position = vec2(gl_FragCoord.x * uVisualPixelSpan + uVisualOffset, 0.0);
					vec4 filtered = waterVisualSample(position, 0.0, dFdx(position), dFdy(position));
					vec4 original = terrainWaterSample(position, 0.0);
					color = vec4(0.5 + 0.5 * filtered.y, 0.5 + 0.5 * original.y,
						waterPhaseFilter(dFdx(position.x), dFdy(position.x)), 1.0); }`
			);

			gl.useProgram(visualProgram);
			gl.uniform1i(gl.getUniformLocation(visualProgram, 'uWaveCount'), 1);
			gl.uniform1i(gl.getUniformLocation(visualProgram, 'uWakeCount'), 0);
			gl.uniform4f(gl.getUniformLocation(visualProgram, 'uWaveVectors[0]'), 1, 0, 1, 1);
			gl.uniform2f(gl.getUniformLocation(visualProgram, 'uWaveDynamics[0]'), 0, 0);
			const aliasResults = [];

			for(const span of [.1,2.3,4])
			for(const offset of [0,.37,.73])
			{
				target(7, 64, 8);
				gl.uniform1f(gl.getUniformLocation(visualProgram, 'uVisualPixelSpan'), span);
				gl.uniform1f(gl.getUniformLocation(visualProgram, 'uVisualOffset'), offset);
				gl.drawArrays(gl.TRIANGLES, 0, 3);

				const bytes = new Uint8Array(64 * 8 * 4);
				gl.readPixels(0, 0, 64, 8, gl.RGBA, gl.UNSIGNED_BYTE, bytes);
				const channels = [0,1,2].map(channel => Array.from({length: 64*8}, (_, i) => bytes[i*4+channel]));
				aliasResults.push({span,offset,channels});
			}

			const reflectionProgram = program(surfaceVertex, `${visualSource}
				uniform float uReflectionElevation;
				void main() { vec3 ray = normalize(vec3(1.0,uReflectionElevation,0.0));
					vec3 reflected = waterEnvironmentReflection(ray,0.0,0.002,vec3(0.006,0.045,0.09),uSunIntensity/20.0);
					color = vec4(terrainToneMap(reflected),1.0); }`
			);

			parameters(reflectionProgram);
			gl.bindFramebuffer(gl.FRAMEBUFFER, null);
			gl.uniform1i(gl.getUniformLocation(reflectionProgram, 'uSkyCacheEye'), 2);
			gl.uniform1i(gl.getUniformLocation(reflectionProgram, 'uSkyCacheWater'), 3);
			gl.uniform2f(gl.getUniformLocation(reflectionProgram, 'uSkyCacheSize'), 256, 128);
			gl.uniform2f(gl.getUniformLocation(reflectionProgram, 'uSkyCacheAltitudes'), 100, 0);
			const reflectionResults = [];

			for(const cached of [0,1])
			for(const elevation of [-.2,-.1,-.01,-.002,-.001,0,.001,.002,.01,.1])
			{
				gl.uniform1i(gl.getUniformLocation(reflectionProgram, 'uSkyCacheReady'), cached);
				gl.uniform1f(gl.getUniformLocation(reflectionProgram, 'uReflectionElevation'), elevation);
				reflectionResults.push({cached,elevation,color: pixel()});
			}

			// Evaluate darkness directly; cache invalidation on irradiance changes has its own lifecycle regression.
			gl.uniform1i(gl.getUniformLocation(reflectionProgram, 'uSkyCacheReady'), 0);
			gl.uniform1f(gl.getUniformLocation(reflectionProgram, 'uSunIntensity'), 0);
			const darkReflections = [];

			for(const elevation of [-.2,0,.1])
			{
				gl.uniform1f(gl.getUniformLocation(reflectionProgram, 'uReflectionElevation'), elevation);
				darkReflections.push(pixel());
			}

			const facingProgram = program(surfaceVertex, `${visualSource}
				uniform vec3 uTestNormal, uTestView;
				void main() { vec3 normal = normalize(uTestNormal), view = normalize(uTestView);
					vec3 facing = waterFacingNormal(normal,view);
					color = vec4(0.5+0.5*dot(facing,view),length(facing),0.5+0.5*dot(normal,view),1.0); }`
			);

			gl.useProgram(facingProgram);
			const facingResults = [];

			for(const [normal,view] of [[[0,1,0],[1,.1,0]],[[-.95,.31,0],[1,.1,0]],[[0,1,0],[0,-1,0]]])
			{
				gl.uniform3fv(gl.getUniformLocation(facingProgram, 'uTestNormal'), normal);
				gl.uniform3fv(gl.getUniformLocation(facingProgram, 'uTestView'), view);
				facingResults.push(pixel());
			}

			const solarProgram = program(vertex, `${header}${data.source}
				uniform float uTestSolarFootprint; out vec4 color;
				void main() { vec2 angle = (gl_FragCoord.xy / 256.0 - 0.5) * 0.08;
					vec3 ray = normalize(vec3(angle.x,1.0,angle.y));
					vec3 light = terrainSolarDisk(ray,100.0,vec3(1.0),uTestSolarFootprint);
						float radius = 2.0 * sin(0.5 * uAngularRadius);
						color = vec4(light * TERRAIN_PI * radius * radius,1.0); }`
			);

			parameters(solarProgram);
			gl.uniform3f(gl.getUniformLocation(solarProgram, 'uSunDirection'), 0, 1, 0);
			gl.uniform1f(gl.getUniformLocation(solarProgram, 'uSunIntensity'), 1);
			const solarResults = [];

			for(const footprint of [0,.005,.02])
			{
				target(7, 256, 256);
				gl.uniform1f(gl.getUniformLocation(solarProgram, 'uTestSolarFootprint'), footprint);
				gl.drawArrays(gl.TRIANGLES, 0, 3);
				const bytes = new Uint8Array(256 * 256 * 4);
				gl.readPixels(0, 0, 256, 256, gl.RGBA, gl.UNSIGNED_BYTE, bytes);
				let energy = 0, peak = 0;

				for(let i = 0; i < bytes.length; i += 4)
				{
					energy += bytes[i];
					peak = Math.max(peak, bytes[i]);
				}

				solarResults.push({footprint,energy,peak});
			}

			gl.bindFramebuffer(gl.FRAMEBUFFER, null);

			const materialProgram = program(
				shaders['terrain.vert']
				, `${header}out vec4 c;void main(){c=vec4(0);}`
				, ['vPaint0','vPaint1']
			);

			const materialInput = gl.createBuffer();
			gl.bindBuffer(gl.ARRAY_BUFFER, materialInput);
			gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([1,4]), gl.STATIC_DRAW);
			gl.enableVertexAttribArray(3);
			gl.vertexAttribPointer(3, 1, gl.FLOAT, false, 4, 0);

			gl.bindTransformFeedback(gl.TRANSFORM_FEEDBACK, feedback);
			gl.bindBuffer(gl.TRANSFORM_FEEDBACK_BUFFER, outputBuffer);
			gl.bufferData(gl.TRANSFORM_FEEDBACK_BUFFER, 12*4, gl.DYNAMIC_READ);
			gl.useProgram(materialProgram);
			gl.enable(gl.RASTERIZER_DISCARD);
			gl.beginTransformFeedback(gl.POINTS);
			gl.drawArrays(gl.POINTS, 0, 2);
			gl.endTransformFeedback();

			const materialWeights = new Float32Array(12);
			gl.getBufferSubData(gl.TRANSFORM_FEEDBACK_BUFFER, 0, materialWeights);
			gl.disable(gl.RASTERIZER_DISCARD);
			gl.disableVertexAttribArray(3);
			gl.bindTransformFeedback(gl.TRANSFORM_FEEDBACK, null);

			// Exercise the real water vertex shader at all three types of new fine vertices:
			// horizontal/vertical edges and the parent triangle diagonal, with a wake crossing them.
			const waterMesh = program(shaders['water.vert'].replace('// WATER', waterSource),
				header + 'out vec4 c; void main(){c=vec4(1);}', ['vWorld','vGround']);
			gl.useProgram(waterMesh);
			const loc = name => gl.getUniformLocation(waterMesh, name);
			gl.uniform1i(loc('uWaveCount'), 1);
			gl.uniform4f(loc('uWaveVectors[0]'), 0.8, 0.6, 0.9, 2);
			gl.uniform2f(loc('uWaveDynamics[0]'), 1.1, 0.4);
			gl.uniform1i(loc('uFrameWakeCount'), 1);
			gl.uniform4f(loc('uFrameWakes[0]'), 20, 20, 3, .7);
			gl.uniform4f(loc('uWakeBounds'), -50, -50, 50, 50);
			gl.vertexAttrib3f(0, 0, -8, 0);
			gl.vertexAttrib1f(2, -3);
			gl.bindTransformFeedback(gl.TRANSFORM_FEEDBACK, feedback);
			gl.bindBuffer(gl.TRANSFORM_FEEDBACK_BUFFER, outputBuffer);
			gl.bufferData(gl.TRANSFORM_FEEDBACK_BUFFER, 16, gl.DYNAMIC_READ);
			gl.enable(gl.RASTERIZER_DISCARD);
			const sampleMesh = (x, z, step, morph) => {
				gl.uniform4f(loc('uPatch'), 0, 0, step, morph);
				gl.beginTransformFeedback(gl.POINTS);
				gl.drawArrays(gl.POINTS, z * 33 + x, 1);
				gl.endTransformFeedback();
				const result = new Float32Array(4);
				gl.getBufferSubData(gl.TRANSFORM_FEEDBACK_BUFFER, 0, result);
				return result;
			};
			let transitionError = 0;
			for(const time of [0, 1.5, 3.75])
			{
				gl.uniform1f(loc('uWaterTime'), time);
				for(const [x,z] of [[11,10],[10,11],[11,11],[10,10]])
				{
					const step = 1.953125, ox = x % 2, oz = -(z % 2);
					const a = sampleMesh((x-ox)/2, (z-oz)/2, step*2, 0);
					const b = sampleMesh((x+ox)/2, (z+oz)/2, step*2, 0);
					const fine = sampleMesh(x, z, step, 0);
					for(const morph of [.4,1])
					{
						const actual = sampleMesh(x, z, step, morph);
						for(let k = 0; k < 3; k++)
						{
							const expected = fine[k]*(1-morph) + .5*(a[k]+b[k])*morph;
							transitionError = Math.max(transitionError, Math.abs(actual[k]-expected));
						}
						transitionError = Math.max(transitionError, Math.abs(actual[3]-(-8+5*morph)));
					}
				}
			}
			gl.disable(gl.RASTERIZER_DISCARD);
			gl.bindTransformFeedback(gl.TRANSFORM_FEEDBACK, null);

			// Compile the real consuming scene programs too, so include/link compatibility is part of this regression.
			for(const name of ['sky', 'terrain', 'water'])
			program(
				shaders[`${name}.vert`].replace('// WATER', waterSource)
				, shaders[`${name}.frag`].replace('// ATMOSPHERE', data.source).replace('// WATER', waterSource)
			);
			// Render the stellar catalog through the horizon at two headings on an otherwise black target.
			const stars = program(integrate(shaders['stars.vert']), shaders['stars.frag']);
			target(7, 256, 128);
			gl.useProgram(stars);
			gl.uniform1f(gl.getUniformLocation(stars,'uPixelScale'), 1);
			gl.uniform1f(gl.getUniformLocation(stars,'uExposure'), 1);
			gl.enable(gl.BLEND);
			gl.blendFuncSeparate(gl.SRC_ALPHA, gl.ONE, gl.ZERO, gl.ONE);
			const starField = (visibility, yaw) => {
				const c = Math.cos(yaw), s = Math.sin(yaw);
				gl.uniformMatrix4fv(gl.getUniformLocation(stars,'uViewProjection'), false,
					[c,0,s,s, 0,2,0,0, s,0,-c,-c, 0,0,-.5,0]);
				gl.uniform1f(gl.getUniformLocation(stars,'uNightVisibility'), visibility);
				gl.clearColor(0,0,0,1);
				gl.clear(gl.COLOR_BUFFER_BIT);
				gl.drawArrays(gl.POINTS, 0, 2400);
				const pixels = new Uint8Array(256 * 128 * 4);
				gl.readPixels(0, 0, 256, 128, gl.RGBA, gl.UNSIGNED_BYTE, pixels);
				return pixels.filter((v,i) => i % 4 === 2);
			};
			const night = starField(1, 0), dusk = starField(.5, 0), day = starField(0, 0);
			const turned = starField(1, 1.2), returned = starField(1, 0);
			const litPixels = night.filter(v => v > 12).length;
			const starChecks = {
				litPixels, belowHorizon: night.slice(0, 256*64).some(v => v > 1),
				fade: dusk.every((v,i) => Math.abs(2*v-night[i]) <= 3),
				dayDark: day.every(v => v === 0), turned: turned.some((v,i) => Math.abs(v-night[i]) > 30),
				stable: returned.every((v,i) => v === night[i])
			};

			// Light level ground at the origin, seen from (0, 5, 5), at night with the lamps that PHP packed.
			gl.disable(gl.BLEND);

			const spotResults = {};

			/**
			 * Link a surface shader with the lit or the lamp-free spot-light include and set up the night scene.
			 * @param {string} name 'terrain' or 'water'.
			 * @param {string} spots Include for the "// SPOT LIGHTS" marker.
			 * @return {WebGLProgram} The program, in use.
			 */
			const atNight = (name, spots) => {
				const linked = program(
					surfaceVertex
					, shaders[`${name}.frag`].replace('// ATMOSPHERE', data.source).replace('// WATER', waterSource)
						.replace('// SPOT LIGHTS', spots)
				);

				parameters(linked);

				if(name === 'terrain') appearance(linked, data.appearance);

				gl.uniform3f(gl.getUniformLocation(linked, 'uEye'), 0, 5, 5);
				gl.uniform1f(gl.getUniformLocation(linked, 'uTestGround'), -3);
				gl.uniform1f(gl.getUniformLocation(linked, 'uSunIntensity'), 0);
				gl.uniform3fv(gl.getUniformLocation(linked, 'uSkyAmbient'), [0, 0, 0]);
				gl.uniform3fv(gl.getUniformLocation(linked, 'uNightAmbient'), data.nightAmbient);

				return linked;
			};

			/**
			 * Sink the surface under a meter of murky water, as for the water column check.
			 * @param {WebGLProgram} linked Program in use.
			 * @return {void}
			 */
			const submerge = linked => {
				gl.uniform1f(gl.getUniformLocation(linked, 'uWaterLevel'), 1);
				gl.uniform4f(gl.getUniformLocation(linked, 'uWaterOptics'), .85, .85, 1, .65);
			};

			for(const name of ['terrain', 'water'])
			{
				// Scene draws the lamp-free variant while no light is set. It must match the lit one without lamps.
				const lampFree = atNight(name, data.lampFreeSpotLights);
				const unlit = {dark: pixel()};
				submerge(lampFree);
				unlit.submerged = pixel();

				const surface = atNight(name, shaders['spot-lights.glsl']);
				const colors = {dark: pixel()};
				const lamps = gl.getUniformLocation(surface, 'uSpotLights[0]');
				// A count of zero ignores whatever the array holds.
				gl.uniform4fv(lamps, data.spotLights.pair['uSpotLights[0]']);
				colors.ignored = pixel();

				for(const [key, uniforms] of Object.entries(data.spotLights))
				{
					gl.uniform1i(gl.getUniformLocation(surface, 'uSpotLightCount'), uniforms.uSpotLightCount);

					if(uniforms.uSpotLightCount) gl.uniform4fv(lamps, uniforms['uSpotLights[0]']);

					colors[key] = pixel();
				}

				// Under a meter of murky water the column between the surface and the ground scatters lamp light.
				submerge(surface);
				gl.uniform1i(gl.getUniformLocation(surface, 'uSpotLightCount'), 0);
				colors.submerged = pixel();
				gl.uniform1i(gl.getUniformLocation(surface, 'uSpotLightCount'), 1);
				gl.uniform4fv(lamps, data.spotLights.column['uSpotLights[0]']);
				colors.column = pixel();
				spotResults[name] = {...colors, unlit};
			}

			// The overview and the fine grid use the same final, coverage-weighted ink blend.
			const chart = program(shaders['shadows.vert'], data.chartShader);
			gl.useProgram(chart);
			const chartLocation = key => gl.getUniformLocation(chart, key);
			target(7, 1, 1);
			gl.uniform1i(chartLocation('uLayerCount'), 1);
			gl.uniform3f(chartLocation('uLayerColor[0]'), 1, 0, 0);
			gl.uniform3f(chartLocation('uPaintColor[4]'), 0, 0, 1);
			gl.uniform1f(chartLocation('uExtent'), 256);
			gl.uniform1f(chartLocation('uWaterLevel'), -20);
			gl.uniform2f(chartLocation('uHeading'), 1, 0);
			const chartSamples = [], chartCutSamples = [], chartVerge = [];
			gl.uniform1i(chartLocation('uPacked'),1);
			gl.uniform3f(chartLocation('uPaintColor[1]'),0,1,0);
			for(const [n,unit,first] of [[257,0,128],[256,2,0]]) {
				const heights = new Float32Array(n*n);
				heights[(first+4)*n+first+1] = 12;
				texture(unit,n,n,new Uint8Array(heights.buffer));
				const inks = new Uint8Array(n*n*4);
				for(let y=0;y<n;y++) inks[4*(y*n+first)] = 5;
				for(const y of [first+3,first+4]) {
					inks[4*(y*n+first+1)] = 5;
					inks[4*(y*n+first+2)] = 2;
				}
				// Raw ink also covers the grading shoulder. Only the spline coordinate distinguishes it from pavement.
				for(let y=first+7;y<=first+9;y++) for(let x=first;x<=first+4;x++) {
					const i=4*(y*n+x);
					inks[i]=5;
					const code=((4096+(x<first+2 ? 1024 : 3072))<<11);
					inks[i+1]=code>>16; inks[i+2]=(code>>8)&255;
				}
				// At the fork boundary, signed coordinates from a single arm meet the
				// unsigned distance to both arms. The grass in the median must survive.
				for(let y=first+11;y<=first+13;y++) for(let k=0;k<4;k++) {
					const distance=k<2 ? .75 : 1.25;
					const code=k%2 ? (8191<<11)|(distance*1024) : (4096-distance*2048)<<11;
					inks.set([5,code>>16,(code>>8)&255,code&255],4*(y*n+first+k));
				}
				texture(unit+1,n,n,inks);
			}
			for(const [i,key] of ['uOverview','uMapInk','uFine','uFineInk'].entries()) gl.uniform1i(chartLocation(key),i);
			for(const localOrigin of [500,-32]) {
				gl.uniform4f(chartLocation('uLocal'),localOrigin,localOrigin,1,64);
				const samples = [];
				for(const x of [-1,-.5,0]) {
					gl.uniform4f(chartLocation('uChart'),x,0,1,1);
					samples.push(pixel());
				}
				chartSamples.push(samples);
				const cut = [];
				for(const [x,z] of [[.2,3.2],[.8,3.8],[1.5,3.8]]) {
					gl.uniform4f(chartLocation('uChart'),x,z,1,1);
					cut.push(pixel());
				}
				chartCutSamples.push(cut);
				for(const [x,z] of [[.2,8],[2.2,8],[.5,12],[2.5,12]]) {
					gl.uniform4f(chartLocation('uChart'),x,z,.02,1);
					chartVerge.push(pixel());
				}
			}

			const journeyColors = [];
			for(const {shader,block} of data.journey) {
				const linked = program(shaders['shadows.vert'],shader);
				gl.useProgram(linked);
				const bytes = Uint8Array.from(atob(block), c=>c.charCodeAt(0));
				const index = gl.getUniformBlockIndex(linked,'JourneyPalettes');
				if(gl.getActiveUniformBlockParameter(linked,index,gl.UNIFORM_BLOCK_DATA_SIZE)!==bytes.length)
					throw Error('PHP and the shader disagree on the journey palette layout');
				const buffer = gl.createBuffer();
				gl.bindBuffer(gl.UNIFORM_BUFFER,buffer);
				gl.bufferData(gl.UNIFORM_BUFFER,bytes,gl.STATIC_DRAW);
				gl.bindBufferBase(gl.UNIFORM_BUFFER,0,buffer);
				gl.uniform1f(gl.getUniformLocation(linked,'uJoinWidth'),4096);
				const colors = [];
				const points=[[-2048,0],[2048,-2048],[2048,2048],[0,0],[-1024,1024]];
				for(const enabled of [0,1]) for(const [x,z] of points) {
					gl.uniform1i(gl.getUniformLocation(linked,'uBlendProfile'),enabled);
					gl.uniform3f(gl.getUniformLocation(linked,'atWorld'),x,10,z);
					colors.push(pixel(4));
				}
				gl.uniform1i(gl.getUniformLocation(linked,'uBlendProfile'),0);
				gl.uniform1i(gl.getUniformLocation(linked,'magnifyGreen'),1);
				for(const t of [.03,.055,.065]) {
					gl.uniform3f(gl.getUniformLocation(linked,'atWorld'),4096*(t-.5),10,-2048);
					colors.push(pixel(4));
				}
				gl.deleteBuffer(buffer);
				journeyColors.push(colors);
			}

			// Compare the four cached fields to direct evaluation on both sides of a physical texture wrap.
			const library = shaders['surface-layers.glsl']+'\n'+shaders['material-noise.glsl'];
			const bake = program(shaders['shadows.vert'],shaders['material-cache.frag']
				.replace('// LAYERS',library).replace('// MATERIAL NOISE',''));
			const fieldTexture=gl.createTexture(), fieldFbo=gl.createFramebuffer(), size=16;
			gl.activeTexture(gl.TEXTURE12);
			gl.bindTexture(gl.TEXTURE_2D_ARRAY,fieldTexture);
			gl.texStorage3D(gl.TEXTURE_2D_ARRAY,1,gl.RGBA8,size,size,4);
			gl.texParameteri(gl.TEXTURE_2D_ARRAY,gl.TEXTURE_MIN_FILTER,gl.LINEAR);
			gl.texParameteri(gl.TEXTURE_2D_ARRAY,gl.TEXTURE_MAG_FILTER,gl.LINEAR);
			gl.bindFramebuffer(gl.FRAMEBUFFER,fieldFbo);
			const outputs=[0,1,2,3].map(i=>gl.COLOR_ATTACHMENT0+i);
			for(const [i,attachment] of outputs.entries())
				gl.framebufferTextureLayer(gl.FRAMEBUFFER,attachment,fieldTexture,0,i);
			gl.drawBuffers(outputs);
			gl.disable(gl.BLEND);
			gl.disable(gl.DITHER);
			gl.useProgram(bake);
			const regions=[[16384,-32,17,4],[-8192,17,65535,73],[175,-16222,99,8]];
			gl.uniform4fv(gl.getUniformLocation(bake,'uRegions[0]'),regions.flat());
			gl.uniform1f(gl.getUniformLocation(bake,'uStep'),2);
			for(const x of [-8,0]) for(const z of [-8,0]) {
				const u=x&15, v=z&15;
				gl.viewport(u,v,8,8);
				gl.uniform2f(gl.getUniformLocation(bake,'uCellOffset'),x-u,z-v);
				gl.drawArrays(gl.TRIANGLES,0,3);
			}
			const compare=program(shaders['shadows.vert'],header+library+`
				uniform highp sampler2DArray cache; uniform vec2 xz; uniform vec4 region;
				uniform int slice; uniform bool useCache; out vec4 result;
				void main() {
					vec2 p=xz-region.xy;
					result=useCache ? texture(cache,vec3((xz/2.+.5)/16.,float(slice))) :
						slice==3 ? .5+.5*scatterBands(xz) : vec4(macroBands(p),.5+.5*grassStand(p,ivec2(region.zw)));
				}`);
			target(0,1,1);
			gl.useProgram(compare);
			gl.uniform1i(gl.getUniformLocation(compare,'cache'),12);
			for(const [i,region] of [...regions,regions[0]].entries()) {
				gl.uniform1i(gl.getUniformLocation(compare,'slice'),i);
				gl.uniform4fv(gl.getUniformLocation(compare,'region'),region);
				for(const xy of [[-14,12],[-.5,.5],[1.25,-3.25],[11.7,7.5]]) {
					gl.uniform2fv(gl.getUniformLocation(compare,'xz'),xy);
					gl.uniform1i(gl.getUniformLocation(compare,'useCache'),0);
					const expected=pixel(4);
					gl.uniform1i(gl.getUniformLocation(compare,'useCache'),1);
					if(pixel(4).some((byte,j)=>Math.abs(byte-expected[j])>3))
						throw Error(`Cached material field ${i} diverged at ${xy}`);
				}
			}
			gl.deleteFramebuffer(fieldFbo);
			gl.deleteTexture(fieldTexture);

			// The four material taps straddle two surfaces at different depths. The foreground is red
			// and the background blue. Clearing or painting a tap must affect the very next lookup.
			const resolve=program(shaders['shadows.vert'],header+'#define READ_SURFACE_SAMPLES\n'+shaders['surface-samples.glsl']+`
				out vec4 color;
				void main() {
					vec3 pigment=vec3(0.,1.,0.), normal=vec3(0.,1.,0.);
					nativeDepthSlope=vec2(dFdx(gl_FragCoord.w),dFdy(gl_FragCoord.w));
					bool found=sampleSurface(vec3(0.,1.,0.),pigment,normal);
					color=vec4(found ? pigment : vec3(0.,1.,0.),1.);
				}`);
			target(0,1,1);
			gl.useProgram(resolve);
			gl.uniform2f(gl.getUniformLocation(resolve,'uSurfacePixel'),1,1);
			gl.uniform1i(gl.getUniformLocation(resolve,'uMaterialPigment'),13);
			gl.uniform1i(gl.getUniformLocation(resolve,'uMaterialNormal'),14);
			const redDepth=Math.round(65535/17), blueDepth=Math.round(Math.log2(21)*65535/17);
			const redSample=[180,0,0,redDepth>>8], blueSample=[0,0,180,blueDepth>>8];
			const up=depth=>[128,128,depth&255,255];
			texture(13,2,2,Uint8Array.from([...redSample,...blueSample,...redSample,...blueSample]));
			texture(14,2,2,Uint8Array.from([...up(redDepth),...up(blueDepth),...up(redDepth),...up(blueDepth)]));
			const edge=pixel();
			if(edge[0]<250 || edge[1]!==0 || edge[2]!==0) throw Error('Different depths bled across a silhouette: '+edge);
			texture(14,2,2,new Uint8Array(16));
			if(String(pixel())!=='0,255,0') throw Error('An empty material neighborhood must request the base pigment');
			texture(13,2,2,Uint8Array.from(Array(4).fill([0,0,180,redDepth>>8]).flat()));
			texture(14,2,2,Uint8Array.from(Array(4).fill(up(redDepth)).flat()));
			if(pixel()[2]<250) throw Error('A replacement surface still shows the previous pigment');

			const error = gl.getError();

			if(error !== gl.NO_ERROR) throw new Error(`WebGL error: ${error}`);

			return {
				skyResults, bandSamples, thinLayerSamples, transitionError, starChecks, chartSamples, chartCutSamples, chartVerge, stripeCuts, junctionInk, journeyColors
				, spotResults, dashPixels, paintEdges
				, aerialResults, canyonHaze, dustViews
				, waterResults, backdropContrast, waterPaths
				, shadowResults, adaptiveResults
				, illuminationResults, iceSamples
				, bandResults
				, mountainBands
				, mountainAmounts, rockDetail, granitePigment, hillsideCover, maxUnresolvedTilt, slopeRelief, drainage
				, inactiveAppearance

				, aliasResults
				, reflectionResults
				, darkReflections
				, facingResults
				, solarResults
				, materialWeights: Array.from(materialWeights)
			};
		}, {data, shaders});

		assert.equal(result.junctionInk,0,'A center dash cannot spill sideways across the junction');
		for(const [level,bank,transition,cliff,reverseCliff] of result.stripeCuts) {
			assert.ok(level > 5 && bank === level && transition > 0 && transition < bank,
				`Paint glows on the bank and fades on a steepening slope: ${[level,bank,transition,cliff,reverseCliff]}`);
			assert.deepEqual([cliff,reverseCliff],[0,0],'Uphill and downhill cut walls have no glowing markings');
		}
		const dash = result.dashPixels;
		assert.ok(dash.near[0] > 5 && dash.near[2] === 0, 'A resolved dash retains its paint and gap');
		for(const sweep of [dash.cycle, dash.several, dash.subpixel]) {
			assert.ok(Math.min(...sweep) > 0 && Math.max(...sweep)-Math.min(...sweep) <= 1,
				`Unresolved dashes keep a stable mean as the view moves: ${sweep}`);
		}
		assert.ok(dash.several[0] < dash.near[0] && dash.subpixel[0] < dash.several[0],
			'The stripe dims in proportion to the paint inside the pixel');
		assert.deepEqual(dash.beside,[0,0,0,0], 'The distant centerline stays inside the projected stripe');
		const [originEdges,...translatedEdges] = result.paintEdges;
		for(const [inside,edge,outside] of originEdges)
			assert.ok(inside > edge && edge > outside && outside === 0, 'Subpixel samples resolve the painted edge');
		for(const moved of translatedEdges)
			assert.deepEqual(moved,originEdges,'World position must not scatter pixels at the centerline or shoulder');
		assert.ok(dash.across[0] > 0 && dash.across[2] === 0,
			'A pixel extending across the road still resolves the gap along it');
		const red=[255,0,0,0], green=[0,255,0,204], blue=[0,0,255,0];
		for(const [i,expected] of [[red,green,green,[128,128,0,102],[229,26,0,21]],[red,green,blue,[128,64,64,51],[229,3,24,2]]].entries()) {
			const colors=result.journeyColors[i];
			assert.deepEqual(colors.slice(5,8),colors.slice(0,3),'Pure landscape materials survive the noisy transition.');
			assert.ok(colors.slice(8,10).some((pixel,j)=>Math.abs(pixel[0]-colors[3+j][0])>1),
				'World-space noise varies material coverage inside the connector.');
			const [trimmed, fading, full] = colors.slice(10).map(pixel=>pixel[1]);
			assert.equal(trimmed,0,'A negligible second material drops out entirely.');
			assert.ok(fading>0 && fading<35 && full>55 && full<70,'A growing material eases back to its full weight.');
			for(const [j,rgba] of expected.entries())
				assert.ok(rgba.every((v,k)=>Math.abs(result.journeyColors[i][j][k]-v)<=1),
					'Regional color and coating weights agree at the endpoints and in the transition: '+JSON.stringify(result.journeyColors[i][j]));
		}
		for(const [i,sample] of result.chartVerge.entries()) {
			const channel=i%2===0 ? 2 : 0;
			assert.ok(sample[channel]>240 && sample[2-channel]===0,'The road map respects the authored pavement edge');
		}
		for(const [level,cliff,mixed] of result.chartCutSamples) {
			assert.ok(level[2]>200 && level[0]===0, 'Level half of a road cell stays paved');
			assert.ok(cliff[0]>180 && cliff[2]===0, 'Steep half exposes the natural material');
			assert.ok(mixed[0]>100 && mixed[1]>100 && mixed[2]===0, 'Only the pavement fraction of mixed wall paint is removed');
		}
		const stars = result.starChecks;
		const ice = result.iceSamples;
		assert.equal(ice.gradedShoulder,ice.glint,'The grading shoulder outside the authored width retains its terrain finish');
		assert.equal(ice.wall,ice.bareWall,'Revealing the cliff also restores its natural surface finish');
		assert.ok(ice.grazing > ice.normal+40, `Exposed ice reflects more sky at grazing incidence: ${JSON.stringify(ice)}`);
		assert.ok(ice.glint > ice.turned+40 && ice.glint > ice.frost+40 && ice.glint > ice.shaded+40,
			'Polished ice catches a sharp, view-dependent solar highlight in sunlight');
		assert.ok(ice.shoulder[1] > ice.shoulder[0]+10, 'Frost spreads reflected sunlight beyond the polished solar disc');
		for(const surface of ['road','submerged','covered']) assert.equal(ice[surface][0],ice[surface][1], `${surface} covers the ice reflection`);
		assert.ok(stars.litPixels > 80 && stars.litPixels < 2500 && !stars.belowHorizon, 'stars sparsely cover the visible sky');
		assert.ok(stars.fade && stars.dayDark, 'stars fade through twilight and vanish by day');
		assert.ok(stars.turned && stars.stable, 'the star field is fixed to viewing directions');
		assert.ok(result.transitionError < 1e-5, 'waves, wakes and shoreline depths match the morphing mesh');

		const near = (a, b) => a.every((value, i) => Math.abs(value - b[i]) <= 1);

		for(const [name, colors] of Object.entries(result.spotResults))
		{
			for(const key of ['ignored', 'none', 'away', 'outside', 'short', 'below'])
			assert.deepEqual(colors[key], colors.dark, `${name}: the ${key} case adds no lamp light`);

			assert.deepEqual(
				colors.unlit
				, {dark: colors.dark, submerged: colors.submerged}
				, `${name}: the lamp-free program draws what the lit one draws without lamps`
			);

			// The knee keeps color in light that would otherwise clip: 64 times the overhead lamp stays below white.
			assert.ok(
				colors.glare.every((value, i) => value > colors.overhead[i] && value < 250)
				, `${name}: 64 times the lamp is brighter and does not clip: ${colors.overhead} to ${colors.glare}`
			);

			// The cones fade the compressed light, so halfway through its band the glare lamp gives a quarter of
			// what it gives on its axis, less than the overhead lamp. Compressed after the cone, it would be
			// brighter.
			assert.ok(
				colors.band.every((value, i) => value > colors.dark[i] && value < colors.overhead[i])
				, `${name}: the cone band dims the glare lamp below the overhead lamp: ${colors.band}`
			);

			if(name === 'terrain')
			assert.ok(
				colors.column[1] > colors.submerged[1] + 5 && colors.column[2] > colors.submerged[2] + 5
				, `the water column scatters lamp light: ${colors.submerged} to ${colors.column}`
			);

			const gain = colors.overhead.map((value, i) => value - colors.dark[i]);

			assert.ok(
				Math.min(...gain) > 0 && gain[0] + gain[1] + gain[2] > 30
				, `${name}: an overhead lamp lights the surface at night: ${colors.dark} to ${colors.overhead}`
			);

			// Inverse squares, the cosine of incidence and the sum over lamps each keep the irradiance.
			for(const key of ['higher', 'oblique', 'pair'])
			assert.ok(
				near(colors[key], colors.overhead)
				, `${name}: the ${key} lamp gives the same irradiance: ${colors[key]} and ${colors.overhead}`
			);
		}

		for(const [i, expected] of [0, 128, 255].entries())
			assert.ok(Math.abs(result.backdropContrast[i] - expected) <= 2, 'surface transmits the selected fraction');
		assert.ok(result.backdropContrast[3] < 255 && result.backdropContrast[3] > 230, 'reflection overlays a clear surface');
		assert.ok(result.backdropContrast[4] < 200, 'foam remains visible on the wet side of the shoreline');
		assert.equal(result.backdropContrast[5], 255, 'shore foam leaves deep water clear');
		const [dry, touching, film, shallow, submerged, distant, crest] = result.backdropContrast.slice(6);
		assert.equal(dry, 255);
		assert.equal(touching, 255, 'even opaque, foamy water transmits the bed at contact');
		assert.ok(film >= 250 && film > shallow && shallow > submerged, 'a rising surface fades in continuously');
		assert.equal(submerged, 0, 'resolved submerged water retains the selected opacity');
		assert.ok(distant > shallow, 'a pixel spanning the shoreline has a wider transition');
		assert.equal(crest, 255, 'an optical crest cannot light a dry, intersecting triangle');
		for(const [i, meters] of [0, 1, 10, Math.sqrt(2)*10].entries())
		{
			const red = Math.round(255 * Math.exp(-.1 * meters));
			assert.ok(Math.abs(result.waterPaths[i][0] - red) <= 1);
			assert.ok(Math.abs(result.waterPaths[i][1] - (255 - red)) <= 1);
		}

		// Along each view path, transmitted energy is exp(-optical depth). Each thin slice scatters its share of the
		// sunlight that reaches it, split between the two colors in proportion to their extinction; the overhead sun
		// is dimmed by the layers above the slice.
		const bands = [[100,120,.005,0],[105,130,.002,1]];
		for(const [index,[eye,up,distance]] of [[0,1,50],[0,1,110],[0,1,10000],[200,-1,200]].entries()) {
			let transmission = 1;
			const scattered = [0,0], steps = 20000, slice = distance/steps;
			for(let i = 0; i < steps; i++) {
				const height = eye + up*(i + .5)*slice;
				const k = [0,0];
				let above = 0;
				for(const [bottom,top,extinction,color] of bands) {
					const h = Math.max(0,Math.min(1,(height-bottom)/(top-bottom)));
					k[color] += extinction*6*h*(1-h);
					above += extinction*(top-bottom)*(1-3*h*h+2*h*h*h);
				}
				const after = transmission*Math.exp(-(k[0] + k[1])*slice);
				if(k[0] + k[1] > 0) for(const c of [0,1])
					scattered[c] += (transmission - after)*k[c]/(k[0] + k[1])*Math.exp(-above);
				transmission = after;
			}
			const expected = [transmission,...scattered].map(value=>Math.round(255*value));
			assert.ok(result.bandSamples[index].every((v,c)=>Math.abs(v-expected[c])<=1),
				`Atmosphere path ${index}: GPU ${result.bandSamples[index]}, reference ${expected}`);
		}
		// Sunlight inside and just above a thin layer matches the exact CPU transmittance. Through the texture alone
		// it was 0.255 instead of 0.004 at 600 m.
		for(const [index,[altitude,mu,exact]] of data.thinLayer.samples.entries()) {
			const expected = exact.map(value=>Math.round(255*value));
			assert.ok(result.thinLayerSamples[index].every((v,c)=>Math.abs(v-expected[c])<=4),
				`Thin layer at ${altitude} m, mu ${mu.toFixed(3)}: GPU ${result.thinLayerSamples[index]}, exact ${expected}`);
		}

		let skyError = 0, aerialError = 0, waterError = 0;

		for(const sample of result.skyResults)
		{
			const delta = Math.max(...sample.colors[0].map((value, i) => Math.abs(value - sample.colors[1][i])));
			skyError = Math.max(skyError, delta);
			assert.ok(delta <= 4, `Sky cache color deviation: ${JSON.stringify(sample)}`);
		}

		for(const sample of result.aerialResults)
		{
			const delta = Math.max(...sample.colors[0].map((value, i) => Math.abs(value - sample.colors[1][i])));
			aerialError = Math.max(aerialError, delta);
			const identity = sample.distance === 0 || sample.distance > 90000 || sample.altitude !== 100;
			assert.ok(delta <= (identity ? 0 : 4), `Aerial cache color deviation: ${JSON.stringify(sample)}`);
		}

		for(const [clear,below,above] of result.canyonHaze)
		{
			assert.ok(Math.max(...below.map((c,i)=>Math.abs(c-clear[i]))) > 8, 'sun haze reaches visible terrain below eye level');
			assert.ok(below.every((c,i)=>Math.abs(c-above[i]) <= 2), 'sun haze is continuous across the canyon horizon');
		}

		for(const ready of [0,1]) {
			const delta=(a,b)=>Math.max(...a.map((value,i)=>Math.abs(value-b[i])));
			const [clear,aloft,filled]=result.dustViews.map(row=>row[ready]);
			for(const profile of [aloft,filled]) {
				assert.deepEqual(profile[0],clear[0],'A surface at the camera has no fog');
				assert.ok(delta(profile[2],profile[3])<=3,'Dust stays continuous across eye level on a visible wall');
			}
			assert.ok(delta(filled[2],aloft[2])>delta(filled[1],aloft[1])+2,'Dust below sea level increases with the distance to visible terrain');
		}

		for(const sample of result.waterResults)
		{
			const delta = Math.max(...sample.expected.map((value, i) => Math.abs(value - sample.actual[i])));
			waterError = Math.max(waterError, delta);
			// GLSL uses float32 phase arithmetic; world-edge coordinates need a larger bound than local samples.
			const tolerance = Math.max(...sample.position.map(Math.abs)) > 10000 ? .003 : .0002;
			for(let i = 0; i < 3; i++)
				assert.ok(Math.abs(sample.actual[7+i] - sample.expected[i]) <= tolerance, 'prepared wake height and slopes match physics');
			const foam = sample.actual[10];
			if(sample.name === 'wakes' && sample.time === .001 && sample.position.every(value => value === 0))
				assert.ok(foam > .9, 'fresh propulsion visibly churns the water at the stern');
			if(sample.name !== 'wakes' || sample.time >= 3 || sample.position[0] < -100)
				assert.equal(foam, 0, 'unpowered, expired and distant water has no jet wash');

			assert.ok(
				Number.isFinite(delta) && delta <= tolerance
				, `Water CPU/GPU deviation: ${JSON.stringify(sample)}`
			);
		}

		const [wall, stripe, packedWall, packedStripe] = result.adaptiveResults;
		assert.deepEqual([packedWall, packedStripe], [wall, stripe], 'packed heights produce the same edges as float heights');
		assert.equal(wall.record[0], 3, 'only the second corner lies in shadow');
		assert.deepEqual(wall.pixels.slice(0,3), [255,0,255], 'the shadow edge cuts across the near triangle');
		assert.ok(wall.pixels[3] > 180 && wall.pixels[3] < 211, 'about three quarters of the distant triangle is lit');
		assert.deepEqual(wall.pixels.slice(4), [0,0], 'the reflected triangle meets the same edge on the diagonal');
		assert.equal(stripe.record[0], 8, 'the center probe finds the narrow shadow between three lit vertices');
		assert.deepEqual(stripe.pixels.slice(0,3), [255,255,0], 'only the interior of the near triangle is shaded');
		assert.ok(stripe.pixels[3] > 204 && stripe.pixels[3] < 255, 'the interior shadow contributes to distant coverage');
		const visibility = Object.fromEntries(result.shadowResults.map(sample => [sample.name, sample.visibility]));
		assert.deepEqual(visibility.flat, [255, 255], 'a flat plane remains sunlit even at low sun angles');
		assert.deepEqual(visibility.ridge, [0, 255], 'a broad ridge blocks the down-sun side');
		assert.deepEqual(visibility.reversed, [255, 0], 'reversing the sun reverses the ridge shadow');
		assert.deepEqual(visibility['near rim'], [0,255], 'a one-texel rim between the old long ray steps casts a shadow');
		assert.deepEqual(visibility.ledges, [0,255], 'the true receiver elevation keeps the lower ledge dark and the upper ledge lit');
		assert.ok(visibility.transition[1] >= 126 && visibility.transition[1] <= 129, 'lighting blends across the mesh detail transition');
		assert.deepEqual(visibility.distant,[0,0], 'changing a distant triangle elevation does not move its shadow');
		assert.ok(Math.abs(visibility['snap -56'][0]-128)<=1 && visibility['snap -56'][1]>245);
		assert.ok(visibility['snap -56'].every((v,i)=> Math.abs(v-visibility['snap -48'][i])<=1), 'recentering the local grid preserves its blend');
		assert.deepEqual(visibility.night, [0, 0], 'the coarse map contains no direct visibility at night');

		for(const sample of result.illuminationResults)
		{
			assert.deepEqual(sample.colors[0], [0, 0, 0], `${sample.name} has no unlit ambient/body/foam emission`);
			assert.ok(sample.night[2] > sample.night[0] && sample.night[1] > sample.night[0], `${sample.name} receives cool night fill`);
			assert.ok(sample.colors[1].some(channel => channel > 0), `${sample.name} receives nonzero daylight`);
		}

		for(const [land, shoulder, road] of result.chartSamples) {
			assert.ok(land[0]>240 && land[2]===0, 'unpainted minimap uses the natural stack');
			assert.ok(road[2]>240 && road[0]===0, 'full pavement overrides the natural stack');
			assert.ok(shoulder[0]>150 && shoulder[2]>150, 'the shoulder blends both materials');
		}

		assert.deepEqual(result.inactiveAppearance, [], 'the reference reads the ink palette and ridge fields');

		const [, grass, redGrass] = result.illuminationResults.find(sample => sample.name === 'terrain').colors;
		assert.ok(grass[1] > grass[0] && grass[1] > grass[2], `default grass paint draws green: ${grass}`);
		assert.ok(redGrass[0] > redGrass[1] && redGrass[0] > redGrass[2], `red grass paint draws red: ${redGrass}`);

		const amplitude = (values, bin) => {
			let real = 0, imaginary = 0;

			values.forEach((value, i) => {
				real += value * Math.cos(2 * Math.PI * bin * i / values.length);
				imaginary -= value * Math.sin(2 * Math.PI * bin * i / values.length);
			});

			return 2 * Math.hypot(real, imaginary) / values.length;
		};

		// A harmonic past the pixel rate folds back onto a bin below it. Tone mapping folds back 1 to 2% of a plain
		// sine's amplitude. Sharpened bands must fade their harmonics out before they fold back 6%.
		const bandAliases = [];

		for(const {sharpness, bin, red} of result.bandResults)
		{
			const resolved = new Set(), folded = new Set();

			for(let k = 1; k * bin <= 128; k++) resolved.add(k * bin);

			// Bins 1 to 3 hold the slow change of sunlight with height.
			for(let k = 2; k <= 21; k++)
			{
				const wrapped = k * bin % 256, alias = wrapped > 128 ? 256 - wrapped : wrapped;

				if(k * bin > 128 && alias > 3 && !resolved.has(alias)) folded.add(alias);
			}

			const fundamental = amplitude(red, bin);
			const ratio = Math.hypot(...[...folded].map(alias => amplitude(red, alias))) / fundamental;
			bandAliases.push({sharpness, bin, fundamental: +fundamental.toFixed(2), ratio: +ratio.toFixed(4)});
		}

		console.log('Folded band harmonics:', JSON.stringify(bandAliases));

		for(const {sharpness, bin, fundamental, ratio} of bandAliases)
		{
			assert.ok(fundamental > 5, `bands at sharpness ${sharpness}, bin ${bin} are visible: ${fundamental}`);
			assert.ok(ratio < .06, `bands at sharpness ${sharpness}, bin ${bin} alias by ${ratio} of their amplitude`);
		}

		// The mountain face varies, but no more at the band frequency than at the frequencies around it.
		const mountainBands = result.mountainBands.map(({bin, red}) => {
			const around = [-3, -2, -1, 1, 2, 3].map(step => amplitude(red, bin + step));

			return {bin, band: +amplitude(red, bin).toFixed(2), around: +Math.max(...around).toFixed(2)};
		});

		console.log('Mountain face at the band frequencies:', JSON.stringify(mountainBands));

		for(const {bin, band, around} of mountainBands)
		{
			const coast = bandAliases.find(sample => sample.sharpness === 1 && sample.bin === bin).fundamental;
			const flat = band < coast / 4 && band <= Math.max(1, 1.5 * around);
			assert.ok(flat, `mountain rock has no bands at bin ${bin}: ${band}`);
		}

		// Talus and stain colors must show at their default amounts and nowhere at an amount of 0.
		console.log('Mountain colors shown by amount:', JSON.stringify(result.mountainAmounts));

		for(const {material, amount, share, maxError} of result.mountainAmounts)
		{
			assert.ok(maxError<=1, `Layered ${material} differs from the reference by ${maxError} channel steps`);
			if(amount === 0) assert.equal(share, 0, `${material} shows on ${share} of a face at an amount of 0`);
			else assert.ok(share > .01, `${material} shows on ${share} of a face at an amount of ${amount}`);
		}

		console.log('Rock field samples in 8-bit channel steps:', result.rockDetail);
		const {centerEdge,edgeJump,aspectJump,toneRange,screeRange,reliefJump,reliefRange} = result.rockDetail;
		assert.ok(centerEdge < 4, 'stone centers and edges have similar average brightness');
		assert.ok(edgeJump < 14, 'scree cover varies continuously across the former cell boundaries');
		assert.ok(aspectJump < 3, 'granite joints continue around the diagonal between upright projections');
		assert.ok(reliefJump <= 1 && reliefRange > 12, 'scree relief varies across the face and stays stable under a change of view');
		assert.ok(toneRange > 18 && screeRange > 32, 'loose rock keeps both tonal detail and patchy coverage');

		const {localRange,distantRange,resizedDelta,off} = result.granitePigment;
		assert.ok(localRange > 45 && resizedDelta > 12, 'granite pigment resolves locally and responds to slab spacing');
		assert.equal(distantRange,0,'unresolved granite pigment converges to the same average across a face');
		assert.equal(off,0,'zero pattern amount restores the base granite color');

		for(const {seed, meters, material, p95Error, range} of result.hillsideCover) {
			const label = `${material} at ${meters} m per pixel with seed ${seed}`;
			assert.ok(p95Error < (meters <= 8 ? 24 : 45), `${label} differs from area coverage by ${p95Error} steps`);
			assert.ok(range > 32, `${label} still varies across the hillside`);
		}
		assert.equal(result.maxUnresolvedTilt, 0, 'one-meter ribs leave no lighting grid at eight meters per pixel');
		for(const {field,seed,degrees,bearing,across,downhill} of result.drainage) {
			const label = `${field} on a ${degrees}° slope at bearing ${bearing} with seed ${seed}`;
			assert.ok(across > 2*downhill, `${label} should run downhill (across ${across}, downhill ${downhill})`);
			assert.ok(downhill > 1, `${label} should still change along its course`);
		}
		let previous = [1,1,1];
		for(const {degrees,full,damped,strong,sameTint} of result.slopeRelief) {
			assert.ok(sameTint, 'rock tint stays fixed when its lighting relief is damped');
			if(degrees === 0) {
				assert.equal(full[0], 0, 'gullies end on level ground');
				assert.deepEqual(strong, full, 'level rock keeps its full relief');
				continue;
			}
			const ratios = damped.map((value,i) => value/full[i]);
			for(const [i,name] of ['ribs','facets','blocks'].entries()) {
				assert.ok(full[i] > 0 && ratios[i] < previous[i], `${name} relief fades on the ${degrees} degree face`);
				assert.ok(strong[i] <= damped[i], `increasing damping smooths ${name}`);
			}
			if(degrees === 50) {
				assert.ok(ratios[0] > .45 && ratios[0] < .9, 'broad ribs retain their form on steep rock');
				assert.ok(ratios[1] < .5*ratios[0] && ratios[2] < .5*ratios[0], 'fine rock detail softens first');
			}
			if(degrees === 90) assert.deepEqual(damped,[0,0,0], 'a vertical face has finite, fully damped rock relief');
			previous = ratios;
		}

		const variance = values => {
			const mean = values.reduce((sum, value) => sum + value, 0) / values.length;

			return values.reduce((sum, value) => sum + (value-mean)**2, 0) / values.length;
		};

		for(const sample of result.aliasResults)
		{
			const [filtered,original,foam] = sample.channels;

			if(sample.span === .1)
			assert.deepEqual(filtered, original, 'resolved close waves keep their full shading slopes');

			if(sample.span === 2.3)
			{
				const ratio = variance(filtered) / variance(original);
				assert.ok(ratio > .05 && ratio < .6, 'near-Nyquist wave energy fades smoothly');
			}

			if(sample.span === 4)
			{
				assert.ok(variance(original)>1000, 'the unfiltered reference exhibits unresolved oscillation');

				assert.ok(
					Math.max(...filtered)-Math.min(...filtered)<=1
					, 'unresolved normals remain stable across pixels/phases'
				);

				assert.ok(foam.every(value => value===0), 'unresolved foam modulation is removed');
			}
		}

		for(const sample of result.reflectionResults)
		assert.ok(Math.max(...sample.color)>40, 'daytime lower-hemisphere reflection has finite nonblack illumination');

		for(const cached of [0,1])
		{
			const horizon = result.reflectionResults.filter(
				sample => sample.cached===cached&&Math.abs(sample.elevation)<=.002
			);

			for(let i = 1; i<horizon.length; i++)
			assert.ok(
				horizon[i].color.every((value, channel) => Math.abs(value-horizon[i-1].color[channel])<=5)
				, 'water reflection remains continuous across the horizon'
			);
		}

		for(const color of result.darkReflections)
		assert.deepEqual(color, [0,0,0], 'environment fallback emits no light');

		for(const color of result.facingResults)
		assert.ok(color[0]>=127&&color[1]===255, 'corrected shading normals remain unit length and face the viewer');

		assert.ok(
			Math.abs(result.facingResults[0][0]-result.facingResults[0][2])<=1
			, 'resolved facing normals are unchanged'
		);

		const solarRadius = 2 * Math.sin(data.uniforms.angularRadius / 2);
		const expectedSolarEnergy = 255 * Math.PI * solarRadius ** 2 / (.08 / 256) ** 2;

		assert.ok(
			Math.abs(result.solarResults[0].energy / expectedSolarEnergy - 1) < .03
			, 'the point-sampled disk retains its physical angular area'
		);

		for(const sample of result.solarResults.slice(1))
		{
			assert.ok(
				Math.abs(sample.energy / result.solarResults[0].energy - 1) < .03
				, `solar footprint filtering preserves energy: ${JSON.stringify(result.solarResults)}`
			);
		}

		assert.ok(
			result.solarResults[2].peak < result.solarResults[1].peak
			, 'larger reflection footprints lower solar peaks'
		);

		assert.deepEqual(
			result.materialWeights
			, [0,1,0,0,0,0,0,0,0,0,1,0]
			, 'material endpoint weights select only grass/road'
		);

		for(const blend of [.25,.5,.75])
		{
			const start = result.materialWeights.slice(0, 6), end = result.materialWeights.slice(6);
			const weights = start.map((value, i) => value * (1 - blend) + end[i] * blend);

			assert.deepEqual(
				weights
				, [0,1-blend,0,0,blend,0]
				, 'interpolated grass/road weights cannot invent sand or snow'
			);
		}

		const counts = [result.skyResults.length, result.aerialResults.length, result.waterResults.length];
		const errors = [skyError, aerialError, waterError];

		console.log('GPU parity (sky/aerial/water):', {samples: counts, maximumErrors: errors});
		console.log('Water footprint cases:', result.aliasResults.length, 'solar coverage:', result.solarResults);
	}
	finally
	{
		await browser.close();
	}
});
