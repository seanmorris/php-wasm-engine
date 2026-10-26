import {snappyApproach} from './Easing.mjs';

/**
 * Rasterize trusted HTML into an SVG foreignObject and composite it into a shared WebGL canvas.
 *
 * This deliberately has no dependency on a scene renderer: callers can use it with any WebGL2
 * canvas, and its state snapshot is JSON-friendly for language bridges such as Vrzno.
 */
export class DomTexture
{

	constructor(canvas)
	{
		if(!(canvas instanceof HTMLCanvasElement)) throw Error('DomTexture requires a canvas');

		this.canvas = canvas;
		this.context = null;

		this.opened = false;
		this.title = '';
		this.html = '';
		this.headerHint = '';
		this.scroll = 0;
		this.targetScroll = 0;
		this.scrollTime = 0;
		this.contentWidth = 0;
		this.contentHeight = 0;
		this.viewportHeight = 0;
		this.layout = null;
		this.contentImage = null;
		this.overlayImage = null;
		this.pendingImages = null;
		this.tiles = [];
		this.resources = null;
		this.overlayUploaded = false;
		this.dirty = false;
		this.revision = 0;
		this.rasterizing = false;
		this.error = '';
		this.uploads = 0;
		this.draws = 0;

		this.lost = () => {
			this.resources = null;
			this.tiles = [];
			this.overlayUploaded = false;
		};

		this.restored = () => {
			this.resources = null;
			this.tiles = [];
			this.overlayUploaded = false;
		};

		canvas.addEventListener('webglcontextlost', this.lost);
		canvas.addEventListener('webglcontextrestored', this.restored);
	}

	/** Let the game choose context attributes before this optional overlay first draws. */
	get gl()
	{
		this.context ??= this.canvas.getContext('webgl2');

		if(!this.context) throw Error('DomTexture requires WebGL2');

		return this.context;
	}

	/** Open fresh trusted HTML at its initial scroll position. */
	show(title, html, headerHint = 'Esc / B: Back')
	{
		this.clearTiles();
		this.opened = true;
		this.title = String(title || 'Information');
		this.html = String(html || '');
		this.headerHint = String(headerHint || '');
		this.scroll = 0;
		this.targetScroll = 0;
		this.scrollTime = 0;
		this.contentWidth = this.contentHeight = 0;
		this.viewportHeight = 0;
		this.layout = null;
		this.contentImage = this.overlayImage = this.pendingImages = null;
		this.tiles = [];
		this.overlayUploaded = false;
		this.dirty = true;
		this.error = '';
		this.revision++;
	}

	/** Close the overlay without disposing its reusable canvas listeners. */
	close()
	{
		this.clearTiles();
		this.opened = false;
		this.title = this.html = this.headerHint = '';
		this.contentImage = this.overlayImage = this.pendingImages = null;
		this.tiles = [];
		this.layout = null;
		this.scroll = this.targetScroll = this.contentWidth = this.contentHeight = this.viewportHeight = 0;
		this.scrollTime = 0;
		this.overlayUploaded = false;
		this.dirty = false;
		this.revision++;
	}

	/** Permanently remove event listeners and browser-owned GL resources. */
	dispose()
	{
		this.close();
		this.canvas.removeEventListener('webglcontextlost', this.lost);
		this.canvas.removeEventListener('webglcontextrestored', this.restored);
		this.destroyResources();
	}

	/** Move the desired document offset, clamping at its measured bounds. */
	scrollBy(amount)
	{
		return this.scrollTo(this.targetScroll + Number(amount || 0));
	}

	/** Seek smoothly to a document pixel offset, clamping at its measured bounds. */
	scrollTo(offset)
	{
		const next = clamp(Number(offset) || 0, 0, this.maxScroll());

		if(next !== this.targetScroll)
		{
			this.targetScroll = next;
		}

		return this.targetScroll;
	}

