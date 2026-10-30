# Private Matrix invitation integration tests

The suite in `matrix-invite.test.mjs` uses two isolated Chromium contexts against real Synapse and coturn. It checks explicit guest activation, resource readiness before SDP, TURN-only candidate pairs, both data channels, ICE restart, survival of a signaling outage, and host redaction of both senders' events. Unit fixtures additionally cover invalid ciphertext, racing claims, replay, cleanup failure/retry, and locks.

Provide an isolated Synapse instance with an existing test user and these settings:

```yaml
allow_guest_access: true
enable_registration: false
turn_allow_guests: true
turn_uris:
  - "turn:YOUR_TURN_HOST:3478?transport=udp"
  - "turn:YOUR_TURN_HOST:3478?transport=tcp"
turn_shared_secret: "YOUR_TEST_ONLY_SHARED_SECRET"
turn_user_lifetime: 1h
```

Configure coturn with the same shared secret, an address reachable from Chromium, and published UDP relay ports. The browser deliberately sets `iceTransportPolicy: "relay"`; a STUN-only connection cannot pass this test. Provide a second Synapse instance with `allow_guest_access: false` for the rejection case. Do not run against a production account: the test creates and cleans up temporary rooms.

```sh
MATRIX_TEST_SERVER=http://localhost:8008 \
MATRIX_TEST_DISABLED_SERVER=http://localhost:8009 \
MATRIX_TEST_USER=host \
MATRIX_TEST_PASSWORD=your-test-password \
node --test tests/integration/matrix-invite.test.mjs
```

Set `CHROMIUM` if the browser is not `/usr/bin/chromium`. Without the corresponding server environment variable, a service test is explicitly skipped. The test starts its own localhost module server and reports no account credentials or invitation secrets.

Set `MATRIX_TEST_SITE` to a built site's root URL (for example `http://localhost:9140/`) to also run the full Doom PHAR test. It activates the actual **Join game** poster, reaches cooperative gameplay in both browsers, and checks recovery without replacing the match. Run `npm run build:docs` first. A URL prefix such as `/phonics/` is supported.

Verified on 2026-10-30 with Synapse image digest `sha256:3c2900775df229b91ae0f21f2c98070e0da48d4e135a9c5b567b029be57fdb56`, coturn image digest `sha256:bbefd3e1fdfdc0d58770fe01b581fd8b00d9f3a5580d00acb77cf719a6bc78e3`, and Chromium 152. These are test fixtures, not production deployment requirements.
