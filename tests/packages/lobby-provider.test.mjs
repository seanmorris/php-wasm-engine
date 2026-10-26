import test from 'node:test';
import assert from 'node:assert/strict';
import {
	InMemoryLobbyHub
	, InMemoryLobbyProvider
} from '../../browser/network/InMemoryLobbyProvider.mjs';

const once = (target, name) => new Promise(resolve => target.addEventListener(name, resolve, {once: true}));

test('in-memory lobby provides presence, finite invitations, signaling, and chat', async () => {
	const hub = new InMemoryLobbyHub;
	const host = new InMemoryLobbyProvider({hub, peerId: 'host', displayName: 'Host'});
	const guest = new InMemoryLobbyProvider({hub, peerId: 'guest', displayName: 'Guest'});
	const hostPresence = once(host, 'presence');

	await host.connect();
	const guestPresence = once(guest, 'presence');
	await guest.connect();

	assert.equal((await hostPresence).detail.peers[0].id, 'host');
	assert.deepEqual((await guestPresence).detail.peers.map(peer => peer.id), ['host','guest']);

	const incoming = once(guest, 'invitation');
	const invitation = await host.invite('guest', {mode: 'deathmatch'});
	assert.equal((await incoming).detail.invitation.payload.mode, 'deathmatch');
	const accepted = once(host, 'invitationstate');
	await guest.respond(invitation, 'accepted');
	assert.equal((await accepted).detail.state, 'accepted');

	const signal = once(guest, 'signal');
	await host.signalPeer('guest', {candidate: {candidate: 'fixture'}});
	assert.equal((await signal).detail.from, 'host');

	const chat = once(host, 'chat');
	await guest.chat('ready');
	assert.equal((await chat).detail.text, 'ready');
	host.close();
	guest.close();
});
