<?php
/**
 * Shortcode for manual custom-ordered queries.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_Shortcode
 *
 * Provides:
 *
 *   [pcpo_posts post_type="product" taxonomy="product_cat" term="electronics"
 *               order_mode="manual" limit="10" template="card"]
 *
 *   [pcpo_query post_type="post" order_mode="popularity" limit="5"]
 *      <li><a href="{permalink}">{title}</a> — {date}</li>
 *   [/pcpo_query]
 *
 * The first form is a self-contained card grid (handy for builders/editors).
 * The second form is a templating-friendly version where the inner content is
 * the per-post template (with {placeholder} tokens).
 */
class PCPO_Shortcode {

	/**
	 * Database.
	 *
	 * @var PCPO_Database
	 */
	private $db;

	/**
	 * Settings.
	 *
	 * @var PCPO_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param PCPO_Database $db       Database.
	 * @param PCPO_Settings $settings Settings.
	 */
	public function __construct( $db, $settings ) {
		$this->db       = $db;
		$this->settings = $settings;

		add_shortcode( 'pcpo_posts', array( $this, 'shortcode_posts' ) );
		add_shortcode( 'pcpo_query', array( $this, 'shortcode_query' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_styles' ) );
	}

	/**
	 * Conditionally enqueue the frontend stylesheet when our shortcode is on the page.
	 */
	public function maybe_enqueue_styles() {
		global $post;
		if ( $post && ( has_shortcode( $post->post_content, 'pcpo_posts' ) || has_shortcode( $post->post_content, 'pcpo_query' ) ) ) {
			wp_enqueue_style(
				'pcpo-frontend',
				PCPO_URL . 'assets/css/frontend.css',
				array(),
				PCPO_VERSION
			);
		}
	}

	/**
	 * [pcpo_posts] — self-contained card grid output.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML.
	 */
	public function shortcode_posts( $atts ) {
		$atts = shortcode_atts(
			array(
				'post_type'  => 'post',
				'taxonomy'   => '',
				'term'       => '', // Slug or ID.
				'term_id'    => 0,
				'order_mode' => 'manual',
				'limit'      => 10,
				'columns'    => 3,
				'template'   => 'card', // 'card' | 'list' | 'minimal'.
				'show'       => 'thumbnail,title,excerpt,date', // Comma list.
				'class'      => '',
			),
			$atts,
			'pcpo_posts'
		);

		$query = $this->run_query( $atts );
		if ( ! $query->have_posts() ) {
			return '<div class="pcpo-empty">' . esc_html__( 'No posts found.', 'power-custom-post-order' ) . '</div>';
		}

		$show    = array_map( 'trim', explode( ',', $atts['show'] ) );
		$columns = max( 1, min( 6, absint( $atts['columns'] ) ) );
		$tmpl    = sanitize_key( $atts['template'] );
		$class   = sanitize_html_class( $atts['class'] );

		ob_start();
		echo '<div class="pcpo-grid pcpo-grid--' . esc_attr( $tmpl ) . ' pcpo-grid--cols-' . esc_attr( $columns ) . ' ' . esc_attr( $class ) . '" data-pcpo-grid="1">';
		while ( $query->have_posts() ) {
			$query->the_post();
			echo '<article class="pcpo-card" data-id="' . esc_attr( (string) get_the_ID() ) . '">';
			if ( in_array( 'thumbnail', $show, true ) && has_post_thumbnail() ) {
				echo '<a class="pcpo-card__thumb" href="' . esc_url( get_permalink() ) . '">';
				the_post_thumbnail( 'medium' );
				echo '</a>';
			}
			echo '<div class="pcpo-card__body">';
			if ( in_array( 'title', $show, true ) ) {
				echo '<h3 class="pcpo-card__title"><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
			}
			if ( in_array( 'excerpt', $show, true ) ) {
				echo '<div class="pcpo-card__excerpt">' . wp_kses_post( get_the_excerpt() ) . '</div>';
			}
			if ( in_array( 'date', $show, true ) ) {
				echo '<div class="pcpo-card__meta"><time>' . esc_html( get_the_date() ) . '</time></div>';
			}
			if ( in_array( 'price', $show, true ) && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( get_the_ID() );
				if ( $product ) {
					echo '<div class="pcpo-card__price">' . wp_kses_post( $product->get_price_html() ) . '</div>';
				}
			}
			echo '</div></article>';
		}
		echo '</div>';
		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * [pcpo_query]inner template{title}{permalink}{excerpt}{thumbnail}{price}{date}{id}[/pcpo_query]
	 *
	 * @param array  $atts    Attributes.
	 * @param string $content Inner template.
	 * @return string Rendered HTML.
	 */
	public function shortcode_query( $atts, $content = '' ) {
		$atts = shortcode_atts(
			array(
				'post_type'  => 'post',
				'taxonomy'   => '',
				'term'       => '',
				'term_id'    => 0,
				'order_mode' => 'manual',
				'limit'      => 10,
				'wrapper'    => 'ul',
				'wrap_class' => 'pcpo-list',
			),
			$atts,
			'pcpo_query'
		);

		$query = $this->run_query( $atts );
		if ( ! $query->have_posts() ) {
			return '';
		}

		$wrapper = sanitize_key( $atts['wrapper'] );
		$class   = sanitize_html_class( $atts['wrap_class'] );

		$out = '<' . esc_attr( $wrapper ) . ' class="' . esc_attr( $class ) . '">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$id        = get_the_ID();
			$price     = '';
			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $id );
				if ( $product ) {
					$price = $product->get_price_html();
				}
			}
			$replacements = array(
				'{id}'        => (string) $id,
				'{title}'     => esc_html( get_the_title() ),
				'{permalink}' => esc_url( get_permalink() ),
				'{excerpt}'   => wp_kses_post( get_the_excerpt() ),
				'{thumbnail}' => get_the_post_thumbnail( $id, 'medium' ),
				'{date}'      => esc_html( get_the_date() ),
				'{price}'     => $price,
			);
			$line = do_shortcode( $content );
			$line = strtr( $line, $replacements );
			$out .= $line;
		}
		$out .= '</' . esc_attr( $wrapper ) . '>';
		wp_reset_postdata();
		return $out;
	}

	/**
	 * Build and run the actual WP_Query with PCPO ordering applied.
	 *
	 * @param array $atts Shortcode atts (already merged with defaults).
	 * @return WP_Query
	 */
	private function run_query( array $atts ) {
		$post_type = sanitize_key( $atts['post_type'] );
		$taxonomy  = sanitize_key( $atts['taxonomy'] );
		$term_id   = absint( $atts['term_id'] );
		$term      = sanitize_title( (string) $atts['term'] );
		$mode      = sanitize_key( $atts['order_mode'] );
		$limit     = max( -1, (int) $atts['limit'] );

		if ( $term && ! $term_id && $taxonomy ) {
			$t = get_term_by( 'slug', $term, $taxonomy );
			if ( ! $t ) {
				$t = get_term_by( 'name', $term, $taxonomy );
			}
			if ( $t && ! is_wp_error( $t ) ) {
				$term_id = (int) $t->term_id;
			}
		}

		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
			'pcpo_apply'     => array(
				'context'   => array(
					'taxonomy' => $taxonomy,
					'term_id'  => $term_id,
				),
				'mode'      => $mode,
				'direction' => 'ASC',
				'post_type' => $post_type,
			),
		);
		if ( $taxonomy && $term_id ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			);
		}
		return new WP_Query( $args );
	}
}
