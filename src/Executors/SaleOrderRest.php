<?php

namespace spaceonfire\Restify\Executors;

use Bitrix\Currency\CurrencyTable;
use Bitrix\Main\EventManager;
use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;
use CMain;
use CSaleBasket;
use CSaleOrder;
use CSaleOrderProps;
use CSaleOrderPropsValue;
use CSalePersonType;
use Emonkak\HttpException\BadRequestHttpException;
use Emonkak\HttpException\InternalServerErrorHttpException;
use Emonkak\HttpException\NotFoundHttpException;
use Emonkak\HttpException\UnauthorizedHttpException;
use Exception;

class SaleOrderRest implements IExecutor {
	use RestTrait;

	private $entity = 'Bitrix\Sale\Internals\OrderTable';

	/**
	 * SaleOrderRest constructor
	 * @param array $options executor options
	 * @throws \Bitrix\Main\LoaderException
	 * @throws InternalServerErrorHttpException
	 * @throws Exception
	 */
	public function __construct($options) {
		$this->loadModules([
			'sale',
			'catalog',
			'currency',
		]);

		$this->filter = [];
		$this->order = [
			'DATE_INSERT' => 'DESC',
		];

		$this->checkEntity();
		$this->setPropertiesFromArray($options);

		$sep = $this->ormNestedSelectSeparator;
		$this->select = [
			'*',
			'BASKET' . $sep => 'BASKET',
			'BASKET' . $sep . 'PRODUCT' . $sep => 'BASKET.PRODUCT',
			'BASKET' . $sep . 'ELEMENT' . $sep => 'BASKET.PRODUCT.IBLOCK',
		];

		$this->registerPermissionsCheck();
		$this->registerBasicTransformHandler();
		$this->buildSchema();
	}

	public function readMany() {
		$this->registerBasketTransfrom();
		return $this->readORM();
	}

	public function readOne($id) {
		$this->registerOneItemTransformHandler();
		$this->filter = array_merge($this->filter, [
			'ID' => $id,
		]);

		// Get only one item
		$this->navParams = ['nPageSize' => 1];

		$results = $this->readMany();

		if (!count($results)) {
			throw new NotFoundHttpException();
		}

		return $results;
	}

	/*
	 * Method
	 * Gets UserID known as anonymous for order
	 * Takes	: n/a
	 * Returns	: Maybe Int user id known as order anon
	 */
	private function getUserOrderAnon(){
		$userId = null;

		$res = UserTable::getList([
			'select' => [ 'ID' ],
			'filter' => [ 'UF_ORDER_ANON' => 1 ],
		]);

		$userArr = $res->fetch();
		if( ! empty( $userArr[ 'ID' ] ) ) $userId = $userArr[ 'ID' ];

		return $userId;
	}

