<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019 Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\WorkflowEngine\Service;

use OCA\WorkflowEngine\Helper\ScopeContext;
use OCA\WorkflowEngine\Manager;
use OCP\AppFramework\QueryException;
use OCP\Files\Storage\IStorage;
use OCP\IL10N;
use OCP\IServerContainer;
use OCP\IUserSession;
use OCP\WorkflowEngine\ICheck;
use OCP\WorkflowEngine\IEntity;
use OCP\WorkflowEngine\IEntityCheck;
use OCP\WorkflowEngine\IFileCheck;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IOperation;
use OCP\WorkflowEngine\IRuleMatcher;
use RuntimeException;

class RuleMatcher implements IRuleMatcher {

	/** @var IUserSession */
	protected $session;
	/** @var IManager */
	protected $manager;
	/** @var array */
	protected $contexts;
	/** @var IServerContainer */
	protected $container;
	/** @var array */
	protected $fileInfo = [];
	/** @var IL10N */
	protected $l;
	/** @var IOperation */
	protected $operation;
	/** @var IEntity */
	protected $entity;

	public function __construct(
		IUserSession $session,
		IServerContainer $container,
		IL10N $l,
		Manager $manager
	) {
		$this->session = $session;
		$this->manager = $manager;
		$this->container = $container;
		$this->l = $l;
	}

	public function setFileInfo(IStorage $storage, string $path, bool $isDir = false): void {
		$this->fileInfo['storage'] = $storage;
		$this->fileInfo['path'] = $path;
		$this->fileInfo['isDir'] = $isDir;
	}

	public function setEntitySubject(IEntity $entity, $subject): void {
		$this->contexts[get_class($entity)] = [$entity, $subject];
	}

	public function setOperation(IOperation $operation): void {
		if($this->operation !== null) {
			throw new RuntimeException('This method must not be called more than once');
		}
		$this->operation = $operation;
	}

	public function setEntity(IEntity $entity): void {
		if($this->entity !== null) {
			throw new RuntimeException('This method must not be called more than once');
		}
		$this->entity = $entity;
	}

	public function getEntity(): IEntity {
		if($this->entity === null) {
			throw new \LogicException('Entity was not set yet');
		}
		return $this->entity;
	}

	public function getFlows(bool $returnFirstMatchingOperationOnly = true): array {
		if(!$this->operation) {
			throw new RuntimeException('Operation is not set');
		}
		return $this->getMatchingOperations(get_class($this->operation), $returnFirstMatchingOperationOnly);
	}

	public function getMatchingOperations(string $class, bool $returnFirstMatchingOperationOnly = true): array {
		$scopes[] = new ScopeContext(IManager::SCOPE_ADMIN);
		$user = $this->session->getUser();
		if($user !== null) {
			$scopes[] = new ScopeContext(IManager::SCOPE_USER, $user->getUID());
		}

		$operations = [];
		foreach ($scopes as $scope) {
			$operations = array_merge($operations, $this->manager->getOperations($class, $scope));
		}

		$additionalScopes = $this->manager->getAllConfiguredScopesForOperation($class);
		foreach ($additionalScopes as $hash => $scopeCandidate) {
			/** @var ScopeContext $scopeCandidate */
			if ($scopeCandidate->getScope() !== IManager::SCOPE_USER) {
				continue;
			}
			if ($this->entity->isLegitimatedForUserId($scopeCandidate->getScopeId())) {
				$operations = array_merge($operations, $this->manager->getOperations($class, $scopeCandidate));
			}
		}

		$matches = [];
		foreach ($operations as $operation) {
			$checkIds = json_decode($operation['checks'], true);
			$checks = $this->manager->getChecks($checkIds);

			foreach ($checks as $check) {
				if (!$this->check($check)) {
					// Check did not match, continue with the next operation
					continue 2;
				}
			}

			if ($returnFirstMatchingOperationOnly) {
				return $operation;
			}
			$matches[] = $operation;
		}

		return $matches;
	}

	/**
	 * @param array $check
	 * @return bool
	 */
	public function check(array $check) {
		try {
			$checkInstance = $this->container->query($check['class']);
		} catch (QueryException $e) {
			// Check does not exist, assume it matches.
			return true;
		}

		if ($checkInstance instanceof IFileCheck) {
			if (empty($this->fileInfo)) {
				throw new RuntimeException('Must set file info before running the check');
			}
			$checkInstance->setFileInfo($this->fileInfo['storage'], $this->fileInfo['path'], $this->fileInfo['isDir']);
		} elseif ($checkInstance instanceof IEntityCheck) {
			foreach($this->contexts as $entityInfo) {
				list($entity, $subject) = $entityInfo;
				$checkInstance->setEntitySubject($entity, $subject);
			}
		} else if(!$checkInstance instanceof ICheck) {
			// Check is invalid
			throw new \UnexpectedValueException($this->l->t('Check %s is invalid or does not exist', $check['class']));
		}
		return $checkInstance->executeCheck($check['operator'], $check['value']);
	}
}
