<?php
declare(strict_types=1);
namespace Phonics\World;

/** Game-defined state handling at scope boundaries, independent of the content format. */
interface ScopeLifecycle
{

	/**
	 * Called after content and systems have been prepared, before they can run.
	 *
	 * @param ScopeContext $scope Owning content scope.
	 * @return void Prepares content without enabling simulation.
	 */
	public function load(ScopeContext $scope): void;

	/**
	 * Called before enabling a prepared or retained scope.
	 *
	 * @param ScopeContext $scope Owning content scope.
	 * @return void Reference-counted handle or content scope for this activation.
	 */
	public function activate(ScopeContext $scope): void;

	/**
	 * Stop a scope and return retain, snapshot, or reset for its owned instances.
	 *
	 * @param ScopeContext $scope Owning content scope.
	 * @return string Selected state disposition or completed deactivation.
	 */
	public function deactivate(ScopeContext $scope): string;

	/**
	 * Called before releasing the scope's instances and resources.
	 *
	 * @param ScopeContext $scope Owning content scope.
	 * @return void Releases prepared instances and source resources.
	 */
	public function unload(ScopeContext $scope): void;
}