	public function create() {
		$this->registerOneItemTransformHandler();

		global $APPLICATION, $USER;

		$basket = new CSaleBasket();
		$order = new CSaleOrder();

		if (!$this->body['DELIVERY_ID']) {
			throw new BadRequestHttpException(Loc::getMessage('SALE_ORDER_CREATE_DELIVERY_EMPTY'));
		}
		if (!$this->body['PAY_SYSTEM_ID']) {
			throw new BadRequestHttpException(Loc::getMessage('SALE_ORDER_CREATE_PAY_SYSTEM_EMPTY'));
		}

		// Count order price
		$currentCart = $basket->GetBasketUserID();

		$dbBasketItems = $basket->GetList([], [
			'FUSER_ID' => $currentCart,
			'LID' => SITE_ID,
			'ORDER_ID' => 'NULL'
		]);
		$orderPrice = 0;
		while ($item = $dbBasketItems->GetNext(true, false)) {
			$orderPrice += (float) $item['PRICE'] * $item['QUANTITY'];
		}

		if ($orderPrice === 0) {
			throw new BadRequestHttpException(Loc::getMessage('SALE_ORDER_CREATE_BASKET_EMPTY'));
		}

		// Delivery
		$delivery = \Bitrix\Sale\Delivery\Services\Manager::getById((int) $this->body['DELIVERY_ID']);
		$deliveryPrice = $delivery['CONFIG']['MAIN']['PRICE'];

		if (!empty($delivery['CONFIG']['MAIN']['MARGIN_TYPE'])) {
			$deliveryPrice =
				$delivery['CONFIG']['MAIN']['MARGIN_TYPE'] === 'CURRENCY' ?
					$delivery['CONFIG']['MAIN']['MARGIN_VALUE'] : // Fixed price
					$orderPrice * (int) $delivery['CONFIG']['MAIN']['MARGIN_VALUE'] / 100; // Percent
		}

		$defaults = [
			'CURRENCY' => CurrencyTable::getList([
				'filter' => [
					'BASE' => 'Y',
				],
			])->fetch()['CURRENCY'],
			'PERSON_TYPE_ID' => (new CSalePersonType())->GetList()->Fetch()['ID'],
		];

		$userId = $USER->GetID();
		if( empty( $userId ) ) $userId = $this->getUserOrderAnon();

		if( empty( $userId ) ) {
			throw new NotFoundHttpException(
				$APPLICATION->LAST_ERROR ?: Loc::getMessage('SALE_ORDER_ANON_USER_FIND')
			);
		}

		$overrides = [
			'LID' => SITE_ID,
			'PAYED' => 'N',
			'CANCELED' => 'N',
			'STATUS_ID' => 'N',
			'ALLOW_DELIVERY' => 'Y',
			'PRICE' => $orderPrice,
			'PRICE_DELIVERY' => $deliveryPrice,
			'USER_ID' => $userId,
		];

		$schemaKeys = array_keys($this->schema);
		$fields = array_merge(
			$defaults,
			array_filter($this->body, function ($key) use ($schemaKeys) {
				return in_array($key, $schemaKeys);
			}, ARRAY_FILTER_USE_KEY),
			$overrides
		);

		// Create order
		$orderId = $order->Add($fields);

		if (!$orderId) {
			throw new InternalServerErrorHttpException(
				$APPLICATION->LAST_ERROR ?: Loc::getMessage('SALE_ORDER_CREATE_ERROR')
			);
		}

		// Add props
		$orderPropsQ = (new CSaleOrderProps())->GetList();
		while ($prop = $orderPropsQ->GetNext(true, false)) {
			if (isset($this->body[$prop['CODE']])) {
				(new CSaleOrderPropsValue())->Add([
					'ORDER_ID' => $orderId,
					'ORDER_PROPS_ID' => $prop['ID'],
					'NAME' => $prop['NAME'],
					'CODE' => $prop['CODE'],
					'VALUE' => $this->body[$prop['CODE']]
				]);
			}
		}

		$basket->OrderBasket($orderId, $currentCart);

		return [
			$this->success(Loc::getMessage('SALE_ORDER_CREATE_SUCCESS', [
				'#ORDER_ID#' => $orderId,
			])),
		];
	}

	private function registerPermissionsCheck() {
		global $SPACEONFIRE_RESTIFY;
		$events = [
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

	public function checkPermissions() {
		global $USER;
		$permissions = CMain::GetUserRight('sale');

		if (!$USER->GetID()) {
			throw new UnauthorizedHttpException();
		}

		switch ($permissions) {
			case 'W':
			case 'U': {
				// Full access to orders, skip check
				return;
				break;
			}

			default: {
				// Can read and change only self orders
				$this->filter = array_merge($this->filter, [
					'CREATED_BY' => $USER->GetID(),
				]);
				break;
			}
		}
	}

	private function registerBasketTransfrom() {
		global $SPACEONFIRE_RESTIFY;
		EventManager::getInstance()->addEventHandler(
			$SPACEONFIRE_RESTIFY->getId(),
			'transform',
			[$this, 'basketProductsTransform'],
			false,
			99999
		);
	}

	public function basketProductsTransform(Event $event) {
		$params = $event->getParameters();
		$orderIds = array_unique(array_map(function ($item) { return $item['ID']; }, $params['result']));
		$result = [];
		foreach ($orderIds as $id) {
			$orders = array_filter($params['result'], function ($item) use ($id) { return $item['ID'] === $id; });
			$order = current($orders);
			$basket = array_values(array_map(function ($item) { return $item['BASKET']; }, $orders));
			$order['BASKET'] = $basket;
			$result[] = $order;
		}
		$params['result'] = $result;
	}
}
