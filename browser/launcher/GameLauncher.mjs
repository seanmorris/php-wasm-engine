import {useOverlayTheme} from '../OverlayTheme.mjs';

const element = (name, attributes = {}, text = '') => {
	const result = document.createElement(name);

	for(const [key, value] of Object.entries(attributes))
	{
		if(key === 'class') result.className = value;
		else if(key === 'type') result.type = value;
		else result.setAttribute(key, value);
	}

	result.textContent = text;

	return result;
};

const selectionId = () => crypto.randomUUID();

const bytes = value => {
	if(!Number.isFinite(value)) return '';

	if(value < 1024) return `${value} B`;

	if(value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KiB`;

	return `${(value / 1024 / 1024).toFixed(1)} MiB`;
};

/** Reusable game launcher for ordered resources, start selection, and declared match settings. */
export class GameLauncher
{

	/**
	 * Create a launcher bound to one normalized world and resource negotiator.
	 * @param {object} options Launcher dependencies.
	 * @param {ResourceNegotiator} options.negotiator Negotiator used to preview resource entries.
	 * @param {HTMLElement} options.frame Element that receives the launcher dialog.
	 * @param {object} options.world Normalized world providing resource slots, match fields, and start scope.
	 * @param {function(object): (void|Promise<void>)|null} [options.prepareLocal] Local launch action used by
	 * openLocal().
	 * @param {function(): (void|Promise<void>)|null} [options.openNetwork] Online action; its button is shown only
	 * for local opens when supplied.
	 * @param {AbortSignal} [options.signal] Host signal combined with the launcher's own abort signal.
	 */
	constructor({negotiator, frame, world, prepareLocal = null, openNetwork = null, signal})
	{
		this.negotiator = negotiator;
		this.frame = frame;
		this.world = world;
		this.prepareLocal = prepareLocal;
		this.openNetwork = openNetwork;
		this.abort = new AbortController;
		this.signal = signal ? AbortSignal.any([signal, this.abort.signal]) : this.abort.signal;
		this.resourceEntries = new Map;
		this.dragged = null;
		this.previousFocus = null;
		this.local = false;
		this.releaseTheme = useOverlayTheme();
		this.#build();
		this.#install();
		this.#seedDefaults();
	}

	/**
	 * Open the launcher with an optional async action for its validated values.
	 * @param {object} [options] Dialog presentation and submit action.
	 * @param {string} [options.title] Dialog title; defaults to `Game settings`.
	 * @param {string} [options.submitLabel] Primary button label; defaults to `Done`.
	 * @param {function(object): (void|Promise<void>)|null} [options.onSubmit] Receives values(); the dialog closes
	 * after it resolves and shows its error otherwise. Null hides the cancel button.
	 * @param {boolean} [options.local] Use local-play setting variants and offer the online action.
	 */
	open({title = 'Game settings', submitLabel = 'Done', onSubmit = null, local = false} = {})
	{
		this.#configureSettings(local);
		this.title.textContent = title;
		this.primary.textContent = submitLabel;
		this.onSubmit = onSubmit;
		this.cancelButton.hidden = onSubmit === null;
		this.onlineButton.hidden = !local || typeof this.openNetwork !== 'function';
		this.status.textContent = '';

		if(!this.dialog.open)
		{
			this.previousFocus = document.activeElement;
			this.dialog.showModal();
		}

		this.title.focus({preventScroll: true});
	}

	/** Open as an editor which retains changes without launching a game. */
	openSettings() { this.open(); }

	/** Open with the local-world launch action supplied at construction. */
	openLocal()
	{
		if(typeof this.prepareLocal !== 'function') throw new Error('This world has no local launch action');

		this.open({
			title: 'Game launcher'
			, submitLabel: 'Start locally'
			, onSubmit: values => this.prepareLocal(values)
			, local: true
		});
	}

	/** Close the modal and restore focus to the control which opened it. */
	close()
	{
		if(this.dialog.open) this.dialog.close();

		if(this.previousFocus?.isConnected) this.previousFocus.focus({preventScroll: true});

		this.previousFocus = null;
	}

	/** Remove launcher DOM, listeners, and shared style ownership. */
	dispose()
	{
		this.close();
		this.abort.abort();
		this.dialog.remove();
		this.releaseTheme();
	}

	#build()
	{
		this.dialog = element(
			'dialog'
			, {'class': 'phonics-modal phonics-launcher', 'aria-labelledby': 'phonics-launcher-title'}
		);

		const header = element('header');
		this.title = element('h2', {id: 'phonics-launcher-title', tabindex: '-1'}, 'Game settings');
		this.closeButton = element('button', {type: 'button', 'aria-label': 'Close game settings'}, '×');
		header.append(this.title, this.closeButton);
		const body = element('div', {'class': 'phonics-launcher-body'});
		this.resourceInputs = new Map;

		for(const [id, slot] of this.#editableSlots()) body.append(this.#buildStack(id, slot));

		body.append(this.#buildSettings());
		this.status = element('output', {'class': 'phonics-modal-output', 'aria-live': 'polite'});
		body.append(this.status);
		const actions = element('footer', {'class': 'phonics-modal-actions'});
		this.cancelButton = element('button', {type: 'button'}, 'Cancel');
		this.onlineButton = element('button', {type: 'button'}, 'Play online');
		this.onlineButton.hidden = true;
		this.primary = element('button', {type: 'button', 'class': 'phonics-modal-primary'}, 'Done');
		actions.append(this.cancelButton, this.onlineButton, this.primary);
		this.dialog.append(header, body, actions);
		this.frame.append(this.dialog);
	}

	#buildStack(id, slot)
	{
		const group = element('section', {'class': 'phonics-launcher-stack', 'data-slot': id});
		const heading = element('div', {'class': 'phonics-launcher-stack-heading'});
		heading.append(element('h3', {}, slot.label ?? id));
		const add = element('button', {type: 'button'}, slot.multiple ? 'Add files' : 'Choose file');
		heading.append(add);
		group.append(heading);

		group.append(
			element(
				'p'
				, {'class': 'phonics-launcher-stack-help'}
				, slot.multiple
					? 'Top loads first. Files lower in the stack override earlier files.'
					: 'Choose the resource used when this game starts.'
			)
		);

		const drop = element(
			'div'
			, {'class': 'phonics-launcher-drop', tabindex: '0', role: 'button'}
			, slot.multiple ? 'Drop files here to add them to the stack' : 'Drop a file here'
		);

		const input = element(
			'input'
			, {name: `resource:${id}`, type: 'file', 'class': 'phonics-visually-hidden', tabindex: '-1'}
		);

		input.accept = slot.accept.join(',');
		input.multiple = slot.multiple;
		const list = element('ol', {'class': 'phonics-launcher-list', 'aria-label': `${slot.label ?? id} load order`});
		group.append(drop, input, list);
		this.resourceInputs.set(id, {input, list, drop, add, slot, revision: 0});
		this.resourceEntries.set(id, []);

		return group;
	}

	#buildSettings()
	{
		this.settingInputs = [];
		this.matchFields = element('fieldset');
		this.matchFields.append(element('legend', {}, 'Session'));
		const fields = element('div', {'class': 'phonics-launcher-fields'});
		this.mapSelect = element('select', {name: 'match:map', 'aria-label': 'Starting map'});
		const mapLabel = element('label', {}, 'Starting map');
		mapLabel.append(this.mapSelect);
		fields.append(mapLabel);

		for(const field of this.world.network?.match?.fields ?? [])
		{
			if(field.name === 'map') continue;

			const label = element('label', {}, field.label ?? field.name);
			let input;

			if(field.type === 'select')
			{
				input = element('select', {name: `match:${field.name}`});

				this.#selectOptions(input, field, field.default);
			}
			else
			{
				input = element('input', {name: `match:${field.name}`, type: field.type ?? 'text'});
				input.value = field.default ?? '';
			}

			label.append(input);
			fields.append(label);
			this.settingInputs.push({field, label, input, values: {}});
		}

		this.matchFields.append(fields);

		return this.matchFields;
	}

	#selectOptions(input, field, selected)
	{
		input.replaceChildren();

		for(const option of field.options ?? [])
		{
			const value = typeof option === 'object' ? option.value : option;
			const label = typeof option === 'object' ? option.label : option;
			input.append(element('option', {value}, label));
		}

		if(selected !== undefined) input.value = selected;
	}

	#configureSettings(local)
	{
		if(this.local === local) return;

		for(const entry of this.settingInputs)
		{
			const {field, label, input, values} = entry;
			values[this.local ? 'local' : 'network'] = input.value;
			const selected = local && field.local && typeof field.local === 'object'
				? {...field, ...field.local}
				: field;

			label.hidden = input.disabled = local && field.local === false;
			const value = values[local ? 'local' : 'network'] ?? selected.default;

			if(field.type === 'select') this.#selectOptions(input, selected, value);
			else input.value = value ?? '';
		}

		this.local = local;
	}

	#install()
	{
		for(const button of [this.closeButton, this.cancelButton])
		button.addEventListener('click', () => this.close(), {signal: this.signal});

		this.dialog.addEventListener('cancel', event => {
			event.preventDefault();
			this.close();
		}, {signal: this.signal});

		this.primary.addEventListener('click', () => this.#submit(), {signal: this.signal});
		this.onlineButton.addEventListener('click', () => this.#openNetwork(), {signal: this.signal});

		for(const [id, controls] of this.resourceInputs)
		{

			const choose = () => controls.input.click();
			controls.add.addEventListener('click', choose, {signal: this.signal});
			controls.drop.addEventListener('click', choose, {signal: this.signal});

			controls.drop.addEventListener('keydown', event => {
				if(!['Enter',' '].includes(event.key)) return;

				event.preventDefault();
				choose();
			}, {signal: this.signal});

			controls.input.addEventListener('change', () => {
				this.#addFiles(id, [...controls.input.files]);
				controls.input.value = '';
			}, {signal: this.signal});

			for(const target of [controls.drop, controls.list])
			{
				target.addEventListener('dragover', event => {
					event.preventDefault();
					controls.drop.classList.add('is-over');
				}, {signal: this.signal});

				target.addEventListener('dragleave', () => controls.drop.classList.remove('is-over'), {
					signal: this.signal
				});

				target.addEventListener('drop', event => this.#drop(id, event), {signal: this.signal});
			}
		}
	}

	#seedDefaults()
	{
		for(const [id, {slot}] of this.resourceInputs)
		if(slot.defaultUrl)
		{
			this.resourceEntries.get(id).push({
				id: selectionId()
				, source: slot.defaultUrl
				, label: this.#filename(slot.defaultUrl)
				, fallback: true
			});

			this.#inspectStack(id);
		}
	}

	#addFiles(id, files)
	{
		const controls = this.resourceInputs.get(id);

		if(!controls.slot.multiple && files.length) this.resourceEntries.set(id, []);

		for(const file of controls.slot.multiple ? files : files.slice(0, 1))
		this.resourceEntries.get(id).push({id: selectionId(), source: file, label: file.name, fallback: false});

		this.#inspectStack(id);
	}

	#drop(id, event)
	{
		event.preventDefault();
		const controls = this.resourceInputs.get(id);
		controls.drop.classList.remove('is-over');

		if(event.dataTransfer?.files?.length)
		{
			this.#addFiles(id, [...event.dataTransfer.files]);

			return;
		}

		const target = event.target.closest('[data-resource-id]');

		if(!this.dragged || this.dragged.slot !== id) return;

		const entries = this.resourceEntries.get(id);
		const from = entries.findIndex(value => value.id === this.dragged.id);
		let to = target ? entries.findIndex(value => value.id === target.dataset.resourceId) : entries.length;

		if(from < 0 || to < 0 || from === to) return;

		const [entry] = entries.splice(from, 1);

		if(from < to) to--;

		entries.splice(to, 0, entry);
		this.#inspectStack(id);
	}

	#moveResource(id, entryId, direction)
	{
		const entries = this.resourceEntries.get(id);
		const from = entries.findIndex(value => value.id === entryId);
		const to = Math.max(0, Math.min(entries.length - 1, from + direction));

		if(from < 0 || from === to) return;

		const [entry] = entries.splice(from, 1);
		entries.splice(to, 0, entry);
		this.#inspectStack(id);
	}

	#inspectStack(id)
	{
		const controls = this.resourceInputs.get(id);
		const revision = ++controls.revision;
		const entries = this.resourceEntries.get(id);

		for(const entry of entries)
		{
			entry.preview = null;
			entry.error = null;
		}

		this.#renderResources(id);

		Promise.all(entries.map(async(entry, stackIndex) => {
			try
			{
				const preview = await this.negotiator.preview(id, entry.source, {stackIndex});

				if(controls.revision !== revision) return;

				entry.preview = preview;
				entry.label = preview.filename || entry.label;
			}
			catch(error)
			{
				if(controls.revision === revision) entry.error = error;
			}
		})).then(() => {
			if(controls.revision !== revision) return;

			this.#renderResources(id);
			this.#updateMaps();
		});
	}

	#renderResources(id)
	{
		const {list, slot} = this.resourceInputs.get(id);
		const entries = this.resourceEntries.get(id);
		list.replaceChildren();

		if(!entries.length)
		{
			list.append(element('li', {'class': 'phonics-launcher-empty'}, 'No files selected'));

			return;
		}

		for(const [index, entry] of entries.entries())
		{
			const item = element('li', {
				'data-resource-id': entry.id
				, 'data-position': String(index)
				, draggable: 'true'
			});

			item.addEventListener('dragstart', () => {
				this.dragged = {slot: id, id: entry.id};
				item.classList.add('is-dragging');
			}, {signal: this.signal});

			item.addEventListener('dragend', () => {
				this.dragged = null;
				item.classList.remove('is-dragging');
			}, {signal: this.signal});

			const description = element('span');
			description.append(element('strong', {'class': 'phonics-launcher-resource-name'}, entry.label));
			const meta = element('small', {'class': 'phonics-launcher-resource-meta'});

			if(entry.error) meta.append(element('span', {'class': 'is-error'}, entry.error.message));
			else if(entry.preview)
			{
				meta.append(element('span', {}, entry.preview.profile.archiveType ?? 'resource'));
				meta.append(element('span', {}, `${entry.preview.profile.maps?.length ?? 0} maps`));
				meta.append(element('span', {}, bytes(entry.preview.bytes)));

				if(entry.preview.candidate) meta.append(element('span', {}, entry.preview.candidate));

				if(entry.fallback) meta.append(element('span', {}, 'default'));
			}
			else meta.append(element('span', {}, 'Inspecting…'));

			description.append(meta);
			const up = element('button', {type: 'button', 'aria-label': `Move ${entry.label} earlier`}, '↑');
			const down = element('button', {type: 'button', 'aria-label': `Move ${entry.label} later`}, '↓');
			up.disabled = index === 0;
			down.disabled = index === entries.length - 1;
			up.addEventListener('click', () => this.#moveResource(id, entry.id, -1), {signal: this.signal});
			down.addEventListener('click', () => this.#moveResource(id, entry.id, 1), {signal: this.signal});
			item.append(description, up, down);

			const removable = !slot.required || entries.length > 1 || !entry.fallback;
			const remove = element('button', {type: 'button', 'aria-label': `Remove ${entry.label}`}, '×');
			remove.disabled = !removable;

			remove.addEventListener('click', () => {
				if(!removable) return;

				entries.splice(entries.indexOf(entry), 1);
				this.#inspectStack(id);
			}, {signal: this.signal});

			item.append(remove);
			list.append(item);
		}
	}

	#updateMaps()
	{
		const selected = this.mapSelect.value || this.world.start?.scope;
		const maps = [];

		for(const [id] of this.#editableSlots())
		for(const entry of this.resourceEntries.get(id) ?? [])
		for(const map of entry.preview?.profile?.maps ?? [])
		{
			const existing = maps.indexOf(map);

			if(existing >= 0) maps.splice(existing, 1);

			maps.push(map);
		}

		this.mapSelect.replaceChildren();

		for(const map of maps) this.mapSelect.append(element('option', {value: map}, map));

		if(maps.includes(selected)) this.mapSelect.value = selected;
		else if(maps.includes(this.world.start?.scope)) this.mapSelect.value = this.world.start.scope;
	}

	#editableSlots()
	{
		const positions = new Map;
		let position = 0;

		for(const source of Object.values(this.world.sources ?? {}))
		for(const resource of source.resources ?? [])
		{
			if(resource.slot && !positions.has(resource.slot)) positions.set(resource.slot, position);

			position++;
		}

		return Object.entries(this.world.resourceSlots ?? {})
			.filter(([, slot]) => slot.authority !== 'world')
			.map((entry, declaration) => ({entry, declaration}))
			.sort(
				(left, right) => (positions.get(left.entry[0])
					?? Number.MAX_SAFE_INTEGER
				) - (positions.get(right.entry[0]) ?? Number.MAX_SAFE_INTEGER)
						|| left.declaration - right.declaration
			)
			.map(value => value.entry);
	}

	/** Return the validated resource order, settings, and selected start scope. */
	values()
	{
		const resources = {};
		const settings = {};

		for(const [id, {slot}] of this.resourceInputs)
		{
			const entries = this.resourceEntries.get(id);
			const pending = entries.find(entry => !entry.preview && !entry.error);
			const invalid = entries.find(entry => entry.error);

			if(pending) throw new Error(`${pending.label} is still being inspected`);

			if(invalid) throw invalid.error;

			if(slot.required && !entries.length) throw new Error(`${slot.label ?? id} requires at least one file`);

			if(entries.length)
			resources[id] = slot.multiple ? entries.map(entry => entry.source) : entries[0].source;
		}

		for(const input of this.matchFields.elements)
		if(!input.disabled && input.name?.startsWith('match:')) settings[input.name.slice(6)] = input.value;

		const map = this.mapSelect.value || this.world.start?.scope;

		if(!map) throw new Error('The selected resource stack contains no maps');

		settings.map = map;

		return {resources, settings, start: {...this.world.start, scope: map}};
	}

	async #openNetwork()
	{
		const selected = new Map(
			this.settingInputs.filter(({input}) => !input.disabled).map(({field, input}) => [field.name, input.value])
		);

		this.#configureSettings(false);

		// Carry compatible local choices into the offer. Local-only choices use the
		// previous network value (or its default), while the resource stack and map stay put.
		for(const {field, input} of this.settingInputs)
		if(selected.has(field.name)
			&& (field.type !== 'select'
				|| [...input.options].some(option => option.value === selected.get(field.name))
			)
		)
		input.value = selected.get(field.name);

		this.close();

		try
		{
			await this.openNetwork();
		}
		catch(error)
		{
			this.openLocal();
			this.status.textContent = error?.message ?? String(error);
		}
	}

	async #submit()
	{
		this.dialog.setAttribute('aria-busy', 'true');
		this.primary.disabled = true;
		this.onlineButton.disabled = true;
		this.status.textContent = '';

		try
		{
			const values = this.values();

			if(this.onSubmit) await this.onSubmit(values);

			this.close();
		}
		catch(error)
		{
			this.status.textContent = error?.message ?? String(error);
		}
		finally
		{
			this.primary.disabled = false;
			this.onlineButton.disabled = false;
			this.dialog.setAttribute('aria-busy', 'false');
		}
	}

	#filename(value)
	{
		try
		{
			return decodeURIComponent(new URL(value, this.world.sourceUrl).pathname.split('/').pop());
		}
		catch
		{
			return String(value);
		}
	}
}
