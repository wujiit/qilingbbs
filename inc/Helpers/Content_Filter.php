<?php
/**
 * 内容安全过滤助手
 * 处理内容中的视频链接解析
 *
 * @package Qibbs_Community
 * @subpackage Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 解析内容中的视频链接
 * 
 * 支持：
 * - 普通视频链接 (.mp4, .webm, .mov, .avi)
 * - B站视频链接 (bilibili.com/video/BVxxxxxx)
 * - B站短链接 (b23.tv/xxxxx)
 *
 * @param string $content 原始内容
 * @return string 处理后的内容
 */
function qibbs_parse_video_links( $content ) {
    // 1. 解析普通视频链接 (.mp4, .webm, .mov, .avi)
    // 匹配以视频扩展名结尾的URL，可能带有查询参数
    $video_pattern = '/(https?:\/\/[^\s<\"\'\)]+\.(mp4|webm|mov|avi)(\?[^\s<\"\'\)]*)?)/i';
    $content = preg_replace_callback( $video_pattern, 'qibbs_video_embed_callback', $content );
    
    // 2. 解析 B站视频链接 (完整链接)
    // 匹配 bilibili.com/video/BVxxxxxxxxxx 格式
    $bilibili_pattern = '/(https?:\/\/(www\.)?bilibili\.com\/video\/(BV[a-zA-Z0-9]+)([^\s<\"\'\)]*)?)/i';
    $content = preg_replace_callback( $bilibili_pattern, 'qibbs_bilibili_embed_callback', $content );
    
    // 3. 解析 B站短链接 (b23.tv)
    $bilibili_short_pattern = '/(https?:\/\/b23\.tv\/([a-zA-Z0-9]+))/i';
    $content = preg_replace_callback( $bilibili_short_pattern, 'qibbs_bilibili_short_callback', $content );

    // 4. 解析网易云音乐链接
    // 匹配 song?id= 之后的数字ID
    $netease_pattern = '/(https?:\/\/music\.163\.com\/.*song\?id=([0-9]+)(?:&[^"\s]*)?)/i';
    $content = preg_replace_callback( $netease_pattern, 'qibbs_netease_music_embed_callback', $content );
    
    return $content;
}

/**
 * 普通视频嵌入回调
 * 
 * @param array $matches 正则匹配结果
 * @return string HTML 视频播放器
 */
function qibbs_video_embed_callback( $matches ) {
    $video_url = esc_url( $matches[1] );
    $extension = strtolower( $matches[2] );
    
    // 映射扩展名到 MIME 类型
    $mime_types = array(
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mov'  => 'video/quicktime',
        'avi'  => 'video/x-msvideo',
    );
    $mime_type = isset( $mime_types[ $extension ] ) ? $mime_types[ $extension ] : 'video/mp4';
    
    // 使用单行 HTML 避免 nl2br 破坏结构
    return '<div class="qibbs-video-player"><video controls preload="metadata" playsinline><source src="' . $video_url . '" type="' . $mime_type . '">' . esc_html__( '您的浏览器不支持视频播放', 'qibbs-community' ) . '</video></div>';
}

/**
 * B站视频嵌入回调
 * 
 * @param array $matches 正则匹配结果
 * @return string HTML B站播放器 iframe
 */
function qibbs_bilibili_embed_callback( $matches ) {
    $bv_id = sanitize_text_field( $matches[3] );
    
    // 使用单行 HTML 避免 nl2br 破坏结构
    return '<div class="qibbs-bilibili-player"><iframe src="https://player.bilibili.com/player.html?bvid=' . esc_attr( $bv_id ) . '&autoplay=0&high_quality=1&danmaku=0" scrolling="no" border="0" frameborder="no" framespacing="0" allowfullscreen="true" loading="lazy"></iframe></div>';
}

/**
 * B站短链接处理回调
 * 
 * 由于 b23.tv 是短链接需要重定向才能获取实际 BV 号，
 * 这里渲染为一个可点击的链接按钮
 * 
 * @param array $matches 正则匹配结果
 * @return string HTML 链接按钮
 */
