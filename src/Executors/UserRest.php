<?php

namespace spaceonfire\Restify\Executors;

use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use \Bitrix\Main\UserTable;
use CMain;
use CUser;
use Emonkak\HttpException\AccessDeniedHttpException;
use Emonkak\HttpException\BadRequestHttpException;
use Emonkak\HttpException\InternalServerErrorHttpException;
use Emonkak\HttpException\NotFoundHttpException;
use Emonkak\HttpException\UnauthorizedHttpException;
use Exception;

class UserRest implements IExecutor {
	use RestTrait {
		buildSchema as private _buildSchema;
	}

	private $entity = 'Bitrix\Main\UserTable';

	/**
	 * UserRest constructor
	 * @param array $options executor options
	 * @throws \Bitrix\Main\LoaderException
	 * @throws InternalServerErrorHttpException
	 */
	public function __construct($options) {
		$this->loadModules('main');
		$this->checkEntity();
		$this->setSelectFieldsFromEntityClass();
		$this->setPropertiesFromArray($options);
		$this->registerBasicTransformHandler();
		$this->registerPermissionsCheck();
		$this->buildSchema();
	}

	private function buildSchema() {
		$this->_buildSchema();
		$schema = $this->get('schema');
		$schema['PERSONAL_PHOTO'] = 'file';
		$schema['WORK_LOGO'] = 'file';
		$this->set('schema', $schema);
	}

	public function create() {
		global $USER;
		$id = $USER->Add($this->body);

		if (!$id) {
			throw new BadRequestHttpException($USER->LAST_ERROR);
		}

		return $this->readOne($id);
	}

	public function readMany() {
		$select = $this->splitSelect($this->select);

		$query = CUser::GetList(
			array_shift(array_keys($this->order)),
			array_shift(array_values($this->order)),
			$this->filter,
			[
				'SELECT' => $select['userFields'],
				'NAV_PARAMS' => $this->navParams,
				'FIELDS' => $select['default'],
			]
		);

		$results = [];
		while ($item = $query->GetNext(true, false)) {
			$results[] = $item;
		}

		return $results;
	}

	public function readOne($id) {
		$this->registerOneItemTransformHandler();

		$id = $this->getId($id);

		// Set id to filter
		if (is_numeric($id)) {
			$this->filter['ID'] = $id;
		}

		// Get only one item
		$this->navParams = ['nPageSize' => 1];

		$results = $this->readMany();

		if (!count($results)) {
			throw new NotFoundHttpException();
		}

		return $results;
	}

	public function update($id = null) {
		if (!$id) {
			$id = $this->body['ID'];
		}

		$id = $this->getId($id);

		global $USER;
		if (!$USER->Update($id, $this->body)) {
			throw new BadRequestHttpException($USER->LAST_ERROR);
		}

		return $this->readOne($id);
	}

	public function delete($id) {
		$this->registerOneItemTransformHandler();

		global $APPLICATION, $DB;
		$DB->StartTransaction();

		try {
			$id = $this->getId($id);
			$result = CUser::Delete($id);
			if (!$result) {
				throw new InternalServerErrorHttpException($APPLICATION->LAST_ERROR);
			}
		} catch (Exception $exception) {
			$DB->Rollback();
			throw $exception;
		}

		$DB->Commit();

		return [
			$this->success(Loc::getMessage('MAIN_USER_DELETE')),
		];
	}

	public function count() {
		$this->registerOneItemTransformHandler();

		$this->select = ['ID'];

		$select = $this->splitSelect($this->select);

		$query = CUser::GetList(
			array_shift(array_keys($this->order)),
			array_shift(array_values($this->order)),
			$this->filter,
			[
				'SELECT' => $select['userFields'],
				'NAV_PARAMS' => $this->navParams,
				'FIELDS' => $select['default'],
			]
		);

		$count = $query->SelectedRowsCount();

		return [
			[
				'count' => $count,
			],
		];
	}

	/**
	 * Static function
	 * Finds user's login name by email
	 */
	private function getLoginByEmail( $email ){
		$loginName = '';

		$filter = [
			'EMAIL' => $email,
		];

		$res = UserTable::getList( [ 'filter' => $filter ] );
		if ( 1 === $res->getSelectedRowsCount() ){ // avoid dupes
			$userArr = $res->fetch();
			if ( ! empty( $userArr ) ){
				$loginName = $userArr[ 'LOGIN' ];
			}
		}

		return $loginName;
	}

	/**
	 * Login user
	 * Tries in this order: 'ID', 'LOGIN', 'EMAIL'
	 * @throws UnauthorizedHttpException
	 */
	public function login() {
		global $USER, $APPLICATION;
		$rv = [];
		$doThrowUnauth = '';
		$result = false;

		list( $inputLogin, $inputPassword, $inputRemember ) = [
			$this->body['LOGIN'], $this->body['PASSWORD'], $this->body['REMEMBER']
		];


		$res = CUser::GetByID( $inputLogin );
		if( $res->SelectedRowsCount() ){
			$userArr = $res->GetNext();
			$loginName = $userArr[ 'LOGIN' ];

			// Login with ID
			if( !empty( $loginName ) ){ $result = $USER->Login(
				$loginName, $inputPassword, $inputRemember );
			}
		}

		if( true !== $result ){
			$loginName = $inputLogin;

			// Login with name
			if( !empty( $loginName ) ){ $result = $USER->Login(
				$loginName, $inputPassword, $inputRemember );
			}
		}

		if( true !== $result ){
			$loginName = self::getLoginByEmail( $inputLogin );

			// Login with email
			if( !empty( $loginName ) ){ $result = $USER->Login(
				$loginName, $inputPassword, $inputRemember );
			}
		}

		$APPLICATION->arAuthResult = $result;
		if ($result === true) {
			$userInfo = $this->readOne($loginName);
			if ( !empty( $userInfo[0][ 'ID' ] ) ){
				$rv = [ $userInfo ];
			}
		} else {
			throw new UnauthorizedHttpException($result['MESSAGE']);
		}

		return $rv;
	}

