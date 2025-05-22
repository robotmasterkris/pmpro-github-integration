<?php

if (!defined('ABSPATH')) exit;

class PMPro_GitHub_OAuth_Handler {

	/**
	 * ------------------------------------------------------------------
	 * Register hooks
	 * ------------------------------------------------------------------
	 */
	public function __construct() {
		add_action( 'init',           [ $this, 'handle_github_oauth' ] );
		add_action( 'wp_ajax_pmpro_github_oauth_callback',
		            [ $this, 'oauth_callback' ] );
		add_action( 'wp_ajax_nopriv_pmpro_github_oauth_callback',
		            [ $this, 'oauth_callback' ] );
	}

	/**
 * ------------------------------------------------------------------
 * Front-end entry point: Launch the GitHub OAuth handshake
 * URL param: ?pmpro_github_oauth=1
 * ------------------------------------------------------------------
 */
    public function handle_github_oauth() {

        /* 0.  query-arg present? */
        if ( ! isset( $_GET['pmpro_github_oauth'] ) ) {
            return;
        }
        if (PMPRO_GITHUB_VERBOSE) error_log( 'GH-OAuth ▶ param detected: pmpro_github_oauth=1' );

        /* 1.  logged-in check */
        if ( ! is_user_logged_in() ) {
            if (PMPRO_GITHUB_VERBOSE) error_log( 'GH-OAuth ▶ NOT logged in – sending to wp-login' );
            wp_safe_redirect( wp_login_url( home_url( $_SERVER['REQUEST_URI'] ) ) );
            exit;
        }

        $user_id = get_current_user_id();
        if (PMPRO_GITHUB_VERBOSE) error_log( 'GH-OAuth ▶ user_id = ' . $user_id );
		
		// if any followup sync jobs were scheduled, remove them at start of OAuth flow
		if( get_user_meta( $user_id, '_pmpro_github_followup_scheduled', true )) {
			delete_user_meta( $user_id, '_pmpro_github_followup_scheduled' );
		}

        /* 2.  linked / reconnect flags */
        $is_linked       = (bool) get_user_meta( $user_id, '_pmpro_github_username', true )
                        && (bool) get_user_meta( $user_id, '_pmpro_github_token',   true );
        $needs_reconnect = (bool) get_user_meta( $user_id, '_pmpro_github_reconnect_needed', true );

        if (PMPRO_GITHUB_VERBOSE) error_log( 'GH-OAuth ▶ is_linked=' . var_export( $is_linked, true ) .
                ' | needs_reconnect=' . var_export( $needs_reconnect, true ) );

        if ( $is_linked && ! $needs_reconnect ) {
            $clean = remove_query_arg( 'pmpro_github_oauth', home_url( $_SERVER['REQUEST_URI'] ) );
            if (PMPRO_GITHUB_VERBOSE) error_log( 'GH-OAuth ▶ already linked, stripping param → ' . $clean );
            wp_safe_redirect( $clean );
            exit;
        }

        /* 3.  duplicate handshake guard */
        if ( get_transient( 'pmpro_github_oauth_in_progress_' . $user_id ) ) {
            if (PMPRO_GITHUB_VERBOSE) error_log( 'GH-OAuth ▶ in-progress transient found – aborting' );
            wp_safe_redirect( home_url( '/github-linked-error' ) );
            exit;
        }
        set_transient( 'pmpro_github_oauth_in_progress_' . $user_id, true, 300 );
        if (PMPRO_GITHUB_VERBOSE) error_log( 'GH-OAuth ▶ transient set, proceeding to GitHub' );

        /* 4.  save clean return-to URL */
        $return_url = remove_query_arg( 'pmpro_github_oauth', home_url( $_SERVER['REQUEST_URI'] ) );
        set_transient( 'pmpro_github_oauth_redirect_' . $user_id, $return_url, 300 );
        if (PMPRO_GITHUB_VERBOSE) error_log( 'GH-OAuth ▶ return_url = ' . $return_url );

        /* 5.  build authorise URL & redirect */
        $state = wp_generate_password( 24, false );
        update_user_meta( $user_id, '_pmpro_github_state', $state );

        $oauth_url = add_query_arg(
            [
                'client_id'    => get_option( 'pmpro_github_client_id' ),
                'redirect_uri' => admin_url( 'admin-ajax.php?action=pmpro_github_oauth_callback' ),
                'scope'        => 'read:org,write:org,user:email',
                'state'        => $state,
            ],
            'https://github.com/login/oauth/authorize'
        );

        if (PMPRO_GITHUB_VERBOSE) error_log( 'GH-OAuth ▶ redirecting to GitHub: ' . $oauth_url );
        wp_safe_redirect( $oauth_url );
        exit;
    }

