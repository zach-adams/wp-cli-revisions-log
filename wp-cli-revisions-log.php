<?php
/**
 * NOTE: This was largely AI-generated and meant for a specific use-case. Use at your own discretion
 * 
 * Install:
 *    Copy into the "mu-plugins" folder or add "--require=wp-cli-revisions-log.php" to your WP-CLI command
 * 
 * Usage:
 *    wp revision-log --start=YYYY-MM-DD --end=YYYY-MM-DD --output="revisions-log.csv"
 */

if ( class_exists( 'WP_CLI' ) ) {

	/**
	 * Generate a log of all post/page revisions in a given time period
	 */
	class Revision_Log_Command {
	
		/**
		 * Generate a CSV log of all post & page revisions in a date range
		 *
		 * ## OPTIONS
		 *
		 * [--start=<date>]
		 * : Start date in YYYY-MM-DD format
		 *
		 * [--end=<date>]
		 * : End date in YYYY-MM-DD format
		 *
		 * [--output=<file>]
		 * : Output file (default: revisions-log.csv)
		 *
		 * [--truncate_diff=<maxchars>]
		 * : Enable truncating long output values. Set to 0 to disable truncation
		 *
		 * [--debug_revisions]
		 * : Enable debug output
		 */
		
		private $truncate = 0;
		
		public function __invoke($args, $assoc_args) {
			$start_date = $assoc_args['start'] ?? '';
			$end_date = $assoc_args['end'] ?? '';
			$output_file = $assoc_args['output'] ?? 'revisions-log.csv';
			$truncate = $assoc_args['truncate'] ?? 0;
			$this->truncate = absint($truncate);
			$debug = isset($assoc_args['debug_revisions']);
	
			if (empty($start_date) || empty($end_date)) {
				WP_CLI::error('Please provide both start and end dates in YYYY-MM-DD format');
				return;
			}
	
			WP_CLI::log("Fetching revisions from $start_date to $end_date...");
	
			// Debug: Check directly in the database
			global $wpdb;
	
			// Direct SQL query to check revisions
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
	            WHERE post_type = 'revision'
	            AND post_date >= %s
	            AND post_date <= %s",
				"$start_date 00:00:00",
				"$end_date 23:59:59"
			);
	
			$count = $wpdb->get_var($sql);
	
			if ($debug) {
				WP_CLI::log("SQL Query: " . $sql);
				WP_CLI::log("Direct DB count of revisions: " . $count);
			}
	
			if ($count == 0) {
				WP_CLI::warning("No revisions found in the database for the specified time period.");
	
				// Show some sample revisions to verify they exist at all
				$sample_sql = "SELECT ID, post_title, post_date, post_parent
	                           FROM {$wpdb->posts}
	                           WHERE post_type = 'revision'
	                           LIMIT 5";
				$samples = $wpdb->get_results($sample_sql);
	
				if ($samples) {
					WP_CLI::log("However, some revisions DO exist in the database (showing up to 5):");
					foreach ($samples as $sample) {
						WP_CLI::log("ID: {$sample->ID}, Parent: {$sample->post_parent}, Date: {$sample->post_date}, Title: {$sample->post_title}");
					}
					WP_CLI::log("Try adjusting your date range. Earliest and latest revision dates:");
					$date_sql = "SELECT MIN(post_date) as earliest, MAX(post_date) as latest FROM {$wpdb->posts} WHERE post_type = 'revision'";
					$dates = $wpdb->get_row($date_sql);
					WP_CLI::log("Earliest: {$dates->earliest}, Latest: {$dates->latest}");
				} else {
					WP_CLI::log("No revisions found in the database at all. Revisions may be disabled on this site.");
				}
	
				return;
			}
	
			// Now get the actual revisions using direct SQL for reliability
			$revisions_sql = $wpdb->prepare(
				"SELECT * FROM {$wpdb->posts}
	            WHERE post_type = 'revision'
	            AND post_date >= %s
	            AND post_date <= %s
	            ORDER BY post_date ASC",
				"$start_date 00:00:00",
				"$end_date 23:59:59"
			);
	
			$revisions = $wpdb->get_results($revisions_sql);
	
			WP_CLI::log("Found " . count($revisions) . " revisions. Processing...");
	
			// Prepare CSV file
			$fp = fopen($output_file, 'w');
	
			// CSV headers with new column
			$headers = array(
				'Date',
				'Time',
				'Editor',
				'Post ID',
				'Post URL',
				'Post Type',
				'Post Title',
				'Field Changed',
				'Revision Difference',
				'Previous Value',
				'New Value',
			);
			fputcsv($fp, $headers);
	
			$progress = \WP_CLI\Utils\make_progress_bar('Processing revisions', count($revisions));
	
			foreach ($revisions as $revision) {
				// Get parent post
				$parent = get_post($revision->post_parent);
				if (!$parent) {
					if ($debug) WP_CLI::log("Skipping revision {$revision->ID} - parent post {$revision->post_parent} not found");
					$progress->tick();
					continue;
				}
	
				// Get editor
				$editor = get_userdata($revision->post_author);
				$editor_name = $editor ? $editor->display_name : 'Unknown';
	
				$post_url = get_permalink($parent->ID) ?: '';
	
				// Get previous revision using direct SQL
				$prev_revision_sql = $wpdb->prepare(
					"SELECT * FROM {$wpdb->posts}
	                WHERE post_type = 'revision'
	                AND post_parent = %d
	                AND post_date < %s
	                ORDER BY post_date DESC
	                LIMIT 1",
					$revision->post_parent,
					$revision->post_date
				);
	
				$prev_revision = $wpdb->get_row($prev_revision_sql);
	
				// Process standard fields
				$fields_to_check = array(
					'post_title' => 'Title',
					'post_content' => 'Content',
					'post_excerpt' => 'Excerpt',
					'post_status' => 'Status',
				);
	
				foreach ($fields_to_check as $field => $field_label) {
					if ($prev_revision) {
						// Compare with previous revision
						if ($revision->$field !== $prev_revision->$field) {
							// Text diff analysis
							[$diff, $prev_context, $new_context] = $this->create_text_diff_with_context(
								$prev_revision->$field,
								$revision->$field,
								$field === 'post_content' // Only use paragraph context for content
							);
	
							$row = array(
								date('Y-m-d', strtotime($revision->post_date)),
								date('H:i:s', strtotime($revision->post_date)),
								$editor_name,
								$parent->ID,
								$post_url,
								$parent->post_type,
								$parent->post_title,
								$field_label,
								$diff,
								$prev_context,
								$new_context,
							);
							fputcsv($fp, $row, ",", "\"", "\\", "\n");
						}
					} else {
						// First revision, compare with parent
						if ($revision->$field !== $parent->$field) {
							// Text diff analysis
							[$diff, $prev_context, $new_context] = $this->create_text_diff_with_context(
								$parent->$field,
								$revision->$field,
								$field === 'post_content' // Only use paragraph context for content
							);
	
							$row = array(
								date('Y-m-d', strtotime($revision->post_date)),
								date('H:i:s', strtotime($revision->post_date)),
								$editor_name,
								$parent->ID,
								$post_url,
								$parent->post_type,
								$parent->post_title,
								$field_label,
								$diff,
								$prev_context,
								$new_context,
							);
							fputcsv($fp, $row, ",", "\"", "\\", "\n");
						}
					}
				}
	
				// Process ACF fields if ACF is active
				if (function_exists('acf_get_field_groups')) {
					$this->process_acf_fields($fp, $revision, $prev_revision, $parent, $editor_name, $post_url);
				}
	
				$progress->tick();
			}
	
			$progress->finish();
			fclose($fp);
	
			WP_CLI::success("Revision log created at $output_file");
		}
	
		/**
		 * Create a text diff with surrounding context
		 *
		 * @param string $old_text Old text
		 * @param string $new_text New text
		 * @param bool $use_paragraphs Whether to use paragraphs for context
		 * @return array [diff summary, old context, new context]
		 */
		private function create_text_diff_with_context($old_text, $new_text, $use_paragraphs = false) {
			// Handle simple cases
			if (empty($old_text) && !empty($new_text)) {
				return ["ADDED: All content", "[Empty]", $this->format_text_for_display($new_text)];
			}
	
			if (!empty($old_text) && empty($new_text)) {
				return ["REMOVED: All content", $this->format_text_for_display($old_text), "[Empty]"];
			}
	
			if ($old_text === $new_text) {
				return ["No changes", "", ""];
			}
	
			// For short text (titles, etc.), do a straightforward comparison
			if (strlen($old_text) < 100 && strlen($new_text) < 100) {
				if (strlen($old_text) < 20 && strlen($new_text) < 20) {
					// Very short text - show simple changes
					return [
						"Changed from \"$old_text\" to \"$new_text\"",
						$old_text,
						$new_text
					];
				} else {
					// Medium length - show word-level changes
					return $this->create_word_level_diff($old_text, $new_text);
				}
			}
	
			// For longer text (content), do a more complex diff
			if ($use_paragraphs) {
				return $this->create_paragraph_level_diff($old_text, $new_text);
			} else {
				return $this->create_sentence_level_diff($old_text, $new_text);
			}
		}
	
		/**
		 * Create a word-level diff for shorter text
		 */
		private function create_word_level_diff($old_text, $new_text) {
			// Split into words
			$old_words = preg_split('/(\s+|\b)/', $old_text, -1, PREG_SPLIT_DELIM_CAPTURE);
			$new_words = preg_split('/(\s+|\b)/', $new_text, -1, PREG_SPLIT_DELIM_CAPTURE);
	
			// WordPress's built-in text diff
			require_once ABSPATH . WPINC . '/wp-diff.php';
	
			$diff = new \Text_Diff('auto', array($old_words, $new_words));
			$renderer = new \Text_Diff_Renderer();
			$diff_output = $renderer->render($diff);
	
			// If we get no meaningful diff, try character by character
			if (empty($diff_output) || $diff->isEmpty()) {
				return [
					"Text was changed (word-level diff couldn't identify specific changes)",
					$old_text,
					$new_text
				];
			}
	
			// Process the diff output to create a more readable summary
			$diff_lines = explode("\n", $diff_output);
			$diff_summary = [];
	
			foreach ($diff_lines as $line) {
				$line = trim($line);
				if (empty($line)) continue;
	
				if (strpos($line, '<del>') !== false) {
					$deleted = strip_tags(str_replace('<del>', '[REMOVED] ', $line));
					$diff_summary[] = $deleted;
				}
	
				if (strpos($line, '<ins>') !== false) {
					$added = strip_tags(str_replace('<ins>', '[ADDED] ', $line));
					$diff_summary[] = $added;
				}
			}
	
			return [
				implode(" | ", $diff_summary),
				$old_text,
				$new_text
			];
		}
	
		/**
		 * Create a sentence-level diff for medium text
		 */
		private function create_sentence_level_diff($old_text, $new_text) {
			// Split into sentences (try to handle various punctuation)
			$pattern = '/(?<=[.!?])\s+(?=[A-Z])/';
			$old_sentences = preg_split($pattern, $old_text);
			$new_sentences = preg_split($pattern, $new_text);
	
			// Find added, removed and changed sentences
			$added = array_diff($new_sentences, $old_sentences);
			$removed = array_diff($old_sentences, $new_sentences);
	
			// Build summary
			$diff_summary = [];
	
			if (!empty($removed)) {
				foreach ($removed as $sentence) {
					if (trim($sentence)) {
						$diff_summary[] = "REMOVED: \"" . $this->truncate_for_diff($sentence) . "\"";
					}
				}
			}
	
			if (!empty($added)) {
				foreach ($added as $sentence) {
					if (trim($sentence)) {
						$diff_summary[] = "ADDED: \"" . $this->truncate_for_diff($sentence) . "\"";
					}
				}
			}
	
			// If no clear sentence changes were detected but text is different,
			// look for smaller changes
			if (empty($diff_summary)) {
				// Try to find changed sentences (similar but not identical)
				$potential_changes = [];
	
				foreach ($old_sentences as $i => $old_sentence) {
					if (isset($new_sentences[$i]) && $old_sentence !== $new_sentences[$i]) {
						similar_text($old_sentence, $new_sentences[$i], $similarity);
						if ($similarity > 50) { // Only consider somewhat similar sentences
							$potential_changes[] = [
								'old' => $old_sentence,
								'new' => $new_sentences[$i],
								'similarity' => $similarity
							];
						}
					}
				}
	
				// Add the most significant changes to summary
				foreach ($potential_changes as $change) {
					$diff_summary[] = "CHANGED: \"" . $this->truncate_for_diff($change['old']) . "\" to \"" .
					                  $this->truncate_for_diff($change['new']) . "\"";
				}
	
				// If still no clear changes, look at overall length
				if (empty($diff_summary)) {
					$length_diff = strlen($new_text) - strlen($old_text);
					if ($length_diff > 0) {
						$diff_summary[] = "Content was expanded by " . $length_diff . " characters";
					} else {
						$diff_summary[] = "Content was reduced by " . abs($length_diff) . " characters";
					}
				}
			}
	
			// Create context by showing the entire text
			return [
				implode(" | ", $diff_summary),
				$this->format_text_for_display($old_text),
				$this->format_text_for_display($new_text)
			];
		}
	
		/**
		 * Create a paragraph-level diff for longer text
		 */
		private function create_paragraph_level_diff($old_text, $new_text) {
			// Split into paragraphs (double line breaks)
			$old_paragraphs = preg_split('/\n\s*\n/', $old_text);
			$new_paragraphs = preg_split('/\n\s*\n/', $new_text);
	
			// Remove empty paragraphs
			$old_paragraphs = array_values(array_filter($old_paragraphs, 'trim'));
			$new_paragraphs = array_values(array_filter($new_paragraphs, 'trim'));
	
			// Find modified, added, and removed paragraphs
			$diff_map = [];
			$this->map_paragraph_changes($old_paragraphs, $new_paragraphs, $diff_map);
	
			// Generate context: Only show paragraphs around changes
			$old_context = [];
			$new_context = [];
			$diff_summary = [];
	
			// Track which paragraphs to include
			$include_old = [];
			$include_new = [];
	
			// First identify which paragraphs should be included
			foreach ($diff_map as $change) {
				$type = $change['type'];
	
				if ($type === 'unchanged') {
					continue;
				}
	
				if ($type === 'added') {
					$new_index = $change['new_index'];
					$diff_summary[] = "ADDED ¶" . ($new_index + 1) . ": \"" .
					                  $this->truncate_for_diff($change['new']) . "\"";
	
					// Include the new paragraph and surrounding ones
					$include_new[$new_index] = true;
	
					// Include surrounding paragraphs for context
					if ($new_index > 0) {
						$include_new[$new_index - 1] = true;
					}
					if ($new_index < count($new_paragraphs) - 1) {
						$include_new[$new_index + 1] = true;
					}
				}
				else if ($type === 'removed') {
					$old_index = $change['old_index'];
					$diff_summary[] = "REMOVED ¶" . ($old_index + 1) . ": \"" .
					                  $this->truncate_for_diff($change['old']) . "\"";
	
					// Include the old paragraph and surrounding ones
					$include_old[$old_index] = true;
	
					// Include surrounding paragraphs for context
					if ($old_index > 0) {
						$include_old[$old_index - 1] = true;
					}
					if ($old_index < count($old_paragraphs) - 1) {
						$include_old[$old_index + 1] = true;
					}
				}
				else if ($type === 'changed') {
					$old_index = $change['old_index'];
					$new_index = $change['new_index'];
					$next = '';
					if(($old_index + 1) !== ($new_index + 1)) {
						$next = " to ¶" . ($new_index + 1);
					}
	
					$diff_summary[] = "CHANGED ¶" . ($old_index + 1) . $next . ": \"" .
					                  $this->truncate_for_diff($change['old']) . "\" to \"" .
					                  $this->truncate_for_diff($change['new']) . "\"";
	
					// Include both paragraphs and surrounding ones
					$include_old[$old_index] = true;
					$include_new[$new_index] = true;
	
					// Include surrounding paragraphs for context
					if ($old_index > 0) {
						$include_old[$old_index - 1] = true;
					}
					if ($old_index < count($old_paragraphs) - 1) {
						$include_old[$old_index + 1] = true;
					}
	
					if ($new_index > 0) {
						$include_new[$new_index - 1] = true;
					}
					if ($new_index < count($new_paragraphs) - 1) {
						$include_new[$new_index + 1] = true;
					}
				}
			}
	
			// Now build the context with paragraph numbers for clarity
			foreach ($old_paragraphs as $i => $paragraph) {
				if (isset($include_old[$i])) {
					if (array_key_exists($i, $diff_map) && $diff_map[$i]['type'] === 'removed') {
						$old_context[] = "[¶" . ($i+1) . " - REMOVED] " . $paragraph;
					} elseif (array_key_exists($i, $diff_map) && $diff_map[$i]['type'] === 'changed') {
						$old_context[] = "[¶" . ($i+1) . " - CHANGED] " . $paragraph;
					} else {
						$old_context[] = "[¶" . ($i+1) . "] " . $paragraph;
					}
				}
			}
	
			foreach ($new_paragraphs as $i => $paragraph) {
				if (isset($include_new[$i])) {
					// Check if this is a new paragraph
					$is_added = true;
					$is_changed = false;
	
					foreach ($diff_map as $change) {
						if ($change['type'] === 'unchanged' && $change['new_index'] === $i) {
							$is_added = false;
							break;
						}
						if ($change['type'] === 'changed' && $change['new_index'] === $i) {
							$is_added = false;
							$is_changed = true;
							break;
						}
					}
	
					if ($is_added) {
						$new_context[] = "[¶" . ($i+1) . " - ADDED] " . $paragraph;
					} elseif ($is_changed) {
						$new_context[] = "[¶" . ($i+1) . " - CHANGED] " . $paragraph;
					} else {
						$new_context[] = "[¶" . ($i+1) . "] " . $paragraph;
					}
				}
			}
	
			// If no paragraphs were selected (small changes within a paragraph), include the whole text
			if (empty($old_context) || empty($new_context)) {
				return [
					empty($diff_summary) ? "Minor changes in text content" : implode(" \n\n ", $diff_summary),
					$this->format_text_for_display($old_text),
					$this->format_text_for_display($new_text)
				];
			}
	
			return [
				implode("\n\n", $diff_summary),
				$this->format_text_for_display(implode("\n\n", $old_context)),
				$this->format_text_for_display(implode("\n\n", $new_context))
			];
		}
	
		/**
		 * Maps paragraph changes between old and new text
		 */
		private function map_paragraph_changes($old_paragraphs, $new_paragraphs, &$diff_map) {
			// Simple longest common subsequence matching
			$matrix = [];
			$similarity_threshold = 50; // Percentage of similarity to consider paragraphs related
	
			// Build similarity matrix
			foreach ($old_paragraphs as $i => $old_p) {
				foreach ($new_paragraphs as $j => $new_p) {
					similar_text($old_p, $new_p, $similarity);
					$matrix[$i][$j] = $similarity;
				}
			}
	
			// Find best matches
			$matched_old = [];
			$matched_new = [];
	
			// First match identical paragraphs
			foreach ($old_paragraphs as $i => $old_p) {
				foreach ($new_paragraphs as $j => $new_p) {
					if ($old_p === $new_p && !in_array($i, $matched_old) && !in_array($j, $matched_new)) {
						$diff_map[] = [
							'type' => 'unchanged',
							'old_index' => $i,
							'new_index' => $j,
							'old' => $old_p,
							'new' => $new_p
						];
						$matched_old[] = $i;
						$matched_new[] = $j;
					}
				}
			}
	
			// Then match similar paragraphs
			foreach ($old_paragraphs as $i => $old_p) {
				if (in_array($i, $matched_old)) continue;
	
				$best_j = null;
				$best_similarity = 0;
	
				foreach ($new_paragraphs as $j => $new_p) {
					if (in_array($j, $matched_new)) continue;
	
					if ($matrix[$i][$j] > $best_similarity && $matrix[$i][$j] >= $similarity_threshold) {
						$best_similarity = $matrix[$i][$j];
						$best_j = $j;
					}
				}
	
				if ($best_j !== null) {
					$diff_map[] = [
						'type' => 'changed',
						'old_index' => $i,
						'new_index' => $best_j,
						'old' => $old_p,
						'new' => $new_paragraphs[$best_j],
						'similarity' => $best_similarity
					];
					$matched_old[] = $i;
					$matched_new[] = $best_j;
				}
			}
	
			// Mark remaining old paragraphs as removed
			foreach ($old_paragraphs as $i => $old_p) {
				if (!in_array($i, $matched_old)) {
					$diff_map[] = [
						'type' => 'removed',
						'old_index' => $i,
						'old' => $old_p
					];
				}
			}
	
			// Mark remaining new paragraphs as added
			foreach ($new_paragraphs as $j => $new_p) {
				if (!in_array($j, $matched_new)) {
					$diff_map[] = [
						'type' => 'added',
						'new_index' => $j,
						'new' => $new_p
					];
				}
			}
	
			// Sort the diff map by old index to make it easier to process
			usort($diff_map, function($a, $b) {
				if (isset($a['old_index']) && isset($b['old_index'])) {
					return $a['old_index'] - $b['old_index'];
				} elseif (isset($a['old_index'])) {
					return -1;
				} elseif (isset($b['old_index'])) {
					return 1;
				} else {
					return $a['new_index'] - $b['new_index'];
				}
			});
		}
	
		/**
		 * Format text for display in CSV
		 */
		private function format_text_for_display($text) {
			// Truncate very long text
			if (strlen($text) > 5000) {
				$text = substr($text, 0, 5000) . "... [truncated, total length: " . strlen($text) . " characters]";
			}
	
			return $text;
		}
	
		/**
		 * Truncate text for diff display
		 */
		private function truncate_for_diff($text) {
			if($this->truncate === 0) {
				return trim($text);
			}
			if (strlen($text) > $this->truncate) {
				return substr($text, 0, $this->truncate - 3) . "...";
			}
			return $text;
		}
	
		/**
		 * Process ACF fields changes
		 */
		private function process_acf_fields($fp, $revision, $prev_revision, $parent, $editor_name, $post_url) {
			// Get field groups for this post type
			$field_groups = acf_get_field_groups(array(
				'post_type' => $parent->post_type,
			));
	
			if (empty($field_groups)) return;
	
			global $wpdb;
			$acf_fields = array();
	
			// First, gather all fields to check
			foreach ($field_groups as $field_group) {
				$fields = acf_get_fields($field_group);
				foreach ($fields as $field) {
					$acf_fields[$field['name']] = $field;
				}
			}
	
			// Get all meta keys for this revision
			$revision_meta_keys_sql = $wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d",
				$revision->ID
			);
			$revision_meta_keys = $wpdb->get_col($revision_meta_keys_sql);
	
			// Get all meta for previous revision or parent
			$prev_id = $prev_revision ? $prev_revision->ID : $parent->ID;
			$prev_meta_keys_sql = $wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d",
				$prev_id
			);
			$prev_meta_keys = $wpdb->get_col($prev_meta_keys_sql);
	
			// Combine all meta keys
			$all_meta_keys = array_unique(array_merge($revision_meta_keys, $prev_meta_keys));
	
			foreach ($all_meta_keys as $meta_key) {
				// Skip internal WordPress meta
				if (substr($meta_key, 0, 1) === '_' && substr($meta_key, 0, 4) !== '_acf') {
					continue;
				}
	
				// Get the field config
				$field_name = str_replace('_', '', $meta_key);
				$field = isset($acf_fields[$field_name]) ? $acf_fields[$field_name] : null;
	
				// If not a recognized ACF field, skip
				if (!$field) continue;
	
				// Get values
				$revision_meta = get_metadata('post', $revision->ID, $meta_key, true);
				$prev_meta = get_metadata('post', $prev_id, $meta_key, true);
	
				// Format values based on field type
				$formatted_revision_meta = $this->format_acf_field_value($revision_meta, $field);
				$formatted_prev_meta = $this->format_acf_field_value($prev_meta, $field);
	
				// Check if values are
				// Check if values are different
				if ($this->is_different($revision_meta, $prev_meta)) {
					// Generate appropriate diff based on field type
					$diff = '';
	
					if ($field['type'] === 'text' || $field['type'] === 'textarea' || $field['type'] === 'wysiwyg') {
						// For text fields, use our text diff methods
						[$diff, $prev_context, $new_context] = $this->create_text_diff_with_context(
							$prev_meta,
							$revision_meta,
							$field['type'] === 'wysiwyg' // Use paragraph context for WYSIWYG fields
						);
					} else {
						// For other field types, show a simple change description
						$diff = "Changed from " . $this->truncate_for_diff($formatted_prev_meta) .
						        " to " . $this->truncate_for_diff($formatted_revision_meta);
	
						$prev_context = $formatted_prev_meta;
						$new_context = $formatted_revision_meta;
					}
	
					$row = array(
						date('Y-m-d', strtotime($revision->post_date)),
						date('H:i:s', strtotime($revision->post_date)),
						$editor_name,
						$parent->ID,
						$post_url,
						$parent->post_type,
						$parent->post_title,
						'ACF: ' . $field['label'],
						$diff,
						$prev_context,
						$new_context,
					);
					fputcsv($fp, $row);
				}
			}
		}
	
		/**
		 * Format ACF field value based on field type
		 */
		private function format_acf_field_value($value, $field) {
			if (empty($value)) {
				return '';
			}
	
			switch ($field['type']) {
				case 'image':
					// Convert image ID to URL
					$attachment_url = wp_get_attachment_url($value);
					return $attachment_url ? $attachment_url : "Image ID: " . $value;
	
				case 'true_false':
					// Convert 1/0 to True/False
					return $value ? 'True' : 'False';
	
				case 'gallery':
					// Convert array of image IDs to array of URLs
					if (is_array($value)) {
						$urls = array();
						foreach ($value as $image_id) {
							$url = wp_get_attachment_url($image_id);
							$urls[] = $url ? $url : "Image ID: " . $image_id;
						}
						return implode(", ", $urls);
					}
					return 'Gallery: ' . (is_array($value) ? count($value) : 0) . ' images';
	
				case 'file':
					// Convert file ID to URL
					$file_url = wp_get_attachment_url($value);
					return $file_url ? $file_url : "File ID: " . $value;
	
				case 'select':
				case 'checkbox':
				case 'radio':
					// Show selected values
					if (is_array($value)) {
						return implode(", ", $value);
					}
					return $value;
	
				case 'relationship':
				case 'post_object':
					// Show post titles
					if (is_array($value)) {
						$titles = array();
						foreach ($value as $post_id) {
							$post_title = get_the_title($post_id);
							$titles[] = $post_title ? $post_title : "Post ID: " . $post_id;
						}
						return implode(", ", $titles);
					} elseif (is_numeric($value)) {
						$post_title = get_the_title($value);
						return $post_title ? $post_title : "Post ID: " . $value;
					}
					return $value;
	
				case 'taxonomy':
					// Show term names
					if (is_array($value)) {
						$terms = array();
						foreach ($value as $term_id) {
							$term = get_term($term_id);
							$terms[] = $term && !is_wp_error($term) ? $term->name : "Term ID: " . $term_id;
						}
						return implode(", ", $terms);
					} elseif (is_numeric($value)) {
						$term = get_term($value);
						return $term && !is_wp_error($term) ? $term->name : "Term ID: " . $value;
					}
					return $value;
	
				case 'date_picker':
				case 'date_time_picker':
					// Format dates consistently
					return date('Y-m-d H:i:s', strtotime($value));
	
				case 'repeater':
					// For repeater fields, show count
					if (is_array($value)) {
						return 'Repeater with ' . count($value) . ' rows';
					}
					return 'Empty repeater';
	
				case 'flexible_content':
					// For flexible content, show layout info
					if (is_array($value)) {
						$layouts = array();
						foreach ($value as $layout) {
							if (isset($layout['acf_fc_layout'])) {
								$layouts[] = $layout['acf_fc_layout'];
							}
						}
						return 'Flexible content with layouts: ' . implode(', ', $layouts);
					}
					return 'Empty flexible content';
	
				default:
					if (is_array($value)) {
						return json_encode($value);
					}
					return $value;
			}
		}
	
		/**
		 * Check if two values are different
		 */
		private function is_different($val1, $val2) {
			if (is_array($val1) && is_array($val2)) {
				return md5(serialize($val1)) !== md5(serialize($val2));
			}
			return $val1 !== $val2;
		}
	}
	
	WP_CLI::add_command('revision-log', 'Revision_Log_Command');
}
