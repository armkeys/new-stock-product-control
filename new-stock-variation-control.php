
<?php
/**
 * Plugin Name: New Stock Product Control
 * Description: Auto-manages WooCommerce Products in the "New Stock" category. Displays only newly added products (≤30 days old) and includes admin tools.
 * Version: 1.2.0
 * Author: ARM
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class New_Stock_Variation_Control {

	const CATEGORY_SLUG   = 'new-stock';
	const EXCLUDE_META    = '_vaspfw_exclude_variation';
	const SIMPLE_PROCESSED_META  = '_new_simple_processed';
	const VARIATION_PROCESSED_META  = '_new_variation_processed';
	const DEFAULT_NEW_META = '_nsvc_is_new';

	public function __construct() {
		add_action( 'save_post_product', [ $this, 'check_and_flag_simple' ], 10, 2 );
		add_action( 'woocommerce_save_product_variation', [ $this, 'check_and_flag_variation' ], 10, 4 );
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_post_nsvc_cleanup', [ $this, 'run_filter' ] );
		add_action( 'admin_post_nsvc_reset', [ $this, 'reset_all_new_stocks' ] );

		add_action( 'init', function() {

			$current_url = rtrim( $_SERVER['REQUEST_URI'], '/' );
		
			if (strpos( $current_url, 'new-stock' ) !== false ) {
					
				$plugin_public_path = WP_PLUGIN_DIR . '/variations-as-single-products-for-woocommerce/public/class-variations-as-single-products-for-woocommerce-public.php';
		
				if ( file_exists( $plugin_public_path ) ) {
					require_once $plugin_public_path;

	
					// Instantiate and run the public-facing hooks
					if ( class_exists( 'Variations_As_Single_Products_For_Woocommerce_Public' ) ) {
						$vasp = new Variations_As_Single_Products_For_Woocommerce_Public( 'vaspfw', '1.0.0' );
			
						add_action( 'woocommerce_product_query', [ $vasp, 'woocommerce_product_query' ], 99999 );
						add_filter( 'woocommerce_shortcode_products_query', [ $vasp, 'shortcode_products_query' ], 99999 );
						add_action( 'pre_get_posts', [ $vasp, 'change_jet_filters_query' ] );
						add_action( 'pre_get_posts', [ $vasp, 'add_variations_to_search_results' ], 99999 );
						add_filter( 'posts_clauses', [ $vasp, 'posts_clauses' ], 99999, 2 );
						add_filter( 'the_title', [ $vasp, 'variable_product_title' ], 10, 2 );
						add_filter( 'woocommerce_subcategory_count_html', [ $vasp, 'subcategory_count' ], 10, 2 );
						add_filter( 'woocommerce_get_filtered_term_product_counts_query', [ $vasp, 'filtered_term_product_counts_query' ], 10 );
						add_filter( 'exclude_attribute_products', [ $vasp, 'exclude_attribute_products' ], 10 );
					}
				}
			}
		}, 5 );
	}

	public static function get_dynamic_range() {
		$start = get_option('nsvc_start_date', date('Y-m-d', strtotime('-30 days')));
		$end = get_option('nsvc_end_date', date('Y-m-d'));
	
		// Basic validation
		if ( ! strtotime($start) || ! strtotime($end) || strtotime($start) > strtotime($end) ) {
			$start = date('Y-m-d', strtotime('-30 days'));
			$end = date('Y-m-d');
		}
	
		return apply_filters(
			'nsvc_dynamic_range',
			[ strtotime($start . ' 00:00:00'), strtotime($end . ' 23:59:59') ]
		);
	}
	
	public function check_and_flag_simple( $post_id, $post ) {
		try {
			error_log('NSVC check_and_flag_simple: ID=' . print_r($post_id, true));
			if ( $post->post_type !== 'product' ) return;
	
			$product = wc_get_product( $post_id );
			if ( ! $product || $product->is_type( 'variable' ) ) return;
	
			$cat = get_term_by( 'slug', self::CATEGORY_SLUG, 'product_cat' );
			if ( ! $cat || is_wp_error( $cat ) ) return;
	
			$terms = wp_get_post_terms( $post_id, 'product_cat', [ 'fields' => 'ids' ] );
			if ( empty( $terms ) || ! in_array( $cat->term_id, $terms ) ) return;
	
			if ( get_post_meta( $post_id, self::SIMPLE_PROCESSED_META , true ) === 'yes' ) return;
	
			$created = get_post_field( 'post_date', $post_id );
			$created_ts = strtotime( $created );
			if ( ! $created || ! $created_ts ) return;
	
			//$is_new = ( $created_ts >= strtotime( '-30 days' ) );
			list($start_ts, $end_ts) = self::get_dynamic_range();
			$is_new = ($created_ts >= $start_ts && $created_ts <= $end_ts);

			update_post_meta( $post_id, self::DEFAULT_NEW_META, $is_new ? 'yes' : 'no' );
	
			if ( ! $is_new ) {
				update_post_meta( $post_id, self::DEFAULT_NEW_META, 'yes' );
			} else {
				delete_post_meta( $post_id, self::DEFAULT_NEW_META );
			}
	
			update_post_meta( $post_id, self::SIMPLE_PROCESSED_META , 'yes' );
	
		} catch ( Throwable $e ) {
			error_log('[NSVC ERROR SIMPLE] ' . $e->getMessage());
		}
	}

	public function check_and_flag_variation( $variation_id, $i ) {
		try {
			error_log('NSVC check_and_flag_variation: ID=' . print_r($variation_id, true));
	
			if ( ! $variation_id ) return;
	
			$variation_post = get_post( $variation_id );
			if ( ! $variation_post || $variation_post->post_type !== 'product_variation' ) return;
	
			if ( get_post_meta( $variation_id, self::EXCLUDE_META, true ) === 'yes' ) return;
			if ( get_post_meta( $variation_id, self::VARIATION_PROCESSED_META, true ) === 'yes' ) return;
	
			$parent_id = wp_get_post_parent_id( $variation_id );
			if ( ! $parent_id ) return;
	
			$cat = get_term_by( 'slug', self::CATEGORY_SLUG, 'product_cat' );
			if ( ! $cat || is_wp_error( $cat ) ) return;
	
			$terms = wp_get_post_terms( $parent_id, 'product_cat', [ 'fields' => 'ids' ] );
			if ( empty( $terms ) || ! in_array( $cat->term_id, $terms ) ) return;
	
			$created = get_post_field( 'post_date', $variation_id );
			$created_ts = strtotime( $created );
			if ( ! $created || ! $created_ts ) return;
	
			//$is_new = ( $created_ts >= strtotime( '-30 days' ) );
			list($start_ts, $end_ts) = self::get_dynamic_range();
            $is_new = ($created_ts >= $start_ts && $created_ts <= $end_ts);

			update_post_meta( $variation_id, self::DEFAULT_NEW_META, $is_new ? 'yes' : 'no' );
	
			if ( ! $is_new ) {
				update_post_meta( $variation_id, self::DEFAULT_NEW_META, 'yes' );
			} else {
				delete_post_meta( $variation_id, self::DEFAULT_NEW_META );
			}
	
			update_post_meta( $variation_id, self::VARIATION_PROCESSED_META, 'yes' );
	
		} catch (Throwable $e) {
			error_log('[NSVC ERROR] ' . $e->getMessage());
			error_log($e->getTraceAsString());
		}
	}
	
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			'New Stock Control',
			'New Stock Control',
			'manage_woocommerce',
			'new-stock-variation-control',
			[ $this, 'admin_page_html' ]
		);
	}

	public function admin_page_html() {
		// Preserve submitted values (if any)
		$start_val = isset($_POST['start_date']) ? esc_attr($_POST['start_date']) : get_option('nsvc_start_date', date('Y-m-d', strtotime('-30 days')));
        $end_val   = isset($_POST['end_date'])   ? esc_attr($_POST['end_date'])   : get_option('nsvc_end_date', date('Y-m-d'));
	
		?>
		<div class="wrap">
			<?php
			if ( isset($_GET['success']) && $_GET['success'] == '1' ) {
				echo '<div class="notice notice-success is-dismissible"><p>Filtered New Stock successfully.</p></div>';
			}

			if ( isset($_GET['reset']) && $_GET['reset'] == '1' ) {
				echo '<div class="notice notice-warning is-dismissible"><p>All New Stock Product statuses have been reset.</p></div>';
			}
			if ( isset($_GET['manual_keep']) && $_GET['manual_keep'] == '1' ) {
				echo '<div class="notice notice-info is-dismissible"><p>Manual override set: selected products will be preserved from auto-reset.</p></div>';
			}
			?>
			<h1>New Stock Product Control</h1>
			<p>This tool manages product display in the <strong>"New Stock"</strong> category.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 20px;">
				<input type="hidden" name="action" value="nsvc_cleanup">
	
				<table class="form-table">
					<tr>
						<th scope="row"><label for="start_date">Start Date</label></th>
						<td><input type="date" name="start_date" id="start_date" value="<?php echo $start_val; ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="end_date">End Date</label></th>
						<td><input type="date" name="end_date" id="end_date" value="<?php echo $end_val; ?>" required></td>
					</tr>
				</table>
	
				<?php submit_button( 'Run Filter', 'primary', 'submit', false ); ?>
			</form>
	
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nsvc_reset">
				<?php submit_button( 'Reset New Stock Products', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
	


	// In run_filter() - Use posted start and end dates
	public function run_filter() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

		$cat = get_term_by( 'slug', self::CATEGORY_SLUG, 'product_cat' );
		if ( ! $cat || is_wp_error( $cat ) ) wp_die( 'Category not found' );

		$start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : null;
		$end_date   = isset($_POST['end_date'])   ? sanitize_text_field($_POST['end_date'])   : null;

		if ( ! $start_date || ! $end_date ) {
			wp_die( 'Start and End dates are required.' );
		}

		$start_ts = strtotime( $start_date . ' 00:00:00' );
		$end_ts   = strtotime( $end_date . ' 23:59:59' );

		update_option('nsvc_start_date', $start_date);
		update_option('nsvc_end_date', $end_date);

		$this->process_variations( $cat->term_id, function( $variation ) use ( $start_ts, $end_ts ) {
			$variation_ts = strtotime( $variation->post_date );
			if ( $variation_ts >= $start_ts && $variation_ts <= $end_ts ) {
				update_post_meta( $variation->ID, self::VARIATION_PROCESSED_META, 'yes' );
				update_post_meta( $variation->ID, '_nsvc_manual_keep', 'yes' ); 
			}
		} );

		$this->process_simple( $cat->term_id, function( $product ) use ( $start_ts, $end_ts ) {
			$ts = strtotime( $product->post_date );
			if ( $ts >= $start_ts && $ts <= $end_ts ) {
				update_post_meta( $product->ID, self::SIMPLE_PROCESSED_META , 'yes' );
				update_post_meta( $product->ID, '_nsvc_manual_keep', 'yes' ); 
			}
		} );

		wp_redirect( admin_url( 'admin.php?page=new-stock-variation-control&success=1&manual_keep=1' ) );

		exit;
	}


	public function reset_all_new_stocks() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

		$cat = get_term_by( 'slug', self::CATEGORY_SLUG, 'product_cat' );
		if ( ! $cat || is_wp_error( $cat ) ) wp_die( 'Category not found' );

		$this->process_variations( $cat->term_id, function( $variation ) {
			delete_post_meta( $variation->ID, self::VARIATION_PROCESSED_META );
			delete_post_meta( $variation->ID, self::DEFAULT_NEW_META );
			delete_post_meta( $variation->ID, '_nsvc_manual_keep' );
			
		} );
		
		$this->process_simple( $cat->term_id, function( $product ) {
			delete_post_meta( $product->ID, self::SIMPLE_PROCESSED_META );
			delete_post_meta( $product->ID, self::DEFAULT_NEW_META );
			delete_post_meta( $product->ID, '_nsvc_manual_keep' );
		} );

		wp_redirect( admin_url( 'admin.php?page=new-stock-variation-control&reset=1' ) );
		exit;
	}

	private function process_simple( $category_id, $callback ) {
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'tax_query'      => [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $category_id,
				],
			],
		];
	
		$products = get_posts( $args );
	
		foreach ( $products as $product ) {
			$wc_product = wc_get_product( $product->ID );
			if ( $wc_product && $wc_product->is_type( 'simple' ) ) {
				call_user_func( $callback, $product );
			}
		}
	}
	

	private function process_variations( $category_id, $callback ) {
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'tax_query'      => [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $category_id,
				],
			],
		];

		$products = get_posts( $args );

		foreach ( $products as $product ) {
			$variations = get_children( [
				'post_parent'    => $product->ID,
				'post_type'      => 'product_variation',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			] );

			foreach ( $variations as $variation ) {
				call_user_func( $callback, $variation );
			}
		}
	}
}

new New_Stock_Variation_Control();


/*** Auto Reset ***/

