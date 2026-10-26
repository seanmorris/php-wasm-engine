import {NetworkError} from './Errors.mjs';

const encode = bytes => {
	let value = '';

	for(let index = 0; index < bytes.length; index += 0x8000)
	value += String.fromCharCode(...bytes.subarray(index, index + 0x8000));

	return btoa(value);
};

const decode = value => Uint8Array.from(atob(value), character => character.charCodeAt(0));

/** Bounded browser/PHP packet queues with explicit realtime shedding. */
export class NetworkBridge
{

	constructor({maximumPackets = 256, maximumBytes = 4 * 1024 * 1024} = {})
	{
		this.maximumPackets = maximumPackets;
		this.maximumBytes = maximumBytes;
		this.incoming = [];
		this.incomingBytes = 0;
		this.transport = null;
		this.dropped = 0;
		this.closed = false;
	}

	attach(transport) { this.requireOpen(); this.detach(); this.transport = transport; }

	/** Discard one match's packets while keeping the PHP bridge usable for the next match. */
	detach()
	{
		this.transport = null;
		this.incoming = [];
		this.incomingBytes = 0;
	}

	push(channel, data)
	{
		this.requireOpen();
		data = data instanceof Uint8Array ? data : new Uint8Array(data);

		while((this.incoming.length >= this.maximumPackets
			|| this.incomingBytes + data.byteLength > this.maximumBytes
		) && this.dropRealtime()
		) {
		}

		if(this.incoming.length >= this.maximumPackets || this.incomingBytes + data.byteLength > this.maximumBytes)
		{
			if(channel === 'realtime')
			{
				this.dropped++;

				return false;
			}

			throw new NetworkError('bridge_backpressure', 'Reliable browser-to-PHP queue is full');
		}

		this.incoming.push({channel, data});
		this.incomingBytes += data.byteLength;

		return true;
	}

	/** Return base64 records because Vrzno cannot retain ArrayBuffers across the PHP call boundary. */
	receive(limit = 64)
	{
		this.requireOpen();
		const result = this.incoming.splice(0, Math.max(0, Math.min(256, Number(limit) || 0)));

		for(const item of result) this.incomingBytes -= item.data.byteLength;

		return JSON.stringify(result.map(item => ({channel: item.channel, data: encode(item.data)})));
	}

	send(channel, value)
	{
		this.requireOpen();

		if(!this.transport) throw new NetworkError('bridge_transport', 'No peer transport is attached');

		return this.transport.send(channel, typeof value === 'string' ? decode(value) : value);
	}

	diagnostics()
	{
		return Object.freeze({
			queuedPackets: this.incoming.length
			, queuedBytes: this.incomingBytes
			, dropped: this.dropped
		});
	}

	close()
	{
		this.closed = true;
		this.detach();
	}

	dropRealtime()
	{
		const index = this.incoming.findIndex(item => item.channel === 'realtime');

		if(index < 0) return false;

		const [item] = this.incoming.splice(index, 1);
		this.incomingBytes -= item.data.byteLength;
		this.dropped++;

		return true;
	}

	requireOpen()
	{
		if(this.closed) throw new NetworkError('bridge_closed', 'Network bridge is closed');
	}
}
