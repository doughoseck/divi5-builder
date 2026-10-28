<?php
/**
 * The one rule for "this names a secret" and the few value shapes that are a secret wherever they appear (#32). This file
 * only defines functions. The result scrubber (scrub.php), the audit redactor and the free-text masker (audit.php) all use
 * it, so a key that is removed from a result is also redacted in the audit log, and the other way round.
 *
 * A key is normalised first (lowercase, trimmed, every character that is not a-z or 0-9 removed), so X-API-KEY, api-key and
 * "Api Key " all read as apikey. Keys written with look-alike letters from other alphabets are not caught (a known limit).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_secret_key_norm' ) ) {
	/** Lowercase, trimmed, only a-z and 0-9 left. */
	function d5b_secret_key_norm( $key ): string {
		if ( ! is_scalar( $key ) ) { return ''; }
		return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( trim( (string) $key ) ) );
	}

	/** A normalised key that contains any of these is a secret. */
	function d5b_secret_words(): array {
		return array( 'pass', 'pwd', 'secret', 'token', 'salt', 'nonce', 'cookie', 'session', 'sessid', 'credential', 'signature', 'hmac', 'otp',
			'backupcode', 'recoverycode', 'csrf', 'xsrf', 'jwt', 'bearer', 'privkey', 'privatekey', 'apikey', 'licensekey', 'activationkey', 'dsn',
			'connectionstring', 'webhook', 'sklive', 'sktest' );
	}

	/** Normalised keys that are a secret on their own. */
	function d5b_secret_exact(): array {
		return array( 'pin', 'sid', 'userpass', 'useractivationkey', 'sessiontokens', 'applicationpasswords' );
	}

	/**
	 * Field names the abilities themselves return that the auth... and ...key rules would otherwise remove. Built from the
	 * real output: plugin-abilities-site-test.php runs every ability and fails on any output key this rule calls a secret.
	 * Add a field here when that test names it; never weaken the rule.
	 */
	function d5b_secret_keep(): array {
		return array( 'author', 'authors', 'authorname', 'authoremail', 'hash', 'metakey', 'menukey' );
	}

	/** True when $key names a secret. */
	function d5b_is_secret_key( $key ): bool {
		$k = d5b_secret_key_norm( $key );
		if ( '' === $k ) { return false; }
		if ( in_array( $k, d5b_secret_exact(), true ) ) { return true; }
		// "sk" as its own last word (stripe_sk, sk): a Stripe secret key. Read before normalising, so desk and task stay.
		if ( 1 === preg_match( '/(?:^|[^a-z0-9])sk\s*$/i', (string) $key ) ) { return true; }
		foreach ( d5b_secret_words() as $w ) { if ( false !== strpos( $k, $w ) ) { return true; } }
		if ( in_array( $k, d5b_secret_keep(), true ) ) { return false; }
		return 0 === strpos( $k, 'auth' ) || 'key' === substr( $k, -3 );
	}

	/**
	 * Value shapes that are a secret wherever they sit, also inside post content: high confidence only, so ordinary text
	 * is left alone. Each pattern matches exactly the part that is replaced.
	 */
	function d5b_secret_value_patterns(): array {
		return array(
			'/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|\z)/s', // a PEM private key (to its END line, or the end of the text)
			'/(?<![A-Za-z0-9])(?:sk_live|rk_live|sk_test)_[A-Za-z0-9]{8,}/',                  // Stripe secret and restricted keys
			'/(?<![A-Za-z0-9_-])eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', // a JSON Web Token
			'/\$(?:P|H)\$[.\/A-Za-z0-9]{8,}/',                                                // phpass password hashes
			'/\$wp\$[.\/A-Za-z0-9$]{8,}/',                                                    // WordPress 6.8+ bcrypt hashes
			'/\$2[abxy]\$[.\/A-Za-z0-9$]{8,}/',                                               // bcrypt
			'/\$argon2(?:id|i|d)\$[^\s\'"]{8,}/',                                             // argon2
			'/\bBearer\s+\K[A-Za-z0-9\-._~+\/]{16,}=*/',                                      // a bearer token (the word stays)
		);
	}

	/** Cheap test before the patterns run: none of the shapes can be in a string without one of these. */
	function d5b_secret_may_hold_shape( string $s ): bool {
		foreach ( array( 'PRIVATE KEY', '_live_', 'sk_test_', 'eyJ', '$', 'Bearer' ) as $needle ) {
			if ( false !== strpos( $s, $needle ) ) { return true; }
		}
		return false;
	}

	/** Replaces every high-confidence secret shape in $s with [redacted]; the rest of the text is kept. */
	function d5b_secret_mask_values( string $s ): string {
		if ( '' === $s || ! d5b_secret_may_hold_shape( $s ) ) { return $s; }
		$out = preg_replace( d5b_secret_value_patterns(), '[redacted]', $s );
		return is_string( $out ) ? $out : '[redacted]'; // a regex failure (invalid UTF-8 is fine, a backtrack limit is not) fails closed
	}
}
