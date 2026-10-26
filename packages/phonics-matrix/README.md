# Phonics Matrix provider

This optional package supplies Matrix OAuth authentication, expiring lobby presence, invitations, signaling, and
chat behind the Phonics `LobbyProvider` contract. It defaults to `https://matrix.org`; a game's networking settings
can select another homeserver and room when the host policy allows them. Login opens the homeserver's authorization page
in a popup, then resumes the running lobby through a same-origin callback. It uses authorization code, PKCE, dynamic client
registration, and refresh tokens. Access tokens are never sent through the popup message, and Phonics never asks for or
receives a Matrix password.

Matrix carries lobby and WebRTC signaling messages. Gameplay and content bytes travel outside Matrix.
