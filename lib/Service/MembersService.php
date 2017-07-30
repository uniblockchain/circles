<?php
/**
 * Circles - bring cloud-users closer
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@pontapreta.net>
 * @copyright 2017
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

namespace OCA\Circles\Service;


use OC\User\NoUserException;
use OCA\Circles\Db\CirclesRequest;
use OCA\Circles\Db\MembersRequest;
use OCA\Circles\Exceptions\CircleTypeNotValidException;
use OCA\Circles\Exceptions\GroupDoesNotExistException;
use OCA\Circles\Exceptions\MemberAlreadyExistsException;
use OCA\Circles\Exceptions\MemberDoesNotExistException;
use OCA\Circles\Model\Circle;
use \OCA\Circles\Model\Member;
use OCP\IL10N;
use OCP\IUserManager;

class MembersService {

	/** @var string */
	private $userId;

	/** @var IL10N */
	private $l10n;

	/** @var IUserManager */
	private $userManager;

	/** @var ConfigService */
	private $configService;

	/** @var CirclesRequest */
	private $circlesRequest;

	/** @var MembersRequest */
	private $membersRequest;

	/** @var EventsService */
	private $eventsService;

	/** @var MiscService */
	private $miscService;

	/**
	 * MembersService constructor.
	 *
	 * @param $userId
	 * @param IL10N $l10n
	 * @param IUserManager $userManager
	 * @param ConfigService $configService
	 * @param CirclesRequest $circlesRequest
	 * @param MembersRequest $membersRequest
	 * @param EventsService $eventsService
	 * @param MiscService $miscService
	 */
	public function __construct(
		$userId,
		IL10N $l10n,
		IUserManager $userManager,
		ConfigService $configService,
		CirclesRequest $circlesRequest,
		MembersRequest $membersRequest,
		EventsService $eventsService,
		MiscService $miscService
	) {
		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->userManager = $userManager;
		$this->configService = $configService;
		$this->circlesRequest = $circlesRequest;
		$this->membersRequest = $membersRequest;
		$this->eventsService = $eventsService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $circleUniqueId
	 * @param string $name
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function addMember($circleUniqueId, $name) {

		try {
			$circle = $this->circlesRequest->getCircle($circleUniqueId, $this->userId);
			$circle->getHigherViewer()
				   ->hasToBeModerator();
		} catch (\Exception $e) {
			throw $e;
		}

		try {
			$member = $this->getFreshNewMember($circleUniqueId, $name);
		} catch (\Exception $e) {
			throw $e;
		}

		$member->inviteToCircle($circle->getType());
		$this->membersRequest->updateMember($member);

		$this->eventsService->onMemberNew($circle, $member);

		return $this->membersRequest->getMembers($circle->getUniqueId(), $circle->getHigherViewer());
	}


	/**
	 * @param string $circleUniqueId
	 * @param string $groupId
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function importMembersFromGroup($circleUniqueId, $groupId) {

		try {
			$circle = $this->circlesRequest->getCircle($circleUniqueId, $this->userId);
			$circle->getHigherViewer()
				   ->hasToBeModerator();
		} catch (\Exception $e) {
			throw $e;
		}

		$group = \OC::$server->getGroupManager()
							 ->get($groupId);
		if ($group === null) {
			throw new GroupDoesNotExistException('This group does not exist');
		}

		foreach ($group->getUsers() as $user) {
			try {
				$member = $this->getFreshNewMember($circleUniqueId, $user->getUID());

				$member->inviteToCircle($circle->getType());
				$this->membersRequest->updateMember($member);

				$this->eventsService->onMemberNew($circle, $member);
			} catch (MemberAlreadyExistsException $e) {
			} catch (\Exception $e) {
				throw $e;
			}
		}

		return $this->membersRequest->getMembers($circle->getUniqueId(), $circle->getHigherViewer());
	}


	/**
	 * getMember();
	 *
	 * Will return any data of a user related to a circle (as a Member). User can be a 'non-member'
	 * Viewer needs to be at least Member of the Circle
	 *
	 * @param $circleId
	 * @param $userId
	 *
	 * @return Member
	 * @throws \Exception
	 */
	public function getMember($circleId, $userId) {

		try {
			$this->circlesRequest->getCircle($circleId, $this->userId)
								 ->getHigherViewer()
								 ->hasToBeMember();

			$member = $this->membersRequest->forceGetMember($circleId, $userId);
			$member->setNote('');

			return $member;
		} catch (\Exception $e) {
			throw $e;
		}
	}


	/**
	 * Check if a fresh member can be generated (by addMember)
	 *
	 * @param string $circleUniqueId
	 * @param string $name
	 *
	 * @return null|Member
	 * @throws MemberAlreadyExistsException
	 * @throws \Exception
	 */
	private function getFreshNewMember($circleUniqueId, $name) {

		try {
			$userId = $this->getRealUserId($name);
		} catch (\Exception $e) {
			throw $e;
		}

		try {
			$member = $this->membersRequest->forceGetMember($circleUniqueId, $userId);

		} catch (MemberDoesNotExistException $e) {
			$member = new Member($this->l10n, $userId, $circleUniqueId);
			$this->membersRequest->createMember($member);
		}

		if ($this->memberAlreadyExist($member)) {
			throw new MemberAlreadyExistsException(
				$this->l10n->t('This user is already a member of the circle')
			);
		}

		return $member;
	}


	/**
	 * return the real userId, with its real case
	 *
	 * @param $userId
	 *
	 * @return string
	 * @throws NoUserException
	 */
	private function getRealUserId($userId) {
		if (!$this->userManager->userExists($userId)) {
			throw new NoUserException($this->l10n->t("This user does not exist"));
		}

		return $this->userManager->get($userId)
								 ->getUID();
	}

	/**
	 * return if member already exists
	 *
	 * @param Member $member
	 *
	 * @return bool
	 */
	private function memberAlreadyExist($member) {
		return ($member->getLevel() > Member::LEVEL_NONE
				|| ($member->getStatus() !== Member::STATUS_NONMEMBER
					&& $member->getStatus() !== Member::STATUS_REQUEST)
		);
	}


	/**
	 * @param string $circleUniqueId
	 * @param string $name
	 * @param int $level
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function levelMember($circleUniqueId, $name, $level) {

		$level = (int)$level;
		try {
			$circle = $this->circlesRequest->getCircle($circleUniqueId, $this->userId);
			if ($circle->getType() === Circle::CIRCLES_PERSONAL) {
				throw new CircleTypeNotValidException(
					$this->l10n->t('You cannot edit level in a personal circle')
				);
			}

			$member = $this->membersRequest->forceGetMember($circle->getUniqueId(), $name);
			if ($member->getLevel() !== $level) {
				if ($level === Member::LEVEL_OWNER) {
					$this->switchOwner($circle, $member);
				} else {
					$this->editMemberLevel($circle, $member, $level);
				}

				$this->eventsService->onMemberLevel($circle, $member);
			}

			return $this->membersRequest->getMembers($circle->getUniqueId(), $circle->getHigherViewer());
		} catch (\Exception $e) {
			throw $e;
		}

	}


	/**
	 * @param Circle $circle
	 * @param Member $member
	 * @param $level
	 *
	 * @throws \Exception
	 */
	private function editMemberLevel(Circle $circle, Member &$member, $level) {
		try {
			$isMod = $circle->getHigherViewer();
			$isMod->hasToBeModerator();
			$isMod->hasToBeHigherLevel($level);

			$member->hasToBeMember();
			$member->cantBeOwner();
			$isMod->hasToBeHigherLevel($member->getLevel());

			$member->setLevel($level);
			$this->membersRequest->updateMember($member);
		} catch (\Exception $e) {
			throw $e;
		}

	}

	/**
	 * @param Circle $circle
	 * @param Member $member
	 *
	 * @throws \Exception
	 */
	private function switchOwner(Circle $circle, Member &$member) {
		try {
			$isMod = $circle->getHigherViewer();
			$isMod->hasToBeOwner();

			$member->cantBeOwner();
			$member->setLevel(Member::LEVEL_OWNER);
			$this->membersRequest->updateMember($member);

			$isMod->setLevel(Member::LEVEL_ADMIN);
			$this->membersRequest->updateMember($isMod);

		} catch (\Exception $e) {
			throw $e;
		}
	}


	/**
	 * @param string $circleUniqueId
	 * @param string $name
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function removeMember($circleUniqueId, $name) {

		try {
			$circle = $this->circlesRequest->getCircle($circleUniqueId, $this->userId);
			$circle->getHigherViewer()
				   ->hasToBeModerator();

			$member = $this->membersRequest->forceGetMember($circleUniqueId, $name);
			$member->hasToBeMemberOrAlmost();
			$member->cantBeOwner();

			$circle->getHigherViewer()
				   ->hasToBeHigherLevel($member->getLevel());
		} catch (\Exception $e) {
			throw $e;
		}

		$member->setStatus(Member::STATUS_NONMEMBER);
		$member->setLevel(Member::LEVEL_NONE);
		$this->membersRequest->updateMember($member);

		$this->eventsService->onMemberLeaving($circle, $member);

		return $this->membersRequest->getMembers($circle->getUniqueId(), $circle->getHigherViewer());
	}


	/**
	 * When a user is removed, remove him from all Circles
	 *
	 * @param $userId
	 */
	public function onUserRemoved($userId) {
		$this->membersRequest->removeAllFromUser($userId);
	}


}