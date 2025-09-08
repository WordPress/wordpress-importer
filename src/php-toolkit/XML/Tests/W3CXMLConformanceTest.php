<?php
/**
 * W3C XML Conformance Tests for XMLProcessor
 *
 * Runs individual test cases from the W3C XML Conformance Test Suite
 * as PHPUnit test cases with direct assertions.
 *
 * @package WordPress
 * @subpackage XML-API
 */

use PHPUnit\Framework\TestCase;
use WordPress\XML\XMLProcessor;

/**
 * @group xml-api
 * @group w3c-conformance
 * 
 * @coversDefaultClass XMLProcessor
 */
class W3CXMLConformanceTest extends TestCase {
	
	/**
	 * Path to the W3C XML test suite directory
	 */
	private static $test_suite_path;
	
	/**
	 * Cache of parsed test cases
	 */
	private static $test_cases = null;
	
	public static function setUpBeforeClass(): void {
		self::$test_suite_path = __DIR__ . '/W3C-XML-Test-Suite';
		
		if (!is_dir(self::$test_suite_path)) {
			throw new Exception("W3C XML Test Suite not found at: " . self::$test_suite_path);
		}
	}
	
	/**
	 * Test individual W3C XML test cases
	 * 
	 * @dataProvider w3cTestCaseProvider
	 * @covers XMLProcessor::create_from_string
	 * @covers XMLProcessor::next_token
	 * @covers XMLProcessor::get_last_error
	 */
	public function test_w3c_xml_test_case($test_id, $test_type, $test_file, $description) {
		$xml_content = file_get_contents($test_file);
		$this->assertNotFalse($xml_content, "Could not read test file: {$test_file}");
		if(strpos($xml_content, "<!DOCTYPE") !== false) {
			$this->markTestSkipped("Skipping test case: {$test_id} – XMLProcessor does not support DOCTYPE declarations.");
			return;
		}
		if(strpos($xml_content, "\xFF\xFE") !== false || strpos($xml_content, "\xFE\xFF") !== false) {
			$this->markTestSkipped("Skipping test case: {$test_id} – it uses a UTF-16 encoded document and XMLProcessor only supports UTF-8.");
			return;
		}
		
		try {
			$processor = XMLProcessor::create_from_string($xml_content);

			// Process through all tokens to trigger any parsing errors
			if ($processor !== false) {
				while ($processor->next_token()) {
					// twiddle thumbs
				}
			}
			
			switch ($test_type) {
				case 'valid':
					$this->assertNotFalse($processor, 
						"Valid XML should parse successfully [{$test_id}]: {$description}");
					$this->assertNull($processor->get_last_error(), 
						"Valid XML should not produce errors [{$test_id}]: {$description}");
					break;
					
				case 'invalid':
					// Invalid XML should parse (non-validating parser) but may have validation errors
					// Since XMLProcessor is non-validating, invalid docs should still parse
					$this->assertNotFalse($processor, 
						"Invalid but well-formed XML should parse with non-validating parser [{$test_id}]: {$description}");
					break;
					
				case 'not-wf':
					// Not well-formed XML should fail to parse or produce errors
					$this->assertTrue(
						$processor === false || ($processor && $processor->get_last_error() !== null),
						"Not well-formed XML should be rejected [{$test_id}]: {$description}"
					);
					break;
					
				case 'error':
					// Error cases - behavior is implementation-defined
					// We'll just verify it doesn't crash
					$this->assertTrue(true, "Error test case completed without crashing [{$test_id}]: {$description}");
					break;
					
				default:
					$this->fail("Unknown test type: {$test_type} for test {$test_id}");
			}
			
		} catch (Exception $e) {
			// For 'not-wf' tests, exceptions might be expected
			if ($test_type === 'not-wf') {
				$this->assertTrue(true, "Expected exception for malformed XML [{$test_id}]: " . $e->getMessage());
			} else {
				throw $e;
			}
		}
	}
	
