import {NetworkError, networkFailure} from './Errors.mjs';

const event = (type, detail) => new CustomEvent(type, {detail});

const descriptionTypes = new Set(['offer', 'answer', 'pranswer', 'rollback']);

const nativeRecord = (value, label) => {
	if(!value || typeof value !== 'object') throw new TypeError(`${label} must be an object`);

	const record = typeof value.toJSON === 'function' ? value.toJSON() : value;

	if(!record || typeof record !== 'object') throw new TypeError(`${label}.toJSON() must return an object`);

	return record;
};

class SessionDescriptionSignal
{

	constructor(value)
	{
		value = nativeRecord(value, 'WebRTC session description');

		if(!descriptionTypes.has(value.type)) throw new TypeError('Invalid WebRTC session description type');

		if(typeof value.sdp !== 'string') throw new TypeError('WebRTC session description SDP must be a string');

		this.type = value.type;
		this.sdp = value.sdp;
		Object.freeze(this);
	}

	toJSON() { return {type: this.type, sdp: this.sdp}; }
}

class IceCandidateSignal
{

	constructor(value)
	{
		value = nativeRecord(value, 'WebRTC ICE candidate');

		if(typeof value.candidate !== 'string') throw new TypeError('WebRTC ICE candidate must be a string');

		if(value.sdpMid !== undefined && value.sdpMid !== null && typeof value.sdpMid !== 'string')
		throw new TypeError('WebRTC ICE candidate sdpMid must be a string or null');

		if(value.sdpMLineIndex !== undefined && value.sdpMLineIndex !== null
			&& (!Number.isInteger(value.sdpMLineIndex) || value.sdpMLineIndex < 0)
		) throw new TypeError('WebRTC ICE candidate sdpMLineIndex must be a non-negative integer or null');

		if(value.usernameFragment !== undefined && value.usernameFragment !== null
			&& typeof value.usernameFragment !== 'string'
		) throw new TypeError('WebRTC ICE candidate usernameFragment must be a string or null');

		this.candidate = value.candidate;
		this.sdpMid = value.sdpMid ?? null;
		this.sdpMLineIndex = value.sdpMLineIndex ?? null;
		this.usernameFragment = value.usernameFragment ?? null;
		Object.freeze(this);
	}

	toJSON()
	{
		return {
			candidate: this.candidate
			, sdpMid: this.sdpMid
			, sdpMLineIndex: this.sdpMLineIndex
			, usernameFragment: this.usernameFragment
		};
	}
}

class WebRtcSignalingMessage
{

	constructor(value)
	{
		if(!value || typeof value !== 'object') throw new TypeError('WebRTC signaling message must be an object');

		const hasDescription = value.description !== undefined && value.description !== null;
		const hasCandidate = value.candidate !== undefined && value.candidate !== null;

		if(hasDescription === hasCandidate)
		throw new TypeError('WebRTC signaling message must contain one description or ICE candidate');

		if(hasDescription) this.description = new SessionDescriptionSignal(value.description);
		else this.candidate = new IceCandidateSignal(value.candidate);

		Object.freeze(this);
	}

	toJSON()
	{
		if(this.description) return {description: this.description.toJSON()};

		return {candidate: this.candidate.toJSON()};
	}
}

/** Role-aware WebRTC transport with perfect negotiation and two traffic classes. */
export class WebRtcTransport extends EventTarget
{

	constructor(
		{
			RTCPeerConnection: PeerConnection = globalThis.RTCPeerConnection
			, iceServers = []
			, polite = false
			, sendSignal
			, signal
			, timeout = 20_000
			, maximumPacketBytes = 1024 * 1024
			, maximumBufferedBytes = 4 * 1024 * 1024
		} = {}
	) {
		super();

		if(typeof PeerConnection !== 'function') throw new NetworkError('webrtc_unavailable', 'WebRTC is unavailable');

		if(typeof sendSignal !== 'function') throw new TypeError('WebRtcTransport requires sendSignal');

		this.sendSignal = sendSignal;
		this.polite = Boolean(polite);
		this.timeout = timeout;
		this.maximumPacketBytes = maximumPacketBytes;
		this.maximumBufferedBytes = maximumBufferedBytes;
		this.controller = new AbortController;
		this.signal = signal ? AbortSignal.any([signal, this.controller.signal]) : this.controller.signal;
		this.pc = new PeerConnection({iceServers});
		this.channels = new Map;
		this.pendingCandidates = [];
		this.makingOffer = false;
		this.ignoreOffer = false;
		this.settingRemoteAnswer = false;
		this.applyingRemoteDescription = false;
		this.started = false;
		this.closed = false;
		this.droppedRealtime = 0;
		this.sentBytes = 0;
		this.receivedBytes = 0;
		this.install();
	}

