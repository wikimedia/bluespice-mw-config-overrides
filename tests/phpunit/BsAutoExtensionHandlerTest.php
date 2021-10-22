<?php

namespace BlueSpice\Installer\Tests;

require_once __DIR__ . '/../../includes/BsAutoExtensionHandler.php';

use BlueSpice\Installer\BsAutoExtensionHandler;
use PHPUnit\Framework\TestCase;

class BsAutoExtensionHandlerTest extends TestCase {

	/**
	 * @covers BsAutoExtensionHandler::getAutoExtensionData
	 * @return void
	 */
	public function testGetAutoExtensionData() {
		$handler = new BsAutoExtensionHandler( __DIR__ . '/data/' );
		$actualExtensionNames = $handler->getExtensions();
		$expectedExtensioName = [
			'ext-Extension1' => 'Extension1',
			'ext-Extension3' => 'Extension3',
			'ext-Extension6' => 'Extension6',
			'ext-Extension8' => 'Extension8',
			'ext-Extension10' => 'Extension10',
			'ext-Extension11' => 'Extension11',
			'ext-Skin1' => 'Skin1',
			'ext-Skin3' => 'Skin3',
			'ext-Skin6' => 'Skin6'
		];
		$this->assertEquals( $expectedExtensioName, $actualExtensionNames );
	}

}
