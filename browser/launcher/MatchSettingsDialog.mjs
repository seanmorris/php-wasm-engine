import {useOverlayTheme, useStyle} from '../OverlayTheme.mjs';
import {node, ownPointerInput} from '../ui/LobbyKit.mjs';

const STYLE = `
.phonics-match-settings{width:min(44rem,calc(100% - 2rem))}
.phonics-match-settings-intro,.phonics-match-settings-help{margin:0;color:var(--ui-muted);font-size:.88rem}
.phonics-match-settings-help{margin-top:.55rem}
.phonics-match-settings [hidden]{display:none!important}
.phonics-match-settings-choices{grid-column:1/-1;display:grid;gap:.35rem .75rem;margin:0;padding:0;border:0;
grid-template-columns:repeat(auto-fill,minmax(var(--choice-width,9.5rem),1fr))}
.phonics-launcher .phonics-match-settings-choices>legend{grid-column:1/-1;margin-bottom:.15rem;font-size:.82rem;
font-weight:600;letter-spacing:normal;text-transform:none;color:var(--ui-muted)}
.phonics-match-settings-group{grid-column:1/-1;margin-top:.35rem;color:var(--ui-muted);font-size:.78rem;font-weight:600}
.phonics-launcher label.phonics-match-settings-check{display:flex;align-items:center;gap:.5rem;min-height:2rem;
font-size:.92rem;font-weight:400;color:var(--ui-text)}
.phonics-launcher label.phonics-match-settings-check input{width:auto;flex:none;margin:0}
.phonics-match-settings-summary{display:flex;flex-direction:column;gap:.2rem;padding:.7rem .85rem;
border:1px solid var(--ui-line);border-radius:10px;font-size:.92rem}
.phonics-match-settings-summary>span{color:var(--ui-muted);font-size:.78rem;font-weight:600;text-transform:uppercase;
letter-spacing:.06em}
.phonics-match-settings>.phonics-modal-actions>.phonics-match-settings-reset{margin-right:auto}
@media(max-width:42rem){.phonics-match-settings>.phonics-modal-actions>.phonics-match-settings-reset{flex-basis:100%}}
.phonics-match-settings .phonics-launcher-body{gap:.6rem}
.phonics-match-settings-section{border:1px solid var(--ui-line);border-radius:10px}
.phonics-match-settings-row{display:flex;align-items:baseline;gap:.75rem;min-height:2.75rem;box-sizing:border-box;
padding:.65rem .85rem .65rem 2.05rem}
.phonics-match-settings-caption{margin-top:-.2rem;color:var(--ui-muted);font-size:.78rem;font-weight:400}
.phonics-match-settings-section>summary{display:flex;align-items:baseline;gap:.75rem;min-height:2.75rem;box-sizing:border-box;
padding:.65rem .85rem;cursor:pointer;list-style:none;border-radius:10px}
.phonics-match-settings-section>summary::-webkit-details-marker{display:none}
.phonics-match-settings-section>summary::before{content:'';flex:none;align-self:center;width:.45rem;height:.45rem;
border:solid currentColor;border-width:0 2px 2px 0;transform:rotate(-45deg);transition:transform .15s;opacity:.7}
.phonics-match-settings-section[open]>summary::before{transform:rotate(45deg)}
.phonics-match-settings-section>summary:hover{background:rgba(255,255,255,.04)}
.phonics-match-settings-section>summary:focus-visible{outline:2px solid var(--ui-accent);outline-offset:-2px}
.phonics-match-settings-section[open]>summary{border-bottom:1px solid var(--ui-line);border-radius:10px 10px 0 0}
.phonics-match-settings-section-title{flex:none;font-weight:700}
.phonics-match-settings-section-value{min-width:0;margin-left:auto;overflow:hidden;color:var(--ui-muted);font-size:.9rem;
text-align:right;text-overflow:ellipsis;white-space:nowrap}
.phonics-match-settings-section>fieldset{padding:.85rem}
@media(prefers-reduced-motion:reduce){.phonics-match-settings-section>summary::before{transition:none}}
`;

const scalar = value => value === null || ['string', 'number', 'boolean'].includes(typeof value);

