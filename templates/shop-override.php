<?php
/**
 * Template Name: Smart Order Builder Shop Override
 *
 * Renders the order builder shortcode inside the theme's header and footer.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

get_header();
?>
<div id="primary" class="content-area sob-shop-page-wrapper" style="background: #f5f7fb; padding: 40px 0;">
	<main id="main" class="site-main" role="main">
		<div class="container">
			<?php
			echo do_shortcode( '[smart_order_builder]' );
			?>
		</div>
	</main>
</div>
<?php
get_footer();
