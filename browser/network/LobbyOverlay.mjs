import {useOverlayTheme} from '../OverlayTheme.mjs';
import {
	ChatLog
	, DisplayNames
	, StatusLine
	, Ticker
	, button
	, copyText
	, node
	, ownPointerInput
	, personRow
	, timeLeft
} from '../ui/LobbyKit.mjs';
import {authorizeWithPopup} from './OAuthPopup.mjs';

/** Controller states in which a direct peer match is being set up. */
const MATCH_STATES = ['negotiating','connected','loading','ready','starting','playing'];

/** Controller states in which the signed-in lobby room is shown. */
const ROOM_STATES = new Set(['available','invited','inviting', ...MATCH_STATES]);

/** Progress steps shown while a match is set up, indexed by the controller states that reach them. */
const MATCH_STEPS = [
	['Connect', ['negotiating']]
	, ['Match files', ['connected','loading']]
	, ['Ready', ['ready']]
	, ['Start', ['starting','playing']]
];

/** Status line text and dot tone for each controller state. */
const STATUS = {
	idle: ['Offline', null]
	, connecting: ['Connecting…', 'busy']
	, 'auth-required': ['Sign-in required', null]
	, available: ['Connected', 'ok']
	, inviting: ['Invite sent', 'ok']
	, invited: ['You have an invite', 'ok']
	, negotiating: ['Connecting to player…', 'busy']
	, connected: ['Checking files…', 'busy']
	, loading: ['Checking files…', 'busy']
	, ready: ['Ready', 'ok']
	, starting: ['Starting…', 'busy']
	, playing: ['Playing', 'ok']
	, 'signing-out': ['Signing out…', 'busy']
	, failed: ['Connection failed', 'error']
	, closed: ['Closed', null]
};

/** Short status for provider progress events that matter to people; null keeps the state's own status. */
const progressText = (type, detail) => ({
	signalingstatus: detail.state !== 'retrying'
		? null
		: detail.code === 'M_LIMIT_EXCEEDED'
			? 'Matrix is rate-limiting messages. Retrying…'
			: 'Connection interrupted. Retrying…'
	, invitestatus: {
		admitted: 'Your friend opened the link. Connecting…'
		, closed: detail.reason ?? 'Invitation closed'
	}[detail.state] ?? null
	, cleanupstatus: {
		cleaning: 'Closing the invitation…'
		, pending: 'Still closing the invitation…'
	}[detail.state] ?? null
})[type] ?? null;

/** End a sentence with a full stop unless it already ends in punctuation, as a name such as `Alice W.` can. */
const sentence = text => /[.!?…]$/.test(text) ? text : `${text}.`;

const activityLabel = activity => ({
	waiting: 'Waiting for a game'
	, active: 'Playing'
	, spectating: 'Watching a game'
})[activity?.state] ?? 'In the lobby';

