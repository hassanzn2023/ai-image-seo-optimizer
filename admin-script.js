/**
 * AI Image SEO Optimizer Admin Script (Using REST API)
 * Handles interactions on BOTH the 'All Images' page and the 'Page Content Analyzer' page.
 * Version: 0.6.8 (Removed H1 context, reformatted)
 */
jQuery(document).ready(function ($) {

	// --- Initial Setup & Variables ---
	if (typeof aiso_ajax_object === 'undefined') {
		console.error('AISO Error: aiso_ajax_object is not defined.');
		return;
	}
	const { __, sprintf } = wp.i18n; // For potential future translations in JS

	// Configuration
	const MAX_RETRIES = 2; // Total attempts = 1 initial + (MAX_RETRIES - 1) retries
	const RETRY_DELAY_MS = 4000; // 4 seconds wait before retry
	const REQUEST_DELAY_MS = 1100; // 1.1 seconds delay between successful bulk requests

	// Element Selectors
	const $imageTableBody = $('.aiso-image-table tbody#the-list');
	const $errorLog = $('#aiso-error-log');
	const $successLog = $('#aiso-success-log');
	// Analyzer specific selectors (only relevant on that page)
	const $analyzerGenerateAllBtn = $('#aiso-analyzer-generate-all-btn');
	const $analyzerUpdateAllBtn = $('#aiso-analyzer-update-all-btn');
	const $analyzerBulkSpinner = $('.aiso-analyzer-bulk-spinner');
	const $analyzerBulkProgress = $('.aiso-analyzer-bulk-actions .aiso-bulk-progress');

	let isBulkProcessing = false; // Flag to prevent overlapping actions

	// --- Helper Functions ---

	/**
	 * Shows a notification message (error, success, warning, info).
	 * @param {string} message The message to display.
	 * @param {string} type Notification type ('error', 'success', 'warning', 'info').
	 * @param {boolean} autoDismiss Automatically dismiss non-error messages after a delay.
	 */
	function showNotification(message, type = 'error', autoDismiss = true) {
		const $logElement = (type === 'success') ? $successLog : $errorLog;
		if (!$logElement.length) return;

		// Sanitize message basic HTML
		const safeMessage = $('<div>').text(message).html();
		$logElement.find('p').html(safeMessage);

		// Ensure dismiss button exists
		if (!$logElement.find('.notice-dismiss').length) {
			const dismissText = aiso_ajax_object.text_dismiss || 'Dismiss this notice.';
			const $dismissButton = $('<button type="button" class="notice-dismiss"><span class="screen-reader-text">' + dismissText + '</span></button>');
			$logElement.append($dismissButton);
			// Attach dismiss handler only once
			$logElement.off('click.aisodismiss').on('click.aisodismiss', '.notice-dismiss', function () {
				$(this).closest('.notice').fadeOut(300, function () { $(this).hide(); });
			});
		}

		// Set type class and show
		$logElement.removeClass('notice-error notice-success notice-warning notice-info')
				   .addClass('notice-' + type)
				   .fadeIn(300);

		// Auto-dismiss logic
		if (autoDismiss && type !== 'error') {
			setTimeout(() => {
				$logElement.fadeOut(500);
			}, 6000);
		}
	}

	/**
	 * Sets the loading/disabled state of a button and manages its text/spinner.
	 * @param {jQuery} $button The button element.
	 * @param {boolean} isLoading True to set loading state, false to restore.
	 * @param {string|null} originalText Text to restore to (optional, uses default/stored if null).
	 */
	function setButtonState($button, isLoading, originalText = null) {
		if (!$button || !$button.length) return;

		const $row = $button.closest('tr');
		// Determine spinner location (row or bulk area)
		const $spinner = $row.length ? $row.find('.spinner') :
						($button.is($analyzerGenerateAllBtn) || $button.is($analyzerUpdateAllBtn) ? $analyzerBulkSpinner : null);

		if (isLoading) {
			$button.prop('disabled', true);
			// Store original text if not already stored and if text node exists
			let textNode = $button.contents().filter(function () { return this.nodeType === 3; }).last();
			if (!$button.data('original-text') && textNode.length) {
				$button.data('original-text', textNode.text().trim());
			}
			// Set processing text
			const processingText = ' ' + (aiso_ajax_object.text_processing || 'Processing...');
			if (textNode.length) {
				textNode.get(0).nodeValue = processingText;
			} else {
				// Append text if no text node found (e.g., only icon)
				$button.append(processingText);
			}
			// Activate spinner
			if ($spinner && $spinner.length) $spinner.addClass('is-active');

		} else {
			$button.prop('disabled', false);
			const storedOriginalText = $button.data('original-text');

			// Determine the default text based on the button type
			let defaultText = 'Action'; // Generic default
			if ($button.is($analyzerGenerateAllBtn)) {
				defaultText = aiso_ajax_object.text_generate_all || 'Generate All';
			} else if ($button.is($analyzerUpdateAllBtn)) {
				defaultText = aiso_ajax_object.text_update_all || 'Update All Generated';
			} else if ($button.hasClass('generate-ai-button')) {
				defaultText = aiso_ajax_object.text_generate || 'Generate';
			} else if ($button.hasClass('update-meta-button')) {
				defaultText = aiso_ajax_object.text_update || 'Update';
			}

			const buttonTextToShow = originalText || storedOriginalText || defaultText;

			// Restore button text
			let textNode = $button.contents().filter(function () { return this.nodeType === 3; }).last();
			if (textNode.length) {
				textNode.get(0).nodeValue = ' ' + buttonTextToShow;
			} else {
				$button.append(' ' + buttonTextToShow);
			}
			$button.removeData('original-text'); // Clear stored text
			// Deactivate spinner
			if ($spinner && $spinner.length) $spinner.removeClass('is-active');
		}
	}

	/**
	 * Handles API errors, logs them, shows notification, and resets button state.
	 * @param {Error|Object|string} error The error object or message.
	 * @param {jQuery} $button The button associated with the failed action.
	 * @param {string} context A string describing the context of the error (for logging).
	 */
	function handleApiError(error, $button, context) {
		console.error(`AISO ${context} Error:`, error);

		let errorMessage = aiso_ajax_object.text_error_occurred || 'An error occurred.';

		// Extract message from error object or use string directly
		if (error && error.message) {
			errorMessage = error.message;
		} else if (typeof error === 'string') {
			errorMessage = error;
		}

		// Append details from error data if available
		if (error && error.data) {
			if (error.data.errors) { // Specific field errors from update endpoint
				errorMessage += " Details: " + JSON.stringify(error.data.errors);
			} else if (error.data.raw_text_snippet) { // Snippet from Gemini parsing error
				errorMessage += " Raw AI Response Snippet: " + error.data.raw_text_snippet;
			} else if (error.data.block_reason) { // Safety block reason
				errorMessage += " Block Reason: " + error.data.block_reason;
			} else if (error.data.finish_reason) { // Generation finish reason
				errorMessage += " Finish Reason: " + error.data.finish_reason;
			} else if (error.data.json_error) { // JSON parsing error code
				errorMessage += " (Parsing Error: " + error.data.json_error + ")";
			}
		}

		showNotification(errorMessage, 'error', false); // Show persistent error notification

		// Reset button state if provided
		if ($button && $button.length) {
			// Determine original text based on button type
			let originalText = 'Action';
			if ($button.is($analyzerGenerateAllBtn)) originalText = aiso_ajax_object.text_generate_all;
			else if ($button.is($analyzerUpdateAllBtn)) originalText = aiso_ajax_object.text_update_all;
			else if ($button.hasClass('generate-ai-button')) originalText = aiso_ajax_object.text_generate;
			else if ($button.hasClass('update-meta-button')) originalText = aiso_ajax_object.text_update;

			setButtonState($button, false, originalText);

			// Add visual feedback to the row
			const $row = $button.closest('tr');
			if ($row.length) {
				$row.addClass('aiso-error-row').removeClass('aiso-success-row aiso-generated-row');
				// Remove error class after a delay
				setTimeout(() => $row.removeClass('aiso-error-row'), 3000);
			}
		}
	}

	// --- Core API Call Functions (with Retry Logic) ---

	/**
	 * Generates AI meta for a single image via REST API.
	 * Includes retry logic for specific errors.
	 * @param {number} imageId The image attachment ID.
	 * @param {string} focusKeyword The primary keyword.
	 * @param {string} secondaryKeyword The secondary keywords.
	 * @returns {Promise<Object>} Promise resolving with the generated {title, alt} object.
	 * @throws {Error} Throws an error on failure after retries.
	 */
	async function generateSingleImage(imageId, focusKeyword, secondaryKeyword) {
		// pageH1 parameter removed
		const requestData = {
			image_id: imageId,
			focus_keyword: focusKeyword,
			secondary_keyword: secondaryKeyword
		};
		// No page_h1 added to requestData

		for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
			try {
				if (attempt > 1) {
					 console.log(`AISO: Retrying generate for Image ${imageId} (Attempt ${attempt})...`);
					 // Optionally show visual feedback for retry
				}

				const response = await fetch(aiso_ajax_object.rest_url + 'generate', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': aiso_ajax_object.nonce
					},
					body: JSON.stringify(requestData)
				});

				const contentType = response.headers.get("content-type");

				if (!response.ok) {
					let errorData = { status: response.status, statusText: response.statusText }; // Basic error data
					let errorMessage = `HTTP error! Status: ${response.status} - ${response.statusText}`;
					let errorCode = `http_${response.status}`;

					// Try to parse JSON error response from the server
					if (contentType && contentType.includes("application/json")) {
						try {
							const errJson = await response.json();
							errorMessage = errJson.message || errorMessage;
							errorCode = errJson.code || errorCode;
							errorData = errJson.data || errorData; // Get more specific data if available
						} catch (e) {
							console.warn('AISO: Failed to parse JSON error response body.');
							// Use the raw response text if JSON parsing fails
							try { errorMessage = await response.text(); } catch(e2) {}
						}
					} else {
						// Get raw text for non-JSON errors
						try { errorMessage = await response.text(); } catch(e) {}
					}

					const error = new Error(errorMessage);
					error.code = errorCode;
					error.data = errorData; // Attach additional data like status, reason, etc.
					// Special flag for empty results returned with 200 status from PHP
					if (errorCode === 'aiso_ai_returned_empty' && response.status === 200) {
						error.isEmptyAIResult = true;
					}

					throw error; // Throw to be caught by the catch block below
				}

				// Success case: Check content type before parsing
				if (contentType && contentType.includes("application/json")) {
					return await response.json(); // Resolve promise with parsed data
				} else {
					// Handle unexpected non-JSON success response
					const responseText = await response.text();
					console.error('AISO Error: Received non-JSON success response from server.', responseText);
					throw new Error('Received unexpected response format from server.');
				}

			} catch (error) {
				console.error(`AISO Generate Attempt ${attempt} Failed for Image ${imageId}:`, error);

				// Determine if the error is retryable
				const status = error?.data?.status || (error.name === 'TypeError' ? 503 : null); // Get status or treat network errors as potentially retryable
				// Retry on rate limits (429), server errors (500, 502, 503, 504), or network errors
				const isRetryable = (status === 429 || (status >= 500 && status <= 504) || error.name === 'TypeError');

				// If retryable and attempts remain, wait and continue loop
				if (isRetryable && attempt < MAX_RETRIES) {
					await new Promise(resolve => setTimeout(resolve, RETRY_DELAY_MS));
					continue; // Go to next attempt
				} else {
					// If not retryable or max retries reached, re-throw the error to be handled by caller
					throw error;
				}
			}
		}
		 // Fallback error if loop finishes unexpectedly (shouldn't happen if MAX_RETRIES >= 1)
		 throw new Error(`AISO: Failed to generate meta for Image ${imageId} after ${MAX_RETRIES} attempts.`);
	}

	/**
	 * Updates image meta (title/alt) for a single image via REST API.
	 * Includes retry logic.
	 * @param {number} imageId The image attachment ID.
	 * @param {string} newTitle The new title text.
	 * @param {string} newAlt The new alt text.
	 * @returns {Promise<Object>} Promise resolving with the server response (usually includes updated meta).
	 * @throws {Error} Throws an error on failure after retries.
	 */
	async function updateSingleImage(imageId, newTitle, newAlt) {
		const requestData = {
			image_id: imageId,
			new_title: newTitle,
			new_alt: newAlt
		};

		 for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
			 try {
				 if (attempt > 1) {
					  console.log(`AISO: Retrying update for Image ${imageId} (Attempt ${attempt})...`);
				 }

				 const response = await fetch(aiso_ajax_object.rest_url + 'update', {
					 method: 'POST', // Or 'PATCH' if your API uses it
					 headers: {
						 'Content-Type': 'application/json',
						 'X-WP-Nonce': aiso_ajax_object.nonce
					 },
					 body: JSON.stringify(requestData)
				 });

				 const contentType = response.headers.get("content-type");

				 if (!response.ok) {
					let errorData = { status: response.status, statusText: response.statusText };
					let errorMessage = `HTTP error! Status: ${response.status} - ${response.statusText}`;
					let errorCode = `http_${response.status}`;

					if (contentType && contentType.includes("application/json")) {
						try {
							const errJson = await response.json();
							errorMessage = errJson.message || errorMessage;
							errorCode = errJson.code || errorCode;
							errorData = errJson.data || errorData;
							// Keep specific field errors if available
							if (errJson.data?.errors) errorData.errors = errJson.data.errors;
						} catch(e) {
							console.warn('AISO: Failed to parse JSON error response body during update.');
							try { errorMessage = await response.text(); } catch(e2) {}
						}
					} else {
						try { errorMessage = await response.text(); } catch(e) {}
					}

					const error = new Error(errorMessage);
					error.code = errorCode;
					error.data = errorData;
					// Attach field-specific errors for logging if present
					if (errorData.errors) error.fieldErrors = errorData.errors;
					throw error;
				 }

				 // Success case
				 if (contentType && contentType.includes("application/json")) {
					 return await response.json();
				 } else {
					 const responseText = await response.text();
					 console.error('AISO Error: Received non-JSON success response from server during update.', responseText);
					 throw new Error('Received unexpected response format from server during update.');
				 }

			 } catch (error) {
				 console.error(`AISO Update Attempt ${attempt} Failed for Image ${imageId}:`, error);

				 const status = error?.data?.status || (error.name === 'TypeError' ? 503 : null);
				 const isRetryable = (status === 429 || (status >= 500 && status <= 504) || error.name === 'TypeError');

				 if (isRetryable && attempt < MAX_RETRIES) {
					 await new Promise(resolve => setTimeout(resolve, RETRY_DELAY_MS));
					 continue;
				 } else {
					 // Add field errors to the main message for final throw
					 if (error.fieldErrors) {
						 let fieldErrorMsg = Object.entries(error.fieldErrors).map(([field, msg]) => `${field}: ${msg}`).join('; ');
						 error.message = `${error.message} ${fieldErrorMsg}`;
					 }
					 throw error; // Re-throw final error
				 }
			 }
		 }
		  // Fallback
		  throw new Error(`AISO: Failed to update meta for Image ${imageId} after ${MAX_RETRIES} attempts.`);
	}

	// --- Event Handlers for Individual Buttons ---

	// Generate Button Click (Single Image)
	$imageTableBody.on('click', '.generate-ai-button', async function (e) {
		e.preventDefault();
		if (isBulkProcessing) return; // Prevent clicks during bulk operations

		const $button = $(this);
		const $row = $button.closest('tr');
		const imageId = $row.data('image-id');

		// Check if button is disabled or API key is missing
		if (!imageId || $button.prop('disabled')) {
			if (!aiso_ajax_object.has_api_key && imageId) {
				showNotification(aiso_ajax_object.text_api_key_missing, 'error');
			}
			return;
		}

		// Get keywords
		const focusKeyword = $row.find('.focus-keyword-input').val().trim();
		const secondaryKeyword = $row.find('.secondary-keyword-input').val().trim();

		// Get output elements and update button
		const $aiTitleOutput = $row.find('.ai-title-output');
		const $aiAltOutput = $row.find('.ai-alt-output');
		const $updateButton = $row.find('.update-meta-button');

		// Set UI state to loading
		setButtonState($button, true);
		if ($updateButton.length) $updateButton.prop('disabled', true);
		$aiTitleOutput.text('');
		$aiAltOutput.text('');
		$row.removeClass('aiso-error-row aiso-success-row aiso-generated-row');
		$errorLog.hide();
		$successLog.hide();

		// Get H1 context NO LONGER NEEDED
		// let pageH1 = null;
		// if (aiso_ajax_object.current_page === 'analyzer') {
		//     const $h1Input = $('#aiso_page_h1_for_js'); // Element removed from PHP
		//     if ($h1Input.length) { // This check will now likely fail
		//         pageH1 = $h1Input.val() || null;
		//     }
		// }

		try {
			// Call the API function (pageH1 removed)
			const data = await generateSingleImage(imageId, focusKeyword, secondaryKeyword);

			// Update UI with results
			if ($aiTitleOutput.length) $aiTitleOutput.text(data.title || '-'); // Show placeholder if empty
			if ($aiAltOutput.length) $aiAltOutput.text(data.alt || '-');   // Show placeholder if empty

			if (data.title || data.alt) {
				// Enable update button only if we got some data
				if ($updateButton.length) $updateButton.prop('disabled', false);
				$row.addClass('aiso-generated-row'); // Indicate data is ready
				// Optional: show success notification for single generation
				// showNotification('AI meta generated successfully.', 'success');
			} else {
				// Handle case where API returned empty strings (but technically succeeded)
				showNotification('AI returned empty title and alt.', 'warning');
				if ($updateButton.length) $updateButton.prop('disabled', true);
			}

			// Restore generate button state
			setButtonState($button, false, aiso_ajax_object.text_generate);

		} catch (error) {
			// Handle specific 'empty result' error code from PHP
			if (error.isEmptyAIResult) {
				showNotification(error.message || 'AI returned empty results.', 'warning');
				if ($aiTitleOutput.length) $aiTitleOutput.text('-');
				if ($aiAltOutput.length) $aiAltOutput.text('-');
				if ($updateButton.length) $updateButton.prop('disabled', true);
				setButtonState($button, false, aiso_ajax_object.text_generate); // Reset button even on empty result
			} else {
				// Handle general API errors
				handleApiError(error, $button, `generate single image ${imageId}`);
				if ($updateButton.length) $updateButton.prop('disabled', true); // Ensure update is disabled on error
			}
		}
	});

	// Update Button Click (Single Image)
	$imageTableBody.on('click', '.update-meta-button', async function (e) {
		e.preventDefault();
		if (isBulkProcessing) return;

		const $button = $(this);
		const $row = $button.closest('tr');
		const imageId = $row.data('image-id');

		if ($button.prop('disabled') || !imageId) {
			return;
		}

		// Get the generated AI data from the row
		const newTitle = $row.find('.ai-title-output').text().trim();
		const newAlt = $row.find('.ai-alt-output').text().trim();

		// Check if there's valid data to update
		if ((!newTitle || newTitle === '-') && (!newAlt || newAlt === '-')) {
			showNotification(aiso_ajax_object.text_no_ai_data, 'warning');
			return;
		}

		// Set UI state to loading
		setButtonState($button, true);
		$row.removeClass('aiso-error-row aiso-success-row aiso-generated-row');
		$errorLog.hide();
		$successLog.hide();

		try {
			// Call update API function (pass empty string if placeholder was '-')
			const data = await updateSingleImage(
				imageId,
				(newTitle === '-' ? '' : newTitle),
				(newAlt === '-' ? '' : newAlt)
			);

			// Update the 'Current Meta' column visually
			const $titleSpan = $row.find('.current-title-text');
			const $altSpan = $row.find('.current-alt-text');
			if ($titleSpan.length) $titleSpan.text(data.updated_title || ''); // Update with response value
			if ($altSpan.length) $altSpan.text(data.updated_alt || '');   // Update with response value

			// Update button state to 'Updated' and disable it
			setButtonState($button, false, aiso_ajax_object.text_updated);
			$button.prop('disabled', true); // Disable after successful update
			$row.addClass('aiso-success-row').removeClass('aiso-generated-row'); // Mark as successfully updated
			showNotification(data.message || aiso_ajax_object.text_update_success, 'success');

			// Fade out success highlight after delay
			setTimeout(() => { $row.removeClass('aiso-success-row'); }, 3000);

		} catch (error) {
			// Handle update errors
			handleApiError(error, $button, `update single image ${imageId}`);
			// Button state is reset within handleApiError
		}
	});


	// --- Event Handlers for Bulk Action Buttons (Page Analyzer Only) ---

	// Generate All Button Click (Analyzer Page)
	$analyzerGenerateAllBtn.on('click', async function () {
		if (isBulkProcessing || $(this).prop('disabled')) return;

		const $rowsToProcess = $imageTableBody.find('tr.aiso-library-image');
		if ($rowsToProcess.length === 0) {
			showNotification('No images found on this page to process.', 'info');
			return;
		}

		// Confirmation dialog
		const confirmMsg = sprintf(aiso_ajax_object.text_bulk_generate_confirm, $rowsToProcess.length);
		if (!confirm(confirmMsg)) {
			return;
		}

		// Start bulk processing
		isBulkProcessing = true;
		setButtonState($analyzerGenerateAllBtn, true);
		$analyzerUpdateAllBtn.prop('disabled', true); // Disable update button
		$imageTableBody.find('.generate-ai-button, .update-meta-button').prop('disabled', true); // Disable all row buttons
		$analyzerBulkProgress.text('Starting...').show();
		$errorLog.hide(); $successLog.hide();

		let successCount = 0;
		let failCount = 0;
		const totalCount = $rowsToProcess.length;

		// H1 context removed
		// let pageH1 = $('#aiso_page_h1_for_js').val() || null; // Element removed from PHP

		// Process each row sequentially with delay
		for (let i = 0; i < totalCount; i++) {
			const $row = $($rowsToProcess[i]);
			const imageId = $row.data('image-id');
			if (!imageId) continue; // Skip if somehow no ID

			const $updateButton = $row.find('.update-meta-button');
			const $aiTitleOutput = $row.find('.ai-title-output');
			const $aiAltOutput = $row.find('.ai-alt-output');
			const focusKeyword = $row.find('.focus-keyword-input').val().trim();
			const secondaryKeyword = $row.find('.secondary-keyword-input').val().trim();

			// Update progress indicator
			$analyzerBulkProgress.text(sprintf(aiso_ajax_object.text_generating_progress, i + 1, totalCount));
			$row.removeClass('aiso-error-row aiso-success-row aiso-generated-row').addClass('aiso-processing'); // Visual feedback

			try {
				 // Add delay between requests (after the first one)
				 if (i > 0) await new Promise(resolve => setTimeout(resolve, REQUEST_DELAY_MS));

				 // Call generate function (includes retries, pageH1 removed)
				 const data = await generateSingleImage(imageId, focusKeyword, secondaryKeyword);

				 // Update row UI
				 $aiTitleOutput.text(data.title || '-');
				 $aiAltOutput.text(data.alt || '-');
				 if (data.title || data.alt) {
					 if ($updateButton.length) $updateButton.prop('disabled', false); // Enable update
					 $row.addClass('aiso-generated-row');
				 } else {
					 if ($updateButton.length) $updateButton.prop('disabled', true); // Keep disabled if empty
					 // Optionally add a warning class?
				 }
				 successCount++;

			} catch (error) {
				 // Handle final failure after retries
				 failCount++;
				 $row.addClass('aiso-error-row');
				 console.error(`Bulk Generate FINAL Error for Image ${imageId}:`, error.message);
				 $aiTitleOutput.text('Error'); // Indicate error in output
				 $aiAltOutput.text('');
				 if ($updateButton.length) $updateButton.prop('disabled', true); // Ensure update is disabled

				 // Check for critical API errors that should stop the bulk process
				 const status = error?.data?.status;
				 const errorCode = error?.code;
				 if (errorCode === 'aiso_api_key_missing' || status === 401 || status === 403) {
					  handleApiError(error, $analyzerGenerateAllBtn, 'bulk generate - stopping');
					  isBulkProcessing = false;
					  $analyzerBulkProgress.text('Stopped due to critical API error.');
					  $analyzerUpdateAllBtn.prop('disabled', false); // Re-enable update btn
					  $row.removeClass('aiso-processing'); // Clean up current row style
					  // Re-enable other row buttons might be complex, leave disabled for simplicity or add logic later
					  return; // Exit the loop and function
				 }
				 // Don't stop for 429 or 5xx here, as retry logic already handled those within generateSingleImage

			} finally {
				// Remove processing class regardless of outcome
				$row.removeClass('aiso-processing');
			}
		} // End loop

		// Bulk processing finished
		setButtonState($analyzerGenerateAllBtn, false);
		$analyzerUpdateAllBtn.prop('disabled', false); // Re-enable update button
		$analyzerBulkProgress.text('');
		$analyzerBulkSpinner.removeClass('is-active');
		isBulkProcessing = false;

		// Re-enable row buttons based on final state (complex logic, simplify for now)
		$imageTableBody.find('tr.aiso-library-image').each(function () {
			const $r = $(this);
			// Re-enable generate button if API key exists and row didn't end in error
			if (!$r.hasClass('aiso-error-row')) {
				$r.find('.generate-ai-button').prop('disabled', !aiso_ajax_object.has_api_key);
				// Re-enable update button IF it has data AND was not successfully updated already AND not error
				const hasGenData = ($r.find('.ai-title-output').text() && $r.find('.ai-title-output').text() !== '-' && $r.find('.ai-title-output').text() !== 'Error') ||
								   ($r.find('.ai-alt-output').text() && $r.find('.ai-alt-output').text() !== '-');
				if(!$r.hasClass('aiso-success-row')) { // Don't re-enable if already updated
					$r.find('.update-meta-button').prop('disabled', !hasGenData);
				} else {
					$r.find('.update-meta-button').prop('disabled', true); // Keep disabled if updated
				}
			} else {
				// Keep buttons disabled on error rows
				$r.find('.generate-ai-button, .update-meta-button').prop('disabled', true);
			}
		});

		// Show final summary notification
		const finalMsg = sprintf(aiso_ajax_object.text_bulk_complete, successCount, failCount);
		showNotification(finalMsg, failCount > 0 ? 'warning' : 'success', false); // Persistent notification
	});

	// Update All Button Click (Analyzer Page)
	$analyzerUpdateAllBtn.on('click', async function () {
		 if (isBulkProcessing || $(this).prop('disabled')) return;

		 // Find rows that have generated data and are not already updated/error
		 const $rowsToProcess = $imageTableBody.find('tr.aiso-library-image').filter(function () {
			 const $updateBtn = $(this).find('.update-meta-button');
			 // Check if update button exists, is enabled (meaning data is generated), and row isn't already error/success
			 return $updateBtn.length && !$updateBtn.prop('disabled') && !$(this).hasClass('aiso-error-row') && !$(this).hasClass('aiso-success-row');
		 });

		 if ($rowsToProcess.length === 0) {
			 showNotification('No images with generated data ready to update.', 'info');
			 return;
		 }

		 const confirmMsg = sprintf(aiso_ajax_object.text_bulk_update_confirm, $rowsToProcess.length);
		 if (!confirm(confirmMsg)) {
			 return;
		 }

		 // Start bulk update
		 isBulkProcessing = true;
		 setButtonState($analyzerUpdateAllBtn, true);
		 $analyzerGenerateAllBtn.prop('disabled', true); // Disable generate button
		 $imageTableBody.find('.generate-ai-button, .update-meta-button').prop('disabled', true); // Disable all row buttons
		 $analyzerBulkProgress.text('Starting...').show();
		 $errorLog.hide(); $successLog.hide();

		 let successCount = 0;
		 let failCount = 0;
		 const totalCount = $rowsToProcess.length;

		 // Process each row sequentially
		 for (let i = 0; i < totalCount; i++) {
			 const $row = $($rowsToProcess[i]);
			 const imageId = $row.data('image-id');
			 if (!imageId) continue;

			 const $updateButton = $row.find('.update-meta-button'); // We know this exists and is enabled
			 const newTitle = $row.find('.ai-title-output').text().trim();
			 const newAlt = $row.find('.ai-alt-output').text().trim();

			 // Update progress
			 $analyzerBulkProgress.text(sprintf(aiso_ajax_object.text_updating_progress, i + 1, totalCount));
			 $row.removeClass('aiso-error-row aiso-success-row aiso-generated-row').addClass('aiso-processing');

			 // Double check for valid data (should be okay due to filter above, but belt-and-suspenders)
			 if ((!newTitle || newTitle === '-' || newTitle === 'Error') && (!newAlt || newAlt === '-')) {
				  failCount++;
				  $row.addClass('aiso-error-row').removeClass('aiso-processing');
				  console.warn(`Skipping update for Image ${imageId}: No valid AI data found unexpectedly.`);
				  continue; // Skip to next row
			 }

			 try {
				 // Add delay
				  if (i > 0) await new Promise(resolve => setTimeout(resolve, REQUEST_DELAY_MS));

				 // Call update function (includes retries)
				 const data = await updateSingleImage(imageId, (newTitle === '-' ? '' : newTitle), (newAlt === '-' ? '' : newAlt));

				 // Update UI on success
				 const $titleSpan = $row.find('.current-title-text');
				 const $altSpan = $row.find('.current-alt-text');
				 if ($titleSpan.length) $titleSpan.text(data.updated_title || '');
				 if ($altSpan.length) $altSpan.text(data.updated_alt || '');
				 $row.addClass('aiso-success-row').removeClass('aiso-generated-row'); // Mark success
				 $updateButton.prop('disabled', true); // Disable button after successful update
				 successCount++;

			 } catch (error) {
				 // Handle final failure after retries
				 failCount++;
				 $row.addClass('aiso-error-row');
				 console.error(`Bulk Update FINAL Error for Image ${imageId}:`, error.message);
				 $updateButton.prop('disabled', true); // Keep disabled on error
			 } finally {
				 $row.removeClass('aiso-processing');
			 }
		 } // End loop

		 // Bulk update finished
		 setButtonState($analyzerUpdateAllBtn, false);
		 $analyzerGenerateAllBtn.prop('disabled', false); // Re-enable generate button
		 $analyzerBulkProgress.text('');
		 $analyzerBulkSpinner.removeClass('is-active');
		 isBulkProcessing = false;

		 // Re-enable relevant row buttons
		 $imageTableBody.find('tr.aiso-library-image').each(function () {
			 const $r = $(this);
			 // Enable generate if API key exists and not error/success row
			 if (!$r.hasClass('aiso-error-row') && !$r.hasClass('aiso-success-row')) {
				 $r.find('.generate-ai-button').prop('disabled', !aiso_ajax_object.has_api_key);
			 } else {
				$r.find('.generate-ai-button').prop('disabled', true); // Keep generate disabled on final state rows
			 }
			 // Update buttons should remain disabled if they were processed (either success or error)
			 if ($r.hasClass('aiso-success-row') || $r.hasClass('aiso-error-row')) {
				 $r.find('.update-meta-button').prop('disabled', true);
			 }
			 // If a row was skipped (e.g., no data), its update button might still be enabled, leave it.
		 });

		 const finalMsg = sprintf(aiso_ajax_object.text_bulk_complete, successCount, failCount);
		 showNotification(finalMsg, failCount > 0 ? 'warning' : 'success', false);
	});


	// --- Initial State Setup On Page Load ---
	// Ensure buttons are correctly enabled/disabled based on API key presence
	$('.aiso-image-table tbody tr').each(function () {
		const $row = $(this);
		const $generateButton = $row.find('.generate-ai-button');
		const $updateButton = $row.find('.update-meta-button');
		const $keywordInputs = $row.find('.focus-keyword-input, .secondary-keyword-input');

		// Always disable update button initially (requires generated data)
		if ($updateButton.length) {
			$updateButton.prop('disabled', true);
		}

		// Disable generation/keywords if no API key
		if ($row.hasClass('aiso-library-image')) { // Only for library images
			if ($generateButton.length) {
				$generateButton.prop('disabled', !aiso_ajax_object.has_api_key);
			}
			if ($keywordInputs.length) {
				$keywordInputs.prop('disabled', !aiso_ajax_object.has_api_key);
			}
		}
	});

// Event Handler for Restore Default Prompt Button
    $('#aiso_restore_default_btn').on('click', function(e) {
        e.preventDefault();
        console.log("Button clicked"); // أضف هذا للتأكد من أن معالج الحدث يتم تنفيذه
        
        if (typeof aiso_default_prompt_text !== 'undefined') {
            console.log("Prompt text found, setting value"); // للتتبع
            $('#aiso_custom_prompt').val(aiso_default_prompt_text);
        } else {
            console.error('AISO Error: Default prompt text not found in JavaScript.');
            alert('Could not restore default prompt. Data missing.');
        }
    });
    
}); // End document ready