let style = null;
let owners = 0;

const css = String.raw`
.phonics-modal{box-sizing:border-box;margin:auto;padding:0;color:#fff;background:#000;border:1px solid #fff;
	font:15px/1.4 ui-monospace,SFMono-Regular,Consolas,"Liberation Mono",monospace;color-scheme:dark;overflow:auto}
.phonics-modal *{box-sizing:border-box}.phonics-modal::backdrop{background:rgba(0,0,0,.82)}
.phonics-modal [hidden]{display:none!important}
.phonics-modal>header{position:sticky;top:0;z-index:2;display:flex;align-items:center;gap:.65rem;padding:.75rem 1rem;
	background:#000;border-bottom:1px solid #fff}.phonics-modal h2{margin:0 auto 0 0;font-size:1rem;letter-spacing:.08em;text-transform:uppercase}
.phonics-modal h2:focus{outline:0}
.phonics-modal h3,.phonics-modal p{margin:.35rem 0}.phonics-modal button,.phonics-modal input,.phonics-modal select{
	min-height:2.25rem;padding:.35rem .55rem;color:#fff;background:#000;border:1px solid #fff;border-radius:0;font:inherit}
.phonics-modal button{cursor:pointer}.phonics-modal button:hover,.phonics-modal button:focus-visible{color:#000;background:#fff;outline:0}
.phonics-modal button:disabled,.phonics-modal input:disabled,.phonics-modal select:disabled{opacity:.45;cursor:not-allowed}
.phonics-modal label{display:grid;gap:.25rem}.phonics-modal fieldset{min-width:0;margin:0;padding:.8rem;border:1px solid #777}
.phonics-modal legend{padding:0 .35rem;font-weight:700}.phonics-modal-output{display:block;min-height:1.4em;color:#ccc}
.phonics-modal-actions{display:flex;justify-content:flex-end;gap:.5rem;flex-wrap:wrap;padding:.8rem 1rem;border-top:1px solid #777}
.phonics-modal-primary{font-weight:700}.phonics-visually-hidden{position:absolute!important;width:1px!important;height:1px!important;
	margin:-1px!important;padding:0!important;overflow:hidden!important;clip:rect(0 0 0 0)!important;white-space:nowrap!important;border:0!important}

.phonics-lobby{width:min(64rem,calc(100% - 2rem));max-height:calc(100% - 2rem)}
.phonics-lobby-toolbar{display:flex;align-items:center;gap:.55rem;padding:.55rem 1rem;border-bottom:1px solid #777;white-space:nowrap}
.phonics-lobby-server{min-width:0;margin:0!important;overflow:hidden;text-overflow:ellipsis;color:#bbb}
.phonics-lobby-status{margin-left:auto;color:#fff;text-transform:capitalize}.phonics-lobby-auth-actions{display:flex;gap:.45rem}
.phonics-lobby-room{display:grid;grid-template-columns:minmax(0,2fr) minmax(13rem,1fr);gap:1rem;padding:1rem}
.phonics-lobby-chat-column{min-width:0}.phonics-lobby-chat{height:min(22rem,48vh);overflow:auto;margin:.5rem 0;padding:.55rem;
	list-style:none;background:#000;border:1px solid #777}.phonics-lobby-chat li{padding:.2rem 0;overflow-wrap:anywhere}
.phonics-lobby-chat-form{display:flex;gap:.5rem}.phonics-lobby-chat-form label{flex:1}.phonics-lobby-chat-form input{width:100%}
.phonics-lobby-peers{margin:.5rem 0;padding:0;list-style:none}.phonics-lobby-peers li{display:flex;gap:.5rem;align-items:center;
	justify-content:space-between;padding:.4rem 0;border-bottom:1px solid #333}.phonics-lobby-invitations article{padding:.65rem;margin:.5rem 0;border:1px solid #fff}

.phonics-launcher{width:min(52rem,calc(100% - 2rem));max-height:calc(100% - 2rem)}
.phonics-launcher-body{display:grid;gap:1rem;padding:1rem}.phonics-launcher-stack-heading{display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem}
.phonics-launcher-stack-heading h3{margin-right:auto}.phonics-launcher-stack-help{color:#bbb}.phonics-launcher-drop{display:grid;place-items:center;
	min-height:4.5rem;margin:.7rem 0;padding:.8rem;border:1px dashed #fff;text-align:center;cursor:pointer}
.phonics-launcher-drop:hover,.phonics-launcher-drop:focus-visible,.phonics-launcher-drop.is-over{color:#000;background:#fff;outline:0}
.phonics-launcher-list{display:grid;gap:.4rem;margin:.7rem 0 0;padding:0;list-style:none;counter-reset:stack}
.phonics-launcher-list>li{display:grid;grid-template-columns:2.25rem minmax(0,1fr) auto auto auto;gap:.4rem;align-items:center;
	min-height:3.2rem;padding:.4rem;border:1px solid #777;background:#000;counter-increment:stack}
.phonics-launcher-list>li::before{content:counter(stack,decimal-leading-zero);align-self:stretch;display:grid;place-items:center;
	color:#bbb;border-right:1px solid #555}.phonics-launcher-list>li[draggable=true]{cursor:grab}
.phonics-launcher-list>li.is-dragging{opacity:.45}.phonics-launcher-resource-name{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.phonics-launcher-resource-meta{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.2rem;color:#bbb;font-size:.8rem}
.phonics-launcher-resource-meta span{padding:0 .3rem;border:1px solid #555}.phonics-launcher-resource-meta .is-error{color:#fff;border-color:#fff}
.phonics-launcher-empty{display:block!important;padding:1rem!important;color:#bbb;text-align:center}.phonics-launcher-empty::before{content:none!important}
.phonics-launcher-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.7rem}.phonics-launcher-fields label{margin:0}
.phonics-launcher-fields input,.phonics-launcher-fields select{width:100%}.phonics-launcher[aria-busy=true]{cursor:progress}
@media(max-width:42rem){.phonics-lobby-room{grid-template-columns:1fr}.phonics-lobby-chat{height:13rem}.phonics-lobby-toolbar{align-items:stretch;flex-wrap:wrap}
	.phonics-lobby-server{width:100%}.phonics-lobby-status{margin-left:0}.phonics-launcher-list>li{grid-template-columns:2rem minmax(0,1fr) repeat(3,2.25rem)}
	.phonics-launcher-list button{padding:.25rem}}
`;

/** Share the plain black-and-white modal treatment across session-owned overlays. */
export function useOverlayTheme()
{
	owners++;

	if(!style)
	{
		style = document.createElement('style');
		style.dataset.phonicsOverlayTheme = '';
		style.textContent = css;
		document.head.append(style);
	}

	let released = false;

	return () => {
		if(released) return;

		released = true;
		owners--;

		if(owners > 0) return;

		style?.remove();
		style = null;
	};
}