	/** JSON bridge used by PHP and tests without exposing implementation objects. */
	state()
	{
		return JSON.stringify({
			open: this.opened
			, ready: this.opened && this.tiles.length > 0 && this.overlayUploaded
			, scroll: this.scroll
			, targetScroll: this.targetScroll
			, maxScroll: this.maxScroll()
			, contentWidth: this.contentWidth
			, contentHeight: this.contentHeight
			, viewportHeight: this.viewportHeight
			, uploads: this.uploads
			, draws: this.draws
			, error: this.error
			, markupBytes: this.html.length
		});
	}

	/**
	 * Upload and draw the current HTML panel. This is intentionally synchronous: it is called
	 * after the PHP renderer and restores every GL binding it changes before returning. The
	 * caller's draw framebuffer remains active so composed render passes retain the overlay.
	 */
	render(width = this.canvas.width, height = this.canvas.height, headerHeight = 38)
	{
		if(!this.opened) return false;

		this.advanceScroll();

		const layout = this.resolveLayout(width, height, headerHeight);

		if(this.dirty || !sameLayout(layout, this.layout)) this.rasterize(layout);

		if(this.pendingImages)
		{
			this.clearTiles();
			this.contentImage = this.pendingImages.content;
			this.overlayImage = this.pendingImages.overlay;
			this.pendingImages = null;
			this.overlayUploaded = false;
		}

		if(!this.contentImage || !this.overlayImage || this.gl.isContextLost()) return false;

		const state = this.saveState();

		try
		{
			const resources = this.createResources();
			const gl = this.gl;

			gl.viewport(0, 0, this.canvas.width, this.canvas.height);
			gl.disable(gl.DEPTH_TEST);
			gl.disable(gl.CULL_FACE);
			gl.disable(gl.SCISSOR_TEST);
			gl.depthMask(false);
			gl.enable(gl.BLEND);
			gl.blendEquationSeparate(gl.FUNC_ADD, gl.FUNC_ADD);
			gl.blendFuncSeparate(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA, gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
			gl.useProgram(resources.program);
			gl.bindVertexArray(resources.vao);
			gl.activeTexture(gl.TEXTURE0);
			gl.uniform1i(resources.sampler, 0);
			gl.uniform2f(resources.viewport, this.canvas.width, this.canvas.height);

			if(!this.overlayUploaded)
			{
				gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
				gl.bindTexture(gl.TEXTURE_2D, resources.overlay);

				gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, this.overlayImage);

				this.uploadTiles();
				this.overlayUploaded = true;
				this.uploads++;
			}

			this.drawTexture(resources.overlay, 0, 0, this.canvas.width, this.canvas.height);
			this.drawTiles(layout);
			this.draws++;
		}
		catch(error)
		{
			this.error = error instanceof Error ? error.message : String(error);
		}
		finally
		{
			this.restoreState(state);
		}

		return this.error === '';
	}

	maxScroll()
	{
		return Math.max(0, this.contentHeight - this.viewportHeight);
	}

	/** Ease the visible document position toward its input-controlled target once per canvas frame. */
	advanceScroll()
	{
		const now = performance.now();
		const elapsed = this.scrollTime === 0 ? 0 : Math.min(.05, (now - this.scrollTime) / 1000);

		this.scrollTime = now;
		this.targetScroll = clamp(this.targetScroll, 0, this.maxScroll());

		if(elapsed === 0 || this.scroll === this.targetScroll) return;

		this.scroll = snappyApproach(this.scroll, this.targetScroll, elapsed);
	}

	/** Return the uniform panel scale from source-document pixels to canvas pixels. */
	contentScale(layout)
	{
		return layout.contentWidth / Math.max(1, this.contentWidth);
	}

	resolveLayout(width, height, headerHeight = 38)
	{
		width = Math.max(1, Math.floor(Number(width) || this.canvas.width || 1));
		height = Math.max(1, Math.floor(Number(height) || this.canvas.height || 1));
		const margin = Math.max(12, Math.round(Math.min(width, height) * .035));
		const panelWidth = Math.max(160, Math.min(920, width - margin * 2));
		const panelHeight = Math.max(120, height - margin * 2);
		headerHeight = Math.max(38, Math.round(Number(headerHeight) || 38));
		const footerHeight = 28;
		const panelX = Math.round((width - panelWidth) / 2);
		const panelY = Math.round((height - panelHeight) / 2);
		const contentWidth = panelWidth - 32;
		const contentHeight = Math.max(24, panelHeight - headerHeight - footerHeight - 24);

		return {
			width
			, height
			, margin
			, panelWidth
			, panelHeight
			, panelX
			, panelY
			, headerHeight
			, footerHeight
			, contentWidth
			, contentHeight
			, contentX: panelX + 16
			, contentY: panelY + headerHeight + 12
		};
	}

