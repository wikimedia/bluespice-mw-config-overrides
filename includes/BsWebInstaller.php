<?php
/**
 * Core installer web interface.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Installer
 */

require_once $GLOBALS['IP'] . '/extensions/BlueSpiceFoundation/src/Installer/AutoExtensionHandler.php';
require_once $GLOBALS['IP'] . '/mw-config/overrides/includes/BsEdition.php';

use BlueSpice\Installer\AutoExtensionHandler as BsAutoExtensionHandler;
use BlueSpice\Installer\BsEdition;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\StaticHookRegistry;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MWStake\MediaWiki\Component\ContentProvisioner\ContentProvisionerPipeline;
use MWStake\MediaWiki\Component\ContentProvisioner\ContentProvisionerRegistry\FileBasedRegistry;
use MWStake\MediaWiki\Component\ContentProvisioner\Output\PrintOutput;

/**
 * Class for the core installer web interface.
 *
 * @ingroup Installer
 * @since 1.17
 */
class BsWebInstaller extends WebInstaller {

	/**
	 * Selected BlueSpice edition
	 *
	 * @var string
	 */
	private $edition = 'free';

	/**
	 * @param WebRequest $request
	 */
	public function __construct( \WebRequest $request ) {
		// BlueSpice
		global $wgMessagesDirs;
		$wgMessagesDirs['BlueSpiceInstaller'] = __DIR__ . '/../i18n';

		parent::__construct( $request );
		$this->output = new BsWebInstallerOutput( $this );

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
	 * Get a WebInstallerPage by name.
	 *
	 * @param string $pageName
	 * @return WebInstallerPage
	 */
	public function getPageByName( $pageName ) {
		if ( $pageName === 'Options' ) {
			return new BsWebInstallerOptions( $this );
		}
		if ( $pageName === 'DBConnect' ) {
			return new BsWebInstallerDBConnect( $this );
		}
		if ( $pageName === 'DBSettings' ) {
			return new BsWebInstallerDBSettings( $this );
		}
		return parent::getPageByName( $pageName );
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
			MediaWikiServices::getInstance()->getObjectFactory()
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

			$rawContent = file_get_contents( $path );
			$processedContent = preg_replace_callback(
				'#\{\{int:(.*?)\}\}#si',
				static function ( $matches ) {
					return wfMessage( $matches[1] )->inContentLanguage()->text();
				},
				$rawContent
			);
			$content = new WikitextContent( $processedContent );
			$page = MediaWikiServices::getInstance()->getWikiPageFactory()
				->newFromTitle( $title );
			$user = User::newSystemUser( 'BlueSpice default' );

			$updater = $page->newPageUpdater( $user );
			$updater->setContent( SlotRecord::MAIN, $content );
			$comment = CommentStoreComment::newUnsavedComment( '' );
			$updater->saveRevision( $comment, EDIT_NEW );
			$status = $updater->getStatus();
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

			$rawContent = file_get_contents( $path );
			$content = new WikitextContent( $rawContent );
			$page = MediaWikiServices::getInstance()->getWikiPageFactory()
				->newFromTitle( $title );
			$user = User::newSystemUser( 'BlueSpice default' );

			$updater = $page->newPageUpdater( $user );
			$updater->setContent( SlotRecord::MAIN, $content );
			$comment = CommentStoreComment::newUnsavedComment( '' );
			$updater->saveRevision( $comment, EDIT_NEW );
			$status = $updater->getStatus();
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

		// Some imported templates may contain "*.css" subpages containing CSS styles for that template
		// Example: "Template:SomeTemplate/styles.css"
		// Such "*.css" wiki pages are handled by "sanitized-css" content model from "TemplateStyles" extension
		// That "sanitized-css" content model and corresponding handler are registered via hook in "TemplateStyles".
		// But, as soon as installer doesn't know anything about hooks from extensions, we cannot apply that model here.
		// In such cases "wikitext" content model will be applied to "Template:*/*.css" pages.
		// As a result, CSS will be outputted as wikitext - that's wrong.

		// So we need to mock "sanitized-css" content model here
		$this->mockSanitizedCssContentModel();

		$objectFactory = MediaWikiServices::getInstance()->getObjectFactory();

		$contentProvisionerRegistry = new FileBasedRegistry( $processedExtensions, $installPath );

		$contentProvisionerPipeline = new ContentProvisionerPipeline( $objectFactory, $contentProvisionerRegistry );
		$contentProvisionerPipeline->setLogger( LoggerFactory::getInstance( 'ContentProvisioner' ) );
		$contentProvisionerPipeline->setOutput( new PrintOutput() );

		try {
			$this->output->startLiveBox();

			$status = $contentProvisionerPipeline->execute();
		} catch ( Exception $e ) {
			$status = Status::newFatal( 'config-install-default-bs-content-failed', $e->getMessage() );
		} finally {
			$this->output->endLiveBox();
		}

		return $status;
	}

	/**
	 * Mock "sanitize-css" content model.
	 *
	 * @return void
	 */
	private function mockSanitizedCssContentModel(): void {
		// We cannot use actual handler for "sanitized-css" here
		// Which is "\MediaWiki\Extension\TemplateStyles\TemplateStylesContentHandler"
		// Because it has some dependencies which we cannot fulfill in installer
		// So use some fallback content handler just for installer
		$contentHandlerFactory = MediaWikiServices::getInstance()->getContentHandlerFactory();
		$contentHandlerFactory->defineContentHandler( 'sanitized-css', FallbackContentHandler::class );

		// That's almost complete copy of "\MediaWiki\Extension\TemplateStyles\Hooks::onContentHandlerDefaultModelFor"
		$GLOBALS['wgHooks']['ContentHandlerDefaultModelFor'][] = static function( $title, &$model ) {
			if ( $title->getNamespace() === NS_TEMPLATE &&
				$title->isSubpage() && substr( $title->getText(), -4 ) === '.css' ) {
				$model = 'sanitized-css';

				return false;
			}

			return true;
		};
	}
}
