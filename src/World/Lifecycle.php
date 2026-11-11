<?php
declare(strict_types=1);
namespace Phonics\World;

/** Stock retain, snapshot/release, and reset policies; applications may implement ScopeLifecycle. */
class Lifecycle implements ScopeLifecycle
{

	/**
	 * Select how deactivation treats the scope's instances.
	 *
	 * @param string $behavior Disposition on deactivation: retain, snapshot, or reset.
	 * @return void Initializes the owned references and validates configuration.
	 */
	public function __construct(private string $behavior = 'snapshot')
	{
		if(!in_array($behavior, ['retain', 'snapshot', 'reset'], true))
		throw new \InvalidArgumentException("Unknown scope lifecycle $behavior");
	}

	/** Use the default preparation behavior.
 * @param ScopeContext $scope Prepared ownership scope.
 * @return void Leaves prepared state unchanged.
 */
	public function load(ScopeContext $scope): void {}

	/** Resume the prepared instances.
 * @param ScopeContext $scope Prepared ownership scope.
 * @return void Requires no additional activation work.
 */
	public function activate(ScopeContext $scope): void {}

	/** Choose how the runtime handles stopped instances.
 * @param ScopeContext $scope Prepared ownership scope.
 * @return string Configured retain, snapshot, or reset disposition.
 */
	public function deactivate(ScopeContext $scope): string { return $this->behavior; }

	/** Complete the stock lifecycle.
 * @param ScopeContext $scope Prepared ownership scope.
 * @return void Leaves resource cleanup to scope ownership.
 */
	public function unload(ScopeContext $scope): void {}
}
