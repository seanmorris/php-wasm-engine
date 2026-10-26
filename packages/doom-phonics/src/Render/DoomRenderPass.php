<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\ViewLighting;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Component\ViewEffects;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\Graphics\ViewColorState;
use DoomPhonics\Menu\MenuOverlay;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use Phonics\Render\FrameContext;
use Phonics\Render\RenderPass;

/** Camera pass for one active WAD map, backed by direct GLES rather than a scene graph. */
final class DoomRenderPass implements RenderPass
{
	private Query $players;
	private ?ScaledSceneTarget $target;
	private CameraHeight $cameraHeight;
	private GameSettings $settings;

	public function __construct(
		private string $name
		, private Registry $registry
		, public GlesMapRenderer $renderer
		, private ?MenuOverlay $menu = null
		, private ?ScreenWipe $wipe = null
		, ?GameSettings $settings = null
		, ?CameraHeight $cameraHeight = null
	){
		$this->players = Query::all(PlayerStart::class, Transform::class);
		$this->cameraHeight = $cameraHeight ?? new CameraHeight;
		$this->settings = $settings ?? new GameSettings;
		$this->target = null;
	}

	public function id(): string { return $this->name; }

	public function after(): array { return []; }

	public function render(FrameContext $frame): void
	{
		$this->synchronize($frame);
		$this->wipe?->beginFrame();

		try
		{
			$this->renderFrame($frame);
		}
		finally
		{
			$this->wipe?->present();
		}
	}

	/** Apply physical framebuffer and user quality settings before any target begins capture. */
	private function synchronize(FrameContext $frame): void
	{
		$scale = $this->settings->renderResolution()->scale($this->renderer->classicRenderScale);
		$viewport = new RenderViewport($frame->device->width, $frame->device->height, $scale);
		$this->renderer->resize($viewport);
		$this->renderer->setPitchMode($this->settings->pitchMode());
		$this->wipe?->resize($viewport->outputWidth, $viewport->outputHeight);

		if($viewport->sceneScale < 1)
		{
			if($this->target) $this->target->resize($viewport);
			else $this->target = new ScaledSceneTarget($viewport);
		}
		elseif($this->target)
		{
			$this->target->close();
			$this->target = null;
		}
	}

	private function renderFrame(FrameContext $frame): void
	{
		$this->renderer->setViewColor(new ViewColorState);

		if($this->menu?->coversWorld())
		{
			$this->menu->render($this->renderer);

			return;
		}

		$players = $this->registry->query($this->players);

		if(!$players) return;

		$entity = $players[0];

		foreach($players as $candidate)
		if($this->registry->has($candidate, NetworkPlayer::class)
			&& $this->registry->get($candidate, NetworkPlayer::class)->local
		){
			$entity = $candidate;
			break;
		}

		$pose = $this->registry->get($entity, Transform::class);
		$alpha = max(0, min(1, $frame->alpha));
		$x = $pose->xAt($alpha);
		$y = $pose->yAt($alpha);
		$z = $this->cameraHeight->follow($pose->zAt($alpha));
		$pitch = $this->registry->has($entity, ViewInput::class)
			? $this->registry->get($entity, ViewInput::class)->pitch
			: 0.0;

		$angle = $pose->angle + ($this->registry->has($entity, ViewInput::class)
			? $this->registry->get($entity, ViewInput::class)->yaw
			: 0
		);

		$extraLight = $this->registry->has($entity, ViewLighting::class)
			? $this->registry->get($entity, ViewLighting::class)->mapLightIncrease()
			: 0;

		if($this->registry->has($entity, PlayerState::class))
		{
			$player = $this->registry->get($entity, PlayerState::class);

			if(isset($player->powers['LightAmp'])) $extraLight = max($extraLight, 255);

			if($this->registry->has($entity, ViewEffects::class))
			$this->renderer->setViewColor($this->registry->get($entity, ViewEffects::class)->colors($player));
		}

		$this->target?->begin();

		if(!$this->target) glViewport(0, 0, $frame->device->width, $frame->device->height);

		$this->renderer->render($x, $y, $z, $angle, $pitch, $extraLight, $alpha);
		$this->renderer->renderActors($this->registry, $x, $y, $z, $angle, $pitch, $entity, $extraLight, $alpha);
		$this->renderer->renderBeams($this->registry, $x, $y, $z, $angle, $pitch, $alpha);
		$this->target?->compose();
		$this->renderer->renderOverlay($this->registry);
		$this->menu?->render($this->renderer);
	}

	public function restore(): void
	{
		$this->wipe?->restore();
		$this->target?->restore();
		$this->renderer->restore();
		$this->menu?->restore();
	}

	public function close(): void
	{
		$this->target?->close();
		$this->renderer->close();
	}
}