add_action( 'nsvc_daily_cleanup_hook', 'nsvc_daily_cleanup_callback' );
if ( ! wp_next_scheduled( 'nsvc_daily_cleanup_hook' ) ) {
	wp_schedule_event( time(), 'daily', 'nsvc_daily_cleanup_hook' );
}

function nsvc_daily_cleanup_callback() {
	list($start_ts, $end_ts) = New_Stock_Variation_Control::get_dynamic_range();

	$args = [
		'post_type'      => [ 'product', 'product_variation' ],
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'meta_query'     => [
			[
				'key'     => '_nsvc_is_new',
				'compare' => 'EXISTS'
			]
		]
	];

	$posts = get_posts( $args );

	foreach ( $posts as $post ) {
		$post_date = strtotime( $post->post_date );
		$manual_keep = get_post_meta( $post->ID, '_nsvc_manual_keep', true );

		// Compare against the dynamic range
		if ( ($post_date < $start_ts || $post_date > $end_ts) && $manual_keep !== 'yes' ) {
			delete_post_meta( $post->ID, '_nsvc_is_new' );
			delete_post_meta( $post->ID, '_new_simple_processed' );
			delete_post_meta( $post->ID, '_new_variation_processed' );
		}else{
			// Optionally expire manual override
			delete_post_meta( $post->ID, '_nsvc_manual_keep' );
		}
	}
}




