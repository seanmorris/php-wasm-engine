import test from 'node:test';
import assert from 'node:assert/strict';
import {cachePixelStoreQueries} from '../../browser/GlesPixelStore.mjs';

function fixture()
{
	const state = {rowLength: 0, lost: false, error: null};
	const calls = {queries: 0, writes: 0};
	const gl = {
		UNPACK_ROW_LENGTH: 0x0cf2

		, getParameter(parameter) {
			calls.queries++;

			if(arguments.length === 0) throw new TypeError('Missing parameter');

			return state.lost ? null : parameter === this.UNPACK_ROW_LENGTH ? state.rowLength : 'other';
		}

		, pixelStorei(parameter, value) {
			calls.writes++;

			if(arguments.length < 2) throw new TypeError('Missing value');

			if(value < 0) state.error = 'INVALID_VALUE';
			else if(parameter === this.UNPACK_ROW_LENGTH) state.rowLength = value;
		}

		, isContextLost() {return state.lost;}
	};

	return {gl, calls, state, canvas: new EventTarget()};
}

test('caches only pixel-layout queries, separately for each context', () => {
	const {gl, calls, canvas} = fixture();
	assert.equal(cachePixelStoreQueries(gl, canvas), gl);
	assert.equal(gl.getParameter(gl.UNPACK_ROW_LENGTH), 0);
	assert.equal(gl.getParameter(gl.UNPACK_ROW_LENGTH), 0);
	assert.equal(calls.queries, 1);

	assert.equal(gl.getParameter(123), 'other');
	assert.equal(gl.getParameter(123), 'other');
	assert.equal(calls.queries, 3);

	const other = fixture();
	other.state.rowLength = 9;
	cachePixelStoreQueries(other.gl, other.canvas);
	assert.equal(other.gl.getParameter(other.gl.UNPACK_ROW_LENGTH), 9);
});

test('observes valid writes without predicting the outcome of invalid writes', () => {
	const {gl, calls, canvas, state} = fixture();
	cachePixelStoreQueries(gl, canvas);
	assert.equal(gl.getParameter(gl.UNPACK_ROW_LENGTH), 0);
	gl.pixelStorei(gl.UNPACK_ROW_LENGTH, 16);
	assert.equal(gl.getParameter(gl.UNPACK_ROW_LENGTH), 16);

	gl.pixelStorei(gl.UNPACK_ROW_LENGTH, -1);
	assert.equal(gl.getParameter(gl.UNPACK_ROW_LENGTH), 16);
	assert.equal(state.error, 'INVALID_VALUE');
	assert.equal(calls.queries, 3);
	assert.equal(calls.writes, 2);
	assert.throws(() => gl.getParameter(), TypeError);
	assert.throws(() => gl.pixelStorei(gl.UNPACK_ROW_LENGTH), TypeError);
});

test('does not serve cached values while lost, including before the loss event', () => {
	const {gl, calls, canvas, state} = fixture();
	state.rowLength = 8;
	cachePixelStoreQueries(gl, canvas);
	assert.equal(gl.getParameter(gl.UNPACK_ROW_LENGTH), 8);

	state.lost = true;
	assert.equal(gl.getParameter(gl.UNPACK_ROW_LENGTH), null);
	assert.equal(gl.getParameter(gl.UNPACK_ROW_LENGTH), null);
	canvas.dispatchEvent(new Event('webglcontextlost'));

	state.lost = false;
	state.rowLength = 0;
	canvas.dispatchEvent(new Event('webglcontextrestored'));
	assert.equal(gl.getParameter(gl.UNPACK_ROW_LENGTH), 0);
	cachePixelStoreQueries(gl, canvas);
	assert.equal(gl.getParameter(gl.UNPACK_ROW_LENGTH), 0);
	assert.equal(calls.queries, 4);
});
