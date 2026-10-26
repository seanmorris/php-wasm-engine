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
