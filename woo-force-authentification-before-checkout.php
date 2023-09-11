<?php
/*
Plugin Name: Soft-Force Authentification Before Checkout for WooCommerce
Description: Soft-Force customer to log in or register before checkout
Version: 1.0.0
Author: Zubasoft
Author URI: https://zubasoft.at

License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Text Domain: wc-soft-force-auth
Domain Path: /languages
*/

if ( ! defined( 'WPINC' ) ) die();

class WC_Soft_Force_Auth_Before_Checkout {

	const FILE = __FILE__;
	const URL_ARG = 'redirect_to_checkout';
  const PLUGIN_NAME = 'wc_force_auth_';

	protected static $_instance = null;

	protected function __construct () {
		add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	protected function is_woocommerce_installed () {
		return function_exists( 'WC' );
	}

	protected function has_query_param () {
		return isset( $_GET[ self::URL_ARG ] );
	}

	protected function get_login_page_url () {
		return apply_filters( self::PLUGIN_NAME . 'login_page_url',
			get_permalink( get_option( 'woocommerce_myaccount_page_id' ) )
		);
	}

	protected function get_checkout_page_url () {
		return apply_filters( self::PLUGIN_NAME . 'checkout_page_url', wc_get_checkout_url() );
	}

	public function init () {
		if ( ! $this->is_woocommerce_installed() ) {
			add_action( 'admin_notices', [ $this, 'add_admin_notice' ] );
			return;
		}

		add_action( 'init', [ $this, 'load_plugin_textdomain' ] );

		add_action( 'template_redirect', [ $this, 'redirect_to_account_page' ] );
		add_action( 'wp_head', [ $this, 'add_wc_notice' ] );

		add_filter( 'woocommerce_registration_redirect', [ $this, 'redirect_to_checkout' ], 100 );
		add_filter( 'woocommerce_login_redirect', [ $this, 'redirect_to_checkout' ], 100 );
		add_action( 'wp_head', [ $this, 'redirect_to_checkout_via_html' ] );

    add_action( 'woocommerce_after_customer_login_form', [ $this, 'add_guest_login_html' ] );

    add_action( 'admin_post_nopriv_wc_force_auth_guest_login', [$this, 'wc_force_auth_guest_login']);

    // WooCommerce PayPal Plugin - Express Checkout
    add_filter('ppcp_create_order_request_body_data', [ $this, 'wc_force_ppcp_create_order_request_body_data' ]);
  }

    function wc_force_ppcp_create_order_request_body_data(array $data): array {
        error_log('test PayPal');

        if(is_user_logged_in() === false) {
            error_log('PayPal - set guest login');

            $session_name = self::PLUGIN_NAME . 'guest_checkout';
            if ( isset( $_SESSION[ $session_name ] ) ) {
                unset( $_SESSION[ $session_name ] );
            }
            $_SESSION[ $session_name ] = 'guest_login';
        }

        return $data;
    }

    public function wc_force_auth_guest_login() {
      $nonceName = self::PLUGIN_NAME . 'guest_nonce';
        if( isset( $_POST[$nonceName] ) &&
            wp_verify_nonce( $_POST[$nonceName], $nonceName) ) {

            $session_name = self::PLUGIN_NAME . 'guest_checkout';
            if ( isset( $_SESSION[ $session_name ] ) ) {
                unset( $_SESSION[ $session_name ] );
            }
            $_SESSION[ $session_name ] = 'guest_login';

            // redirect the user to the appropriate page
            wp_safe_redirect( add_query_arg( self::URL_ARG, '', $this->get_checkout_page_url() ) );
            die;
        } else {
            wp_die( __( 'Invalid nonce specified', self::PLUGIN_NAME ), __( 'Error', self::PLUGIN_NAME ), array(
                'response' 	=> 403

            ) );
        }
    }

  public function add_guest_login_html() {
      $wc_force_auth_guest_nonce = wp_create_nonce( self::PLUGIN_NAME . 'guest_nonce' );

    ?>
    <div class="wc-soft-force-auth">
      <h2><?php esc_html_e( 'Ohne Kundenkonto bestellen', 'wc-soft-force-auth' ); ?></h2>

      <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="woocommerce-form woocommerce-form-guest guest">
        <input type="hidden" name="action" value="wc_force_auth_guest_login">
        <input type="hidden" name="wc_force_auth_guest_nonce" value="<?php echo $wc_force_auth_guest_nonce ?>" />

        <p class="woocommerce-form-row form-row">
            <?php esc_html_e( 'Mit dem Bestellprozess fortfahren ohne ein Kundenkonto zu erstellen. Sie können natürlich auch später jederzeit ein Kundenkonto bei uns erstellen.', 'wc-soft-force-auth' ); ?>
        </p>
        <p class="woocommerce-form-row form-row">
          <button type="submit" class="woocommerce-Button woocommerce-button button woocommerce-form-guest__submit" name="guest" value="guest"><?php esc_html_e( 'Als Gast bestellen', 'wc-soft-force-auth' ); ?></button>
        </p>
      </form>

    </div>
    <?php
  }

	public function redirect_to_account_page () {
    $session_name = self::PLUGIN_NAME . 'guest_checkout';

		$condition = apply_filters(
			self::PLUGIN_NAME . 'redirect_to_account_page',
			is_checkout() && (!is_user_logged_in() && isset( $_SESSION[ $session_name ] ) === FALSE)
		);

		if( $condition ) {
			wp_safe_redirect( add_query_arg( self::URL_ARG, '', $this->get_login_page_url() ) );
			die;
		}
	}

	public function redirect_to_checkout_via_html () {
		if ( $this->has_query_param() && is_user_logged_in() ) {
			?>
			<meta
				http-equiv="Refresh"
				content="0; url='<?php echo esc_attr( $this->get_checkout_page_url() ); ?>'"
			/>
			<?php
			exit();
		}
	}

	public function redirect_to_checkout ( $redirect ) {
		if ( $this->has_query_param() ) {
			$redirect = $this->get_checkout_page_url();
		}
		return $redirect;
	}

	public function get_alert_message () {
		return apply_filters( self::PLUGIN_NAME . 'message', __( 'Bitte melden Sie sich an, oder erstellen ein Konto, um die Bestellung abzuschließen.', 'wc-soft-force-auth' ) );
	}

	public function add_wc_notice () {
		if ( ! is_user_logged_in() && is_account_page() && $this->has_query_param() ) {
			wc_add_notice( $this->get_alert_message(), 'notice' );
		}
	}

	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'wc-soft-force-auth', false, dirname( plugin_basename( self::FILE ) ) . '/languages/' );

      if(!session_id()) {
        session_start();
      }
	}

	public function add_admin_notice () {
		?>
		<div class="notice notice-error">
			<p>
				<?php echo esc_html__( 'You need install and activate the WooCommerce plugin.', 'wc-soft-force-auth' ) ?>
			</p>
		</div>
		<?php
	}

	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public static function activation () {
		$prefix = self::PLUGIN_NAME;
		delete_option( $prefix . 'donation_notice_dismissed' );
	}

	public static function deactivation () {
		$prefix = self::PLUGIN_NAME;
		$cookie_name = $prefix . 'donation_notice_dismissed';
		if ( isset( $_COOKIE[ $cookie_name ] ) ) {
			unset( $_COOKIE[ $cookie_name ] );
			setcookie( $cookie_name, null, -1 );
		}
	}
}

WC_Soft_Force_Auth_Before_Checkout::get_instance();

register_activation_hook( __FILE__, [ WC_Soft_Force_Auth_Before_Checkout::class, 'activation' ] );
register_deactivation_hook( __FILE__, [ WC_Soft_Force_Auth_Before_Checkout::class, 'deactivation' ] );
