<?php
/**
 * Standalone (theme-less) pay page. Vars: $title, $content
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php echo esc_html( $title . ' – ' . get_bloginfo( 'name' ) ); ?></title>
	<?php wp_print_styles( array( 'ncp-frontend' ) ); ?>
	<style><?php echo NCP_Frontend::inline_css(); // phpcs:ignore ?> body{margin:0;background:var(--ncp-page-bg,#f3f4f6);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}.ncp-standalone{max-width:720px;margin:32px auto;padding:0 16px}.ncp-standalone-brand{text-align:center;margin-bottom:16px;font-weight:600}</style>
</head>
<body class="ncp-page ncp-standalone-body <?php echo esc_attr( NCP_Frontend::theme_class() ); ?>">
	<div class="ncp-standalone">
		<div class="ncp-standalone-brand"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></div>
		<?php echo $content; // phpcs:ignore ?>
	</div>
	<?php wp_print_scripts( array( 'ncp-frontend' ) ); ?>
</body>
</html>