/** Check a game-supplied form before any of it reaches the DOM, so a mistake fails loudly at open time. */
const validForm = form => form && typeof form === 'object' && Array.isArray(form.groups) && form.groups.every(
	group => group && Array.isArray(group.fields) && group.fields.every(field => field
		&& typeof field.name === 'string' && field.name
		&& ['select', 'number', 'checkboxes'].includes(field.type)
		&& (field.type === 'number' || (Array.isArray(field.options)
			&& field.options.every(option => scalar(option?.value))
		))
	)
);

const fieldVisible = (field, values) => {
	const rule = field.visibleWhen;

	if(!rule) return true;

	const value = values[rule.field];

	if('includes' in rule) return Array.isArray(value) && value.includes(rule.includes);

	return value === rule.equals;
};

/**
 * Ask the host of an online table for its settings in a launcher-style modal.
 *
 * The game supplies the form, the check and the one-line label; the dialog only collects values, so any game can
 * describe its own rules without the lobby knowing them. Fields are `select` (options with `value`, `label` and an
 * optional `disabled`), `number` (`min`, `max`, `step`) or `checkboxes` (options; the value is the checked option
 * values in option order, and options sharing a `group` name sit under that heading). A field with
 * `visibleWhen: {field, includes}` or `{field, equals}` is shown only while that rule holds; a hidden checkbox field
 * contributes an empty list and a hidden select or number its default.
 *
 * @param {object} options Dialog options.
 * @param {HTMLElement} options.parent Element that receives the dialog while it is open.
 * @param {object} options.form Game form:
 *     `{title, intro, submitLabel, values, defaults, groups: [{legend, help, open, summary, fields}]}`. Each group is a
 *     folded section, closed unless `open`; its header shows `summary(values)` or the field values. A group with no
 *     fields is a fixed row showing only its summary. A number field's `help` is a caption under its label.
 * @param {function(object): (string|null)} options.problem Returns why the settings cannot be used, or null.
 * @param {function(object): string} [options.describe] One readable line for valid settings.
 * @param {string} [options.submitLabel] Primary button text when the form does not name one.
 * @param {AbortSignal} [options.signal] Closes the dialog, resolving null.
 * @returns {Promise<object|null>} The chosen settings, or null when the host cancels.
 */