	/** Start negotiation; the initiator creates both negotiated traffic classes. */
	async start({initiator = false} = {})
	{
		if(this.started) return this.opened;

		this.started = true;

		this.opened = new Promise((resolve, reject) => {
			this.resolveOpen = resolve;
			this.rejectOpen = reject;
		});

		this.opened.catch(() => {});

		if(initiator)
		{
			this.attach(this.pc.createDataChannel('control', {ordered: true}));
			this.attach(this.pc.createDataChannel('realtime', {ordered: false, maxRetransmits: 0}));
		}

		const timer = setTimeout(
			() => this.fail(new NetworkError('webrtc_timeout', 'WebRTC connection timed out'))
			, this.timeout
		);

		this.opened.finally(() => clearTimeout(timer)).catch(() => {});

		return this.opened;
	}

	/** Apply an authenticated signaling message from the selected lobby provider. */
	async receiveSignal(message)
	{
		this.requireOpen(false);

		try
		{
			const signal = new WebRtcSignalingMessage(message);

			if(signal.description)
			{
				const description = signal.description.toJSON();
				const ready = !this.makingOffer
					&& (this.pc.signalingState === 'stable' || this.settingRemoteAnswer);

				const collision = description.type === 'offer' && !ready;
				this.ignoreOffer = !this.polite && collision;

				if(this.ignoreOffer)
				{
					this.pendingCandidates.length = 0;

					return;
				}

				this.settingRemoteAnswer = description.type === 'answer';
				this.applyingRemoteDescription = true;

				try
				{
					await this.pc.setRemoteDescription(description);
				}
				finally
				{
					this.settingRemoteAnswer = false;
					this.applyingRemoteDescription = false;
				}

				if(description.type === 'rollback') this.pendingCandidates.length = 0;
				else await this.flushCandidates();

				if(description.type === 'offer')
				{
					await this.pc.setLocalDescription();
					await this.transmitSignal({description: this.pc.localDescription});
				}
			}
			else
			{
				const candidate = signal.candidate.toJSON();

				if(this.ignoreOffer) return;

				if(this.applyingRemoteDescription || !this.pc.remoteDescription)
				{
					this.queueCandidate(candidate);

					return;
				}

				await this.applyCandidate(candidate);
			}
		}
		catch(error)
		{
			this.fail(networkFailure('webrtc_signal', 'Cannot apply WebRTC signaling', error));
			throw error;
		}
	}

	/** Send control reliably or drop obsolete realtime data under pressure. */
	send(channel, value)
	{
		this.requireOpen();
		const data = value instanceof Uint8Array ? value : new Uint8Array(value);

		if(data.byteLength > this.maximumPacketBytes)
		throw new NetworkError('packet_size', `Packet exceeds ${this.maximumPacketBytes} bytes`);

		const target = this.channels.get(channel);

		if(!target || target.readyState !== 'open') throw new NetworkError('channel_closed', `${channel} is not open`);

		if(target.bufferedAmount + data.byteLength > this.maximumBufferedBytes)
		{
			if(channel === 'realtime')
			{
				this.droppedRealtime++;

				return false;
			}

			throw new NetworkError('transport_backpressure', 'Reliable WebRTC queue is full');
		}

		target.send(data);
		this.sentBytes += data.byteLength;

		return true;
	}

	diagnostics()
	{
		return Object.freeze({
			connectionState: this.pc.connectionState
			, signalingState: this.pc.signalingState
			, controlState: this.channels.get('control')?.readyState ?? 'missing'
			, realtimeState: this.channels.get('realtime')?.readyState ?? 'missing'
			, bufferedBytes: [...this.channels.values()].reduce((sum, channel) => sum + channel.bufferedAmount, 0)
			, pendingCandidates: this.pendingCandidates.length
			, droppedRealtime: this.droppedRealtime
			, sentBytes: this.sentBytes
			, receivedBytes: this.receivedBytes
		});
	}

