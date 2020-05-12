<?php

namespace spaceonfire\Restify\Executors;

use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use CIBlock;
use CIBlockSection;
use CIBlockFindTools;
use Emonkak\HttpException\AccessDeniedHttpException;
use Emonkak\HttpException\BadRequestHttpException;
use Emonkak\HttpException\InternalServerErrorHttpException;
use Emonkak\HttpException\NotFoundHttpException;
use Exception;

class IblockSectionRest implements IExecutor {
	use RestTrait {
		prepareQuery as private _prepareQuery;
		buildSchema as private _buildSchema;
	}

	protected $iblockId;
	private $permissions = [];
	private $entity = 'Bitrix\Iblock\SectionTable';

	/**
	 * IblockSectionRest constructor
	 * @param array $options executor options
	 * @throws \Bitrix\Main\LoaderException
	 * @throws InternalServerErrorHttpException
	 * @throws Exception
	 */
	public function __construct($options) {
		$this->loadModules('iblock');

		if (!$options['iblockId']) {
			throw new InternalServerErrorHttpException(Loc::getMessage('REQUIRED_PROPERTY', [
				'#PROPERTY#' => 'iblockId',
			]));
		}

		$this->filter = [
			'ACTIVE' => 'Y',
			'GLOBAL_ACTIVE' => 'Y',
		];

		$this->checkEntity();
		$this->setSelectFieldsFromEntityClass();
		$this->setPropertiesFromArray($options);
		$this->registerBasicTransformHandler();
		$this->registerPermissionsCheck();
		$this->registerSectionTransform();
		$this->buildSchema();
	}

	/**
	 * @throws Exception
	 */
	private function buildSchema() {
		$this->_buildSchema();
		$schema = $this->get('schema');
		$schema['PICTURE'] = 'file';
		$schema['DETAIL_PICTURE'] = 'file';
		$this->set('schema', $schema);
	}

	public function create() {
		$section = new CIBlockSection;
		$sectionId = $section->Add($this->body);

		if (!$sectionId) {
			throw new BadRequestHttpException($section->LAST_ERROR);
		}

		return $this->readOne($sectionId);
	}

	public function readMany() {
		$query = CIBlockSection::GetList(
			$this->order,
			$this->filter,
			false,
			$this->select,
			$this->navParams
		);

		$results = [];
		while ($item = $query->GetNext(true, false)) {
			$results[] = $item;
		}

		return $results;
	}

	public function readOne($id) {
		$this->registerOneItemTransformHandler();

		// Set id to filter
		if (is_numeric($id)) {
			$this->filter['ID'] = $id;
		} else {
			$this->filter['CODE'] = $id;
		}

		// Get only one item
		$this->navParams = ['nPageSize' => 1];

		$results = $this->readMany();

		if (!count($results)) {
			throw new NotFoundHttpException();
		}

		$depthLevel = $results[0][ 'DEPTH_LEVEL' ];
		$results[0]['IBLOCK_ROOT_SECTION_ID'] = null;
		if( 1 != $depthLevel ){
			$result = $results[0];

			$sth = CIBlockSection::GetNavChain(
				$result[ 'IBLOCK_ID' ], $result[ 'IBLOCK_SECTION_ID' ],
				[ "ID", "DEPTH_LEVEL", ]
			);
			while($sectionArr = $sth->Fetch()){
				if ($sectionArr['DEPTH_LEVEL'] == 1){
					$result['IBLOCK_ROOT_SECTION_ID'] = $sectionArr['ID'];
				}
			}

			$results[0] = $result;
		}

		if( is_array( $results[0][ 'UF_FILTER_TAGS' ] )
				&&
			( ! empty( $results[0][ 'UF_FILTER_TAGS' ] ) )
		){
			$uf_ft_ids = $results[0][ 'UF_FILTER_TAGS' ];


			$uf_fts = [];
			$filterEntityId = 'IBLOCK_' . $this->iblockId . '_SECTION';
			$sth = \CUserTypeEntity::GetList( [], [
				'ENTITY_ID' => $filterEntityId,
				'FIELD_NAME' => 'UF_FILTER_TAGS',
			] );
			$entitiesCount = $sth->SelectedRowsCount();
			if( 1 == $entitiesCount ){
				$userFieldArr = $sth->fetch();
				if( ( ! empty( $userFieldArr ) )
					&&
					( ! empty( $userFieldArr[ 'SETTINGS' ] ) )
					&&
					( ! empty( $userFieldArr[ 'SETTINGS' ][ 'IBLOCK_ID' ] ) )
				){
					$userFieldIblockId = $userFieldArr[ 'SETTINGS' ][ 'IBLOCK_ID' ];
					$userFieldFilterTagsSth = \CIBlockElement::GetList( [],
					[
						'IBLOCK_ID' => $userFieldIblockId,
						'ID' => $uf_ft_ids,
					],
					false, false,
					[ 'CODE', 'NAME', 'PROPERTY_JSON',
					]
					 );
					$filterTags = [];
					while ( $filterTag = $userFieldFilterTagsSth->Fetch() ){
						if( ! empty( $filterTag[ 'PROPERTY_JSON_VALUE' ] ) ){
							$propertyJsonValue = $filterTag[ 'PROPERTY_JSON_VALUE' ];
							foreach ( [ 'PROPERTY_JSON_VALUE', 'PROPERTY_JSON_VALUE_ID', ] as $key ){
								unset( $filterTag[ $key ] );
							}
							$filterTag[ 'PROPERTY_JSON' ] = $propertyJsonValue;
							$filterTags[] = $filterTag;
						}
					}
					$results[0][ 'UF_FILTER_TAGS' ] = $filterTags;
				}
			}
		} else {
			$results[0][ 'UF_FILTER_TAGS' ] = [];
		}

		return $results;
	}

