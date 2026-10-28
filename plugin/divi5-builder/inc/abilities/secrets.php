<?php
/**
 * The one rule for "this names a secret" and the few value shapes that are a secret wherever they appear (#32). This file
 * only defines functions. The result scrubber (scrub.php), the audit redactor and the free-text masker (audit.php) all use
 * it, so a key that is removed from a result is also redacted in the audit log, and the other way round.
 *
 * A key is read two ways. As TOKENS: split at anything that is not a letter or digit and at camelCase boundaries, then
 * lowercased (smtp_pw -> smtp, pw; apiKey -> api, key), so a secret word must be a whole word (compass and bypass_cache
 * stay). And JOINED: lowercase letters and digits only (X-API-KEY -> xapikey), for compounds written in one piece
 * (application_passwords, 2fa_backup_codes). Keys written with look-alike letters from other alphabets are not caught
 * (a known limit).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_secret_key_norm' ) ) {
	/** The joined form: lowercase, trimmed, only a-z and 0-9 left. */
	function d5b_secret_key_norm( $key ): string {
		if ( ! is_scalar( $key ) ) { return ''; }
		return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( trim( (string) $key ) ) );
	}

	/** The token form: split at non-alphanumerics and camelCase boundaries, lowercased. @return string[] */
	function d5b_secret_key_tokens( $key ): array {
		if ( ! is_scalar( $key ) ) { return array(); }
		$s = (string) preg_replace( array( '/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/', '/([A-Za-z])([0-9])/', '/([0-9])([A-Za-z])/' ), '$1 $2', (string) $key );
		$parts = preg_split( '/[^A-Za-z0-9]+/', strtolower( $s ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parts ) ? $parts : array();
	}

	/** A token that is one of these makes the key a secret. */
	function d5b_secret_tokens(): array {
		return array( 'pass', 'password', 'passwd', 'passphrase', 'pwd', 'pw', 'pword',
			'secret', 'secrets', 'token', 'tokens', 'salt', 'nonce', 'cookie', 'cookies', 'session', 'sessid', 'phpsessid',
			'credential', 'credentials', 'creds', 'signature', 'hmac', 'otp', 'jwt', 'bearer', 'csrf', 'xsrf', 'dsn', 'mnemonic', 'seed', 'privkey', 'apikey', 'pin', 'sid',
			'auth', 'authorization', 'oauth', 'authkey' );
	}

	/**
	 * A joined key that contains one of these is a secret. 'nonce' is here as well as in the tokens so that _wpnonce (one
	 * token, wpnonce) stays caught.
	 */
	function d5b_secret_compounds(): array {
		return array( 'apikey', 'privatekey', 'secretkey', 'licensekey', 'activationkey', 'accesskey', 'authkey', 'loggedinkey', 'encryptionkey', 'signingkey',
			'accesstoken', 'refreshtoken', 'clientsecret', 'password', 'passphrase', 'authorization', 'connectionstring', 'webhookurl', 'backupcode', 'recoverycode', 'mfacode',
			'sklive', 'sktest', 'stripesk', 'nonce' );
	}

	/** Joined keys that are a secret on their own (WordPress's user and session fields). */
	function d5b_secret_exact(): array {
		return array( 'userpass', 'useractivationkey', 'sessiontokens', 'applicationpasswords' );
	}

	/** Tokens after which a lone "key" token names a secret (api_key, license-key, x-key); layout_key and module_key do not. */
	function d5b_secret_key_owners(): array {
		return array( 'api', 'private', 'secret', 'license', 'activation', 'access', 'auth', 'encryption', 'signing', 'stripe', 'x' );
	}

	/** (b) The joined key contains one of these: catches one-word compounds (apitoken, smtppass, appsecret) and split words. */
	function d5b_secret_substrings(): array {
		return array( 'pass', 'pwd', 'secret', 'token', 'salt', 'nonce', 'cookie', 'session', 'credential', 'creds', 'signature', 'hmac', 'otp', 'totp', 'csrf', 'xsrf', 'jwt', 'bearer',
			'privkey', 'privatekey', 'apikey', 'licensekey', 'activationkey', 'accesskey', 'masterkey', 'hashkey', 'authkey', 'authsalt',
			'passcode', 'passphrase', 'mnemonic', 'seedphrase', 'pincode', 'dsn', 'connectionstring', 'webhook', 'sklive', 'sktest', 'stripesk', 'twofactor', 'mfacode',
			'authorization', 'oauth', 'xauth' );
	}

	/**
	 * The only joined keys exempt from the union: harmless words that hold a secret word inside them. Fail-safe: anything
	 * else either rule flags stays hidden. A key added here needs its reason in the report.
	 */
	function d5b_secret_harmless(): array {
		return array( 'compass', 'bypass', 'bypasscache', 'passenger', 'footprint', 'hotpink', 'basalt', 'monkey', 'turkey', 'hotkey',
			'author', 'authors', 'authorid', 'authorname', 'authority', 'authored', 'authorized',
			'layoutkey', 'modulekey', 'hash', 'metakey', 'menukey' );
	}

	/**
	 * True when $key names a secret: the token rule (a) OR the substring rule (b), minus the exact harmless list. Key-name
	 * rules are heuristics, so the rule is built to hide when in doubt.
	 */
	function d5b_is_secret_key( $key ): bool {
		static $memo = array(); // the masker asks about the same few words thousands of times in a long text
		if ( ! is_scalar( $key ) ) { return false; }
		$mk = (string) $key;
		if ( isset( $memo[ $mk ] ) ) { return $memo[ $mk ]; }
		if ( count( $memo ) >= 4096 ) { $memo = array(); }
		return $memo[ $mk ] = d5b_secret_key_decide( $key );
	}

	/** The rule itself, without the memo. */
	function d5b_secret_key_decide( $key ): bool {
		$joined = d5b_secret_key_norm( $key );
		if ( '' === $joined || in_array( $joined, d5b_secret_harmless(), true ) ) { return false; }
		foreach ( d5b_secret_substrings() as $w ) { if ( false !== strpos( $joined, $w ) ) { return true; } }
		// ends in "auth": basicauth, httpauth, smtpauth (author and authority do not end in it)
		if ( 'auth' === substr( $joined, -4 ) ) { return true; }
		return d5b_secret_token_rule( $key, $joined );
	}

	/** (a) The token and compound rule of round 2. */
	function d5b_secret_token_rule( $key, string $joined ): bool {
		if ( in_array( $joined, d5b_secret_exact(), true ) ) { return true; }
		foreach ( d5b_secret_compounds() as $c ) { if ( false !== strpos( $joined, $c ) ) { return true; } }
		$tokens = d5b_secret_key_tokens( $key );
		if ( array_intersect( $tokens, d5b_secret_tokens() ) ) { return true; }
		if ( array( 'key' ) === $tokens ) { return true; }
		// a lone "key" after an owner word; version numbers in between do not count (api2key, api_2_key)
		$words = array_values( array_filter( $tokens, function ( $t ) { return ! ctype_digit( $t ); } ) );
		foreach ( $words as $i => $t ) {
			if ( 'key' === $t && $i > 0 && in_array( $words[ $i - 1 ], d5b_secret_key_owners(), true ) ) { return true; }
		}
		return false;
	}

	/**
	 * Value shapes that are a secret wherever they sit, also inside post content: high confidence only, so ordinary text
	 * is left alone. Each pattern matches exactly the part that is replaced. PEM private keys are masked by
	 * d5b_secret_mask_pem(), not here.
	 */
	function d5b_secret_value_patterns(): array {
		return array(
			'/(?<![A-Za-z0-9])(?:sk_live|rk_live|sk_test)_[A-Za-z0-9]{8,}/',                  // Stripe secret and restricted keys
			'/(?<![A-Za-z0-9_-])eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', // a JSON Web Token
			'/\$(?:P|H)\$[.\/A-Za-z0-9]{8,}/',                                                // phpass password hashes
			'/\$wp\$[.\/A-Za-z0-9$]{8,}/',                                                    // WordPress 6.8+ bcrypt hashes
			'/\$2[abxy]\$[.\/A-Za-z0-9$]{8,}/',                                               // bcrypt
			'/\$argon2(?:id|i|d)\$[^\s\'"]{8,}/',                                             // argon2
			// a bearer token: 20+ characters with a digit or one of ._-~+/ in it (the word stays; "Bearer instrumentalisation" is prose)
			'/\bBearer\s+\K(?=[A-Za-z0-9\-._~+\/]*[0-9._\-~+\/])[A-Za-z0-9\-._~+\/]{20,}=*/',
		);
	}

	/**
	 * PEM private keys: from the BEGIN line through the END line. Without an END line, only the BEGIN line and the base64
	 * lines right after it (16+ characters, or ending in =) go; the first other line and everything after it stay.
	 */
	function d5b_secret_mask_pem( string $s ): string {
		$out = ''; $pos = 0;
		while ( preg_match( '/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $s, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$start = $m[0][1];
			$after = $start + strlen( $m[0][0] );
			if ( preg_match( '/-----END [A-Z ]*PRIVATE KEY-----/', $s, $e, PREG_OFFSET_CAPTURE, $after ) ) {
				$end = $e[0][1] + strlen( $e[0][0] );
			} else {
				$nl  = strpos( $s, "\n", $after );
				$end = false === $nl ? strlen( $s ) : $nl; // the rest of the BEGIN line
				while ( false !== $nl && preg_match( '/\G\r?\n(?:[A-Za-z0-9+\/]{16,}={0,2}|[A-Za-z0-9+\/]*={1,2})\r?(?=\n|$)/', $s, $b, 0, $nl ) ) {
					$end = $nl + strlen( rtrim( $b[0], "\r" ) );
					$nl  = strpos( $s, "\n", $end );
				}
			}
			$out .= substr( $s, $pos, $start - $pos ) . '[redacted]';
			$pos  = $end;
		}
		return $out . substr( $s, $pos );
	}

	/** Cheap test before the patterns run: none of the shapes can be in a string without one of these. */
	function d5b_secret_may_hold_shape( string $s ): bool {
		foreach ( array( 'PRIVATE KEY', '_live_', 'sk_test_', 'eyJ', '$', 'Bearer' ) as $needle ) {
			if ( false !== strpos( $s, $needle ) ) { return true; }
		}
		return false;
	}

	/**
	 * How many result strings the scrubber changed (masked by shape, by the free-text masker, or redacted as a pair value) since the last reset. The run wrapper resets it before scrubbing a
	 * result and reads it afterwards, to add "masked": true to the result.
	 */
	function d5b_secret_masked_count( bool $reset = false, int $add = 0 ): int {
		static $n = 0;
		if ( $reset ) { $n = 0; }
		$n += $add;
		return $n;
	}

	/** Replaces every high-confidence secret shape in $s with [redacted]; the rest of the text is kept. */
	function d5b_secret_mask_values( string $s ): string {
		if ( '' === $s || ! d5b_secret_may_hold_shape( $s ) ) { return $s; }
		$out = false !== strpos( $s, 'PRIVATE KEY' ) ? d5b_secret_mask_pem( $s ) : $s;
		$out = preg_replace( d5b_secret_value_patterns(), '[redacted]', $out );
		$out = is_string( $out ) ? $out : '[redacted]'; // a regex failure (a backtrack limit) fails closed
		return $out;
	}
}
