import assert from 'node:assert/strict';
import test from 'node:test';
import {SafeStorage} from '../../browser/SafeStorage.mjs';

test('SafeStorage persists strings and keeps a session overlay', () => {
	const values = new Map([['old', 'disk']]);
	const storage = {
		getItem: key => values.get(key) ?? null
		, setItem: (key, value) => values.set(key, value)
		, removeItem: key => values.delete(key)
	};

	const safe = new SafeStorage(storage);

	assert.equal(safe.get('old'), 'disk');
	assert.equal(safe.set('new', 42), true);
	assert.equal(safe.get('new'), '42');
	assert.equal(values.get('new'), '42');
	assert.equal(safe.remove('old'), true);
	assert.equal(safe.get('old'), null);
	assert.equal(safe.sessionOnly, false);
});

test('SafeStorage contains backing-store failures for the rest of the session', () => {
	const storage = {
		getItem: () => { throw new Error('blocked'); }
		, setItem: () => { throw new Error('quota'); }
		, removeItem: () => { throw new Error('blocked'); }
	};

	const safe = new SafeStorage(storage);

	assert.equal(safe.get('missing'), null);
	assert.equal(safe.set('answer', 'yes'), false);
	assert.equal(safe.get('answer'), 'yes');
	assert.equal(safe.remove('answer'), false);
	assert.equal(safe.get('answer'), null);
	assert.equal(safe.sessionOnly, true);
});