export function chooseMatchSettings({parent, form, problem, describe = () => '', submitLabel = 'Start', signal})
{
	if(!validForm(form)) throw new TypeError('The game supplied an invalid settings form');

	const pending = Promise.withResolvers();
	const releaseTheme = useOverlayTheme();
	const releaseStyle = useStyle('match-settings', STYLE);
	const previousFocus = document.activeElement;
	const controls = [];

	const dialog = node(
		'dialog'
		, {
			class: 'phonics-modal phonics-launcher phonics-match-settings phonics-ui'
			, 'aria-labelledby': 'phonics-match-settings-title'
		}
	);

	const header = node('header');
	const title = node('h2', {id: 'phonics-match-settings-title', tabindex: '-1'}, form.title ?? 'House rules');

	const close = node(
		'button'
		, {type: 'button', class: 'is-quiet is-icon', 'aria-label': `Close ${title.textContent}`}
		, '×'
	);

	header.append(title, close);

	const body = node('div', {class: 'phonics-launcher-body'});

	if(form.intro) body.append(node('p', {class: 'phonics-match-settings-intro'}, form.intro));

	// Each group folds into a section whose header shows its current values, so the whole rule set reads at a glance.
	const sections = [];

	for(const group of form.groups)
	{
		// A group without fields is a fixed row, such as a player count the game does not let the host change.
		if(!group.fields.length)
		{
			const row = node('div', {class: 'phonics-match-settings-section is-fixed'});
			const header = node('div', {class: 'phonics-match-settings-row'});
			const value = node('span', {class: 'phonics-match-settings-section-value'});
			header.append(node('span', {class: 'phonics-match-settings-section-title'}, group.legend ?? ''), value);
			row.append(header);
			body.append(row);
			sections.push({group, value, controls: []});
			continue;
		}

		const section = node('details', {class: 'phonics-match-settings-section'});
		section.open = Boolean(group.open);
		const header = node('summary');
		const value = node('span', {class: 'phonics-match-settings-section-value'});
		header.append(node('span', {class: 'phonics-match-settings-section-title'}, group.legend ?? ''), value);

		const fieldset = node('fieldset');

		if(group.legend) fieldset.append(node('legend', {class: 'phonics-visually-hidden'}, group.legend));

		const fields = node('div', {class: 'phonics-launcher-fields'});
		const first = controls.length;

		for(const field of group.fields) fields.append(buildField(field, controls));

		fieldset.append(fields);

		if(group.help) fieldset.append(node('p', {class: 'phonics-match-settings-help'}, group.help));

		section.append(header, fieldset);
		body.append(section);
		sections.push({group, value, controls: controls.slice(first)});
	}

	const summary = node('div', {class: 'phonics-match-settings-summary', 'aria-live': 'polite'});
	const summaryText = node('strong');
	summary.append(node('span', {}, 'At this table'), summaryText);
	const output = node('output', {class: 'phonics-modal-output', 'aria-live': 'assertive'});
	body.append(summary, output);

	const footer = node('footer', {class: 'phonics-modal-actions'});
	const reset = node('button', {type: 'button', class: 'is-quiet phonics-match-settings-reset'}, 'Defaults');
	reset.hidden = !form.defaults;
	const cancel = node('button', {type: 'button', class: 'is-quiet'}, 'Cancel');
	const submit = node('button', {type: 'button', class: 'phonics-modal-primary'}, form.submitLabel ?? submitLabel);
	footer.append(reset, cancel, submit);
	dialog.append(header, body, footer);

	const read = () => {
		const values = {...form.values};

		for(const control of controls) values[control.field.name] = control.read();

		// Visibility can depend on fields declared later, so hidden fields are settled after every value is read.
		for(const control of controls)
		{
			const visible = fieldVisible(control.field, values);
			control.element.hidden = !visible;

			if(!visible) values[control.field.name] = control.hiddenValue();
		}

		return values;
	};

	let current = null;

	const refresh = () => {
		current = read();
		let reason;

		try
		{
			reason = problem(current);
		}
		catch(error)
		{
			reason = error?.message || 'These settings cannot be used';
		}

		output.textContent = reason ?? '';
		submit.disabled = Boolean(reason);

		let label = '';

		if(!reason)
		{
			try
			{
				label = String(describe(current) ?? '');
			}
			catch
			{
				label = '';
			}
		}

		summary.hidden = !label;
		summaryText.textContent = label;

		for(const section of sections) section.value.textContent = sectionSummary(section, current);
	};

	const write = values => {
		for(const control of controls) control.write(values?.[control.field.name]);

		refresh();
	};

	let settled = false;

	const finish = value => {
		if(settled) return;

		settled = true;
		signal?.removeEventListener('abort', abort);

		if(dialog.open) dialog.close();

		dialog.remove();
		releaseStyle();
		releaseTheme();

		if(previousFocus?.isConnected) previousFocus.focus({preventScroll: true});

		pending.resolve(value);
	};

	const confirm = () => {
		refresh();

		if(!submit.disabled) finish(structuredClone(current));
	};

	const abort = () => finish(null);

	ownPointerInput(dialog);
	dialog.addEventListener('input', refresh);
	dialog.addEventListener('change', refresh);

	// Enter in a list or number field confirms like the launcher's primary action; checkboxes keep Enter for toggling.
	// The modal owns the keyboard: keys never bubble to the lobby or the game behind it.
	dialog.addEventListener('keydown', event => {
		event.stopPropagation();

		if(event.key !== 'Enter' || event.isComposing) return;

		if(!(event.target instanceof HTMLSelectElement || event.target?.type === 'number')) return;

		event.preventDefault();
		confirm();
	});

	dialog.addEventListener('cancel', event => { event.preventDefault(); finish(null); });

	close.addEventListener('click', () => finish(null));
	cancel.addEventListener('click', () => finish(null));
	submit.addEventListener('click', confirm);
	reset.addEventListener('click', () => write(form.defaults));

	write(form.values ?? {});
	parent.append(dialog);
	dialog.showModal();
	title.focus({preventScroll: true});

	if(signal?.aborted) abort();
	else signal?.addEventListener('abort', abort, {once: true});

	return pending.promise;
}

