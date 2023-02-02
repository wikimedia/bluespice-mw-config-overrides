<?php

require_once $GLOBALS['IP'] . '/extensions/BlueSpiceFoundation/src/Installer/AutoExtensionHandler.php';
require_once $GLOBALS['IP'] . '/mw-config/overrides/includes/BsEdition.php';

use BlueSpice\Installer\AutoExtensionHandler as BsAutoExtensionHandler;
use BlueSpice\Installer\BsEdition;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\StaticHookRegistry;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\MediaWikiServices as MediaWikiMediaWikiServices;
use MWStake\MediaWiki\Component\ContentProvisioner\ContentProvisionerPipeline;
use MWStake\MediaWiki\Component\ContentProvisioner\ContentProvisionerRegistry\FileBasedRegistry;
use MWStake\MediaWiki\Component\ContentProvisioner\Output\PrintOutput;

// New constants
$sTMPUploadDir = empty( $GLOBALS['wgUploadDirectory'] )
	? $GLOBALS['IP'] . DIRECTORY_SEPARATOR . 'images'
	: $GLOBALS['wgUploadDirectory'];

$sTMPCacheDir = empty( $GLOBALS['wgFileCacheDirectory'] )
	? $sTMPUploadDir . DIRECTORY_SEPARATOR . 'cache'
	: $GLOBALS['wgFileCacheDirectory'];

$sTMPUploadPath = empty( $GLOBALS['wgUploadPath'] )
	? $GLOBALS['wgScriptPath'] . "/images"
	: $GLOBALS['wgUploadPath'];

if ( !defined( 'BS_DATA_DIR' ) ) {
	// Future
	define( 'BS_DATA_DIR', $sTMPUploadDir . DIRECTORY_SEPARATOR . 'bluespice' );
}
if ( !defined( 'BS_CACHE_DIR' ) ) {
	// $wgCacheDirectory?
	define( 'BS_CACHE_DIR', $sTMPCacheDir . DIRECTORY_SEPARATOR . 'bluespice' );
}
if ( !defined( 'BS_DATA_PATH' ) ) {
	define( 'BS_DATA_PATH', $sTMPUploadPath . '/bluespice' );
}

$GLOBALS['wgMessagesDirs']['BlueSpiceInstaller'] = __DIR__ . '/../i18n';

class BsCliInstaller extends CliInstaller {

	/**
	 * Selected BlueSpice edition
	 *
	 * @var string
	 */
	private $edition = 'free';

	/**
	 * @param string $siteName
	 * @param string|null $admin
	 * @param array $options
	 * @throws InstallException
	 */
	public function __construct( $siteName, $admin = null, array $options = [] ) {
		parent::__construct( $siteName, $admin, $options );

		$blueSpiceEdition = new BsEdition();
		$this->edition = $blueSpiceEdition->getEdition();
	}

	/**
	 * Get an array of install steps. Should always be in the format of
	 * [
	 *   'name'     => 'someuniquename',
	 *   'callback' => [ $obj, 'method' ],
	 * ]
	 * There must be a config-install-$name message defined per step, which will
	 * be shown on install.
	 *
	 * @param DatabaseInstaller $installer DatabaseInstaller so we can make callbacks
	 * @return array[]
	 * @phan-return array<int,array{name:string,callback:array{0:object,1:string}}>
	 */
	protected function getInstallSteps( DatabaseInstaller $installer ) {
		$this->installSteps = parent::getInstallSteps( $installer );
		$bsInstallSteps = [
			[ 'name' => 'sidebar', 'callback' => [ $this, 'createSidebar' ] ],
			[ 'name' => 'default-bs-content', 'callback' => [ $this, 'createDefaultBsContent' ] ]
		];

		// Add BlueSpice install steps to the core install list,
		// then adding any callbacks that wanted to attach after a given step
		foreach ( $bsInstallSteps as $step ) {
			$this->installSteps[] = $step;
			if ( isset( $this->extraInstallSteps[$step['name']] ) ) {
				$this->installSteps = array_merge(
					$this->installSteps,
					$this->extraInstallSteps[$step['name']]
				);
			}
		}

		// Extensions should always go first, chance to tie into hooks and such
		$extensionStep = [];
		foreach ( $this->installSteps as $key => $step ) {
			if ( $step['name'] === 'extensions' ) {
				$extensionStep = $step;
				unset( $this->installSteps[$key] );
				break;
			}
		}
		if ( !empty( $extensionStep ) ) {
			array_unshift( $this->installSteps, $extensionStep );
		}

		return $this->installSteps;
	}

	/**
	 * Get an MW configuration variable, or internal installer configuration variable.
	 * The defaults come from $GLOBALS (ultimately DefaultSettings.php).
	 * Installer variables are typically prefixed by an underscore.
	 *
	 * @param string $name
	 * @param mixed|null $default
	 *
	 * @return mixed
	 */
	public function getVar( $name, $default = null ) {
		if ( strpos( $name, 'ext-' ) === 0 ) {
			$this->setVar( $name, '1' );
		}
		// HW: Get enabled extensions from settings.d directory instead of options page
		if ( $name === '_Extensions' ) {
			$installPath = $this->getVar( 'IP' );
			$autoExtensionsHandler = new BsAutoExtensionHandler( $installPath );
			$exts = $autoExtensionsHandler->getExtensions();
			return $exts;
		}

		return parent::getVar( $name, $default );
	}

