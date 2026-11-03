<?php

require_once MW_INSTALL_PATH . '/mw-config/overrides/includes/BsProductContent.php';

use BlueSpice\Installer\BsProductContent;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Installer\CliInstaller;
use MediaWiki\Installer\Task\ITaskContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class BsCliInstaller extends CliInstaller {

	/**
	 * @inheritDoc
	 */
	public function __construct( $siteName, $admin = null, array $options = [] ) {
		$GLOBALS['wgMessagesDirs']['BlueSpiceInstaller'] = __DIR__ . '/../i18n';
		parent::__construct( $siteName, $admin, $options );

		$this->setVar( 'wgInstallerInitialPages', [] );
		$this->addInstallStep( [
			'name' => 'default-bs-content',
			'callback' => [ $this, 'createMainpage' ]
		], 'mainpage' );
		$this->addInstallStep( [
			'name' => 'sidebar',
			'callback' => [ $this, 'createSidebar' ]
		], 'mainpage' );
	}

	/**
	 * We don't want to have any extensions installed by default,
	 * as we require update.php to be run anyways.
	 * This avoids issues with several extensions, like SemanticMediaWiki and OATHAuth
	 *
	 * @inheritDoc
	 */
	public function getVar( $name, $default = null ) {
		if ( strpos( $name, 'ext-' ) === 0 ) {
			$this->setVar( $name, '0' );
		}
		if ( $name === '_Extensions' ) {
			return [];
		}

		return parent::getVar( $name, $default );
	}

	/**
	 * @inheritDoc
	 */
	protected function includeExtensions() {
		// Don't load extensions
		return Status::newGood();
	}

	/**
	 * Insert Main Page with default content, and the "Getting started"
	 * help page if the product has one.
	 * Content depends on `BLUESPICE-PRODUCT` and the edition, see `BsProductContent`.
	 * Files used by the galaxy content are imported by
	 * BlueSpiceWikiFarm's `ImportDefaultContentFiles` during update.php,
	 * because the installer does not know the upload directory.
	 *
	 * @param ITaskContext $context
	 * @return Status
	 */
	public function createMainpage( ITaskContext $context ) {
		$status = $this->createPage(
			Title::newMainPage(),
			$this->getLocalizedContent( BsProductContent::getPath( 'mainpage', 'html' ) ),
			'config-install-mainpage-exists',
			'config-install-mainpage-failed'
		);

		$helpPath = BsProductContent::getPath( 'gettingstarted', 'html' );
		if ( $helpPath ) {
			$product = BsProductContent::getProductFromPath( $helpPath );
			$helpTitle = wfMessage( "bs-gettingstarted-content-$product-title" )
				->inContentLanguage()->plain();
			$status->merge( $this->createPage(
				Title::makeTitleSafe( NS_HELP, $helpTitle ),
				$this->getLocalizedContent( $helpPath ),
				'config-install-mainpage-exists',
				'config-install-default-bs-content-failed'
			) );
		}

		return $status;
	}

	/**
	 * Sidebar with BlueSpice content
	 *
	 * @param ITaskContext $context
	 * @return Status
	 */
	public function createSidebar( ITaskContext $context ) {
		return $this->createPage(
			Title::makeTitleSafe( NS_MEDIAWIKI, 'Sidebar' ),
			file_get_contents( BsProductContent::getPath( 'sidebar', 'wikitext' ) ),
			'config-install-sidebar-exists',
			'config-install-sidebar-failed'
		);
	}

	/**
	 * Replaces `{{int:...}}` with the raw wikitext of the message in the content language,
	 * so templates and magic words inside the messages are kept for the page to render,
	 * and `{{InstallerOption:...}}` like core's InitialContentTask does.
	 *
	 * @param string $path
	 * @return string
	 */
	private function getLocalizedContent( string $path ): string {
		$content = preg_replace_callback(
			'#\{\{int:(.*?)\}\}#si',
			static function ( $matches ) {
				return wfMessage( $matches[1] )->inContentLanguage()->plain();
			},
			file_get_contents( $path )
		);
		return preg_replace_callback(
			'/\{\{ *InstallerOption: *(\w+) *}}/',
			function ( $matches ) {
				return (string)$this->getVar( '_' . $matches[1] );
			},
			$content
		);
	}

	/**
	 * @param Title $title
	 * @param string $text
	 * @param string $existsMessage
	 * @param string $failureMessage
	 * @return Status
	 */
	private function createPage( Title $title, string $text, string $existsMessage, string $failureMessage ) {
		$status = Status::newGood();
		if ( $title->exists() ) {
			$status->warning( $existsMessage );
			return $status;
		}
		try {
			$page = MediaWikiServices::getInstance()->getWikiPageFactory()
				->newFromTitle( $title );
			$user = User::newSystemUser( 'BlueSpice default' );

			$updater = $page->newPageUpdater( $user );
			$updater->setContent( SlotRecord::MAIN, new WikitextContent( $text ) );
			$comment = CommentStoreComment::newUnsavedComment( '' );
			$updater->saveRevision( $comment, EDIT_NEW );
			$status = $updater->getStatus();
		} catch ( Exception $e ) {
			// using raw, because $wgShowExceptionDetails can not be set yet
			$status->fatal( $failureMessage, $e->getMessage() );
		}

		return $status;
	}
}