/**
 * One line for a folded section: the game's own `summary(values)` when it supplies one, otherwise each visible
 * field's value in order (a list's chosen label, a number with its label, checked boxes or None).
 */
function sectionSummary({group, controls}, values)
{
	if(typeof group.summary === 'function')
	{
		try
		{
			return String(group.summary(structuredClone(values)) ?? '');
		}
		catch
		{
			// A summary that cannot read half-edited values falls back to the field values.
		}
	}

	return controls.filter(control => !control.element.hidden).map(({field}) => {
		const value = values[field.name];

		if(field.type === 'select')
		return field.options.find(option => option.value === value)?.label ?? String(value ?? '');

		if(field.type === 'number') return `${field.label ?? field.name} ${value ?? '–'}`;

		const checked = field.options.filter(option => Array.isArray(value) && value.includes(option.value));

		return checked.length ? checked.map(option => option.label ?? String(option.value)).join(', ') : 'None';
	}).join(' · ');
}

/** Build one field, registering how to read, restore and default it. */
function buildField(field, controls)
{
	if(field.type === 'checkboxes')
	{
		const element = node('fieldset', {class: 'phonics-match-settings-choices'});

		if(field.columns) element.style.setProperty('--choice-width', `${Number(field.columns)}rem`);

		// An empty label leaves the boxes under their section's own title.
		if(field.label !== '') element.append(node('legend', {}, field.label ?? field.name));
		else element.setAttribute('aria-label', field.name);

		let group = null;

		const boxes = field.options.map(option => {
			// Options that name a group, such as a suit, start a new row under a small heading.
			if(option.group && option.group !== group)
			{
				group = option.group;
				element.append(node('span', {class: 'phonics-match-settings-group'}, group));
			}

			const label = node('label', {class: 'phonics-match-settings-check'});
			const input = node('input', {type: 'checkbox', name: field.name, disabled: Boolean(option.disabled)});
			label.append(input, option.label ?? String(option.value));

			if(option.hint) label.title = option.hint;

			element.append(label);

			return {option, input};
		});

		if(field.help) element.append(node('p', {class: 'phonics-match-settings-help'}, field.help));

		controls.push({
			field
			, element
			, read: () => boxes.filter(({input}) => input.checked).map(({option}) => option.value)
			, write: value => {
				const chosen = Array.isArray(value) ? value : [];

				for(const {option, input} of boxes) input.checked = chosen.includes(option.value);
			}
			, hiddenValue: () => []
		});

		return element;
	}

	const element = node('label', {}, field.label ?? field.name);

	if(field.type === 'select')
	{
		const input = node('select', {name: field.name});

		field.options.forEach(
			(option, index) => input.append(
				node(
					'option'
					, {value: String(index), disabled: Boolean(option.disabled), title: option.hint ?? null}
					, option.label ?? String(option.value)
				)
			)
		);

		element.append(input);

		if(field.help) element.append(node('span', {class: 'phonics-match-settings-help'}, field.help));

		const indexOf = value => field.options.findIndex(option => option.value === value);

		controls.push({
			field
			, element
			, read: () => field.options[Number(input.value)]?.value ?? null
			, write: value => {
				const index = indexOf(value);
				input.value = String(index >= 0 ? index : Math.max(0, indexOf(field.default)));
			}
			, hiddenValue: () => field.default ?? null
		});

		return element;
	}

	const input = node('input', {
		type: 'number'
		, name: field.name
		, min: field.min ?? null
		, max: field.max ?? null
		, step: field.step ?? 1
		, inputmode: 'numeric'
	});

	// A caption sits between the label and its box, like the game's own setup captions.
	if(field.help) element.append(node('span', {class: 'phonics-match-settings-caption'}, field.help));

	element.append(input);

	controls.push({
		field
		, element
		// An empty or partial number stays invalid instead of silently becoming zero, so the game's check reports it.
		, read: () => input.value.trim() === '' || !Number.isFinite(input.valueAsNumber) ? null : input.valueAsNumber
		, write: value => { input.value = Number.isFinite(value) ? String(value) : String(field.default ?? ''); }
		, hiddenValue: () => field.default ?? null
	});

	return element;
}