	/**
	 * Logout user
	 */
	public function logout() {
		$this->registerOneItemTransformHandler();

		global $USER;
		$USER->Logout();

		return [
			$this->success(Loc::getMessage('LOGOUT_MESSAGE')),
		];
	}

	/**
	 * Forgot password
	 * @throws BadRequestHttpException
	 */
	public function forgotPassword() {
		$this->registerOneItemTransformHandler();
		global $USER;
		$result = $USER->SendPassword($this->body['LOGIN'], $this->body['LOGIN']);
		$result['MESSAGE'] = str_replace('<br>', '', $result['MESSAGE']);

		if ($result['TYPE'] !== 'OK') {
			throw new BadRequestHttpException($result['MESSAGE']);
		}

		return [
			$this->success($result['MESSAGE']),
		];
	}

	/**
	 * Reset password
	 * @throws BadRequestHttpException
	 */
	public function resetPassword() {
		$this->registerOneItemTransformHandler();
		global $USER;
		$result = $USER->ChangePassword(
			$this->body['LOGIN'],
			$this->body['CHECKWORD'],
			$this->body['PASSWORD'],
			$this->body['PASSWORD']
		);
		$result['MESSAGE'] = str_replace('<br>', '', $result['MESSAGE']);

		if ($result['TYPE'] !== 'OK') {
			throw new BadRequestHttpException($result['MESSAGE']);
		}

		return [
			$this->success($result['MESSAGE']),
		];
	}

	/**
	 * Split select fields on UserFields and Default fields
	 * @param array $select
	 * @return array
	 */
	private function splitSelect(array $select) {
		$userFields = array_filter($select, function($field) {
			return strpos($field, 'UF') === 0;
		});
		$defaultFields = array_filter($select, function($field) {
			return strpos($field, 'UF') !== 0;
		});
		return [
			'userFields' => $userFields,
			'default' => $defaultFields
		];
	}

	/**
	 * Get user id
	 * @param string $loginName user login or 'me' alias
	 * @return int user id
	 * @throws UnauthorizedHttpException if me alias used by user unauthorized
	 * @throws NotFoundHttpException no user found with this login
	 */
	private function getId($loginName) {
		global $USER;
		$id='';

		// Convert me to current user id
		if ($id === 'me') {
			$id = $USER->GetID();
		} else {

			// Find by login first
			$tmpBy = 'LOGIN';
			$tmpOrder = 'ASC';
			$user = UserTable::getList( [ 'filter' =>
				array_merge($this->filter,
					['LOGIN' => $loginName ]
				), ]
			)->fetch();
			if ($user) {
				$id = (int) $user['ID'];
			} else {

				// Then return argument as is
				$id = $loginName;
			}
		}

		// Number is id
		if (is_numeric($id) && (int) $id > 0) {
			return (int) $id;
		} else {
			throw new NotFoundHttpException();
		}
	}

	private function registerPermissionsCheck() {
		global $SPACEONFIRE_RESTIFY;
		$events = [
			'pre:update',
			'pre:delete',
			'pre:readMany',
			'pre:readOne',
		];

		foreach ($events as $event) {
			EventManager::getInstance()->addEventHandler(
				$SPACEONFIRE_RESTIFY->getId(),
				$event,
				[$this, 'checkPermissions']
			);
		}
	}

	public function checkPermissions(Event $event) {
		global $USER;
		$permissions = CMain::GetUserRight('main');
		$eventType = $event->getEventType();
		$isWrite = in_array($eventType, ['pre:update', 'pre:delete']);

		if (!$USER->GetID()) {
			throw new UnauthorizedHttpException();
		}

		switch ($permissions) {
			case 'W': {
				// Full access, skip check
				return;
				break;
			}

			case 'V': {
				// Can read all data and change profiles by some groups
				if ($isWrite) {
					$this->filter = array_merge($this->filter, [
						'GROUPS_ID' => \CGroup::GetSubordinateGroups($USER->GetUserGroupArray()),
					]);
				}
				break;
			}

			case 'T': {
				// Can read all data and change self profile
				if ($isWrite) {
					$this->filter = array_merge($this->filter, [
						'ID' => $USER->GetID(),
					]);
				}
			}

			case 'R': {
				// Can only read all data
				if ($isWrite) {
					throw new AccessDeniedHttpException();
				}
			}

			case 'P': {
				// Can read and change only self profile
				$this->filter = array_merge($this->filter, [
					'ID' => $USER->GetID(),
				]);
				break;
			}

			default: {
				throw new AccessDeniedHttpException();
				break;
			}
		}
	}
}
