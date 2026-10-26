import {DemoControls as Controls} from '../packages/sane-phonics/browser/game.mjs';
import {SdlAudio} from '../browser/SdlAudio.mjs';
import {QuickControls} from '../browser/QuickControls.mjs';

/** Deprecated demo control adapter. Package controls own all game behavior. */
export class DemoControls extends Controls
{

	constructor() { super(SdlAudio, document.querySelector('canvas'), QuickControls); }
}