	async rasterize(layout)
	{
		this.layout = layout;
		this.dirty = false;
		this.rasterizing = true;
		const revision = ++this.revision;

		try
		{
			const content = this.contentMarkup();
			const size = await this.measure(content, layout.contentWidth);

			if(revision !== this.revision || !this.opened) return;

			this.contentWidth = Math.max(1, Math.ceil(size.width));
			this.contentHeight = Math.max(0, Math.ceil(size.height));
			this.viewportHeight = layout.contentHeight / this.contentScale(layout);
			this.scroll = clamp(this.scroll, 0, this.maxScroll());
			this.targetScroll = clamp(this.targetScroll, 0, this.maxScroll());

			const [contentImage, overlayImage] = await Promise.all([
				decodeSvg(this.contentSvg(content, this.contentWidth, this.contentHeight))
				, decodeSvg(this.overlaySvg(layout))
			]);

			if(revision !== this.revision || !this.opened) return;

			this.pendingImages = {content: contentImage, overlay: overlayImage};
			this.error = '';
		}
		catch(error)
		{
			if(revision === this.revision)
			{
				this.error = error instanceof Error ? error.message : String(error);
			}
		}
		finally
		{
			if(revision === this.revision) this.rasterizing = false;
		}
	}

	contentMarkup()
	{
		const source = new DOMParser().parseFromString(this.html, 'text/html');
		const root = document.createElement('div');
		root.setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
		root.className = 'phpinfo-root';

		for(const {name, value} of source.body.attributes)
		{
			if(name === 'class') root.className += ' ' + value;
			else root.setAttribute(name, value);
		}

		const style = document.createElement('style');

		const sourceStyles = [...source.querySelectorAll('style')]
			.map(node => node.textContent.replace(/\bbody\b|:root/g, '.phpinfo-root'))
			.join('\n');

		style.textContent = sourceStyles;

		root.append(style);

		for(const node of source.body.childNodes) root.append(node.cloneNode(true));

		return new XMLSerializer().serializeToString(root);
	}

	async measure(markup, width)
	{
		const frame = document.createElement('iframe');

		const css = [
			'position:fixed'
			, 'left:-100000px'
			, 'top:0'
			, `width:${width}px`
			, 'height:1px'
			, 'border:0'
			, 'visibility:hidden'
			, 'pointer-events:none'
			, 'contain:layout'
		].join(';');

		frame.setAttribute('aria-hidden', 'true');
		frame.tabIndex = -1;
		frame.style.cssText = css;

		const loaded = new Promise((resolve, reject) => {
			frame.addEventListener('load', resolve, {once: true});
			frame.addEventListener('error', () => reject(Error('DomTexture measurement frame failed')), {once: true});
		});

		frame.srcdoc
			= `<!doctype html><html><head><style>html,body { margin: 0; padding: 0; overflow: hidden; }</style></head><body>${markup}</body></html>`;

		document.body.append(frame);

		try
		{
			await loaded;

			const document = frame.contentDocument;

			if(!document) throw Error('DomTexture measurement frame is unavailable');

			await Promise.all([
				...document.querySelectorAll('img')
			].map(image => image.decode?.().catch(() => {})));

			await document.fonts?.ready;

			const root = document.querySelector('.phpinfo-root');

			if(!root) throw Error('DomTexture markup root is unavailable');

			const intrinsicWidth = Math.max(width, root.scrollWidth);

			if(intrinsicWidth > width)
			{
				frame.style.width = intrinsicWidth + 'px';
				await new Promise(resolve => requestAnimationFrame(resolve));
			}

			return {
				width: Math.max(intrinsicWidth, root.scrollWidth)
				, height: root.scrollHeight
			};
		}
		finally
		{
			frame.remove();
		}
	}

