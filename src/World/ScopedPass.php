<?php
declare(strict_types=1);
namespace Phonics\World;

use Phonics\Render\FrameContext;
use Phonics\Render\RenderPass;

/** Gate a retained render pass with the same activation boundary as its systems. */
final class ScopedPass implements RenderPass
{

	/** Bind render eligibility to scope activation.
 * @param ScopeContext $scope Owning scope.
 * @param RenderPass $pass Underlying render pass.
 * @return void Retains the scope and pass.
 */
	public function __construct(private ScopeContext $scope, private RenderPass $pass) {}

	/** Expose the underlying pass identity.
 * @return string Stable render pass ID.
 */
	public function id(): string { return $this->pass->id(); }

	/** Expose render dependencies.
 * @return array Preceding pass IDs.
 */
	public function after(): array { return $this->pass->after(); }

	/** Render only while the owning scope is active.
 * @param FrameContext $frame Current graphics services.
 * @return void Delegates rendering when enabled.
 */
	public function render(FrameContext $frame): void
	{
		if($this->scope->active()) $this->pass->render($frame);
	}

	/** Restore the owned pass graphics resources.
 * @return void Delegates graphics restoration.
 */
	public function restore(): void { $this->pass->restore(); }

	/** Release the owned render pass.
 * @return void Delegates resource release.
 */
	public function close(): void { $this->pass->close(); }
}
