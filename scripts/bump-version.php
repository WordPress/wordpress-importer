#!/usr/bin/env php
<?php
declare(strict_types=1);

$usage = "Usage: composer run bump-version -- <version|major|minor|patch>\n";

if ( $argc < 2 ) {
	fwrite( STDERR, $usage );
	exit( 1 );
}

$root  = dirname( __DIR__ );
$files = array(
	'wordpress-importer.php'     => $root . DIRECTORY_SEPARATOR . 'wordpress-importer.php',
	'src/wordpress-importer.php' => $root . DIRECTORY_SEPARATOR . 'src/wordpress-importer.php',
	'src/readme.txt'             => $root . DIRECTORY_SEPARATOR . 'src/readme.txt',
);

preg_match( '/\* Version: (\d+\.\d+\.\d+)/', file_get_contents( $files['wordpress-importer.php'] ), $matches );
$current_version = $matches[1];

list($major, $minor, $patch) = explode( '.', $current_version );
switch ( $argv[1] ?? 'patch' ) {
	case 'patch':
		++$patch;
		break;
	case 'major':
		++$major;
		break;
	case 'minor':
		++$minor;
		break;
}
$next_version = "$major.$minor.$patch";

$files['wordpress-importer.php']     = preg_replace(
	'/(\* Version:\s+)([\d\.]+)[ ]*/',
	'${1}' . $next_version,
	file_get_contents( $files['wordpress-importer.php'] )
);
$files['src/wordpress-importer.php'] = preg_replace(
	'/(\* Version:\s+)([\d\.]+)[ ]*/',
	'${1}' . $next_version,
	file_get_contents( $files['src/wordpress-importer.php'] )
);
$files['src/readme.txt']             = preg_replace(
	'/(Stable tag:\s+)([\d\.]+)[ ]*/',
	'${1}' . $next_version,
	file_get_contents( $files['src/readme.txt'] )
);

// Ensure changelog for the current version contains any missing commit subjects labeled with this version.
try {
	$readme_content = $files['src/readme.txt'];

	// Gather commit subjects which contain the exact current version string.
	$commit_subjects = array();
	$command         = 'git log --no-color --no-merges --grep=' . escapeshellarg( $current_version ) . ' --pretty=format:%s';
	@exec( $command, $commit_subjects );

	if ( is_array( $commit_subjects ) && ! empty( $commit_subjects ) ) {
		$normalized_commits = array();
		foreach ( $commit_subjects as $subject_line ) {
			$subject_line = trim( (string) $subject_line );
			if ( $subject_line === '' ) {
				continue;
			}
			if ( ! in_array( $subject_line, $normalized_commits, true ) ) {
				$normalized_commits[] = $subject_line;
			}
		}

		if ( ! empty( $normalized_commits ) ) {
			// Locate the Changelog header to insert a new section if needed.
			$changelog_header_regex = '/^==\s*Changelog\s*==\s*$/m';
			$has_changelog          = preg_match( $changelog_header_regex, $readme_content, $chl_m, PREG_OFFSET_CAPTURE ) === 1;
			$changelog_insert_pos   = $has_changelog ? ( $chl_m[0][1] + strlen( $chl_m[0][0] ) ) : false;

			// Find the current version section body.
			$section_regex = '/^=\s*' . preg_quote( $current_version, '/' ) . '\s*=\s*\R(?P<body>.*?)(?=^=\s*\d+\.\d+\.\d+\s*=\s*$|\z)/ms';
			if ( preg_match( $section_regex, $readme_content, $matches, PREG_OFFSET_CAPTURE ) === 1 ) {
				$section_full_match = $matches[0][0];
				$section_start_pos  = (int) $matches[0][1];
				$section_body       = (string) $matches['body'][0];

				// Existing bullets in this section.
				$existing_bullets = array();
				foreach ( preg_split( '/\R/', $section_body ) as $line ) {
					if ( preg_match( '/^\*\s+(.*)$/', $line, $bm ) === 1 ) {
						$existing_bullets[] = trim( (string) $bm[1] );
					}
				}

				$bullets_to_add = array();
				foreach ( $normalized_commits as $subject_line ) {
					$already_listed = false;
					foreach ( $existing_bullets as $existing ) {
						if ( $subject_line === $existing ) {
							$already_listed = true;
							break;
						}
					}
					if ( ! $already_listed ) {
						$bullets_to_add[] = '* ' . $subject_line;
					}
				}

				if ( ! empty( $bullets_to_add ) ) {
					$new_section_body = rtrim( $section_body, "\r\n" );
					if ( $new_section_body !== '' ) {
						$new_section_body .= "\n";
					}
					$new_section_body .= implode( "\n", $bullets_to_add ) . "\n";

					$new_section_full = '= ' . $current_version . " =\n" . $new_section_body;
					$readme_content   = substr_replace( $readme_content, $new_section_full, $section_start_pos, strlen( $section_full_match ) );
				}
			} elseif ( $has_changelog ) {
				// Create a new version section under the Changelog header.
				$new_section    = "\n\n= {$current_version} =\n" . implode( "\n", array_map( static function ( $s ) { return '* ' . $s; }, $normalized_commits ) ) . "\n";
				$readme_content = substr_replace( $readme_content, $new_section, (int) $changelog_insert_pos, 0 );
			}

			$files['src/readme.txt'] = $readme_content;
		}
	}
} catch ( \Throwable $e ) {
	// Non-fatal: continue without aborting the version bump.
}

foreach ( $files as $file => $new_content ) {
	file_put_contents( $file, $new_content );
	echo "Updated $file\n";
}

echo "Bumped version from $current_version to $next_version\n";