	contentSvg(content, width, height)
	{
		return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"`
			+ ` viewBox="0 0 ${width} ${height}"><foreignObject width="100%" height="100%">`
			+ `${content}</foreignObject></svg>`;
	}

	overlaySvg(layout)
	{
		const body = `<div xmlns="http://www.w3.org/1999/xhtml" style="position:relative;width:${layout.width}px;height:${layout.height}px;background:rgba(0,0,0,.58);font-family:system-ui,sans-serif;">
	<div style="position:absolute;left:${layout.panelX}px;top:${layout.panelY}px;width:${layout.panelWidth}px;height:${layout.panelHeight}px;border-radius:8px;overflow:hidden;background:#fff;box-shadow:0 12px 45px rgba(0,0,0,.48);display:flex;flex-direction:column;">
		<div style="height:${layout.headerHeight}px;flex:0 0 ${layout.headerHeight}px;display:flex;align-items:center;justify-content:space-between;padding:0 14px;background:#1c2638;color:#f7f9ff;font-weight:700;font-size:15px;"><span>${escapeXml(
			this.title
		)}</span>${this.headerHint
			? `<span style="opacity:.72;font-size:12px;font-weight:500">${escapeXml(
				this.headerHint
			)}</span>`
			: ''}</div>
		<div style="height:${layout.contentHeight + 24}px;flex:0 0 ${layout.contentHeight + 24}px;box-sizing:border-box;padding:12px 16px;background:#fff;"></div>
		<div style="height:${layout.footerHeight}px;flex:0 0 ${layout.footerHeight}px;display:flex;align-items:center;padding:0 14px;background:#edf1f7;color:#39465a;font-size:11px;">↑↓ / wheel scroll · PgUp/PgDn page · Home/End</div>
	</div>
</div>`;

		return `<svg xmlns="http://www.w3.org/2000/svg" width="${layout.width}" height="${layout
			.height}" viewBox="0 0 ${layout.width} ${layout
				.height}"><foreignObject width="100%" height="100%">${body}</foreignObject></svg>`;
	}

	createResources()
	{
		if(this.resources) return this.resources;

		const gl = this.gl;

		const program = link(gl, `#version 300 es
precision highp float;
uniform vec4 rect;
uniform vec4 sourceRect;
uniform vec2 viewport;
out vec2 uv;
void main() {
	vec2 unit = gl_VertexID == 0 ? vec2(0.0, 0.0)
		: gl_VertexID == 1 ? vec2(1.0, 0.0)
		: gl_VertexID == 2 ? vec2(0.0, 1.0)
		: gl_VertexID == 3 ? vec2(0.0, 1.0)
		: gl_VertexID == 4 ? vec2(1.0, 0.0)
		: vec2(1.0, 1.0);
	uv = sourceRect.xy + vec2(unit.x, 1.0 - unit.y) * sourceRect.zw;
	vec2 pixel = rect.xy + unit * rect.zw;
	gl_Position = vec4(pixel / viewport * 2.0 - 1.0, 0.0, 1.0);
}`, `#version 300 es
precision highp float;
uniform sampler2D source;
in vec2 uv;
out vec4 color;
void main() { color = texture(source, uv); }`
		);

		this.resources = {
			program
			, overlay: createTexture(gl)
			, vao: gl.createVertexArray()
			, sampler: gl.getUniformLocation(program, 'source')
			, rect: gl.getUniformLocation(program, 'rect')
			, sourceRect: gl.getUniformLocation(program, 'sourceRect')
			, viewport: gl.getUniformLocation(program, 'viewport')
		};

		return this.resources;
	}

	destroyResources()
	{
		if(!this.resources || this.gl.isContextLost()) return;

		this.clearTiles();

		const {program, overlay, vao} = this.resources;
		this.gl.deleteProgram(program);
		this.gl.deleteTexture(overlay);
		this.gl.deleteVertexArray(vao);
		this.resources = null;
	}

