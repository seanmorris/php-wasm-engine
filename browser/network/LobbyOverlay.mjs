import {useOverlayTheme} from '../OverlayTheme.mjs';
import {authorizeWithPopup} from './OAuthPopup.mjs';

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

/**
 * Accessible session-owned Matrix chat lobby. Game configuration lives in a reusable launcher modal.
 *
 * The overlay renders controller snapshots, forwards deliberate user actions, preserves accessibility and
 * focus, and leaves game settings to the shared launcher.
 */
export class LobbyOverlay
{
	/** Lobby controller observed and driven by this overlay. */
	controller;

	/** Game launcher reopened from this lobby overlay. */
	launcher;

	/** Game iframe associated with the active lobby. */
	frame;

	/** World descriptor used to configure this operation. */
	world;

	/** Game configuration rendered by the lobby overlay. */
	configuration;

	/** Abort controller used to cancel work owned by this view. */
	abort;

	/** Abort signal that cancels asynchronous work owned here. */
	signal;

	/** Element focused before this modal opened. */
	previousFocus;

	/** Callback that restores the page theme after modal closure. */
	releaseTheme;

	/** Modal dialog element owned by this view. */
	dialog;

	/** Document title text or modal heading element. */
	title;

	/** Button that reopens game settings from the lobby. */
	settingsButton;

	/** Button that closes the current modal view. */
	closeButton;

	/** Element displaying the connected homeserver. */
	server;

	/** Live output element displaying current operation status. */
	status;

	/** Container for lobby authentication controls. */
	oauthActions;

	/** Button that begins Matrix OAuth authentication. */
	oauthButton;

	/** Button that cancels the active OAuth popup flow. */
	oauthCancelButton;

	/** Container for authenticated session controls. */
	sessionActions;

	/** Button that signs the current user out of the lobby provider. */
	logoutButton;

	/** Container for private invitation controls. */
	linkActions;

	/** Button that creates a private invitation URL. */
	createLink;

	/** Button that copies the active private invitation URL. */
	copyLink;

	/** Button that revokes the active private invitation. */
	cancelLink;

	/** Read-only field containing the active private invitation URL. */
	linkValue;

	/** Section containing peers, invitations, and lobby chat. */
	roomView;

	/** Live region containing received lobby chat messages. */
	chatLog;

	/** Form used to submit a lobby chat message. */
	chatForm;

	/** Text field used to compose a lobby chat message. */
	messageInput;

	/** Section displaying incoming game invitations. */
	incoming;

	/** List element displaying available lobby peers. */
	peers;

	/** Abort controller for the active OAuth popup flow. */
	oauthAbort;

	/** Whether an OAuth authentication request is pending. */
	oauthPending;


	/**
	 * Build the lobby dialog inside the game frame and subscribe to controller events.
	 * @param {object} options Overlay dependencies.
	 * @param {LobbyController} options.controller Lobby state and invitation coordinator.
	 * @param {GameLauncher} options.launcher Modal used to configure offered and accepted games.
	 * @param {HTMLElement} options.frame Element that receives the dialog.
	 * @param {object} options.world World whose network.lobby.title names the dialog.
	 * @param {{homeserver?: string}} options.configuration Fallback homeserver label.
	 * @param {AbortSignal} [options.signal] Removes the overlay's listeners when aborted.
	 */
	constructor({controller, launcher, frame, world, configuration, signal})
	{
		this.controller = controller;
		this.launcher = launcher;
		this.frame = frame;
		this.world = world;
		this.configuration = configuration;
		this.abort = new AbortController;
		this.signal = signal ? AbortSignal.any([signal, this.abort.signal]) : this.abort.signal;
		this.previousFocus = null;
		this.releaseTheme = useOverlayTheme();
		this.build();
		this.install();
	}

	/**
	 * Show the modal dialog, render the current lobby state, and focus its title.
	 * @returns {void}
	 */
	open()
	{
		if(!this.dialog.open)
		{
			this.previousFocus = document.activeElement;
			this.dialog.showModal();
		}

		this.render(this.controller.snapshot());

		this.title.focus({preventScroll: true});
	}

	/**
	 * Compatibility adapter for game menus which previously opened inline content controls.
	 * @returns {void} Opens the launcher's local-game modal.
	 */
	openContent() { this.launcher.openLocal(); }

