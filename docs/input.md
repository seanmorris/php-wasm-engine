# Logical input and sequences

Space and Xbox A are button **0**. PlayStation Cross and the bottom face button
on a standard pad use the same number. Existing gameplay actions still work:

```php
$jumped = $engine->controls->pressed(0);
$alsoJumped = $engine->controls->pressed('jump');
$strength = $engine->controls->value('button0');
$released = $engine->controls->released(0);
$horizontal = $engine->controls->axis(14, 15);
```

`Controls` accepts numbers, `buttonN` or `ButtonN` strings, and action names.
It merges aliases into one control. Holding Space and A together creates one
press; releasing either keeps the control down until both are released.
Custom action names also work with `Controls::set()` and `Sequences::update()`.

## Default bindings

Numbers follow the standard browser gamepad layout. SDL's own button enum has
a different order; `SdlInput` translates it. Existing engine keyboard bindings
remain available, including Z for jump and left Shift for action.

| Button | Xbox / PlayStation | Keyboard | Action alias |
| --- | --- | --- | --- |
| 0 | A / Cross | Space, Z, Enter, keypad Enter | `jump` |
| 1 | B / Circle | X, left Shift, either Ctrl | `action` |
| 2 | X / Square | C | |
| 3 | Y / Triangle | V | |
| 4, 5 | LB, RB / L1, R1 | Q, E | |
| 6, 7 | LT, RT / L2, R2 | 1, 3 | |
| 8 | Back / Select | R | `reset` |
| 9 | Start / Options | P, Escape | `pause` |
| 10, 11 | Left, right stick click | Tab, Backspace | |
| 12, 13 | D-pad up, down | Up/W, Down/S | `up`, `down` |
| 14, 15 | D-pad left, right | Left/A, Right/D | `left`, `right` |
| 16 | Guide / PS | None | |

The left stick also supplies buttons 12–15 at proportional strength after the
configured dead zone. D-pad input overrides that stick axis. Triggers retain
their analog strength. The browser or operating system may reserve Guide and
some keyboard shortcuts.

Replace keyboard bindings without changing pad numbering:

```php
$engine->input->bindButton(0, [SDLK_SPACE]);
$engine->input->bindButton(2, [SDLK_LSHIFT, SDLK_c]);
$engine->input->bindButton(20, [SDLK_f]); // Application-defined button.
```

Rebinding discards held input and partial sequences. Menu navigation and text
editing keep their own UI bindings. For separate players, read individual SDL
instance IDs through `Gamepads`, then feed each player's logical snapshot into
its own `Controls` and `Sequences` objects.

## Observe a code

`SdlInput::$sequences` observes keyboard transitions in SDL queue order and
sampled pad input. It runs independently of fixed simulation steps, so several
quick taps between updates remain separate. Reading matches does not consume
gameplay controls.

```php
$codes = $engine->input->sequences;
$codes->add('konami', [12, 12, 13, 13, 14, 15, 14, 15, 1, 0], .75);
$codes->add('debug', ['up', 'up', 'down', 'down', 'up', 'up', 'up', 'up']);

// In an existing frame or system callback:
foreach($codes->drain() as $match)
{
	// name, completion time, and total duration in seconds.
	echo 'Matched: '.$match['name']."\n";
}

$matchedSteps = $codes->progress('konami');
$codes->remove('debug');
```

`.75` is the maximum gap between matched steps, matching moving-boxes-2's
750 ms code window. Times use seconds throughout this API. An optional fourth
argument limits total duration. A fifth argument, `strict: false`, lets unrelated
presses pass without cancelling progress. Those presses do not extend the gap.

Each fresh press advances at most one step. Key repeat, holding a pad button,
and ordinary releases do not repeat a press. Wrong presses cancel a strict
sequence, while overlapping prefixes can start again immediately. Ordinary
returns to neutral do not cancel a motion. Exact diagonals are distinct from
cardinal directions; opposing directions cancel on that axis.