	/**
	 * ------------------------------------------------------------------
	 * AJAX callback – exchange the code, store token & username
	 * ------------------------------------------------------------------
	 */
	public function oauth_callback() {

		error_log( 'OAuth callback initiated.' );

		$user_id = get_current_user_id();
		delete_transient( 'pmpro_github_oauth_in_progress_' . $user_id );

		/* -------------------------------------------------------------
		 * 2-a.  Verify state
		 * ------------------------------------------------------------- */
		if ( empty( $_GET['state'] ) ||
		     $_GET['state'] !== get_user_meta( $user_id, '_pmpro_github_state', true )
		) {
			wp_safe_redirect( home_url( '/github-linked-error?reason=state' ) );
			exit;
		}
		delete_user_meta( $user_id, '_pmpro_github_state' );

		/* -------------------------------------------------------------
		 * 2-b.  Exchange code → access token
		 * ------------------------------------------------------------- */
		if ( empty( $_GET['code'] ) ) {
			if (PMPRO_GITHUB_VERBOSE) error_log( 'OAuth code missing in callback.' );
			wp_safe_redirect( home_url( '/github-linked-error' ) );
			exit;
		}

		$code = sanitize_text_field( $_GET['code'] );
		if (PMPRO_GITHUB_VERBOSE) error_log( 'OAuth code received: ' . $code );

		$token_resp = wp_remote_post(
			'https://github.com/login/oauth/access_token',
			array_merge(
				pmpro_github_http_defaults(),
				[
					'body'    => [
						'client_id'     => get_option( 'pmpro_github_client_id' ),
						'client_secret' => get_option( 'pmpro_github_client_secret' ),
						'code'          => $code,
					],
					'headers' => [ 'Accept' => 'application/json' ],
				]
			)
		);

		if ( is_wp_error( $token_resp ) ) {
			if (PMPRO_GITHUB_VERBOSE) error_log( 'OAuth access token error: ' . $token_resp->get_error_message() );
			wp_safe_redirect( home_url( '/github-linked-error' ) );
			exit;
		}

		$token_body = json_decode( wp_remote_retrieve_body( $token_resp ) );
		if (PMPRO_GITHUB_VERBOSE) error_log( 'OAuth access token response: ' . print_r( $token_body, true ) );

		if ( wp_remote_retrieve_response_code( $token_resp ) !== 200 ||
		     empty( $token_body->access_token )
		) {
			wp_safe_redirect( home_url( '/github-linked-error?reason=token' ) );
			exit;
		}

		/* -------------------------------------------------------------
		 * 2-c.  Fetch GitHub username
		 * ------------------------------------------------------------- */
		$user_resp = wp_remote_get(
			'https://api.github.com/user',
			pmpro_github_http_defaults( [
				'headers' => [
					'Authorization' => 'token ' . $token_body->access_token,
					'User-Agent'    => PMPRO_GITHUB_USER_AGENT,
				],
			] )
		);

		if ( is_wp_error( $user_resp ) ) {
			if (PMPRO_GITHUB_VERBOSE) error_log( 'GitHub user request error: ' . $user_resp->get_error_message() );
			wp_safe_redirect( home_url( '/github-linked-error' ) );
			exit;
		}

		$user_data = json_decode( wp_remote_retrieve_body( $user_resp ) );

		if ( empty( $user_data->login ) ) {
			wp_safe_redirect( home_url( '/github-linked-error' ) );
			exit;
		}
		if (PMPRO_GITHUB_VERBOSE) error_log( 'GitHub user data response: ' . print_r( $user_data->login, true ) );

		/* -------------------------------------------------------------
		 * 2-d.  Store username & token
		 * ------------------------------------------------------------- */
		update_user_meta( $user_id, '_pmpro_github_username', $user_data->login );
        update_user_meta($user_id, '_pmpro_github_uid', (int) $user_data->id);                  // numeric ID from the /user endpoint
		update_user_meta( $user_id, '_pmpro_github_token', robotwealth_encrypt_value( $token_body->access_token ) );

		// clear reconnect flag if it existed
		delete_user_meta( $user_id, '_pmpro_github_reconnect_needed' );

		// enqueue sync
		as_enqueue_async_action( 'pmpro_github_sync_user', [ $user_id ] );

		/* -------------------------------------------------------------
		 * 2-e.  Redirect back (clean URL)
		 * ------------------------------------------------------------- */
		$redirect_back = get_transient( 'pmpro_github_oauth_redirect_' . $user_id );
		delete_transient( 'pmpro_github_oauth_redirect_' . $user_id );

		if ( $redirect_back ) {
			$redirect_back = remove_query_arg( 'pmpro_github_oauth', $redirect_back );
		} else {
			$redirect_back = home_url( '/account-details/' );
		}

		if (PMPRO_GITHUB_VERBOSE) error_log( 'Redirecting user back to: ' . $redirect_back );
		wp_safe_redirect( $redirect_back );
		exit;
	}
}


/**
 * Derive a binary key using sodium_crypto_generichash.
 */
function robotwealth_derive_key() {
    return sodium_crypto_generichash(
        'pmpro_github|' . AUTH_KEY . SECURE_AUTH_KEY
    );
}

/**
 * Encrypt before saving to the DB.
 */
function robotwealth_encrypt_value($plaintext) {
    $key   = robotwealth_derive_key();   // 32-byte binary
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher= sodium_crypto_secretbox($plaintext, $nonce, $key);
    return base64_encode($nonce . $cipher);
}

/**
 * Decrypt after reading from the DB.
 */
function robotwealth_decrypt_value( $stored ) {
    if ( empty( $stored ) ) {
        return false;
    }

    $decoded = base64_decode( $stored, true );        // strict mode
    if ( $decoded === false ||
         strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
        return false;                                 // malformed
    }

    $nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
    $cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
    $key    = robotwealth_derive_key();

    try {
        return sodium_crypto_secretbox_open( $cipher, $nonce, $key );
    } catch ( SodiumException $e ) {
        return false;                                 // tampered or old format
    }
}

