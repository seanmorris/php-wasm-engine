# Logical input and sequences

Space and Xbox A are button **0**. PlayStation Cross and the bottom face button
on a standard pad use the same number. Read logical buttons or aliases through `Controls`:

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

| Button | Xbox | PlayStation | Keyboard | Action alias |
| --- | --- | --- | --- | --- |
| 0 | <img class="controller-glyph" src="../art/controllers/xbox-button-a.svg" alt="Xbox A button"> | <img class="controller-glyph" src="../art/controllers/playstation-button-x.svg" alt="PlayStation Cross button"> | Space, Z, Enter, keypad Enter | `jump` |
| 1 | <img class="controller-glyph" src="../art/controllers/xbox-button-b.svg" alt="Xbox B button"> | <img class="controller-glyph" src="../art/controllers/playstation-button-circle.svg" alt="PlayStation Circle button"> | X, left Shift, either Ctrl | `action` |
| 2 | <img class="controller-glyph" src="../art/controllers/xbox-button-x.svg" alt="Xbox X button"> | <img class="controller-glyph" src="../art/controllers/playstation-button-square.svg" alt="PlayStation Square button"> | C | `lightdash` (legacy) |
| 3 | <img class="controller-glyph" src="../art/controllers/xbox-button-y.svg" alt="Xbox Y button"> | <img class="controller-glyph" src="../art/controllers/playstation-button-triangle.svg" alt="PlayStation Triangle button"> | V | |
| 4, 5 | <span class="controller-glyphs"><img class="controller-glyph" src="../art/controllers/xbox-button-lb.svg" alt="Xbox LB button"><img class="controller-glyph" src="../art/controllers/xbox-button-rb.svg" alt="Xbox RB button"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../art/controllers/playstation-button-l1.svg" alt="PlayStation L1 button"><img class="controller-glyph" src="../art/controllers/playstation-button-r1.svg" alt="PlayStation R1 button"></span> | Q, E | |
| 6, 7 | <span class="controller-glyphs"><img class="controller-glyph" src="../art/controllers/xbox-button-lt.svg" alt="Xbox LT button"><img class="controller-glyph" src="../art/controllers/xbox-button-rt.svg" alt="Xbox RT button"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../art/controllers/playstation-button-l2.svg" alt="PlayStation L2 button"><img class="controller-glyph" src="../art/controllers/playstation-button-r2.svg" alt="PlayStation R2 button"></span> | 1, 3 | |
| 8 | <img class="controller-glyph" src="../art/controllers/xbox-button-back.svg" alt="Xbox Back button"> | <img class="controller-glyph" src="../art/controllers/playstation-button-select.svg" alt="PlayStation Select button"> | R | `reset` |
| 9 | <img class="controller-glyph" src="../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | P, Escape | `pause` |
| 10, 11 | <span class="controller-glyphs"><img class="controller-glyph" src="../art/controllers/xbox-button-lsb.svg" alt="Xbox left stick click"><img class="controller-glyph" src="../art/controllers/xbox-button-rsb.svg" alt="Xbox right stick click"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../art/controllers/playstation-button-l3.svg" alt="PlayStation L3 button"><img class="controller-glyph" src="../art/controllers/playstation-button-r3.svg" alt="PlayStation R3 button"></span> | Tab, Backspace | |
| 12, 13 | <span class="controller-glyphs"><img class="controller-glyph" src="../art/controllers/xbox-button-up.svg" alt="Xbox D-pad up"><img class="controller-glyph" src="../art/controllers/xbox-button-down.svg" alt="Xbox D-pad down"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../art/controllers/playstation-button-up.svg" alt="PlayStation D-pad up"><img class="controller-glyph" src="../art/controllers/playstation-button-down.svg" alt="PlayStation D-pad down"></span> | Up/W, Down/S | `up`, `down` |
| 14, 15 | <span class="controller-glyphs"><img class="controller-glyph" src="../art/controllers/xbox-button-left.svg" alt="Xbox D-pad left"><img class="controller-glyph" src="../art/controllers/xbox-button-right.svg" alt="Xbox D-pad right"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../art/controllers/playstation-button-left.svg" alt="PlayStation D-pad left"><img class="controller-glyph" src="../art/controllers/playstation-button-right.svg" alt="PlayStation D-pad right"></span> | Left/A, Right/D | `left`, `right` |
| 16 | Guide | PS | None | |

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

Replace controller sources for a gameplay button independently of its keyboard keys:

```php
$engine->input->bindGamepad(0, [SDL_CONTROLLER_BUTTON_RIGHTSHOULDER]);
$engine->input->bindGamepad(5, [SDL_CONTROLLER_BUTTON_A]);
$engine->input->bindGamepad(14, axes: [SDL_CONTROLLER_AXIS_LEFTX => -1]);
$engine->input->bindGamepad(15, axes: [SDL_CONTROLLER_AXIS_LEFTX => 1]);
$engine->input->bindGamepad(19, axes: [SDL_CONTROLLER_AXIS_RIGHTX => -1]);
$engine->input->bindGamepad(20, axes: [SDL_CONTROLLER_AXIS_RIGHTX => 1]);
```

`bindGamepad()` replaces both the digital and analog controller sources for that
logical button. Axis directions select the negative or positive half after the
configured dead zone; multiple sources merge at their strongest value. Calling it
with no sources removes controller input for that button.

Rebinding discards held input and partial sequences. Menu navigation and text
editing keep their own UI bindings. For separate players, read individual SDL
instance IDs through `Gamepads`, then feed each player's logical snapshot into
its own `Controls` and `Sequences` objects.

`lightdash` is a legacy compatibility alias for button 2, matching
`InputCode::LIGHTDASH`. New games should name their own actions instead.

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

`.75` sets a maximum gap of 750 ms between matched steps. Times use seconds throughout this API. An optional fourth
argument limits total duration. A fifth argument, `strict: false`, lets unrelated
presses pass without cancelling progress. Those presses do not extend the gap.

Each fresh press advances at most one step. Key repeat, holding a pad button,
and ordinary releases do not repeat a press. Wrong presses cancel a strict
sequence, while overlapping prefixes can start again immediately. Ordinary
returns to neutral do not cancel a motion. Exact diagonals are distinct from
cardinal directions; opposing directions cancel on that axis.

`drain()` returns completions in recognition order and empties its queue. Poll
it regularly; the queue retains the latest 64 completions. `progress()` reports
the number of steps in the longest matched prefix, including a possible
overlapping start after a completion. Definitions remain registered after a match.

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
Read completions in the frame hook if they must act while paused. Registered
sequences keep observing menu navigation;
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
$codes = new Phonics\Input\Sequences;
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

## Inspect recognition

Use `progress()` to show partial input and `drain()` to log completed sequence names. Keep presentation and gameplay responses outside the recognizer: the same definitions can drive a debug action, menu shortcut, accessibility feature, or game move. Tests can feed timestamped snapshots directly without SDL.

## Named action profiles

`InputCode` reserves logical codes through `0xFFFF` for the engine. New game
commands start at `InputCode::GAME_START` (`0x10000`). Existing legacy button
IDs remain supported. Use the shared `YES`, `NO`, `PAUSE`, directional,
paging, home/end and revert aliases directly for UI commands.

`Source` describes physical input independently of its action: SDL keys and
controller buttons use the named `Key` and `Pad` constants; mouse motion,
wheel movement and controller axes use signed directional halves. A `Bindings`
profile maps each action and direction to alternatives. Keyboard/mouse and
controller alternatives can be edited or restored independently. `bind()`
requires a `replace` or `share` policy when a source conflicts with another
action; `remove()` removes just that source.

Install a profile with `$engine->input->useBindings($bindings)`. Fixed updates
then receive signed `Controls::value()` values in `[-1,1]`, buffered digital
taps, and `Controls::pressCount()` for wheel pulses or repeated taps. Opposing
inputs subtract; alternatives in one direction use their strongest strength.
Absolute axes always use their latest sample. The legacy `snapshot()`, button
binding methods and sequence observer remain available.

`Engine::setFrameInputHandler()` runs after UI, pause and focus gating, before
fixed updates. Its delta is capped at the fixed clock's stall limit. Read
`$engine->input->frameActions()` here to consume relative displacement once
per display frame. `ActionFrame::value($code, $device)` retains held strengths
per device group: `Source::KEYBOARD` covers keys and mouse buttons, and
`Source::CONTROLLER` covers pad buttons and axes. Mouse motion and wheel
movement arrive separately through `ActionFrame::delta($code)`, so
applications can apply different keyboard, controller and mouse gains.
Fixed-step action reads do not consume mouse displacement, and display-frame
reads do not consume fixed-step press counts.

Doom-Phonics uses this hook for display-rate mouse and controller look; its
bindings, Controls menu and look settings are described in
[Doom-Phonics playing and controls](../packages/doom-phonics/docs/playing.md).