	/**
	 * Data provider for W3C XML test cases
	 */
	public static function w3cTestCaseProvider() {
		// Initialize path if not set (data providers run before setUpBeforeClass)
		if (self::$test_suite_path === null) {
			self::$test_suite_path = __DIR__ . '/W3C-XML-Test-Suite';
			
			if (!is_dir(self::$test_suite_path)) {
				throw new Exception("W3C XML Test Suite not found at: " . self::$test_suite_path);
			}
		}
		
		if (self::$test_cases === null) {
			self::$test_cases = self::parseAllTestCases();
		}
		
		return self::$test_cases;
	}
	
	/**
	 * Parse all test cases from the W3C XML test suite
	 */
	private static function parseAllTestCases() {
		$main_config = self::$test_suite_path . '/xmlconf.xml';
		if (!file_exists($main_config)) {
			throw new Exception("Main test configuration not found: {$main_config}");
		}
		
		$test_suites = self::parseMainConfiguration($main_config);
		$all_test_cases = [];
		
		foreach ($test_suites as $suite) {
			$suite_test_cases = self::parseTestSuite($suite);
			$all_test_cases = array_merge($all_test_cases, $suite_test_cases);
		}
		
		return $all_test_cases;
	}
	
	/**
	 * Parse the main xmlconf.xml configuration file
	 */
	private static function parseMainConfiguration($config_path) {
		$xml_content = file_get_contents($config_path);
		$suites = [];
		
		// Extract TESTCASES elements and their xml:base attributes
		if (preg_match_all('/<TESTCASES[^>]*?xml:base="([^"]*)"[^>]*?PROFILE="([^"]*)"[^>]*?>/', $xml_content, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$suites[] = [
					'base_path' => $match[1],
					'profile' => $match[2]
				];
			}
		}
		
		// Also handle TESTCASES without explicit PROFILE but with xml:base
		if (preg_match_all('/<TESTCASES[^>]*?xml:base="([^"]*)"[^>]*?>(?![^<]*PROFILE)/', $xml_content, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$suites[] = [
					'base_path' => $match[1],
					'profile' => 'Unknown Profile'
				];
			}
		}
		
		return $suites;
	}
	
	/**
	 * Parse tests for a specific test suite
	 */
	private static function parseTestSuite($suite) {
		$base_path = rtrim(self::$test_suite_path . '/' . $suite['base_path'], '/');
		$test_cases = [];
		
		// Look for test definition files in the base path
		if (is_dir($base_path)) {
			$files = glob($base_path . '/*.xml');
			foreach ($files as $file) {
				if (basename($file) !== 'xmlconf.xml') {
					$suite_test_cases = self::parseTestFile($file, $base_path);
					$test_cases = array_merge($test_cases, $suite_test_cases);
				}
			}
		}
		
		return $test_cases;
	}
	
	/**
	 * Parse a single test definition file
	 */
	private static function parseTestFile($test_file, $base_path) {
		$content = file_get_contents($test_file);
		$test_cases = [];
		
		// Parse TEST elements using regex
		$pattern = '/<TEST\s+([^>]+)>(.*?)<\/TEST>/s';
		if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$attributes = self::parseAttributes($match[1]);
				$description = trim(strip_tags($match[2]));
				
				if (isset($attributes['URI']) && isset($attributes['ID']) && isset($attributes['TYPE'])) {
					$test_file_path = $base_path . '/' . $attributes['URI'];
					
					// Only include tests that have actual test files
					if (file_exists($test_file_path)) {
						$test_cases[$attributes['ID']] = [
							$attributes['ID'],      // test_id
							$attributes['TYPE'],    // test_type
							$test_file_path,        // test_file
							$description            // description
						];
					}
				}
			}
		}
		
		return $test_cases;
	}
	
	/**
	 * Parse XML attributes from a string
	 */
	private static function parseAttributes($attr_string) {
		$attributes = [];
		$pattern = '/(\w+)="([^"]*)"|\s+(\w+)=\'([^\']*)\'/';
		
		if (preg_match_all($pattern, $attr_string, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				if (!empty($match[1])) {
					$attributes[$match[1]] = $match[2];
				} elseif (!empty($match[3])) {
					$attributes[$match[3]] = $match[4];
				}
			}
		}
		
		return $attributes;
	}
}