	/** Release the current document tiles without invalidating their source image. */
	clearTiles()
	{
		if(this.tiles.length && !this.gl.isContextLost())
		{
			for(const tile of this.tiles) this.gl.deleteTexture(tile.texture);
		}

		this.tiles = [];
	}

	/** Upload one complete tall HTML shot as independently sampleable GPU-height tiles. */
	uploadTiles()
	{
		const gl = this.gl;
		const width = this.contentImage.naturalWidth || this.contentImage.width;
		const maximumHeight = Math.min(2048, gl.getParameter(gl.MAX_TEXTURE_SIZE));

		for(let start = 0; start < this.contentHeight; start += maximumHeight)
		{
			const height = Math.min(maximumHeight, this.contentHeight - start);
			const canvas = document.createElement('canvas');
			canvas.width = width;
			canvas.height = height;
			canvas.getContext('2d').drawImage(this.contentImage, 0, -start);
			const texture = createTexture(gl);

			gl.bindTexture(gl.TEXTURE_2D, texture);
			gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, canvas);

			this.tiles.push({texture, start, height});
		}

		this.uploads += this.tiles.length;
	}

	/** Draw a texture in drawing-buffer pixels using the shared unit quad. */
	drawTexture(texture, x, y, width, height, source = [0, 0, 1, 1])
	{
		const gl = this.gl;
		gl.bindTexture(gl.TEXTURE_2D, texture);
		gl.uniform4f(this.resources.rect, x, y, width, height);
		gl.uniform4f(this.resources.sourceRect, ...source);
		gl.drawArrays(gl.TRIANGLES, 0, 6);
	}

	/** Scissor and position prebuilt tiles over the panel's fixed document viewport. */
	drawTiles(layout)
	{
		const gl = this.gl;
		const scale = this.contentScale(layout);
		const bottom = this.scroll + this.viewportHeight;
		gl.enable(gl.SCISSOR_TEST);

		gl.scissor(
			layout.contentX
			, this.canvas.height - layout.contentY - layout.contentHeight
			, layout.contentWidth
			, layout.contentHeight
		);

		for(const tile of this.tiles)
		{
			if(tile.start + tile.height <= this.scroll || tile.start >= bottom) continue;

			const start = Math.max(tile.start, this.scroll);
			const end = Math.min(tile.start + tile.height, bottom);
			const height = (end - start) * scale;
			const y = this.canvas.height - layout.contentY - (start - this.scroll) * scale - height;
			const sourceY = (start - tile.start) / tile.height;
			const sourceHeight = (end - start) / tile.height;

			this.drawTexture(
				tile.texture
				, layout.contentX
				, y
				, layout.contentWidth
				, height
				, [0, sourceY, 1, sourceHeight]
			);
		}
	}

	saveState()
	{
		const gl = this.gl;
		const activeTexture = gl.getParameter(gl.ACTIVE_TEXTURE);
		const activeBinding = gl.getParameter(gl.TEXTURE_BINDING_2D);
		let texture0 = activeBinding;

		if(activeTexture !== gl.TEXTURE0)
		{
			gl.activeTexture(gl.TEXTURE0);
			texture0 = gl.getParameter(gl.TEXTURE_BINDING_2D);
			gl.activeTexture(activeTexture);
		}

		return {
			activeTexture
			, activeBinding
			, texture0
			, program: gl.getParameter(gl.CURRENT_PROGRAM)
			, vao: gl.getParameter(gl.VERTEX_ARRAY_BINDING)
			, readFramebuffer: gl.getParameter(gl.READ_FRAMEBUFFER_BINDING)
			, drawFramebuffer: gl.getParameter(gl.DRAW_FRAMEBUFFER_BINDING)
			, viewport: gl.getParameter(gl.VIEWPORT)
			, blend: gl.isEnabled(gl.BLEND)
			, depth: gl.isEnabled(gl.DEPTH_TEST)
			, cull: gl.isEnabled(gl.CULL_FACE)
			, scissor: gl.isEnabled(gl.SCISSOR_TEST)
			, scissorBox: gl.getParameter(gl.SCISSOR_BOX)
			, depthMask: gl.getParameter(gl.DEPTH_WRITEMASK)
			, unpackFlipY: gl.getParameter(gl.UNPACK_FLIP_Y_WEBGL)
			, blendSourceRgb: gl.getParameter(gl.BLEND_SRC_RGB)
			, blendDestinationRgb: gl.getParameter(gl.BLEND_DST_RGB)
			, blendSourceAlpha: gl.getParameter(gl.BLEND_SRC_ALPHA)
			, blendDestinationAlpha: gl.getParameter(gl.BLEND_DST_ALPHA)
			, blendEquationRgb: gl.getParameter(gl.BLEND_EQUATION_RGB)
			, blendEquationAlpha: gl.getParameter(gl.BLEND_EQUATION_ALPHA)
		};
	}

	restoreState(state)
	{
		const gl = this.gl;
		gl.bindFramebuffer(gl.READ_FRAMEBUFFER, state.readFramebuffer);
		gl.bindFramebuffer(gl.DRAW_FRAMEBUFFER, state.drawFramebuffer);
		gl.viewport(...state.viewport);
		gl.useProgram(state.program);
		gl.bindVertexArray(state.vao);
		gl.depthMask(state.depthMask);
		gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, state.unpackFlipY);
		gl.scissor(...state.scissorBox);
		gl.blendEquationSeparate(state.blendEquationRgb, state.blendEquationAlpha);

		gl.blendFuncSeparate(
			state.blendSourceRgb
			, state.blendDestinationRgb
			, state.blendSourceAlpha
			, state.blendDestinationAlpha
		);

		toggle(gl, gl.BLEND, state.blend);
		toggle(gl, gl.DEPTH_TEST, state.depth);
		toggle(gl, gl.CULL_FACE, state.cull);
		toggle(gl, gl.SCISSOR_TEST, state.scissor);
		gl.activeTexture(gl.TEXTURE0);
		gl.bindTexture(gl.TEXTURE_2D, state.texture0);
		gl.activeTexture(state.activeTexture);

		if(state.activeTexture !== gl.TEXTURE0) gl.bindTexture(gl.TEXTURE_2D, state.activeBinding);
	}
}