	/**
	 * Close the dialog and restore the previously focused element.
	 * @returns {void}
	 */
	close()
	{
		if(this.dialog.open) this.dialog.close();

		if(this.previousFocus?.isConnected) this.previousFocus.focus({preventScroll: true});

		this.previousFocus = null;
	}

	/**
	 * Close the dialog, remove its listeners and element, and release the overlay theme.
	 * @returns {void}
	 */
	dispose()
	{
		this.close();
		this.abort.abort();
		this.dialog.remove();
		this.releaseTheme();
	}

	/**
	 * Create the dialog header, status toolbar, and room view, then append the dialog to the frame.
	 * @returns {void}
	 */
	build()
	{
		this.dialog = element(
			'dialog'
			, {'class': 'phonics-modal phonics-lobby', 'aria-labelledby': 'phonics-lobby-title'}
		);

		const header = element('header');

		this.title = element(
			'h2'
			, {id: 'phonics-lobby-title', tabindex: '-1'}
			, this.world.network?.lobby?.title ?? 'Online Play'
		);

		this.settingsButton = element('button', {type: 'button'}, 'Game settings');
		this.closeButton = element('button', {type: 'button', 'aria-label': 'Close lobby'}, '×');
		header.append(this.title, this.settingsButton, this.closeButton);
		const toolbar = element('div', {'class': 'phonics-lobby-toolbar'});
		this.server = element('p', {'class': 'phonics-lobby-server'});
		this.status = element('output', {'class': 'phonics-lobby-status', 'aria-live': 'polite'}, 'Opening…');
		this.oauthActions = element('div', {'class': 'phonics-lobby-auth-actions'});
		this.oauthButton = element('button', {type: 'button'}, 'Sign in with Matrix');
		this.oauthCancelButton = element('button', {type: 'button'}, 'Cancel');
		this.oauthCancelButton.hidden = true;
		this.oauthActions.append(this.oauthButton, this.oauthCancelButton);
		this.sessionActions = element('div', {'class': 'phonics-lobby-auth-actions'});
		this.logoutButton = element('button', {type: 'button'}, 'Sign out');
		this.sessionActions.append(this.logoutButton);
		toolbar.append(this.server, this.status, this.oauthActions, this.sessionActions);

		this.linkActions = element(
			'section'
			, {class: 'phonics-lobby-auth-actions', 'aria-label': 'Private invitation'}
		);

		this.createLink = element('button', {type: 'button'}, 'Create invite link');
		this.copyLink = element('button', {type: 'button'}, 'Copy link');
		this.cancelLink = element('button', {type: 'button'}, 'Cancel invitation');
		this.linkValue = element('input', {type: 'text', readonly: '', 'aria-label': 'Private invite link'});
		this.linkActions.append(this.createLink, this.linkValue, this.copyLink, this.cancelLink);
		toolbar.append(this.linkActions);

		this.createLink.addEventListener('click', () => this.launcher.open({
			title: 'Create private game'
			, submitLabel: 'Create invite link'
			, onSubmit: async values => { await this.controller.createInviteLink(values); this.open(); }
		}), {signal: this.signal});

		this.copyLink.addEventListener('click', async () => {
			try
			{
				await navigator.clipboard.writeText(this.linkValue.value);
				this.status.textContent = 'Invite link copied';
			}
			catch
			{
				this.linkValue.select();
				this.status.textContent = 'Copy the selected invite link';
			}
		}, {signal: this.signal});

		this.cancelLink.addEventListener(
			'click'
			, () => this.controller.cancelInviteLink().catch(error => this.showError(error))
			, {signal: this.signal}
		);

		for(const type of ['invitestatus', 'cleanupstatus', 'signalingstatus'])
		this.controller.addEventListener(type, ({detail}) => {
			this.render(this.controller.snapshot());
			this.status.textContent = detail.message ?? detail.reason ?? detail.state ?? 'Connecting';
		}, {signal: this.signal});
		this.buildRoom();
		this.dialog.append(header, toolbar, this.roomView);
		this.frame.append(this.dialog);
	}

