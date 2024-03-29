<?php
/**
 * BlueSpice Output handler for the web installer.
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
 * @ingroup Deployment
 */

/**
 * BlueSpice output class modelled on OutputPage.
 *
 * @ingroup Deployment
 * @since 2.27
 *
 * @author Stephan Muggli
 * @author Robert Vogel <vogel@hallowelt.com>
 */
class BsWebInstallerOutput extends WebInstallerOutput {

	/**
	 * Opens a textarea used to display the progress of a long operation
	 * Copied from {@link \WebInstallerPage::startLiveBox}
	 */
	public function startLiveBox() {
		$this->addHTML(
			'<div id="config-spinner" style="display:none;">' .
			'<img src="images/ajax-loader.gif" /></div>' .
			'<script>jQuery( "#config-spinner" ).show();</script>' .
			'<div id="config-live-log">' .
			'<textarea name="LiveLog" rows="10" cols="30" readonly="readonly">'
		);
		$this->flush();
	}

	/**
	 * Opposite to BsWebInstallerOutput::startLiveBox
	 * Copied from {@link \WebInstallerPage::endLiveBox}
	 */
	public function endLiveBox() {
		$this->addHTML( '</textarea></div>
<script>jQuery( "#config-spinner" ).hide()</script>' );
		$this->flush();
	}

	/**
	 * BlueSpice
	 *
	 * @return void
	 */
	public function outputTitle() {
		// phpcs:ignore MediaWiki.Usage.DeprecatedGlobalVariables.Deprecated$wgVersion
		global $wgVersion;
		$foundationManifestFile = __DIR__
			. '/../../../extensions/BlueSpiceFoundation/extension.json';
		$foundationManifest = FormatJson::decode(
			file_get_contents( $foundationManifestFile ),
			true
		);
		$bsVersion = $foundationManifest['version'];
		echo wfMessage( 'bs-installer-title', $wgVersion, $bsVersion )->plain();
	}

	/**
	 *
	 * @return void
	 */
	public function getCSS() {
		$sInlineCSS = parent::getCSS();

		$sInlineCSS .= file_get_contents( dirname( __DIR__ ) . '/resources/main.css' );

		return $sInlineCSS;
	}

	/**
	 *
	 * @return void
	 */
	public function getJQuery() {
		$sJQueryScriptTag = parent::getJQuery();
		return $sJQueryScriptTag .
			"\n\t" .
			Html::linkedScript( "overrides/resources/main.js" );
	}

	/**
	 *
	 * @return void
	 */
	public function outputFooter() {
		echo '</div></div><div id="mw-panel">' . "\n";
		echo '<div class="portal" id="p-logo"></div>' . "\n";

		$message = wfMessage( 'config-sidebar' )->plain();
		echo $this->renderBlueSpiceSidebar();
		foreach ( explode( '----', $message ) as $section ) {
			echo '<div class="portal"><div class="body">' . "\n";
			echo $this->parent->parse( $section, true );
			echo '</div></div>';
		}
		echo '</div>' . "\n";
		echo Html::closeElement( 'body' ) . Html::closeElement( 'html' );
	}

	/**
	 *
	 * @return void
	 */
	protected function renderBlueSpiceSidebar() {
?>
<div class="portal">
	<div class="body">
		<ul>
			<li><a href="https://www.bluespice.com" title="BlueSpice Home" target="_blank">BlueSpice Home</a></li>
			<li><a href="https://help.bluespice.com" title="Helpdesk" target="_blank">Helpdesk</a></li>
		</ul>
	</div>
</div>

<?php
	}

}
