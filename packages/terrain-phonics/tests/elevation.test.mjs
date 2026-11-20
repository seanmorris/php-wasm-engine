import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {chromium} from '@playwright/test';

test('GPU heights agree with the PHP lattice on cliffs and at the world edges',async()=>{
	const cases=JSON.parse(execFileSync('php',[fileURLToPath(new URL('elevation-reference.php',import.meta.url))]));
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
			gl.bindTexture(gl.TEXTURE_2D,permutation); gl.viewport(0,0,32,1);
			gl.uniform1i(loc('uPacked'),1); gl.uniform1f(loc('uStep'),1); gl.uniform4f(loc('uRegion'),16384,0,1,0);
			return cases.map(({recipe:r,perm,samples})=>{
				gl.texImage2D(gl.TEXTURE_2D,0,gl.R8,256,1,0,gl.RED,gl.UNSIGNED_BYTE,new Uint8Array(perm));
				for(const [name,keys] of Object.entries({uShape:['baseBias','rolling','directionalRise','ridgeHeight'],
					uProfile:['ridgeRoundness','wallHeight','floorWidth','wallWidth'],uField:['heightScale','baseFrequency','warpAmplitude','warpFrequency'],
					uRock:['secondary','terraceSpacing','terraceStrength','bedding'],uDetail:['detailAmplitude','persistence','lacunarity','damping']}))
					gl.uniform4fv(loc(name),keys.map(k=>r[k]));
				gl.uniform2f(loc('uFieldResidual'),r.baseFrequency-Math.fround(r.baseFrequency),r.warpFrequency-Math.fround(r.warpFrequency));
				gl.uniform1f(loc('uSlopeScale'),r.slopeScale); gl.uniform1i(loc('uOctaves'),r.detailOctaves);
				let error=0;
				for(const [x,z,reference] of samples) {
					gl.uniform2i(loc('uOriginCell'),x,z); gl.drawArrays(gl.TRIANGLES,0,3);
					const bytes=new Uint8Array(128); gl.readPixels(0,0,32,1,gl.RGBA,gl.UNSIGNED_BYTE,bytes);
					const values=new Float32Array(bytes.buffer);
					reference.forEach((h,i)=>{error=Math.max(error,Math.abs(h-values[i]));});
				}
				return [r.seed,r.preset,r.version,error];
			});
		},{cases,vert:source('shadows.vert'),frag:source('elevation.frag')});
		assert.ok(errors.every(e=>e[3]<=.005),JSON.stringify(errors));
	} finally {await browser.close();}
});