`drain()` returns completions in recognition order and empties its queue. Poll
it regularly; the queue retains the latest 64 completions. `progress()` reports
the longest remaining prefix, including a possible overlapping start after a
completion. Definitions remain registered after a match.

## Fighting-game combinations

Use direction steps, held modifiers, and explicit release steps for Mortal
Kombat style moves. The recognizer reports inputs; your game decides which move
is legal, how far away an opponent must be, and which animation or hitbox to use.

```php
// Facing comes from combat orientation toward the opponent, not the last stick tilt.
$codes->setFacing(1); // 1: right, -1: left. A change clears partial motions.

$codes->add('special', ['down', 'forward', 1], .25, .6);
$codes->add('uppercut', [['press' => 2, 'held' => ['down']]]);
$codes->add('two-button', [[0, 1]]); // Both held, at least one newly pressed.
$codes->add('motion', ['down', 'down-forward', 'forward', 0], .25);
$codes->add('charged-release', [['release' => 0, 'minHold' => .5], 1], .3);

// If the last direction and attack may arrive together, describe one step:
$codes->add('down-then-forward-punch', [
	'down', ['press' => 2, 'held' => ['forward']]
], .25);
```

`forward`, `back`, `up-forward`, `up-back`, `down-forward`, and `down-back`
follow the configured facing. Absolute `left`, `right`, `up-left`, `up-right`,
`down-left`, and `down-right` are also accepted. A held cardinal modifier accepts
diagonal holds containing that direction. `neutral` can be an explicit step.

Simultaneous inputs in one snapshot have no invented order. `[0, 1]` means two
ordered presses; `[[0, 1]]` means one chord. Use a held modifier when an attack
should work while a direction remains down. Sequence buttons become digitally
active at strength `.5`; movement still uses the full analog value.

## Timing and recovery

`Engine` polls input every visible frame, including while simulation is paused.
Read completions in the frame hook if they must act while paused. The demo does
this in `DemoGame::input()`. Registered sequences keep observing menu navigation;
opening or closing a capturing menu clears partial history through the engine's
normal input reset. SDL text editing suppresses recognition, clears partial
history and requires held editing controls to be released before recognition
resumes. Canvas focus loss, tab recovery, WebGL recovery, rebinding and closing
input also clear history and unread matches.

For an application-specific transition, call `$codes->reset()`. To observe
only gameplay, drain/discard matches outside your gameplay phase. Physical
gamepad changes entirely between browser polls cannot be recovered. Pad input
is sampled once per poll, so its precise ordering against intervening keyboard
events is unavailable. Keyboard events retain their SDL timestamps and order.
Switching devices without a sampled neutral state is one logical hold. For two
presses of the same logical button, release it between polls before pressing
it on the other device.

The matcher works without SDL, including native tests and replay code:

```php
$codes = new PhpWasmEngine\Input\Sequences;
$codes->add('double-jump', [0, 0], .3);
$codes->update([0 => 1], 0.0);
$codes->update([], .05);
$codes->update([0 => 1], .1);
$matches = $codes->drain();
```

Pass finite, nondecreasing timestamps. Call `update()` with unchanged or empty
snapshots during idle frames so timeouts expire. Custom SDL loops should call
`poll()` once per frame and `consume()` once per simulation step; the built-in
observer must not be fed the buffered `snapshot()` again.

## Try it in the demo

While playing, enter **Up Up Down Down Left Right Left Right X Space**, or use
the D-pad followed by **B A**. Konami enables the debug panel. The moving-boxes
debug code, **Up Up Down Down Up Up Up Up**, also enables it.

The panel's **Sequence** and **Matches** rows show completions. Try **Down,
Right, X** quickly, hold **Down** and press **C**, or hold **Space + X**. On a
pad those are **Down, Right, B**, **Down + X**, and **A + B**. These examples
report `special`, `uppercut`, and `two-button`; they do not add combat to the
platformer. The sample uses right-facing motions. Its definitions live in
[`examples/sequences.php`](https://github.com/seanmorris/php-wasm-engine/blob/develop/examples/sequences.php).
