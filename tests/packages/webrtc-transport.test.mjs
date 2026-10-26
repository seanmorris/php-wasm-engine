import test from 'node:test';
import assert from 'node:assert/strict';
import {WebRtcTransport} from '../../browser/network/WebRtcTransport.mjs';

class FakeChannel
{

	constructor(label)
	{
		this.label = label;
		this.readyState = 'connecting';
		this.bufferedAmount = 0;
		this.sent = [];
	}

	open() { this.readyState = 'open'; this.onopen?.(); }

	send(value) { this.sent.push(new Uint8Array(value)); }

	close() { this.readyState = 'closed'; this.onclose?.(); }

	message(value) { this.onmessage?.({data: value.buffer}); }
}

class FakePeerConnection
{

	constructor()
	{
		this.connectionState = 'new';
		this.signalingState = 'stable';
		this.channels = [];
		this.candidates = [];
	}

	createDataChannel(label)
	{
		const channel = new FakeChannel(label);
		this.channels.push(channel);

		return channel;
	}

	async setLocalDescription()
	{
		this.localDescription = {
			type: this.signalingState === 'have-remote-offer' ? 'answer' : 'offer'
			, sdp: 'fixture'
		};
	}

	async setRemoteDescription(value)
	{
		this.remoteDescription = value;
		this.signalingState = value.type === 'offer' ? 'have-remote-offer' : 'stable';
	}

	async addIceCandidate(value)
	{
		if(!this.remoteDescription) throw new DOMException('The remote description was null', 'InvalidStateError');

		this.candidates.push(value);
	}

	close() { this.connectionState = 'closed'; }
}

test('WebRTC transport opens two channels and sheds disposable traffic under pressure', async () => {
	const signals = [];

	const transport = new WebRtcTransport({
		RTCPeerConnection: FakePeerConnection
		, sendSignal: value => signals.push(value)
		, maximumBufferedBytes: 8
		, timeout: 1000
	});

	const opened = transport.start({initiator: true});
	const [control,realtime] = transport.pc.channels;

	control.open();
	realtime.open();
	await opened;
	assert.equal(transport.send('control', new Uint8Array([1,2,3])), true);
	realtime.bufferedAmount = 8;
	assert.equal(transport.send('realtime', new Uint8Array([4])), false);
	assert.equal(transport.diagnostics().droppedRealtime, 1);

	const received = new Promise(resolve => transport.addEventListener('message', resolve, {once: true}));
	realtime.bufferedAmount = 0;
	realtime.message(new Uint8Array([9,8]));
	assert.deepEqual([...((await received).detail.data)], [9,8]);
	await transport.receiveSignal({candidate: {candidate: 'candidate'}});
	assert.equal(transport.pc.candidates.length, 0);
	assert.equal(transport.diagnostics().pendingCandidates, 1);

	await transport.receiveSignal({description: {type: 'offer', sdp: 'fixture'}});
	assert.equal(transport.pc.candidates.length, 1);
	assert.equal(transport.diagnostics().pendingCandidates, 0);
	transport.close();
	transport.close();
});

test('WebRTC transport serializes native descriptions and candidates before signaling', async () => {
	const signals = [];

	const transport = new WebRtcTransport({
		RTCPeerConnection: FakePeerConnection
		, sendSignal: value => {
			structuredClone(value);
			signals.push(value);
		}
		, timeout: 1000
	});

	transport.pc.setLocalDescription = async () => {
		const type = transport.pc.signalingState === 'have-remote-offer' ? 'answer' : 'offer';

		transport.pc.localDescription = new Proxy({type, sdp: `native ${type}`}, {});
	};

	await transport.pc.onnegotiationneeded();
	assert.deepEqual(signals[0], {description: {type: 'offer', sdp: 'native offer'}});

	await transport.receiveSignal({description: {type: 'offer', sdp: 'remote offer'}});
	assert.deepEqual(signals[1], {description: {type: 'answer', sdp: 'native answer'}});

	const candidate = new Proxy({
		candidate: 'candidate:fixture'
		, sdpMid: '0'
		, sdpMLineIndex: 0
		, usernameFragment: 'fixture'
	}, {});

	transport.pc.onicecandidate({candidate});

	await Promise.resolve();

	assert.deepEqual(signals[2], {
		candidate: {
			candidate: 'candidate:fixture'
			, sdpMid: '0'
			, sdpMLineIndex: 0
			, usernameFragment: 'fixture'
		}
	});

	transport.close();
});

test('WebRTC transport discards candidates that belong to an ignored colliding offer', async () => {
	const signals = [];

	const transport = new WebRtcTransport({
		RTCPeerConnection: FakePeerConnection
		, sendSignal: value => signals.push(value)
		, timeout: 1000
	});

	transport.makingOffer = true;
	await transport.receiveSignal({candidate: {candidate: 'candidate:before-collision'}});
	assert.equal(transport.diagnostics().pendingCandidates, 1);
	await transport.receiveSignal({description: {type: 'offer', sdp: 'colliding offer'}});
	assert.equal(transport.diagnostics().pendingCandidates, 0);
	assert.equal(transport.pc.remoteDescription, undefined);

	await transport.receiveSignal({candidate: {candidate: 'candidate:ignored-offer'}});
	assert.equal(transport.diagnostics().pendingCandidates, 0);

	transport.makingOffer = false;
	await transport.receiveSignal({description: {type: 'offer', sdp: 'accepted offer'}});
	assert.equal(transport.pc.candidates.length, 0);
	assert.equal(signals[0].description.type, 'answer');
	transport.close();
});