	/**
	 * Create the chat column, invitation list, and player list.
	 * @returns {void}
	 */
	buildRoom()
	{
		this.roomView = element('section', {'class': 'phonics-lobby-room'});
		const chatColumn = element('section', {'class': 'phonics-lobby-chat-column'});
		chatColumn.append(element('h3', {}, 'Lobby chat'));

		this.chatLog = element(
			'ol'
			, {'class': 'phonics-lobby-chat', 'aria-label': 'Lobby chat', 'aria-live': 'polite'}
		);

		this.chatForm = element('form', {'class': 'phonics-lobby-chat-form'});
		const message = element('label');
		message.append(element('span', {'class': 'phonics-visually-hidden'}, 'Message'));
		this.messageInput = element('input', {name: 'message', maxlength: '1000', autocomplete: 'off'});
		message.append(this.messageInput);
		this.chatForm.append(message, element('button', {type: 'submit'}, 'Send'));
		chatColumn.append(this.chatLog, this.chatForm);
		const playerColumn = element('aside');
		playerColumn.append(element('h3', {}, 'Players'));
		this.incoming = element('section', {'class': 'phonics-lobby-invitations', 'aria-label': 'Game offers'});
		this.peers = element('ul', {'class': 'phonics-lobby-peers', 'aria-label': 'Available players'});
		playerColumn.append(this.incoming, this.peers);
		this.roomView.append(chatColumn, playerColumn);
	}

	/**
	 * Wire dialog controls, chat submission, and controller events.
	 * @returns {void}
	 */
	install()
	{
		this.closeButton.addEventListener('click', () => this.close(), {signal: this.signal});
		this.settingsButton.addEventListener('click', () => this.launcher.openSettings(), {signal: this.signal});

		this.dialog.addEventListener('cancel', event => {
			event.preventDefault();
			this.close();
		}, {signal: this.signal});

		this.oauthButton.addEventListener('click', () => this.signIn(), {signal: this.signal});

		this.logoutButton.addEventListener(
			'click'
			, () => this.controller.logout().catch(error => this.showError(error))
			, {signal: this.signal}
		);

		this.oauthCancelButton.addEventListener('click', () => {
			this.oauthAbort?.abort(new Error('Matrix sign-in was cancelled'));
		}, {signal: this.signal});

		this.chatForm.addEventListener('submit', event => {
			event.preventDefault();

			this.controller.provider.chat(this.messageInput.value).then(
				() => { this.messageInput.value = ''; }
			).catch(error => this.showError(error));
		}, {signal: this.signal});

		this.controller.addEventListener('statechange', ({detail}) => this.render(detail), {signal: this.signal});
		this.controller.addEventListener('update', ({detail}) => this.render(detail), {signal: this.signal});
		this.controller.addEventListener('chat', ({detail}) => this.addChat(detail), {signal: this.signal});
	}

	/**
	 * Run Matrix OAuth in a popup once; a second call returns the pending attempt.
	 * @returns {Promise<void>} Settles after sign-in finishes; failures are shown in the status line.
	 */
	signIn()
	{
		if(this.oauthPending) return this.oauthPending;

		this.oauthButton.disabled = true;
		this.oauthCancelButton.hidden = false;
		this.dialog.setAttribute('aria-busy', 'true');
		this.status.textContent = 'Opening sign-in…';
		let failure = null;
		this.oauthAbort = new AbortController;

		this.oauthPending = authorizeWithPopup({
			begin: returnUrl => this.controller.beginOAuth(returnUrl)
			, complete: callbackUrl => this.controller.completeOAuth(callbackUrl)
			, signal: AbortSignal.any([this.signal, this.oauthAbort.signal])
		}).catch(error => { failure = error; }).finally(() => {
			this.oauthPending = null;
			this.oauthAbort = null;
			this.oauthButton.disabled = false;
			this.oauthCancelButton.hidden = true;
			this.render(this.controller.snapshot());

			if(failure) this.showError(failure);
		});

		return this.oauthPending;
	}