function qibbs_bilibili_short_callback( $matches ) {
    $short_url = esc_url( $matches[1] );
    $short_code = sanitize_text_field( $matches[2] );
    
    // 检查短代码是否看起来像 BV 号
    if ( strpos( strtoupper( $short_code ), 'BV' ) === 0 ) {
        // 如果短代码以 BV 开头，直接嵌入（单行 HTML）
        return '<div class="qibbs-bilibili-player"><iframe src="https://player.bilibili.com/player.html?bvid=' . esc_attr( $short_code ) . '&autoplay=0&high_quality=1&danmaku=0" scrolling="no" border="0" frameborder="no" framespacing="0" allowfullscreen="true" loading="lazy"></iframe></div>';
    }
    
    // 否则渲染为链接按钮（单行 HTML）
    return '<a href="' . $short_url . '" class="qibbs-bilibili-link" target="_blank" rel="noopener noreferrer"><svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M17.813 4.653h.854c1.51.054 2.769.578 3.773 1.574 1.004.995 1.524 2.249 1.56 3.76v7.36c-.036 1.51-.556 2.769-1.56 3.773s-2.262 1.524-3.773 1.56H5.333c-1.51-.036-2.769-.556-3.773-1.56S.036 18.858 0 17.347v-7.36c.036-1.511.556-2.765 1.56-3.76 1.004-.996 2.262-1.52 3.773-1.574h.774l-1.174-1.12a1.234 1.234 0 0 1-.373-.906c0-.356.124-.659.373-.907l.027-.027c.267-.249.573-.373.92-.373.347 0 .653.124.92.373L9.653 4.44c.071.071.134.142.187.213h4.267c.053-.071.116-.142.187-.213L17.147 1.64c.267-.249.573-.373.92-.373.347 0 .653.124.92.373l.027.027c.249.248.373.551.373.907 0 .355-.124.658-.373.906l-1.201 1.133zM5.333 7.24c-.746.018-1.373.276-1.88.773-.506.498-.769 1.13-.787 1.893v7.52c.018.764.281 1.395.787 1.893.507.498 1.134.756 1.88.773h13.334c.746-.017 1.373-.275 1.88-.773.506-.498.769-1.129.787-1.893V9.906c-.018-.764-.281-1.395-.787-1.893-.507-.497-1.134-.755-1.88-.773H5.333zm4 3.893c.355 0 .658.124.907.373.248.248.373.551.373.907v3.2c0 .355-.125.658-.373.907a1.236 1.236 0 0 1-.907.373h-1.6a1.236 1.236 0 0 1-.907-.373 1.236 1.236 0 0 1-.373-.907v-3.2c0-.356.124-.659.373-.907.249-.249.551-.373.907-.373h1.6zm8 0c.355 0 .658.124.907.373.248.248.373.551.373.907v3.2c0 .355-.125.658-.373.907a1.236 1.236 0 0 1-.907.373h-1.6a1.236 1.236 0 0 1-.907-.373 1.236 1.236 0 0 1-.373-.907v-3.2c0-.356.124-.659.373-.907.249-.249.551-.373.907-.373h1.6z"/></svg>' . esc_html__( '点击查看B站视频', 'qibbs-community' ) . '</a>';
}

/**
 * 网易云音乐嵌入回调
 * 
 * @param array $matches 正则匹配结果
 * @return string HTML 网易云播放器 iframe
 */
function qibbs_netease_music_embed_callback( $matches ) {
    $song_id = sanitize_text_field( $matches[2] );
    
    // 返回 iframe 代码
    return '<div class="qibbs-netease-player"><iframe frameborder="no" border="0" marginwidth="0" marginheight="0" width=330 height=86 src="//music.163.com/outchain/player?type=2&id=' . esc_attr( $song_id ) . '&auto=0&height=66"></iframe></div>';
}

/**
 * 获取允许视频播放器的 wp_kses 标签
 * 
 * 扩展 wp_kses_post 允许的标签，添加 iframe、video、source 支持
 * 仅允许来自可信来源的 iframe (bilibili.com)
 *
 * @return array 允许的标签数组
 */
function qibbs_get_allowed_video_tags() {
    // 获取 wp_kses_post 默认允许的标签
    $allowed_tags = wp_kses_allowed_html( 'post' );
    
    // 添加 video 标签及其属性
    $allowed_tags['video'] = array(
        'src'      => true,
        'controls' => true,
        'preload'  => true,
        'autoplay' => true,
        'loop'     => true,
        'muted'    => true,
        'poster'   => true,
        'width'    => true,
        'height'   => true,
        'class'    => true,
        'id'       => true,
        'style'    => true,
        'playsinline' => true,
    );
    
    // 添加 source 标签
    $allowed_tags['source'] = array(
        'src'  => true,
        'type' => true,
    );
    
    // 添加 iframe 标签（用于 B站视频和网易云音乐）
    $allowed_tags['iframe'] = array(
        'src'             => true,
        'width'           => true,
        'height'          => true,
        'frameborder'     => true,
        'scrolling'       => true,
        'allowfullscreen' => true,
        'allow'           => true,
        'class'           => true,
        'id'              => true,
        'style'           => true,
        'loading'         => true,
        'border'          => true,
        'framespacing'    => true,
        'marginwidth'     => true,
        'marginheight'    => true,
    );
    
    return $allowed_tags;
}

/**
 * 安全输出包含视频的内容
 * 
 * 使用自定义的允许标签列表来过滤内容，支持视频播放器
 *
 * @param string $content 内容
 * @return string 过滤后的内容
 */
function qibbs_kses_video_content( $content ) {
    return wp_kses( $content, qibbs_get_allowed_video_tags() );
}