	public function update($id = null) {
		if (!$id) {
			$id = $this->body['ID'];
		}

		if (!$id) {
			throw new NotFoundHttpException();
		}

		$id = CIBlockFindTools::GetSectionID($id, $id, $this->filter);

		unset($this->body['ID']);
		unset($this->body['IBLOCK_ID']);

		$section = new CIBlockSection;
		if (!$section->Update($id, $this->body)) {
			throw new BadRequestHttpException($section->LAST_ERROR);
		}

		return $this->readOne($id);
	}

	public function delete($id) {
		$this->registerOneItemTransformHandler();

		global $APPLICATION, $DB;
		$DB->StartTransaction();

		try {
			$id = CIBlockFindTools::GetSectionID($id, $id, $this->filter);
			$result = CIBlockSection::Delete($id);
			if (!$result) {
				throw new InternalServerErrorHttpException($APPLICATION->LAST_ERROR);
			}
		} catch (Exception $exception) {
			$DB->Rollback();
			throw $exception;
		}

		$DB->Commit();

		return [
			$this->success(Loc::getMessage('IBLOCK_SECTION_DELETE')),
		];
	}

	public function count() {
		$this->registerOneItemTransformHandler();

		$this->select = ['ID'];

		$query = CIBlockSection::GetList(
			$this->order,
			$this->filter,
			false,
			$this->select,
			$this->navParams
		);
		$count = $query->SelectedRowsCount();

		return [
			[
				'count' => $count,
			],
		];
	}

	public function prepareQuery() {
		$this->_prepareQuery();

		// Delete iblock props from filter
		unset($this->filter['IBLOCK_ID']);
		unset($this->filter['IBLOCK_CODE']);
		unset($this->filter['IBLOCK_SITE_ID']);
		unset($this->filter['IBLOCK_TYPE']);

		// Set IBLOCK_ID filter
		$this->filter['IBLOCK_ID'] = $this->iblockId;

		// Force check permissions
		$this->filter['CHECK_PERMISSIONS'] = 'Y';

		// Force set IBLOCK_ID to body
		if (!empty($this->body)) {
			$this->body['IBLOCK_ID'] = $this->iblockId;
		}

		// Extend select with properties
		if (in_array('*', $this->select)) {
			$this->select = array_merge(
				$this->select,
				array_filter(array_keys($this->get('schema')), function ($path) {
					return strpos($path, 'PROPERTY_') !== false;
				})
			);
		}
	}

	private function registerSectionTransform() {
		global $SPACEONFIRE_RESTIFY;
		// Register transform
		EventManager::getInstance()->addEventHandler(
			$SPACEONFIRE_RESTIFY->getId(),
			'transform',
			[$this, 'sectionTransform']
		);
	}

	public function sectionTransform(Event $event) {
		$params = $event->getParameters();
		foreach ($params['result'] as $key => $item) {
			$item['ELEMENTS_COUNT'] = (int) (new CIBlockSection())->GetSectionElementsCount(
				$item['ID'],
				['CNT_ACTIVE' => 'Y']
			);

			$params['result'][$key] = $item;
		}
	}

	private function registerPermissionsCheck() {
		global $SPACEONFIRE_RESTIFY;
		$events = [
			'pre:create',
			'pre:update',
			'pre:delete',
		];

		foreach ($events as $event) {
			EventManager::getInstance()->addEventHandler(
				$SPACEONFIRE_RESTIFY->getId(),
				$event,
				[$this, 'checkPermissions']
			);
		}
	}

	public function checkPermissions() {
		global $USER;

		$this->permissions = CIBlock::GetGroupPermissions($this->iblockId);
		$permissions = $this->permissions;

		$userGroupsPermissions = array_map(function ($group) use ($permissions) {
			return $permissions[$group];
		}, $USER->GetUserGroupArray());

		$canWrite = in_array('W', $userGroupsPermissions) || in_array('X', $userGroupsPermissions);

		if (!$canWrite) {
			throw new AccessDeniedHttpException();
		}
	}
}