	/**
	 * Update the server label, status, sign-in controls, peers, and invitations.
	 * @param {object} state LobbyController snapshot.
	 * @returns {void}
	 */
	render(state)
	{
		const busy = ['connecting','negotiating','loading','starting','signing-out'].includes(state.state);

		const roomReady = ['available','invited','inviting','negotiating','connected','loading','ready','starting']
			.includes(state.state);

		this.dialog.setAttribute('aria-busy', String(busy));
		this.server.textContent = `${this.controller.provider.homeserver ?? this.configuration.homeserver} · ${this
			.controller.provider.room ?? ''}`;

		this.status.textContent = state.state === 'auth-required'
			? 'Sign-in required'
			: state.state === 'signing-out'
				? 'Signing out…'
				: state.error?.message ?? state.state.replaceAll('-', ' ');

		this.oauthActions.hidden = state.state !== 'auth-required' && !this.oauthPending;
		this.oauthButton.hidden = state.state !== 'auth-required' && !this.oauthPending;
		this.sessionActions.hidden = this.controller.provider.guest
			|| typeof this.controller.provider.logout !== 'function'
			|| !state.profile;

		this.logoutButton.disabled = state.state !== 'available';
		this.roomView.hidden = !roomReady || this.controller.provider.guest;
		const link = state.outgoing?.invitation?.url ?? '';
		this.linkActions.hidden = typeof this.controller.provider.createInviteLink !== 'function'
			|| (state.inviteLinkSupport !== 'supported' && !link);

		this.createLink.hidden = this.controller.provider.guest || !['available', 'inviting'].includes(state.state);
		this.createLink.disabled = this.controller.provider.guest;
		this.linkValue.value = link;
		this.linkValue.hidden = this.copyLink.hidden = !link;
		this.cancelLink.hidden = !link && !this.controller.joinAttempt;
		this.renderPeers(state);
		this.renderInvitations(state);
	}

	/**
	 * List available peers with an Offer game button enabled only while available.
	 * @param {object} state LobbyController snapshot.
	 * @returns {void}
	 */
	renderPeers(state)
	{
		this.peers.replaceChildren();

		for(const peer of state.peers)
		{
			const name = peer.displayName ?? peer.id;
			const item = element('li');
			item.append(element('span', {}, name));
			const invite = element('button', {type: 'button'}, 'Offer game');
			invite.disabled = state.state !== 'available';

			invite.addEventListener('click', () => this.launcher.open({
				title: `Offer game to ${name}`
				, submitLabel: 'Offer game'
				, onSubmit: values => this.controller.invite(peer.id, values)
			}), {signal: this.signal});

			item.append(invite);
			this.peers.append(item);
		}

		if(!state.peers.length) this.peers.append(element('li', {}, 'Waiting for another player…'));
	}

	/**
	 * List received invitations with Accept and Reject buttons.
	 * @param {object} state LobbyController snapshot.
	 * @returns {void}
	 */
	renderInvitations(state)
	{
		this.incoming.replaceChildren();

		for(const invitation of state.invitations)
		{
			const card = element('article');
			card.append(element('p', {}, `${invitation.from} offered a game`));

			if(invitation.payload?.start?.scope)
			card.append(element('p', {}, `Map: ${invitation.payload.start.scope}`));

			for(const content of invitation.payload?.content ?? [])
			card.append(element('p', {}, `${content.order + 1}. ${content.label}`));

			const accept = element('button', {type: 'button'}, 'Accept');
			const reject = element('button', {type: 'button'}, 'Reject');

			accept.addEventListener('click', () => this.launcher.open({
				title: `Accept game from ${invitation.from}`
				, submitLabel: 'Accept game'
				, onSubmit: values => this.controller.accept(invitation, values)
			}), {signal: this.signal});

			reject.addEventListener(
				'click'
				, () => this.controller.reject(invitation).catch(error => this.showError(error))
				, {signal: this.signal}
			);

			card.append(accept, reject);
			this.incoming.append(card);
		}
	}

	/**
	 * Append one chat message and scroll the log to it.
	 * @param {{from: string, text: string}} value Chat event detail.
	 * @returns {void}
	 */
	addChat(value)
	{
		this.chatLog.append(element('li', {}, `${value.from}: ${value.text}`));
		this.chatLog.scrollTop = this.chatLog.scrollHeight;
	}

	/**
	 * Show a failure in the status line and clear the busy state.
	 * @param {*} error Failure or message to display.
	 * @returns {void}
	 */
	showError(error)
	{
		this.status.textContent = error?.message ?? String(error);
		this.dialog.setAttribute('aria-busy', 'false');
	}
}