/*** Set default sorting: category query control and newest first ***/

add_action('pre_get_posts', 'co_woocommerce_category_query_control', 9999999999, 1);
function co_woocommerce_category_query_control($query) {
	if ( is_admin() || ! $query->is_main_query() || ! is_product_category() ) {
		return;
	}

	// Apply sorting
	$query->set('orderby', 'date');
	$query->set('order', 'DESC');

	$term = get_queried_object();

	if ( isset($term->slug) && $term->slug === 'new-stock' ) {
		// Only include products and variations that meet criteria
		$query->set('post_type', [ 'product', 'product_variation' ]);

		$meta_query = [
            'relation' => 'OR',
            [
                'key'     => '_nsvc_is_new',
                'value'   => 'yes',
                'compare' => '='
            ],
            [
                'key'     => '_new_variation_processed',
                'value'   => 'yes',
                'compare' => '='
            ],
            [
                'key'     => '_new_simple_processed',
                'value'   => 'yes',
                'compare' => '='
            ]
        ];

		$query->set('meta_query', $meta_query);

		// Optional: force product_visibility for filtering
		$query->set('tax_query', [
			[
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => [ 'exclude-from-catalog' ],
				'operator' => 'NOT IN'
			]
		]);
	} else {
		$query->set('post_type', 'product');
	}
}


