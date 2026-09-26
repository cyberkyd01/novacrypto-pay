<?php defined( 'ABSPATH' ) || exit; $primary = sanitize_hex_color( ncp()->settings->get( 'primary_color' ) ) ?: '#2563eb'; ?>
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">
<table role="presentation" width="600" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">
<tr><td style="background:<?php echo esc_attr( $primary ); ?>;color:#fff;padding:20px 28px;font-size:18px;font-weight:700;">
<?php if ( ncp()->settings->get( 'logo_url' ) ) : ?><img src="<?php echo esc_url( ncp()->settings->get( 'logo_url' ) ); ?>" alt="" style="max-height:40px;vertical-align:middle;margin-right:12px;"><?php endif; ?>
<?php echo esc_html( get_bloginfo( 'name' ) ); ?></td></tr>
<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
