<?php
/**
 * 访问受限提示模板
 *
 * @package Qibbs_Community
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$context = isset( $GLOBALS['qibbs_access_restricted_context'] ) && is_array( $GLOBALS['qibbs_access_restricted_context'] )
    ? $GLOBALS['qibbs_access_restricted_context']
    : array();

$type = isset( $context['type'] ) ? sanitize_key( $context['type'] ) : 'guest';
$is_blacklist = ( 'blacklist' === $type );

$default_title = $is_blacklist
    ? qibbs_get_option( 'blacklist_notice_title', __( '你的账号已被限制访问社区', 'qibbs-community' ) )
    : qibbs_get_option( 'guest_access_notice_title', __( '当前功能仅限已登录用户访问', 'qibbs-community' ) );
$title = isset( $context['title'] ) ? $context['title'] : $default_title;
$title = is_string( $title ) ? trim( $title ) : '';
if ( '' === $title ) {
    $title = $is_blacklist
        ? __( '你的账号已被限制访问社区', 'qibbs-community' )
        : __( '当前功能仅限已登录用户访问', 'qibbs-community' );
}

$default_text = $is_blacklist
    ? qibbs_get_option( 'blacklist_notice_text', __( '如有疑问请联系管理员。', 'qibbs-community' ) )
    : qibbs_get_option( 'guest_access_notice_text', __( '请先登录后继续访问社区功能。', 'qibbs-community' ) );
$notice_text = isset( $context['text'] ) ? $context['text'] : $default_text;
$notice_text = is_string( $notice_text ) ? trim( $notice_text ) : '';
if ( '' === $notice_text ) {
    $notice_text = $is_blacklist
        ? __( '如有疑问请联系管理员。', 'qibbs-community' )
        : __( '请先登录后继续访问社区功能。', 'qibbs-community' );
}

$show_login = array_key_exists( 'show_login', $context ) ? (bool) $context['show_login'] : ( ! $is_blacklist );
$show_home = array_key_exists( 'show_home', $context ) ? (bool) $context['show_home'] : true;
$login_label = isset( $context['login_label'] ) ? (string) $context['login_label'] : __( '立即登录', 'qibbs-community' );
$home_label = isset( $context['home_label'] ) ? (string) $context['home_label'] : __( '返回首页', 'qibbs-community' );
$home_url = isset( $context['home_url'] ) ? esc_url_raw( (string) $context['home_url'] ) : home_url( '/' );

get_header();

$layout_service = new Qibbs_Layout_Service();
$theme_attr = $layout_service->get_theme_attribute();
?>

<div class="qibbs-wrapper"<?php echo $theme_attr; ?>>
    <section class="qibbs-access-restricted">
        <div class="qibbs-access-card">
            <div class="qibbs-access-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="5" y="11" width="14" height="10" rx="2"></rect>
                    <path d="M8 11V8a4 4 0 1 1 8 0v3"></path>
                </svg>
            </div>
            <h1 class="qibbs-access-title"><?php echo esc_html( $title ); ?></h1>
            <p class="qibbs-access-text"><?php echo nl2br( esc_html( $notice_text ) ); ?></p>
            <div class="qibbs-access-actions">
                <?php if ( $show_login ) : ?>
                    <?php echo Qibbs_Theme_Compatibility::get_login_button_html( 'qibbs-btn qibbs-btn-primary qibbs-access-login', $login_label ); ?>
                <?php endif; ?>
                <?php if ( $show_home ) : ?>
                    <a href="<?php echo esc_url( $home_url ); ?>" class="qibbs-btn qibbs-btn-outline qibbs-access-home"><?php echo esc_html( $home_label ); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php
get_footer();