	close(reason = 'closed')
	{
		if(this.closed) return;

		this.closed = true;
		this.controller.abort(new NetworkError('transport_closed', reason));

		for(const channel of this.channels.values()) try { channel.close(); } catch {}

		this.channels.clear();
		this.pendingCandidates.length = 0;

		try { this.pc.close(); } catch {}

		this.rejectOpen?.(new NetworkError('transport_closed', reason));
		this.dispatchEvent(event('close', {reason}));
	}

	install()
	{

		this.pc.onicecandidate = ({candidate}) => {
			if(candidate) this.transmitSignal({candidate}).catch(error => this.fail(error));
		};

		this.pc.ondatachannel = ({channel}) => this.attach(channel);

		this.pc.onnegotiationneeded = async () => {
			try
			{
				this.makingOffer = true;
				await this.pc.setLocalDescription();
				await this.transmitSignal({description: this.pc.localDescription});
			}
			catch(error)
			{
				this.fail(networkFailure('webrtc_offer', 'Cannot create WebRTC offer', error));
			}
			finally
			{
				this.makingOffer = false;
			}
		};

		this.pc.onconnectionstatechange = () => {
			this.dispatchEvent(event('statechange', this.diagnostics()));

			if(['failed','closed'].includes(this.pc.connectionState))
			this.fail(new NetworkError('webrtc_connection', `WebRTC ${this.pc.connectionState}`));
		};

		this.signal.addEventListener('abort', () => this.close('cancelled'), {once: true});
	}

	async transmitSignal(value)
	{
		const signal = new WebRtcSignalingMessage(value);

		await this.sendSignal(signal.toJSON());
	}

	queueCandidate(candidate)
	{
		if(this.pendingCandidates.length >= 128)
		throw new NetworkError('webrtc_signal_queue', 'Too many ICE candidates arrived before the remote description');

		this.pendingCandidates.push(candidate);
	}

	async flushCandidates()
	{
		for(const candidate of this.pendingCandidates.splice(0)) await this.applyCandidate(candidate);
	}

	async applyCandidate(candidate)
	{
		try
		{
			await this.pc.addIceCandidate(candidate);
		}
		catch(error)
		{
			if(!this.ignoreOffer) throw error;
		}
	}

	attach(channel)
	{
		if(!['control','realtime'].includes(channel.label))
		{
			channel.close();

			return;
		}

		if(this.channels.has(channel.label))
		{
			channel.close();

			return;
		}

		channel.binaryType = 'arraybuffer';
		channel.bufferedAmountLowThreshold = Math.floor(this.maximumBufferedBytes / 2);
		this.channels.set(channel.label, channel);

		channel.onopen = () => this.checkOpen();

		channel.onclose = () => {
			if(!this.closed) this.fail(new NetworkError('channel_closed', `${channel.label} channel closed`));
		};

		channel.onerror = () => this.fail(new NetworkError('channel_error', `${channel.label} channel failed`));

		channel.onmessage = message => {
			const data = message.data instanceof ArrayBuffer
				? new Uint8Array(message.data)
				: message.data instanceof Uint8Array ? message.data : new TextEncoder().encode(String(message.data));

			if(data.byteLength > this.maximumPacketBytes)
			{
				this.fail(new NetworkError('packet_size', `Received packet exceeds ${this.maximumPacketBytes} bytes`));

				return;
			}

			this.receivedBytes += data.byteLength;
			this.dispatchEvent(event('message', {channel: channel.label, data}));
		};
	}

	checkOpen()
	{
		if(this.channels.get('control')?.readyState !== 'open'
			|| this.channels.get('realtime')?.readyState !== 'open'
		) return;

		this.resolveOpen?.(this);
		this.resolveOpen = this.rejectOpen = null;
		this.dispatchEvent(event('open', this.diagnostics()));
	}

	fail(error)
	{
		if(this.closed) return;

		this.dispatchEvent(event('error', error));
		this.rejectOpen?.(error);
		this.close(error.message);
	}

	requireOpen(channels = true)
	{
		if(this.closed) throw new NetworkError('transport_closed', 'WebRTC transport is closed');

		if(channels && (!this.started || !this.channels.size))
		throw new NetworkError('transport_state', 'WebRTC transport has not opened');
	}
}
