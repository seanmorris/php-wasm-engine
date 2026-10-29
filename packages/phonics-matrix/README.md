# Phonics Matrix provider

This optional package supplies Matrix OAuth authentication, expiring lobby presence, invitations, signaling, and
chat behind the Phonics `LobbyProvider` contract. It defaults to `https://matrix.org`; a game's networking settings
can select another homeserver and room when the host policy allows them. Login opens the homeserver's authorization page
in a popup, then resumes the running lobby through a same-origin callback. It uses authorization code, PKCE, dynamic client
registration, and refresh tokens. Access tokens are never sent through the popup message, and Phonics never asks for or
receives a Matrix password.

Matrix carries lobby and WebRTC signaling messages. Gameplay and content bytes travel outside Matrix.

## Use

Install `@seanmorris/phonics-matrix` beside `@seanmorris/phonics`. A Phonics world document (`"type": "phonics-world"`; Tiled `.world` files have no `network` block) enables it by importing this package's `phonics.json` and naming its `lobby` export, a `lobby-provider`, in the world's `network` block. A transport is still required; the core `@phonics/webrtc` export provides one:

```json
"imports": {
  "matrix": "path/or/url/to/phonics-matrix/phonics.json",
  "phonics": "path/or/url/to/phonics/phonics.json"
},
"network": {
  "lobby": {
    "provider": "@matrix/lobby",
    "options": {"homeserver": "https://matrix.org", "room": "#my-game:matrix.org"}
  },
  "transport": "@phonics/webrtc"
}
```

Without `options`, the provider uses `https://matrix.org` and the room `#phonics:matrix.org`. A host can override both through `loadWorld(url, {network: {lobby: {homeserver, room}}})`. If the host sets `policy.signalingOrigins`, the homeserver's origin must be in that list or loading fails. The OAuth return page must be served over HTTPS or from an HTTP loopback address (`localhost`, `127.0.0.1` or `[::1]`). [`episode-one-online.phonics-world.json`](https://github.com/seanmorris/php-wasm-engine/blob/HEAD/packages/doom-phonics/demo/episode-one-online.phonics-world.json) is a complete example, including player counts and match fields.
