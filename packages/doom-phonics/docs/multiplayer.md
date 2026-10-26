# Multiplayer and replication

Doom-Phonics uses host-authoritative fixed-step simulation. Peers send compact input commands stamped with simulation ticks; the host validates ownership, command order, and an acceptance window before applying them. Snapshots replicate scoped entity and component state.

Local camera look updates at display rate while simulation remains at 35 Hz. Pending yaw is consumed exactly once by the next command without changing the displayed orientation. Batched commands accumulate accepted yaw deltas and retain the latest absolute pitch.

Player correction interpolates network-owned remote actors. The local player keeps immediate prediction and reconciles against authoritative state without treating a spawn snapshot as a fresh teleport. Projectile and beam effects use replicated identities so every peer creates and retires the same effect.

Transport close is a session event. Sending stops, the replication scope is released, and the game returns to its disconnected UI instead of allowing a closed WebRTC bridge to terminate PHP.
