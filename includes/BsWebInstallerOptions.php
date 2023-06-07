<?php

class BsWebInstallerOptions extends WebInstallerOptions {
	/**
	 * We need a class attribute in order to hide the skins/extensions sections via CSS
	 * @param string $legend
	 * @return string HTML
	 */
	protected function getFieldsetStart( $legend ) {
		$htmlId = Sanitizer::escapeIdForAttribute( $legend );
		return "\n<fieldset class=\"$htmlId\"><legend>" . wfMessage( $legend )->escaped() . "</legend>\n";
	}

	/**
	 * @return string
	 */
	public function getName() {
		return 'Options';
	}
}
