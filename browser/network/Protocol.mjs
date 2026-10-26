import {NetworkError} from './Errors.mjs';

export const PROTOCOL_VERSION = Object.freeze({major: 1, minor: 0});
export const HEADER_BYTES = 40;
const MAGIC = 0x50484E31;

export const MessageFamily = Object.freeze({
	hello: 1
	, compatibility: 2
	, resourceManifest: 3
	, resourceReady: 4
	, gameReady: 5
	, start: 6
	, input: 7
	, snapshot: 8
	, event: 9
	, chat: 10
	, ping: 11
	, pong: 12
	, resync: 13
	, transition: 14
	, disconnect: 15
	, diagnostics: 16
});

const familyValues = new Set(Object.values(MessageFamily));

const unsigned = (value, bits, label) => {
	const maximum = 2 ** bits - 1;

	if(!Number.isSafeInteger(value) || value < 0 || value > maximum)
	throw new RangeError(`${label} must fit in ${bits} unsigned bits`);

	return value;
};

const bytes = value => {
	if(value instanceof Uint8Array) return value;

	if(value instanceof ArrayBuffer) return new Uint8Array(value);

	if(typeof value === 'string') return new TextEncoder().encode(value);

	throw new TypeError('Protocol payload must be bytes or text');
};

/** Encode a bounded, versioned network packet. */
export function encodePacket(
	{
		family
		, flags = 0
		, session = 0
		, peer = 0
		, sequence = 0
		, tick = 0
		, ack = 0
		, ackBits = 0
		, schema = 1
		, payload = new Uint8Array
	}
	, {maximumBytes = 1024 * 1024} = {}
) {
	if(!familyValues.has(family)) throw new RangeError(`Unknown message family ${family}`);

	payload = bytes(payload);

	if(payload.byteLength > maximumBytes) throw new RangeError(`Protocol payload exceeds ${maximumBytes} bytes`);

	const result = new Uint8Array(HEADER_BYTES + payload.byteLength);
	const view = new DataView(result.buffer);

	view.setUint32(0, MAGIC);
	view.setUint8(4, PROTOCOL_VERSION.major);
	view.setUint8(5, PROTOCOL_VERSION.minor);
	view.setUint8(6, family);
	view.setUint8(7, unsigned(flags, 8, 'flags'));
	view.setUint32(8, unsigned(session, 32, 'session'));
	view.setUint32(12, unsigned(peer, 32, 'peer'));
	view.setUint32(16, unsigned(sequence, 32, 'sequence'));
	view.setUint32(20, unsigned(tick, 32, 'tick'));
	view.setUint32(24, unsigned(ack, 32, 'ack'));
	view.setUint32(28, unsigned(ackBits, 32, 'ackBits'));
	view.setUint16(32, unsigned(schema, 16, 'schema'));
	view.setUint16(34, 0);
	view.setUint32(36, payload.byteLength);
	result.set(payload, HEADER_BYTES);

	return result;
}

/** Decode and validate one complete protocol packet. */
export function decodePacket(value, {maximumBytes = 1024 * 1024} = {})
{
	value = bytes(value);

	if(value.byteLength < HEADER_BYTES)
	throw new NetworkError('packet_truncated', 'Network packet header is truncated');

	const view = new DataView(value.buffer, value.byteOffset, value.byteLength);

	if(view.getUint32(0) !== MAGIC) throw new NetworkError('packet_magic', 'Network packet has invalid magic');

	if(view.getUint8(4) !== PROTOCOL_VERSION.major)
	throw new NetworkError('protocol_version', `Unsupported network protocol major ${view.getUint8(4)}`);

	const length = view.getUint32(36);

	if(length > maximumBytes) throw new NetworkError('packet_size', `Network payload exceeds ${maximumBytes} bytes`);

	if(value.byteLength !== HEADER_BYTES + length)
	throw new NetworkError('packet_length', 'Network packet length does not match its header');

	const family = view.getUint8(6);

	if(!familyValues.has(family)) throw new NetworkError('packet_family', `Unknown message family ${family}`);

	return Object.freeze({
		version: Object.freeze({major: view.getUint8(4), minor: view.getUint8(5)})
		, family
		, flags: view.getUint8(7)
		, session: view.getUint32(8)
		, peer: view.getUint32(12)
		, sequence: view.getUint32(16)
		, tick: view.getUint32(20)
		, ack: view.getUint32(24)
		, ackBits: view.getUint32(28)
		, schema: view.getUint16(32)
		, payload: value.slice(HEADER_BYTES)
	});
}

export function encodeJsonPacket(header, value, options)
{
	return encodePacket({...header, payload: JSON.stringify(value)}, options);
}

export function decodeJsonPayload(packet)
{
	try
	{
		return JSON.parse(new TextDecoder().decode(packet.payload));
	}
	catch(error)
	{
		throw new NetworkError('packet_json', 'Network JSON payload is invalid', {cause: error});
	}
}

/** Track a 32-packet acknowledgement window and reject stale duplicate sequences. */
export class SequenceWindow
{

	constructor() { this.latest = 0; this.bits = 0; this.initialized = false; }

	accept(sequence)
	{
		unsigned(sequence, 32, 'sequence');

		if(!this.initialized)
		{
			this.latest = sequence;
			this.bits = 1;
			this.initialized = true;

			return true;
		}

		const forward = (sequence - this.latest) >>> 0;

		if(forward > 0 && forward < 0x80000000)
		{
			this.bits = forward >= 32 ? 1 : ((this.bits << forward) | 1) >>> 0;
			this.latest = sequence;

			return true;
		}

		const behind = (this.latest - sequence) >>> 0;

		if(behind >= 32 || (this.bits & (1 << behind))) return false;

		this.bits = (this.bits | (1 << behind)) >>> 0;

		return true;
	}
}
