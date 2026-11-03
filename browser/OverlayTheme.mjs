const styles = new Map;

/* Tokens and components shared by every session-owned overlay: the world lobby and launcher dialogs, and the
 * embedded room lobby. A smoked-glass panel over the running game, brass for the one action that matters, and
 * quiet raised controls for everything else. */
const css = String.raw`
.phonics-ui{--ui-scrim:rgba(3,8,6,.66);--ui-panel:rgba(10,18,15,.9);--ui-raised:rgba(24,38,33,.94);
--ui-sunken:rgba(0,0,0,.28);--ui-field:rgba(0,0,0,.32);--ui-wash:rgba(255,255,255,.035);--ui-line:rgba(232,212,160,.16);--ui-text:#f2efe6;
--ui-muted:#a9b5ad;--ui-accent:#d9a441;--ui-accent-strong:#f0c46a;--ui-accent-text:#171106;--ui-danger:#e0533d;
--ui-danger-text:#ffb4a6;--ui-ok:#6bc78c;color:var(--ui-text);color-scheme:dark;
font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
.phonics-ui *,.phonics-ui *::before,.phonics-ui *::after{box-sizing:border-box}
.phonics-ui [hidden]{display:none!important}
.phonics-ui p{margin:0}
.phonics-ui h3,.phonics-ui legend{margin:0;padding:0;font-size:.75rem;font-weight:700;letter-spacing:.09em;
text-transform:uppercase;color:var(--ui-muted)}
.phonics-ui button{font:inherit;font-weight:600;color:var(--ui-text);background:var(--ui-raised);
border:1px solid var(--ui-line);border-radius:10px;padding:.45rem .95rem;min-height:2.5rem;cursor:pointer;
white-space:nowrap;transition:border-color .12s,background .12s,filter .12s}
.phonics-ui button:hover:not(:disabled){border-color:rgba(217,164,65,.65)}
.phonics-ui :is(button,input,select,[tabindex]:not([tabindex="-1"])):focus-visible{outline:2px solid var(--ui-accent-strong);
outline-offset:2px}
.phonics-ui button:disabled{opacity:.5;cursor:default}
.phonics-ui button:is(.is-primary,.phonics-modal-primary){background:linear-gradient(var(--ui-accent-strong),var(--ui-accent));
color:var(--ui-accent-text);border-color:transparent}
.phonics-ui button:is(.is-primary,.phonics-modal-primary):hover:not(:disabled){filter:brightness(1.07)}
.phonics-ui button.is-quiet{background:transparent}
.phonics-ui button.is-danger{background:var(--ui-danger);border-color:transparent;color:#fff}
.phonics-ui button.is-icon{min-width:2.5rem;padding:.3rem .55rem;font-size:1.05rem;line-height:1}
.phonics-ui button.is-small,.phonics-ui .phonics-ui-item>button,.phonics-ui .phonics-ui-actions.is-compact button{
min-height:2.1rem;padding:.3rem .7rem;font-size:.85rem}
.phonics-ui :is(input,select){font:inherit;color:var(--ui-text);background-color:var(--ui-field);
border:1px solid var(--ui-line);border-radius:10px;padding:.5rem .75rem;min-height:2.5rem;min-width:0}
.phonics-ui select{appearance:none;padding-right:2.1rem;background-repeat:no-repeat;
background-position:right .8rem center;background-size:.65rem;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%23a9b5ad' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E")}
.phonics-ui option{color:var(--ui-text);background:#121c18}
.phonics-ui :is(input,select):disabled{opacity:.5}
.phonics-ui-section{display:flex;flex-direction:column;gap:.55rem;min-height:0}
.phonics-ui-section-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;min-height:2.5rem}
.phonics-ui-card{display:flex;flex-direction:column;gap:.65rem;padding:1rem 1.1rem;border:1px solid var(--ui-line);
border-radius:12px;background:var(--ui-raised)}
.phonics-ui-card>strong{font-size:1.05rem}.phonics-ui-card>p{color:var(--ui-muted)}
.phonics-ui-card.is-waiting{border-color:rgba(217,164,65,.55)}
.phonics-ui-card.is-error{border-color:rgba(224,83,61,.6)}
.phonics-ui-card.is-error>strong{color:var(--ui-danger-text)}
.phonics-ui-row{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.phonics-ui-pulse{display:inline-block;width:.6rem;height:.6rem;margin-right:.5rem;border-radius:50%;
background:var(--ui-accent);animation:phonics-ui-pulse 1.4s ease-in-out infinite}
@keyframes phonics-ui-pulse{50%{opacity:.25;transform:scale(.7)}}
.phonics-ui-link{flex:1 1 14rem;font-size:.85rem;color:var(--ui-muted)}
.phonics-ui-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.4rem}
.phonics-ui-item{display:flex;align-items:center;gap:.7rem;min-height:3rem;padding:.45rem .6rem;
border:1px solid transparent;border-radius:10px;background:var(--ui-wash)}
.phonics-ui-item.is-for-you{border-color:rgba(217,164,65,.7);background:rgba(217,164,65,.1)}
.phonics-ui-avatar{flex:none;display:grid;place-items:center;width:2rem;height:2rem;border-radius:50%;
font-weight:700;font-size:.9rem;color:#0b1210}
.phonics-ui-who{display:flex;flex-direction:column;flex:1;min-width:0}
.phonics-ui-who>*{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.phonics-ui-who>span{color:var(--ui-muted);font-size:.85rem}
.phonics-ui-actions{display:flex;gap:.4rem;flex:none}
.phonics-ui-empty{margin:0;padding:.25rem 0;color:var(--ui-muted);font-size:.9rem}
.phonics-ui-chat{flex:1;min-height:8rem;display:flex;flex-direction:column;gap:.45rem;padding:.65rem .75rem;
overflow:auto;border:1px solid var(--ui-line);border-radius:12px;background:var(--ui-sunken)}
.phonics-ui-message{display:grid;grid-template-columns:auto 1fr;column-gap:.5rem;align-items:baseline;
overflow-wrap:anywhere}
.phonics-ui-message b{font-weight:700}
.phonics-ui-message time{color:var(--ui-muted);font-size:.75rem;justify-self:end;font-variant-numeric:tabular-nums}
.phonics-ui-message p{grid-column:1/-1;margin:0}
.phonics-ui-message.is-own b{color:var(--ui-accent-strong)}
.phonics-ui-message.is-system{display:block;color:var(--ui-muted);font-size:.88rem;font-style:italic}
.phonics-ui-chat-form{display:flex;gap:.5rem}.phonics-ui-chat-form input{flex:1}
.phonics-ui-status{display:flex;align-items:center;gap:.45rem;min-width:0;overflow:hidden;color:var(--ui-muted);
font-size:.85rem;white-space:nowrap}
.phonics-ui-status>output{color:inherit;overflow:hidden;text-overflow:ellipsis}
.phonics-ui-status>b{color:var(--ui-text);font-weight:600;overflow:hidden;text-overflow:ellipsis}
.phonics-ui-dot{flex:none;width:.55rem;height:.55rem;border-radius:50%;background:var(--ui-muted)}
.phonics-ui-dot[data-tone=ok]{background:var(--ui-ok);box-shadow:0 0 0 3px rgba(107,199,140,.18)}
.phonics-ui-dot[data-tone=busy]{background:var(--ui-accent);animation:phonics-ui-pulse 1.4s ease-in-out infinite}
.phonics-ui-dot[data-tone=error]{background:var(--ui-danger)}
.phonics-ui-chips{display:flex;flex-wrap:wrap;gap:.3rem}
.phonics-ui-chip{display:inline-flex;align-items:center;min-height:1.35rem;padding:0 .5rem;border:1px solid var(--ui-line);
border-radius:999px;font-size:.78rem;line-height:1.2;color:var(--ui-muted);white-space:nowrap}
.phonics-ui-chip.is-error{border-color:rgba(224,83,61,.7);color:var(--ui-danger-text);white-space:normal}
.phonics-ui-steps{display:flex;flex-wrap:wrap;gap:.35rem;margin:0;padding:0;list-style:none}
.phonics-ui-steps>li{display:flex;align-items:center;gap:.35rem;padding:.2rem .6rem;border:1px solid var(--ui-line);
border-radius:999px;color:var(--ui-muted);font-size:.82rem}
.phonics-ui-steps>li::before{content:"";width:.45rem;height:.45rem;border-radius:50%;background:currentColor;opacity:.5}
.phonics-ui-steps>li.is-done{color:var(--ui-ok)}.phonics-ui-steps>li.is-done::before{opacity:1}
.phonics-ui-steps>li.is-current{color:var(--ui-text);border-color:rgba(217,164,65,.65)}
.phonics-ui-steps>li.is-current::before{background:var(--ui-accent);opacity:1;
animation:phonics-ui-pulse 1.4s ease-in-out infinite}
@media(prefers-reduced-motion:reduce){.phonics-ui-pulse,.phonics-ui-dot,.phonics-ui-steps>li::before{animation:none!important}}
.phonics-visually-hidden{position:absolute!important;width:1px!important;height:1px!important;margin:-1px!important;
padding:0!important;overflow:hidden!important;clip:rect(0 0 0 0)!important;white-space:nowrap!important;border:0!important}

.phonics-modal{box-sizing:border-box;margin:auto;padding:0;color:var(--ui-text);background:var(--ui-panel);
border:1px solid var(--ui-line);border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.45);
max-width:calc(100% - 2rem);max-height:calc(100% - 2rem);overflow:hidden}
.phonics-modal[open]{display:flex;flex-direction:column}
.phonics-modal::backdrop{background:rgba(3,8,6,.66);-webkit-backdrop-filter:blur(10px) saturate(.85);
backdrop-filter:blur(10px) saturate(.85)}
.phonics-modal>header{flex:none;display:flex;flex-wrap:wrap;align-items:center;gap:.6rem .9rem;margin:0;padding:.85rem 1.1rem;
border-bottom:1px solid var(--ui-line);background:none;color:inherit;font-size:inherit}
.phonics-modal h2{flex:1 1 auto;min-width:0;margin:0;font-size:1.15rem;font-weight:700;letter-spacing:.01em;
overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.phonics-modal h2:focus{outline:0}
.phonics-modal-output{display:block;min-height:1.4em;color:var(--ui-muted)}
.phonics-modal-output:not(:empty){color:var(--ui-danger-text)}
/* Two classes deep, so a host page's own header and footer rules cannot restyle the dialog's. */
.phonics-modal>.phonics-modal-actions{flex:none;display:flex;justify-content:flex-end;align-items:center;gap:.5rem;
flex-wrap:wrap;margin:0;padding:.85rem 1.1rem;border-top:1px solid var(--ui-line);background:none;color:inherit;font-size:inherit}

.phonics-lobby{width:min(70rem,calc(100% - 2rem))}
.phonics-lobby[data-view=room]{height:min(46rem,calc(100% - 2rem))}
.phonics-lobby[data-view=gate]{width:min(36rem,calc(100% - 2rem))}
.phonics-lobby>header{display:grid;grid-template:"title status settings close"/minmax(0,1fr) auto auto auto}
.phonics-lobby>header>h2{grid-area:title}.phonics-lobby>header>.phonics-ui-status{grid-area:status}
.phonics-lobby-settings{grid-area:settings;justify-self:end}.phonics-lobby-close{grid-area:close;justify-self:end}
.phonics-lobby[data-view=gate]>header{grid-template:"title settings close" "status status status"/minmax(0,1fr) auto auto}
.phonics-lobby-body{flex:1 1 auto;min-height:0;display:grid;grid-template-columns:minmax(0,1fr) 19rem}
.phonics-lobby[data-view=gate] .phonics-lobby-body{grid-template-columns:minmax(0,1fr)}
.phonics-lobby-main{display:flex;flex-direction:column;gap:1.1rem;min-width:0;min-height:0;padding:1rem 1.1rem;overflow:auto}
.phonics-lobby-main>*{flex:none}
.phonics-lobby-main>.phonics-lobby-chat-section{flex:1 1 auto;min-height:14rem}
.phonics-lobby-rail{display:flex;flex-direction:column;gap:.55rem;min-width:0;min-height:0;padding:1rem;
border-left:1px solid var(--ui-line);overflow:auto}
.phonics-lobby-account{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem;
margin-top:auto;padding-top:.85rem;border-top:1px solid var(--ui-line)}
.phonics-lobby-server{min-width:0;color:var(--ui-muted);font-size:.8rem;overflow-wrap:anywhere}
.phonics-lobby-offer-detail{display:flex;flex-wrap:wrap;gap:.3rem .6rem;color:var(--ui-muted);font-size:.85rem}
.phonics-lobby-countdown{color:var(--ui-accent-strong);font-variant-numeric:tabular-nums}
.phonics-lobby-offer .phonics-ui-who>*{white-space:normal}

.phonics-launcher{width:min(46rem,calc(100% - 2rem))}
.phonics-launcher-body{flex:1 1 auto;min-height:0;overflow:auto;display:flex;flex-direction:column;gap:1.25rem;
padding:1rem 1.1rem}
.phonics-launcher-stack{display:flex;flex-direction:column;gap:.55rem}
.phonics-launcher-stack-heading{display:flex;align-items:center;gap:.75rem;min-height:2.5rem}
.phonics-launcher-stack-heading h3{margin-right:auto}
.phonics-launcher-stack-help{color:var(--ui-muted);font-size:.88rem}
.phonics-launcher-drop{display:grid;place-items:center;min-height:3.5rem;padding:.8rem;color:var(--ui-muted);
border:1px dashed rgba(232,212,160,.32);border-radius:12px;text-align:center;font-size:.9rem;cursor:pointer;
transition:border-color .12s,background .12s,color .12s}
.phonics-launcher-drop:hover,.phonics-launcher-drop:focus-visible,.phonics-launcher-drop.is-over{color:var(--ui-text);
border-color:var(--ui-accent);background:rgba(217,164,65,.08);outline:0}
.phonics-launcher-list{display:flex;flex-direction:column;gap:.4rem;margin:0;padding:0;list-style:none;counter-reset:stack}
.phonics-launcher-list>li{display:grid;grid-template-columns:1.9rem minmax(0,1fr) auto auto auto;gap:.4rem;
align-items:center;min-height:3.2rem;padding:.4rem .5rem;border:1px solid var(--ui-line);border-radius:10px;
background:var(--ui-wash);counter-increment:stack}
.phonics-launcher-list>li::before{content:counter(stack);display:grid;place-items:center;width:1.7rem;height:1.7rem;
border-radius:50%;background:var(--ui-raised);color:var(--ui-muted);font-size:.8rem;font-weight:700;
font-variant-numeric:tabular-nums}
.phonics-launcher-list>li[draggable=true]{cursor:grab}.phonics-launcher-list>li.is-dragging{opacity:.45}
.phonics-launcher-list button{min-width:2.1rem;min-height:2.1rem;padding:.2rem .45rem}
.phonics-launcher-resource-name{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.phonics-launcher-resource-meta{display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.25rem}
.phonics-launcher-resource-meta span{display:inline-flex;align-items:center;min-height:1.35rem;padding:0 .5rem;
border:1px solid var(--ui-line);border-radius:999px;color:var(--ui-muted);font-size:.78rem;line-height:1.2}
.phonics-launcher-resource-meta .is-error{color:var(--ui-danger-text);border-color:rgba(224,83,61,.7)}
.phonics-launcher-empty{display:block!important;padding:1rem!important;color:var(--ui-muted);text-align:center;
border-style:dashed!important;background:none!important}.phonics-launcher-empty::before{content:none!important}
.phonics-launcher fieldset{min-width:0;margin:0;padding:0;border:0}
.phonics-launcher legend{margin-bottom:.65rem}
.phonics-launcher-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.75rem}
.phonics-launcher label{display:grid;gap:.3rem;margin:0;font-size:.82rem;font-weight:600;color:var(--ui-muted)}
.phonics-launcher label :is(input,select){width:100%;font-size:.95rem;font-weight:400}
.phonics-launcher[aria-busy=true]{cursor:progress}

@media(max-width:42rem){
.phonics-modal{width:100%;height:100%;max-width:100%;max-height:100%;border-width:0;border-radius:0}
.phonics-lobby[data-view]{width:100%;height:100%}
.phonics-modal>header{padding:.7rem .85rem}.phonics-modal h2{font-size:1rem}
.phonics-lobby[data-view]>header{grid-template:"title close" "status settings"/minmax(0,1fr) auto}
.phonics-lobby>header>h2{white-space:normal}
.phonics-lobby>header>.phonics-ui-status{white-space:normal}.phonics-lobby>header>.phonics-ui-status>b{display:none}
.phonics-lobby-offer{flex-wrap:wrap}.phonics-lobby-offer>.phonics-ui-actions{flex-basis:100%;padding-left:2.7rem}
.phonics-lobby-body{display:block;overflow:auto}
.phonics-lobby-main{overflow:visible;padding:.85rem}
.phonics-lobby-main>.phonics-lobby-chat-section{min-height:auto}
.phonics-lobby-rail{overflow:visible;border-left:0;border-top:1px solid var(--ui-line);padding:.85rem}
.phonics-lobby .phonics-ui-chat{min-height:12rem;max-height:45vh}
.phonics-launcher-body{padding:.85rem}.phonics-modal-actions>button{flex:1 1 auto}
.phonics-launcher-list>li{grid-template-columns:1.8rem minmax(0,1fr) repeat(3,2.25rem)}
.phonics-ui button{min-height:2.75rem}}
`;

/**
 * Install one shared stylesheet for as long as any owner holds it.
 * @param {string} key Stable stylesheet identifier, written to `data-phonics-style`.
 * @param {string} text Stylesheet source.
 * @returns {function(): void} Idempotent release; the last release removes the stylesheet.
 */
export function useStyle(key, text)
{
	let entry = styles.get(key);

	if(!entry)
	{
		const style = document.createElement('style');
		style.dataset.phonicsStyle = key;
		style.textContent = text;
		document.head.append(style);
		entry = {style, owners: 0};
		styles.set(key, entry);
	}

	entry.owners++;
	let released = false;

	return () => {
		if(released) return;

		released = true;

		if(--entry.owners > 0) return;

		entry.style.remove();
		styles.delete(key);
	};
}

/**
 * Share the smoked-glass overlay theme across session-owned lobbies and launchers.
 * @returns {function(): void} Idempotent release of this owner's hold on the theme.
 */
export function useOverlayTheme()
{
	return useStyle('overlay', css);
}
