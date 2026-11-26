import test from 'node:test';
import assert from 'node:assert/strict';
import {cacheProgramValidation} from '../../browser/GlesPrograms.mjs';

function fixture()
{
	const live = new WeakSet();
	const calls = {create: 0, remove: 0, query: 0};
	const gl = {

		createProgram() { calls.create++; const program = {}; live.add(program); return program; }


		, deleteProgram(program) { calls.remove++; live.delete(program); }


		, isProgram(program) { calls.query++; return live.has(program); }
	};

	return {gl, calls, canvas: new EventTarget(), live};
}

test('tracks created and deleted program handles without synchronous validation', () => {
	const {gl, calls, canvas} = fixture();
	assert.equal(cacheProgramValidation(gl, canvas), gl);
	const program = gl.createProgram();
	assert.equal(gl.isProgram(program), true);
	assert.equal(gl.isProgram(program), true);
	assert.equal(calls.query, 0);
	gl.deleteProgram(program);
	assert.equal(gl.isProgram(program), false);
	assert.equal(calls.query, 1);
});

test('caches existing handles and invalidates the cache after context loss', () => {
	const {gl, calls, canvas, live} = fixture();
	const program = {};
	live.add(program);
	cacheProgramValidation(gl, canvas);
	assert.equal(gl.isProgram(program), true);
	assert.equal(gl.isProgram(program), true);
	assert.equal(calls.query, 1);
	canvas.dispatchEvent(new Event('webglcontextlost'));
	assert.equal(gl.isProgram(program), true);
	assert.equal(calls.query, 2);
	cacheProgramValidation(gl, canvas);
	assert.equal(gl.isProgram(program), true);
	assert.equal(calls.query, 2);
});

test('the cached methods stay assignable so hosts and specs can hook them', () => {
	const {gl, calls, canvas} = fixture();
	cacheProgramValidation(gl, canvas);
	const program = gl.createProgram();
	const cached = gl.isProgram;
	let hooked = 0;

	gl.isProgram = handle => {
		hooked++;
		gl.isProgram = cached;

		return handle === program ? false : cached(handle);
	};

	assert.equal(gl.isProgram(program), false);
	assert.equal(hooked, 1);
	assert.equal(gl.isProgram(program), true);
	assert.equal(calls.query, 0);
});

test('element queries follow VAO bindings, storage changes and context restoration', async () => {
	const {cacheIndexQueries} = await import('../../browser/GlesPrograms.mjs');
	const canvas = new EventTarget();
	const bindings = new Map();
	const sizes = new Map();
	let active = null, bindingReads = 0, sizeReads = 0;
	const otherTargets = new Map();
	const gl = {
		ARRAY_BUFFER: 34962
		, ELEMENT_ARRAY_BUFFER: 34963
		, ELEMENT_ARRAY_BUFFER_BINDING: 34965
		, BUFFER_SIZE: 34660


		, createVertexArray() { return {}; }


		, bindVertexArray(vao) { if(!arguments.length) throw new TypeError(); active = vao; }


		, deleteVertexArray(vao) { if(active === vao) active = null; bindings.delete(vao); }


		, bindBuffer(target, buffer) {
			if(buffer?.invalid) return;

			if(Number(target) === 34963) bindings.set(active, buffer);
			else otherTargets.set(target, buffer);
		}


		, bufferData(target, size) {
			sizes.set(target === 34963 ? bindings.get(active) : otherTargets.get(target), size);
		}


		, deleteBuffer(buffer) { if(bindings.get(active) === buffer) bindings.delete(active); }


		, getParameter() { bindingReads++; return bindings.get(active) ?? null; }


		, getBufferParameter() { sizeReads++; return sizes.get(bindings.get(active)); }


		, isContextLost() { return false; }
	};

	cacheIndexQueries(gl, canvas);
	cacheIndexQueries(gl, canvas);
	const a = gl.createVertexArray(), b = gl.createVertexArray(), buffer = {};

	const binding = () => gl.getParameter(gl.ELEMENT_ARRAY_BUFFER_BINDING);

	const size = () => gl.getBufferParameter(gl.ELEMENT_ARRAY_BUFFER, gl.BUFFER_SIZE);
	gl.bindVertexArray(a);
	gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, buffer);
	gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, 16, 0);
	assert.equal(binding(), buffer);
	assert.equal(size(), 16);
	assert.equal(size(), 16);
	gl.bindVertexArray(b);
	assert.equal(binding(), null);
	gl.bindVertexArray(a);
	assert.equal(binding(), buffer);
	assert.deepEqual([bindingReads,sizeReads], [2,1]);
	gl.bindBuffer(gl.ARRAY_BUFFER, {});
	gl.bufferData(gl.ARRAY_BUFFER, 4096, 0);
	assert.equal(size(), 16);
	assert.equal(sizeReads, 1);
	const copyWriteBuffer = 36663;
	gl.bindBuffer(copyWriteBuffer, buffer);
	gl.bufferData(copyWriteBuffer, 32, 0);
	assert.equal(binding(), buffer);
	assert.equal(size(), 32);
	assert.equal(sizeReads, 2);
	gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, 48, 0);
	assert.equal(size(), 48);
	gl.bindBuffer('34963', {invalid: true});
	assert.equal(binding(), buffer);
	assert.equal(bindingReads, 4);
	assert.throws(() => gl.bindVertexArray(), TypeError);
	canvas.dispatchEvent(new Event('webglcontextrestored'));
	assert.equal(binding(), buffer);
	assert.equal(bindingReads, 5);
	gl.deleteBuffer(buffer);
	assert.equal(binding(), null);
});