function clamp(value, minimum, maximum)
{
	return Math.min(maximum, Math.max(minimum, value));
}

function sameLayout(a, b)
{
	return b !== null && a.width === b.width && a.height === b.height && a.headerHeight === b.headerHeight;
}

function escapeXml(value)
{
	return String(value).replace(
		/[&<>"']/g
		, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;'}[character])
	);
}

async function decodeSvg(svg)
{
	const image = new Image();
	image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
	await image.decode();

	return image;
}

function createTexture(gl)
{
	const texture = gl.createTexture();
	gl.bindTexture(gl.TEXTURE_2D, texture);
	gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
	gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
	gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
	gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);

	return texture;
}

function toggle(gl, capability, enabled)
{
	if(enabled) gl.enable(capability);
	else gl.disable(capability);
}

function link(gl, vertexSource, fragmentSource)
{
	const vertex = compile(gl, gl.VERTEX_SHADER, vertexSource);
	const fragment = compile(gl, gl.FRAGMENT_SHADER, fragmentSource);
	const program = gl.createProgram();
	gl.attachShader(program, vertex);
	gl.attachShader(program, fragment);
	gl.linkProgram(program);
	gl.deleteShader(vertex);
	gl.deleteShader(fragment);

	if(gl.getProgramParameter(program, gl.LINK_STATUS)) return program;

	const message = gl.getProgramInfoLog(program);
	gl.deleteProgram(program);
	throw Error('DomTexture shader link failed: ' + message);
}

function compile(gl, type, source)
{
	const shader = gl.createShader(type);
	gl.shaderSource(shader, source);
	gl.compileShader(shader);

	if(gl.getShaderParameter(shader, gl.COMPILE_STATUS)) return shader;

	const message = gl.getShaderInfoLog(shader);
	gl.deleteShader(shader);
	throw Error('DomTexture shader compile failed: ' + message);
}
