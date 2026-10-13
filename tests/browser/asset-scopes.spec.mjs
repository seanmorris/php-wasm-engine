import {test, expect} from '@playwright/test';
import {AssetLoader} from '../../browser/AssetLoader.mjs';

test('scope progress counts failures and resets a retried dependency to pending', async () => {
	const originalFetch = globalThis.fetch;
	const transfers = [];
	globalThis.fetch = () => new Promise(resolve => transfers.push(resolve));
	const assets = new AssetLoader({mkdirTree() {}, writeFile() {}, unlink() {}},
		'https://example.test/engine/');
	try
	{
		const scope = assets.scope(), path = '/engine/title.tmj';
		scope.request(path);
		transfers.shift()(new Response('Offline', {status: 503}));
		await assets.files.get(path).promise;
		expect(scope.progress()).toEqual({ready: 0, total: 1, failed: 1});
		scope.request(path, true);
		expect(scope.progress()).toEqual({ready: 0, total: 1, failed: 0});
		transfers.shift()(new Response('{}'));
		await assets.files.get(path).promise;
		expect(scope.progress()).toEqual({ready: 1, total: 1, failed: 0});
		scope.close();
		expect(scope.progress()).toEqual({ready: 0, total: 0, failed: 0});
	}
	finally
	{
		assets.close();
		globalThis.fetch = originalFetch;
	}
});

test('asset scopes share pending files and retain mounted files until their last owner closes',
async () => {
	const mounted = new Map(), transfers = [];
	const originalFetch = globalThis.fetch;
	globalThis.fetch = (_url, {signal}) => new Promise(resolve => {
		transfers.push({signal, resolve});
	});
	const assets = new AssetLoader({
		mkdirTree() {}, writeFile: (path, bytes) => mounted.set(path, bytes),
		unlink: path => mounted.delete(path)
	}, 'https://example.test/engine/');
	try
	{
		const first = assets.scope(), second = assets.scope();
		const path = '/engine/tiles.png';
		expect(first.progress()).toEqual({ready: 0, total: 0, failed: 0});
		expect(first.request(path)).toBe(false);
		expect(second.request(path)).toBe(false);
		expect(transfers).toHaveLength(1);
		expect(second.progress()).toEqual({ready: 0, total: 1, failed: 0});
		first.close();
		expect(transfers[0].signal.aborted).toBe(false);
		transfers[0].resolve(new Response('image'));
		await assets.files.get(path).promise;
		expect(second.request(path)).toBe(true);
		expect(second.progress()).toEqual({ready: 1, total: 1, failed: 0});
		expect(mounted.has(path)).toBe(true);
		second.release(path);
		expect(second.progress()).toEqual({ready: 0, total: 0, failed: 0});
		expect(mounted.has(path)).toBe(false);
		second.close();
		expect(first.request(path)).toBe('Asset scope is closed');
	}
	finally
	{
		assets.close();
		globalThis.fetch = originalFetch;
	}
});

test('closing an asset scope cancels unshared downloads and rejects late responses', async () => {
	const mounted = new Map();
	const originalFetch = globalThis.fetch;
	let resolve, signal;
	globalThis.fetch = (_url, options) => {
		signal = options.signal;
		return new Promise(done => { resolve = done; });
	};
	const assets = new AssetLoader({
		mkdirTree() {}, writeFile: (path, bytes) => mounted.set(path, bytes),
		unlink: path => mounted.delete(path)
	}, 'https://example.test/engine/');
	try
	{
		const scope = assets.scope();
		scope.request('/engine/late.png');
		const request = assets.files.get('/engine/late.png').promise;
		scope.close();
		expect(signal.aborted).toBe(true);
		resolve(new Response('late image'));
		await request;
		expect(mounted.size).toBe(0);
	}
	finally
	{
		assets.close();
		globalThis.fetch = originalFetch;
	}
});

test('an explicit retry replaces malformed mounted data without unlinking another scope', async () => {
	const mounted = new Map(), transfers = [];
	const originalFetch = globalThis.fetch;
	globalThis.fetch = () => new Promise(resolve => { transfers.push(resolve); });
	const assets = new AssetLoader({
		mkdirTree() {}, writeFile: (path, bytes) => mounted.set(path, new TextDecoder().decode(bytes)),
		unlink: path => mounted.delete(path)
	}, 'https://example.test/engine/');
	try
	{
		const first = assets.scope(), second = assets.scope(), path = '/engine/map.tmj';
		first.request(path);
		transfers[0](new Response('malformed'));
		await assets.files.get(path).promise;
		expect(second.request(path)).toBe(true);
		expect(second.request(path, true)).toBe(false);
		expect(mounted.get(path)).toBe('malformed');
		first.close();
		transfers[1](new Response('{"valid":true}'));
		await assets.files.get(path).promise;
		expect(second.request(path)).toBe(true);
		expect(mounted.get(path)).toBe('{"valid":true}');
		second.close();
		expect(mounted.size).toBe(0);
	}
	finally
	{
		assets.close();
		globalThis.fetch = originalFetch;
	}
});