/**
 * Accessible session-owned Matrix lobby: people, invitations, private invite links, match progress, and chat.
 *
 * The overlay renders controller snapshots, forwards deliberate user actions, preserves accessibility and
 * focus, and leaves game settings to the shared launcher. It shares its look and parts with the embedded room
 * lobby through `OverlayTheme` and `LobbyKit`.
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

	/** Display names learned from presence. */
	names;

	/** Latest controller snapshot rendered by this overlay. */
	snapshot;

	/** Modal dialog element owned by this view. */
	dialog;

	/** Document title text or modal heading element. */
	title;

	/** Connection dot, status text, and signed-in name in the header. */
	statusLine;

	/** Live output element displaying current operation status; `data-state` holds the controller state. */
	status;

	/** Button that reopens game settings from the lobby. */
	settingsButton;

	/** Button that closes the current modal view. */
	closeButton;

	/** Element naming the lobby room and homeserver. */
	server;

	/** Card shown while the lobby connects. */
	connectingCard;

	/** Card explaining Matrix sign-in. */
	signinCard;

	/** Card explaining a failed connection, with a retry. */
	failedCard;

	/** Element in the failed card displaying the failure. */
	failedText;

	/** Row holding the failed card's retry button, hidden for invite-link guests. */
	retryRow;

	/** Container for lobby authentication controls. */
	oauthActions;

	/** Button that begins Matrix OAuth authentication. */
	oauthButton;

	/** Button that cancels the active OAuth popup flow. */
	oauthCancelButton;

	/** Card shown while a direct match is being set up. */
	matchCard;

	/** Heading of the match progress card. */
	matchTitle;

	/** Explanation of the current match setup step. */
	matchText;

	/** Ordered list of match setup steps. */
	matchSteps;

	/** Button that abandons a match before it starts. */
	matchCancel;

	/** Card shown while this account's invite or invite link waits for an answer. */
	waitingCard;

	/** Heading of the waiting card. */
	waitingTitle;

	/** Explanation and countdown in the waiting card. */
	waitingText;

	/** Container for private invitation controls. */
	linkActions;

	/** Button that creates a private invitation URL. */
	createLink;

	/** Button that copies the active private invitation URL. */
	copyLink;

	/** Button that withdraws the outgoing invite or revokes the active private invitation. */
	cancelLink;

	/** Read-only field containing the active private invitation URL. */
	linkValue;

	/** Section listing incoming invitations. */
	gamesSection;

	/** Explanation shown while no invitation is pending. */
	gamesEmpty;

	/** Main column containing status cards, invitations, and chat. */
	roomView;

	/** Section containing the lobby chat. */
	chatSection;

	/** Received lobby chat messages and lobby events. */
	chat;

	/** Live region containing received lobby chat messages. */
	chatLog;

	/** Form used to submit a lobby chat message. */
	chatForm;

	/** Text field used to compose a lobby chat message. */
	messageInput;

	/** List displaying incoming game invitations. */
	incoming;

	/** Sidebar listing people in the lobby. */
	rail;

	/** Heading that counts people in the lobby. */
	peerHeading;

	/** List element displaying available lobby peers. */
	peers;

	/** Explanation shown while nobody else is in the lobby. */
	peersEmpty;

	/** Container for authenticated session controls. */
	sessionActions;

	/** Button that signs the current user out of the lobby provider. */
	logoutButton;

	/** Once-a-second re-render of invitation countdowns. */
	ticker;

	/** Peer this account most recently invited, named in follow-up lobby events. */
	invitedPeer;

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
		this.names = new DisplayNames;
		this.snapshot = controller.snapshot();
		this.invitedPeer = null;
		this.ticker = new Ticker(() => this.renderCountdowns());
		this.releaseTheme = useOverlayTheme();
		this.build();
		this.install();
		this.render(this.snapshot);
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
		this.ticker.stop();
		this.chat.dispose();
		this.dialog.remove();
		this.releaseTheme();
	}

	/**
	 * Create the dialog header and body, then append the dialog to the frame.
	 * @returns {void}
	 */
	build()
	{
		this.dialog = node('dialog', {
			class: 'phonics-modal phonics-lobby phonics-ui'
			, 'aria-labelledby': 'phonics-lobby-title'
			, 'data-view': 'gate'
		});

		ownPointerInput(this.dialog, this.signal);

		const header = node('header');

		this.title = node(
			'h2'
			, {id: 'phonics-lobby-title', tabindex: '-1'}
			, this.world.network?.lobby?.title ?? 'Online Play'
		);

		this.statusLine = new StatusLine({className: 'phonics-lobby-status'});
		this.status = this.statusLine.text;
		this.statusLine.set('Opening…', 'busy');
		this.settingsButton = button('Game settings', {attributes: {class: 'is-quiet phonics-lobby-settings'}});

		this.closeButton = button(
			'Close'
			, {attributes: {class: 'is-quiet phonics-lobby-close', 'aria-label': 'Close lobby'}}
		);

		header.append(this.title, this.statusLine.element, this.settingsButton, this.closeButton);
		const body = node('div', {class: 'phonics-lobby-body'});
		this.buildRoom();
		this.buildRail();
		body.append(this.roomView, this.rail);
		this.dialog.append(header, body);
		this.frame.append(this.dialog);
	}

	/**
	 * Create the main column: status cards, invitations, and chat.
	 * @returns {void}
	 */
	buildRoom()
	{
		this.roomView = node('section', {class: 'phonics-lobby-main', 'aria-label': 'Lobby'});

		this.connectingCard = node('div', {class: 'phonics-ui-card'});

		this.connectingCard.append(
			node('strong', {}, 'Connecting to the lobby…')
			, node('p', {}, 'Loading people and chat.')
		);

		this.signinCard = node('div', {class: 'phonics-ui-card'});
		this.oauthActions = node('div', {class: 'phonics-ui-row'});
		this.oauthButton = button('Sign in with Matrix', {variant: 'primary'});
		this.oauthCancelButton = button('Cancel');
		this.oauthCancelButton.hidden = true;
		this.oauthActions.append(this.oauthButton, this.oauthCancelButton);

		this.signinCard.append(
			node('strong', {}, 'Play online with a Matrix account')
			, node(
				'p'
				, {}
				, 'Invite people in the lobby, send a friend an invite link, and chat. Matrix is an open chat network,'
					+ ' and accounts are free.'
			)
			, this.oauthActions
			, node('p', {}, 'Sign-in opens in a pop-up window.')
		);

		this.failedCard = node('div', {class: 'phonics-ui-card is-error'});
		this.failedText = node('p');
		this.retryRow = node('div', {class: 'phonics-ui-row'});

		this.retryRow.append(
			button('Try again', {variant: 'primary', onClick: () => this.retry(), signal: this.signal})
		);

		this.failedCard.append(node('strong', {}, 'Connection failed'), this.failedText, this.retryRow);

		this.matchCard = node('div', {class: 'phonics-ui-card is-waiting'});
		this.matchTitle = node('strong');
		this.matchText = node('p');
		this.matchSteps = node('ol', {class: 'phonics-ui-steps', 'aria-label': 'Match setup'});
		this.matchCancel = button('Cancel');
		const matchRow = node('div', {class: 'phonics-ui-row'});
		matchRow.append(this.matchCancel);
		this.matchCard.append(this.matchTitle, this.matchText, this.matchSteps, matchRow);

		this.waitingCard = node('div', {class: 'phonics-ui-card is-waiting'});
		this.waitingTitle = node('strong');
		this.waitingText = node('p');
		this.linkActions = node('div', {class: 'phonics-ui-row', role: 'group', 'aria-label': 'Private invitation'});

		this.linkValue = node(
			'input'
			, {type: 'text', readonly: true, class: 'phonics-ui-link', 'aria-label': 'Private invite link'}
		);

		this.copyLink = button('Copy link');
		this.linkActions.append(this.linkValue, this.copyLink);
		this.cancelLink = button('Cancel invitation');
		const waitingRow = node('div', {class: 'phonics-ui-row'});
		waitingRow.append(this.cancelLink);
		this.waitingCard.append(this.waitingTitle, this.waitingText, this.linkActions, waitingRow);

		this.gamesSection = node('section', {class: 'phonics-ui-section', 'aria-labelledby': 'phonics-lobby-games'});
		const gamesHead = node('div', {class: 'phonics-ui-section-head'});
		this.createLink = button('Create invite link', {variant: 'primary'});
		gamesHead.append(node('h3', {id: 'phonics-lobby-games'}, 'Invites'), this.createLink);
		this.incoming = node('ul', {class: 'phonics-ui-list', 'aria-label': 'Game offers'});
		this.gamesEmpty = node('p', {class: 'phonics-ui-empty'});
		this.gamesSection.append(gamesHead, this.incoming, this.gamesEmpty);

		this.chatSection = node('section', {
			class: 'phonics-ui-section phonics-lobby-chat-section'
			, 'aria-labelledby': 'phonics-lobby-chat'
		});

		const chatHead = node('div', {class: 'phonics-ui-section-head'});
		chatHead.append(node('h3', {id: 'phonics-lobby-chat'}, 'Lobby chat'));

		this.chat = new ChatLog({
			names: this.names
			, self: () => this.snapshot?.profile?.id ?? null
		});

		this.chatLog = this.chat.element;
		this.chatForm = node('form', {class: 'phonics-ui-chat-form'});

		this.messageInput = node('input', {
			type: 'text'
			, name: 'message'
			, maxlength: '1000'
			, autocomplete: 'off'
			, placeholder: 'Message the lobby'
			, 'aria-label': 'Message'
		});

		this.chatForm.append(this.messageInput, node('button', {type: 'submit'}, 'Send'));
		this.chatSection.append(chatHead, this.chatLog, this.chatForm);

		this.roomView.append(
			this.connectingCard
			, this.signinCard
			, this.failedCard
			, this.matchCard
			, this.waitingCard
			, this.gamesSection
			, this.chatSection
		);
	}

	/**
	 * Create the people sidebar and the account footer.
	 * @returns {void}
	 */
	buildRail()
	{
		this.rail = node('aside', {class: 'phonics-lobby-rail', 'aria-labelledby': 'phonics-lobby-people'});
		const head = node('div', {class: 'phonics-ui-section-head'});
		this.peerHeading = node('h3', {id: 'phonics-lobby-people'}, 'People');
		head.append(this.peerHeading);
		this.peers = node('ul', {class: 'phonics-ui-list', 'aria-label': 'Available players'});

		this.peersEmpty = node(
			'p'
			, {class: 'phonics-ui-empty'}
			, 'Nobody else is here yet. Send a friend an invite link.'
		);

		this.sessionActions = node('div', {class: 'phonics-lobby-account'});
		this.server = node('p', {class: 'phonics-lobby-server'});
		this.logoutButton = button('Sign out', {variant: 'small'});
		this.sessionActions.append(this.server, this.logoutButton);
		this.rail.append(head, this.peers, this.peersEmpty, this.sessionActions);
	}

	/**
	 * Wire dialog controls, chat submission, and controller events.
	 * @returns {void}
	 */
	install()
	{
		const signal = this.signal;
		this.closeButton.addEventListener('click', () => this.close(), {signal});
		this.settingsButton.addEventListener('click', () => this.launcher.openSettings(), {signal});

		this.dialog.addEventListener('cancel', event => {
			event.preventDefault();

			// Escape first clears an unsent message, then closes the lobby.
			if(document.activeElement === this.messageInput && this.messageInput.value)
			{
				this.messageInput.value = '';

				return;
			}

			this.close();
		}, {signal});

		this.oauthButton.addEventListener('click', () => this.signIn(), {signal});

		this.oauthCancelButton.addEventListener('click', () => {
			this.oauthAbort?.abort(new Error('Matrix sign-in was cancelled'));
		}, {signal});

		this.logoutButton.addEventListener(
			'click'
			, () => this.controller.logout().catch(error => this.showError(error))
			, {signal}
		);

		this.createLink.addEventListener('click', () => this.launcher.open({
			title: 'Create an invite link'
			, submitLabel: 'Create invite link'
			, onSubmit: async values => { await this.controller.createInviteLink(values); this.open(); }
		}), {signal});

		this.copyLink.addEventListener('click', async () => {
			const copied = await copyText(this.linkValue.value, this.linkValue);
			this.copyLink.textContent = copied ? 'Copied' : 'Press Ctrl+C';
			this.statusLine.set(copied ? 'Invite link copied' : 'Copy the selected invite link', 'ok');
			setTimeout(() => { this.copyLink.textContent = 'Copy link'; }, 2000);
		}, {signal});

		this.cancelLink.addEventListener('click', () => this.cancelOutgoing(), {signal});
		this.matchCancel.addEventListener('click', () => this.cancelMatch(), {signal});

		this.chatForm.addEventListener('submit', event => {
			event.preventDefault();
			const text = this.messageInput.value.trim();

			if(!text) return;

			this.controller.provider.chat(text).then(
				() => { this.messageInput.value = ''; }
			).catch(error => this.showError(error));
		}, {signal});

		this.controller.addEventListener('statechange', ({detail}) => this.change(detail), {signal});
		this.controller.addEventListener('update', ({detail}) => this.change(detail), {signal});
		this.controller.addEventListener('chat', ({detail}) => this.addChat(detail), {signal});
		this.controller.addEventListener('error', ({detail}) => this.showError(detail), {signal});

		for(const type of ['invitestatus', 'cleanupstatus', 'signalingstatus'])
		this.controller.addEventListener(type, ({detail}) => {
			this.render(this.controller.snapshot());
			const text = progressText(type, detail ?? {});

			if(text) this.statusLine.set(text, type === 'invitestatus' && detail.state === 'closed' ? null : 'busy');
		}, {signal});
	}

	/**
	 * Note lobby events that a state change implies, then render it.
	 * @param {object} state LobbyController snapshot with event detail.
	 * @returns {void}
	 */
	change(state)
	{
		const peer = this.invitedPeer ? this.names.name(this.invitedPeer) : 'They';

		if(state.invitationState === 'rejected') this.chat.system(`${peer} declined your invite.`);
		else if(state.expired?.direction === 'outgoing' && !state.expired.privateLink)
		this.chat.system(sentence(`${peer} didn’t answer in time`));
		else if(state.expired?.direction === 'incoming')
		this.chat.system(`${this.names.name(state.expired.from)}’s invite expired.`);
		else if(state.withdrawn)
		this.chat.system(`${this.names.name(state.withdrawn.from)} withdrew their invite.`);
		else if(state.reason && MATCH_STATES.includes(this.snapshot?.state))
		this.chat.system(sentence(`The game ended: ${state.reason}`));

		if(state.outgoing?.peerId) this.invitedPeer = state.outgoing.peerId;

		this.render(state);
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
		this.statusLine.set('Opening sign-in…', 'busy');
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
	 * Reconnect after a failed connection.
	 * @returns {Promise<void>} Settles after the attempt; failures are rendered by the controller state.
	 */
	retry()
	{
		return this.controller.connect().catch(() => {});
	}

	/**
	 * Withdraw this account's pending invite, its private invite link, or a link being joined.
	 * @returns {Promise<void>} Settles after the provider confirms; failures are shown in the status line.
	 */
	cancelOutgoing()
	{
		const cancel = this.controller.outgoing?.invitation.privateLink || this.controller.joinAttempt
			? this.controller.cancelInviteLink()
			: this.controller.cancel();

		return Promise.resolve(cancel).catch(error => this.showError(error));
	}

	/**
	 * Abandon a match before it starts and return to the lobby.
	 * @returns {void}
	 */
	cancelMatch()
	{
		if(this.controller.joinAttempt) void this.cancelOutgoing();
		else this.controller.closeMatch('Cancelled', {local: true});
	}

	/**
	 * Accept an invitation, closing the launcher as soon as the peer connection starts.
	 * @param {object} invitation Invitation being accepted.
	 * @param {object} values Local launcher selection.
	 * @returns {Promise<void>} Resolves once setup has begun, so the lobby can show its progress.
	 */
	acceptInvitation(invitation, values)
	{
		const pending = Promise.withResolvers();
		let begun = false;

		const started = ({detail}) => {
			if(detail.state !== 'negotiating') return;

			begun = true;
			pending.resolve();
		};

		this.controller.addEventListener('statechange', started, {signal: this.signal});

		this.controller.accept(invitation, values).then(pending.resolve, error => {
			if(begun) this.showError(error);
			else pending.reject(error);
		}).finally(() => this.controller.removeEventListener('statechange', started));

		return pending.promise;
	}

	/**
	 * Summarize match settings, using the world's option labels.
	 * @param {Object<string, string>} [settings] Selected match settings.
	 * @param {{scope?: string}|null} [start] Selected start, whose scope names the map.
	 * @returns {string} Settings such as `Deathmatch · E1M3 · Skill 3`.
	 */
	describeSettings(settings = {}, start = null)
	{
		const parts = [];

		for(const field of this.world.network?.match?.fields ?? [])
		{
			const value = settings?.[field.name];

			if(field.name === 'map' || value === undefined || value === null || value === '') continue;

			const option = [...field.options ?? [], ...field.local?.options ?? []]
				.find(item => String(typeof item === 'object' ? item.value : item) === String(value));

			const label = typeof option === 'object' ? option.label : null;
			parts.push(label && label !== String(value) ? label : `${field.label ?? field.name} ${value}`);
		}

		const map = settings?.map ?? start?.scope;

		if(map) parts.splice(Math.min(1, parts.length), 0, map);

		return parts.join(' · ');
	}

	/**
	 * Name the files an invitation requires, in load order.
	 * @param {object} [payload] Invitation payload with a content summary.
	 * @returns {string} Comma-separated file labels.
	 */
	describeFiles(payload)
	{
		return [...payload?.content ?? []].sort((left, right) => (left.order ?? 0) - (right.order ?? 0))
			.map(content => content.label).filter(Boolean).join(', ');
	}

	/**
	 * Update the header, status cards, invitations, people, and chat availability.
	 * @param {object} state LobbyController snapshot.
	 * @returns {void}
	 */
	render(state)
	{
		this.snapshot = state;
		const provider = this.controller.provider;
		const me = state.profile?.id ?? null;

		if(this.names.learn([...state.peers ?? [], ...state.profile ? [state.profile] : []])) this.chat.relabel();

		const room = ROOM_STATES.has(state.state) && !provider.guest;
		const matching = MATCH_STATES.includes(state.state) || Boolean(this.controller.joinAttempt);
		const [text, tone] = STATUS[state.state] ?? [state.state.replaceAll('-', ' '), null];
		this.dialog.dataset.view = room ? 'room' : 'gate';

		this.dialog.setAttribute(
			'aria-busy'
			, String(['connecting','negotiating','loading','starting','signing-out'].includes(state.state))
		);

		this.statusLine.set(text, tone);
		this.status.dataset.state = state.state;
		this.statusLine.setDetail(room && me ? `as ${this.names.name(me)}` : '');

		const host = (() => {
			try
			{
				return new URL(provider.homeserver ?? this.configuration.homeserver).host;
			}
			catch
			{
				return provider.homeserver ?? this.configuration.homeserver ?? '';
			}
		})();

		this.server.textContent = provider.room ? `${provider.room} on ${host}` : host;
		this.server.title = provider.homeserver ?? this.configuration.homeserver ?? '';

		this.connectingCard.hidden = !['idle','connecting'].includes(state.state) || matching;
		this.signinCard.hidden = state.state !== 'auth-required' && !this.oauthPending;
		this.oauthActions.hidden = this.oauthButton.hidden = false;
		this.failedCard.hidden = state.state !== 'failed';
		this.failedText.textContent = state.error?.message ?? 'The lobby could not be reached.';
		this.retryRow.hidden = Boolean(provider.guest);

		this.chatSection.hidden = this.rail.hidden = !room;
		this.gamesSection.hidden = !room || !['available','invited'].includes(state.state);
		this.messageInput.disabled = !room;
		this.sessionActions.hidden = !room;

		this.logoutButton.hidden = provider.guest || typeof provider.logout !== 'function' || !state.profile;
		this.logoutButton.disabled = state.state !== 'available';

		const linkable = typeof provider.createInviteLink === 'function' && state.inviteLinkSupport === 'supported'
			&& !provider.guest;

		this.createLink.hidden = !linkable;
		this.createLink.disabled = state.state !== 'available';

		this.gamesEmpty.textContent = linkable
			? 'No invites yet. Invite someone from People, or send a friend an invite link.'
			: 'No invites yet. Invite someone from People.';

		this.renderMatch(state, matching);
		this.renderWaiting(state);
		this.renderInvitations(state);
		this.renderPeers(state);
		this.ticker.sync();
	}

	/**
	 * Show match setup progress for a direct match or a joined invite link.
	 * @param {object} state LobbyController snapshot.
	 * @param {boolean} matching Whether a match is being set up.
	 * @returns {void}
	 */
	renderMatch(state, matching)
	{
		this.matchCard.hidden = !matching;

		if(!matching) return;

		const match = state.match ?? this.controller.match;
		const peer = match?.peerId ? this.names.name(match.peerId) : null;
		const joining = Boolean(this.controller.joinAttempt) && !MATCH_STATES.includes(state.state);

		this.matchTitle.replaceChildren(
			node('span', {class: 'phonics-ui-pulse', 'aria-hidden': 'true'})
			, joining || !peer
				? 'Joining the game…'
				: match.initiator ? `Starting a game with ${peer}` : `Joining ${peer}’s game`
		);

		this.matchText.textContent = joining
			? 'Opening the invitation.'
			: {
				negotiating: 'Setting up a direct connection. This can take a few seconds.'
				, connected: 'Checking that you both have the same game files.'
				, loading: 'Checking that you both have the same game files.'
				, ready: sentence(`Ready. Waiting for ${peer ?? 'the other player'}`)
				, starting: 'Starting the game…'
				, playing: 'The game is running.'
			}[state.state] ?? '';

		const current = joining ? 0 : MATCH_STEPS.findIndex(([, states]) => states.includes(state.state));

		this.matchSteps.replaceChildren(...MATCH_STEPS.map(([label], index) => {
			const item = node(
				'li'
				, {class: index < current ? 'is-done' : index === current ? 'is-current' : null}
				, label
			);

			if(index === current) item.setAttribute('aria-current', 'step');

			return item;
		}));

		this.matchCancel.hidden = ['starting','playing'].includes(state.state);
	}

	/**
	 * Show this account's outgoing invite or private invite link while it waits for an answer.
	 * @param {object} state LobbyController snapshot.
	 * @returns {void}
	 */
	renderWaiting(state)
	{
		const outgoing = state.state === 'inviting' ? state.outgoing : null;
		this.waitingCard.hidden = !outgoing;

		if(!outgoing) return;

		const link = outgoing.invitation?.url ?? '';

		const summary = this.describeSettings(
			outgoing.invitation?.payload?.settings
			, outgoing.invitation?.payload?.start
		);

		this.linkActions.hidden = !link;
		this.linkValue.value = link;
		this.cancelLink.textContent = link ? 'Cancel invitation' : 'Cancel invite';

		this.waitingTitle.replaceChildren(
			node('span', {class: 'phonics-ui-pulse', 'aria-hidden': 'true'})
			, link ? 'Invite link ready' : `Waiting for ${this.names.name(outgoing.peerId)} to accept`
		);

		this.waitingText.dataset.summary = summary;
		this.renderCountdowns();
	}

	/**
	 * Refresh every countdown on screen.
	 * @returns {boolean} Whether any countdown is still running.
	 */
	renderCountdowns()
	{
		const now = Date.now();
		let pending = false;
		const outgoing = this.snapshot?.state === 'inviting' ? this.snapshot.outgoing?.invitation : null;

		if(outgoing && !this.waitingCard.hidden)
		{
			const left = Number.isFinite(outgoing.expiresAt) ? timeLeft(outgoing.expiresAt, now) : null;
			const summary = this.waitingText.dataset.summary;
			pending ||= left !== null;

			this.waitingText.textContent = outgoing.url
				? `Send this link to a friend. Whoever opens it first joins your game, no Matrix account needed.${
					left ? ` It expires in ${left}.` : ''}`
				: `${summary ? `${summary}. ` : ''}${left ? `They have ${left} to answer.` : ''}`;
		}

		for(const time of this.incoming.querySelectorAll('[data-expires]'))
		{
			time.textContent = `${timeLeft(Number(time.dataset.expires), now)} left`;
			pending = true;
		}

		return pending;
	}

	/**
	 * List people with an Invite button enabled only while available.
	 * @param {object} state LobbyController snapshot.
	 * @returns {void}
	 */
	renderPeers(state)
	{
		const me = state.profile?.id ?? null;
		const offered = new Set(state.invitations.map(invitation => invitation.from));
		const rows = [];

		if(me)
		rows.push(personRow({
			id: me
			, name: `${this.names.name(me)} (you)`
			, avatarName: this.names.name(me)
			, detail: 'In the lobby'
		}));

		for(const peer of state.peers)
		{
			const name = this.names.name(peer.id);
			const invited = state.outgoing?.peerId === peer.id;

			// Someone who has already invited you is answered from Invites, so only idle people offer Invite.
			const invite = button('Invite', {
				attributes: {'aria-label': `Invite ${name}`}
				, signal: this.signal
				, onClick: () => this.launcher.open({
					title: `Invite ${name}`
					, submitLabel: 'Send invite'
					, onSubmit: values => this.controller.invite(peer.id, values)
				})
			});

			invite.disabled = state.state !== 'available';
			invite.hidden = invited || offered.has(peer.id);

			rows.push(personRow({
				id: peer.id
				, name
				, detail: invited ? 'Invite sent' : offered.has(peer.id) ? 'Invited you' : activityLabel(peer.activity)
				, highlight: offered.has(peer.id)
				, actions: [invite]
				, compact: true
			}));
		}

		this.peers.replaceChildren(...rows);
		this.peerHeading.textContent = me || state.peers.length
			? `People · ${state.peers.length + (me ? 1 : 0)} online`
			: 'People';

		this.peersEmpty.hidden = state.peers.length > 0;
	}

	/**
	 * List received invitations with their settings, files, countdown, and Accept and Decline buttons.
	 * @param {object} state LobbyController snapshot.
	 * @returns {void}
	 */
	renderInvitations(state)
	{
		const answerable = ['available','invited'].includes(state.state);
		const now = Date.now();

		const invitations = answerable
			? state.invitations.filter(
				invitation => !Number.isFinite(invitation.expiresAt) || invitation.expiresAt > now
			)
			: [];

		this.incoming.replaceChildren(...invitations.map(invitation => {
			const name = this.names.name(invitation.from);
			const settings = this.describeSettings(invitation.payload?.settings, invitation.payload?.start);
			const files = this.describeFiles(invitation.payload);
			const detail = node('span', {class: 'phonics-lobby-offer-detail'});

			if(settings) detail.append(node('span', {}, settings));

			if(Number.isFinite(invitation.expiresAt))
			detail.append(
				node('span', {class: 'phonics-lobby-countdown', 'data-expires': String(invitation.expiresAt)})
			);

			const accept = button('Accept', {
				variant: 'primary'
				, attributes: {'aria-label': `Accept ${name}’s invite`}
				, signal: this.signal
				, onClick: () => this.launcher.open({
					title: `Join ${name}’s game`
					, submitLabel: 'Accept game'
					, onSubmit: values => this.acceptInvitation(invitation, values)
				})
			});

			const decline = button('Decline', {
				attributes: {'aria-label': `Decline ${name}’s invite`}
				, signal: this.signal
				, onClick: () => this.controller.reject(invitation).catch(error => this.showError(error))
			});

			const row = personRow({
				id: invitation.from
				, name: `${name} invited you`
				, avatarName: name
				, detail
				, highlight: true
				, actions: [accept, decline]
			});

			row.classList.add('phonics-lobby-offer');

			if(files) row.querySelector('.phonics-ui-who').append(node('span', {}, `Uses ${files}`));

			return row;
		}));

		this.gamesEmpty.hidden = invitations.length > 0;
	}

	/**
	 * Append one chat message.
	 * @param {{from: string, text: string, sentAt?: number}} value Chat event detail.
	 * @returns {void}
	 */
	addChat(value)
	{
		this.chat.add(value);
	}

	/**
	 * Show a failure in the status line and clear the busy state.
	 * @param {*} error Failure or message to display.
	 * @returns {void}
	 */
	showError(error)
	{
		this.statusLine.set(error?.message ?? String(error), 'error');
		this.dialog.setAttribute('aria-busy', 'false');
	}
}
