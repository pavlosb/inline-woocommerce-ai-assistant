<?php
/**
 * Deterministic test double for the WordPress AI Client prompt builder.
 *
 * @package Inline_Woo_AI
 */

/**
 * Captures prompts and returns a configured result without network access.
 */
final class Inline_Woo_AI_Test_Prompt_Builder {
	/**
	 * Prompt text.
	 *
	 * @var string
	 */
	public $prompt = '';

	/**
	 * System instruction.
	 *
	 * @var string
	 */
	public $system_instruction = '';

	/**
	 * Whether text generation is supported.
	 *
	 * @var bool
	 */
	public $supported = true;

	/**
	 * Result returned by generate_text().
	 *
	 * @var string|WP_Error
	 */
	public $result = '<p>Generated test draft.</p>';

	/**
	 * Store the system instruction.
	 *
	 * @param string $instruction System instruction.
	 * @return self
	 */
	public function using_system_instruction( string $instruction ): self {
		$this->system_instruction = $instruction;
		return $this;
	}

	/**
	 * Report deterministic capability support.
	 *
	 * @return bool
	 */
	public function is_supported_for_text_generation(): bool {
		return $this->supported;
	}

	/**
	 * Return the configured generation result.
	 *
	 * @return string|WP_Error
	 */
	public function generate_text() {
		return $this->result;
	}
}