/*** Hide Non New Stock ***/

add_filter('woocommerce_product_is_visible', 'co_hide_non_newstock_variations', 9999999999999, 2);
function co_hide_non_newstock_variations($visible, $product_id) {
	if ( is_product_category('new-stock') && (get_post_type($product_id) === 'product_variation')) {
		$is_new = get_post_meta($product_id, '_nsvc_is_new', true);
		$is_processed = get_post_meta($product_id, '_new_variation_processed', true);

		if ($is_new !== 'yes' && $is_processed !== 'yes') {
			return false;
		}
	}

    if ( is_product_category('new-stock') && (get_post_type($product_id) === 'product')) {
		$is_new = get_post_meta($product_id, '_nsvc_is_new', true);
		$is_processed = get_post_meta($product_id, '_new_simple_processed', true);

		if ($is_new !== 'yes' && $is_processed !== 'yes') {
			return false;
		}
	}
	return $visible;
}


/*** Deativation ***/


register_deactivation_hook( __FILE__, 'nsvc_deactivate_cleanup' );
function nsvc_deactivate_cleanup() {
	wp_clear_scheduled_hook( 'nsvc_daily_cleanup_hook' );

	$meta_keys = [
		'_nsvc_is_new',
		'_new_simple_processed',
		'_new_variation_processed',
	];

	$args = [
		'post_type'      => [ 'product', 'product_variation' ],
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => [
			'relation' => 'OR',
			[
				'key'     => '_nsvc_is_new',
				'compare' => 'EXISTS',
			],
			[
				'key'     => '_new_simple_processed',
				'compare' => 'EXISTS',
			],
			[
				'key'     => '_new_variation_processed',
				'compare' => 'EXISTS',
			],
		],
	];

	$posts = get_posts( $args );

	foreach ( $posts as $post_id ) {
		foreach ( $meta_keys as $meta_key ) {
			delete_post_meta( $post_id, $meta_key );
		}
	}
}