	/**
	 * Installs the auto-detected extensions.
	 *
	 * @suppress SecurityCheck-OTHER It thinks $exts/$IP is user controlled but they are not.
	 * @return Status
	 */
	protected function includeExtensions() {
		// Marker for DatabaseUpdater::loadExtensions so we don't
		// double load extensions
		define( 'MW_EXTENSIONS_LOADED', true );

		// HW: Change oder of processing extensions compared with parent class.
		// This is neccessary because Widgets has a legecy class where wfLoadExtension is called.
		$data = $this->getAutoExtensionData();
		$legacySchemaHooks = $this->getAutoExtensionLegacyHooks();

		if ( isset( $data['globals']['wgHooks']['LoadExtensionSchemaUpdates'] ) ) {
			$legacySchemaHooks = array_merge( $legacySchemaHooks,
				$data['globals']['wgHooks']['LoadExtensionSchemaUpdates'] );
		}
		$extDeprecatedHooks = $data['attributes']['DeprecatedHooks'] ?? [];
		$this->autoExtensionHookContainer = new HookContainer(
			new StaticHookRegistry(
				[ 'LoadExtensionSchemaUpdates' => $legacySchemaHooks ],
				$data['attributes']['Hooks'] ?? [],
				$extDeprecatedHooks
			),
			MediaWikiMediaWikiServices::getInstance()->getObjectFactory()
		);

		return Status::newGood();
	}

	/**
	 * Insert Main Page with default content.
	 *
	 * @param DatabaseInstaller $installer
	 * @return Status
	 */
	protected function createMainpage( DatabaseInstaller $installer ) {
		$status = Status::newGood();
		$title = Title::newMainPage();
		if ( $title->exists() ) {
			$status->warning( 'config-install-mainpage-exists' );
			return $status;
		}
		try {
			$path = dirname( __DIR__ ) . '/content/mainpage/' . $this->edition . '.html';
			echo "$path\n";

			$rawContent = file_get_contents( $path );
			$processedContent = preg_replace_callback(
				'#\{\{int:(.*?)\}\}#si',
				function ( $matches ) {
					return wfMessage( $matches[1] )->inContentLanguage()->text();
				},
				$rawContent
			);
			$content = new WikitextContent( $processedContent );

			$page = WikiPage::factory( $title );
			$status = $page->doEditContent(
				$content,
				'',
				EDIT_NEW,
				false,
				User::newSystemUser( 'BlueSpice default' )
			);
		} catch ( Exception $e ) {
			// using raw, because $wgShowExceptionDetails can not be set yet
			$status->fatal( 'config-install-mainpage-failed', $e->getMessage() );
		}

		return $status;
	}

	/**
	 * Sidebar with BlueSpice content
	 *
	 * @param DatabaseInstaller $installer
	 * @return Status
	 */
	protected function createSidebar( DatabaseInstaller $installer ) {
		$status = Status::newGood();
		$title = Title::makeTitleSafe( NS_MEDIAWIKI, 'Sidebar' );
		if ( $title->exists() ) {
			$status->warning( 'config-install-mainpage-exists' );
			return $status;
		}
		try {
			$path = dirname( __DIR__ ) . '/content/sidebar/' . $this->edition . '.wikitext';
			echo "$path\n";

			$rawContent = file_get_contents( $path );
			$content = new WikitextContent( $rawContent );

			$page = WikiPage::factory( $title );
			$status = $page->doEditContent(
				$content,
				'',
				EDIT_NEW,
				false,
				User::newSystemUser( 'BlueSpice default' )
			);
		} catch ( Exception $e ) {
			// using raw, because $wgShowExceptionDetails can not be set yet
			$status->fatal( 'config-install-sidebar-failed', $e->getMessage() );
		}

		return $status;
	}

	/**
	 * Processes manifests with default BlueSpice content and imports it.
	 *
	 * @param DatabaseInstaller $installer
	 * @return Status
	 */
	protected function createDefaultBsContent( DatabaseInstaller $installer ) {
		$installPath = $this->getVar( 'IP' );
		$exts = $this->getVar( '_Extensions' );

		$processedExtensions = [];
		foreach ( $exts as $e ) {
			$processedExtensions[] = $e;
		}

		$objectFactory = MediaWikiServices::getInstance()->getObjectFactory();

		$contentProvisionerRegistry = new FileBasedRegistry( $processedExtensions, $installPath );

		$contentProvisionerPipeline = new ContentProvisionerPipeline( $objectFactory, $contentProvisionerRegistry );
		$contentProvisionerPipeline->setLogger( LoggerFactory::getInstance( 'ContentProvisioner' ) );
		$contentProvisionerPipeline->setOutput( new PrintOutput() );

		try {
			$status = $contentProvisionerPipeline->execute();
		} catch ( Exception $e ) {
			$status = Status::newFatal( 'config-install-default-bs-content-failed', $e->getMessage() );
		}

		return $status;
	}
}
