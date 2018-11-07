<?php

namespace goldencode\Bitrix\Restify;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use goldencode\Bitrix\Restify\Executors\SaleDeliveryServiceRest;

if (!Loader::includeModule('goldencode.restify')) return false;

Loc::loadLanguageFile(__FILE__);

class RestifySaleDeliveryComponent extends RouterComponent {
	public function executeComponent() {
		$executor = new SaleDeliveryServiceRest($this->arParams);
		$this->setExecutor($executor);
		$this->cors();
		$this->route('GET /', [$this, 'readMany']);
		$this->route('GET /@id', [$this, 'readOne']);
		$this->start();
	}
}
