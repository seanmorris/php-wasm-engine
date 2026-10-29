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

	/**
	 * Create empty browser-to-PHP queues with packet and byte limits.
	 * @param {object} [options] Queue limits.
	 * @param {number} [options.maximumPackets] Queued packet limit; defaults to 256.
	 * @param {number} [options.maximumBytes] Queued byte limit; defaults to 4 MiB.
	 */
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

	/**
	 * Replace the peer transport, discarding packets queued for the previous match.
	 * @param {{send: function(string, Uint8Array): *}} transport Peer transport used by send().
	 * @returns {void}
	 * @throws {NetworkError} When the bridge is closed.
	 */
	attach(transport) { this.requireOpen(); this.detach(); this.transport = transport; }

	/** Discard one match's packets while keeping the PHP bridge usable for the next match. */
	detach()
	{
		this.transport = null;
		this.incoming = [];
		this.incomingBytes = 0;
	}

	/**
	 * Queue one received packet for PHP, shedding older realtime packets when the queue is full.
	 * @param {string} channel Channel name; realtime packets may be dropped, others are reliable.
	 * @param {Uint8Array|ArrayBuffer|ArrayLike<number>} data Packet bytes; non-Uint8Array values are copied.
	 * @returns {boolean} True when queued; false when a realtime packet was dropped.
	 * @throws {NetworkError} When the bridge is closed or a reliable packet does not fit.
	 */
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

	/**
	 * Return base64 records because Vrzno cannot retain ArrayBuffers across the PHP call boundary.
	 * @param {number} [limit] Maximum packets to dequeue, clamped to 0 through 256; defaults to 64.
	 * @returns {string} JSON array of {channel, data} records with base64 data.
	 * @throws {NetworkError} When the bridge is closed.
	 */
	receive(limit = 64)
	{
		this.requireOpen();
		const result = this.incoming.splice(0, Math.max(0, Math.min(256, Number(limit) || 0)));

		for(const item of result) this.incomingBytes -= item.data.byteLength;

		return JSON.stringify(result.map(item => ({channel: item.channel, data: encode(item.data)})));
	}

	/**
	 * Send one PHP packet through the attached transport.
	 * @param {string} channel Channel name passed to the transport.
	 * @param {string|Uint8Array} value Base64 packet text, or bytes passed through unchanged.
	 * @returns {*} The transport's send() result.
	 * @throws {NetworkError} When the bridge is closed or no transport is attached.
	 */
	send(channel, value)
	{
		this.requireOpen();

		if(!this.transport) throw new NetworkError('bridge_transport', 'No peer transport is attached');

		return this.transport.send(channel, typeof value === 'string' ? decode(value) : value);
	}

	/**
	 * Report queue occupancy and dropped realtime packets.
	 * @returns {{queuedPackets: number, queuedBytes: number, dropped: number}} Frozen counters.
	 */
	diagnostics()
	{
		return Object.freeze({
			queuedPackets: this.incoming.length
			, queuedBytes: this.incomingBytes
			, dropped: this.dropped
		});
	}

	/**
	 * Close the bridge and discard the transport and queued packets.
	 * @returns {void}
	 */
	close()
	{
		this.closed = true;
		this.detach();
	}

	/**
	 * Drop the oldest queued realtime packet.
	 * @returns {boolean} True when a packet was dropped.
	 */
	dropRealtime()
	{
		const index = this.incoming.findIndex(item => item.channel === 'realtime');

		if(index < 0) return false;

		const [item] = this.incoming.splice(index, 1);
		this.incomingBytes -= item.data.byteLength;
		this.dropped++;

		return true;
	}

	/**
	 * Reject use after close().
	 * @returns {void}
	 * @throws {NetworkError} When the bridge is closed.
	 */
	requireOpen()
	{
		if(this.closed) throw new NetworkError('bridge_closed', 'Network bridge is closed');
	}
}
