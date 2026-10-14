<?php
/**
 * 工单通知异步推送服务
 *
 * 通过 Webhook 渠道异步投递新工单提醒
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Ticket_Push_Service {
    /**
     * WP-Cron 异步推送新工单通知钩子
     */
    const CRON_HOOK = 'qibbs_ticket_push_event';

    /**
     * 将新工单推送推入 WP-Cron 异步队列，防止阻塞用户请求
     *
     * @param int $ticket_id Ticket ID.
     * @param int $user_id   Submitter user ID.
     * @return void
     */
    public function queue_new_ticket_notification( $ticket_id, $user_id ) {
        if ( ! qibbs_get_option( 'enable_ticket_push_notifications', false ) ) {
            return;
        }

        $ticket_id = absint( $ticket_id );
        $user_id = absint( $user_id );
        if ( ! $ticket_id || ! $user_id ) {
            return;
        }

        $args = array( $ticket_id, $user_id );
        if ( wp_next_scheduled( self::CRON_HOOK, $args ) ) {
            return;
        }

        wp_schedule_single_event( time() + 5, self::CRON_HOOK, $args );
    }

    /**
     * 消费队列并向目标渠道推送新工单通知
     *
     * @param int $ticket_id Ticket ID.
     * @param int $user_id   Submitter user ID.
     * @return void
     */
    public function notify_new_ticket( $ticket_id, $user_id ) {
        if ( ! qibbs_get_option( 'enable_ticket_push_notifications', false ) ) {
            return;
        }

        if ( ! function_exists( 'qilinghook_send' ) ) {
            return;
        }

        $ticket_id = absint( $ticket_id );
        $user_id = absint( $user_id );
        if ( ! $ticket_id || ! $user_id ) {
            return;
        }

        $channels = $this->get_push_channels();
        if ( empty( $channels ) ) {
            return;
        }

        // 保证单张工单通知投递的幂等性（防重复触发）
        $dedupe_key = 'qibbs_ticket_push_sent_' . $ticket_id;
        if ( get_transient( $dedupe_key ) ) {
            return;
        }

        $ticket_service = new Qibbs_Ticket_Service();
        $ticket = $ticket_service->get_ticket( $ticket_id );
        if ( ! $ticket ) {
            return;
        }

        $message = $this->build_message( $ticket, $ticket_service );
        if ( '' === $message ) {
            return;
        }

        $args = array(
            'type'   => 'text',
            'source' => 'qibbs_ticket_new',
        );

        $success_count = 0;
        foreach ( $channels as $channel_id ) {
            $result = qilinghook_send( $channel_id, $message, $args );
            if ( ! is_wp_error( $result ) ) {
                $success_count++;
            }
        }

        if ( $success_count > 0 ) {
            set_transient( $dedupe_key, 1, DAY_IN_SECONDS );
        }
    }

    /**
     * 构建推送消息载荷
     *
     * @param object               $ticket         Ticket row.
     * @param Qibbs_Ticket_Service $ticket_service Ticket service instance.
     * @return string
     */
    private function build_message( $ticket, $ticket_service ) {
        $ticket_id = isset( $ticket->id ) ? absint( $ticket->id ) : 0;
        if ( ! $ticket_id ) {
            return '';
        }

        $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $title_prefix = trim( (string) qibbs_get_option( 'ticket_push_title_prefix', '新工单通知' ) );
        if ( '' === $title_prefix ) {
            $title_prefix = '新工单通知';
        }

        $message = '[' . $site_name . '] ' . $title_prefix;

        $author_name = __( '未知用户', 'qibbs-community' );
        if ( ! empty( $ticket->author ) && $ticket->author instanceof WP_User ) {
            $author_name = $ticket->author->display_name;
        } elseif ( ! empty( $ticket->user_id ) ) {
            $user = get_userdata( absint( $ticket->user_id ) );
            if ( $user ) {
                $author_name = $user->display_name;
            }
        }

        $category_name = __( '未分类', 'qibbs-community' );
        if ( ! empty( $ticket->category ) && ! empty( $ticket->category->name ) ) {
            $category_name = $ticket->category->name;
        } elseif ( ! empty( $ticket->category_id ) ) {
            $category_service = new Qibbs_Ticket_Category_Service();
            $category = $category_service->get( absint( $ticket->category_id ) );
            if ( $category && ! empty( $category->name ) ) {
                $category_name = $category->name;
            }
        }

        $subject = ! empty( $ticket->subject ) ? sanitize_text_field( (string) $ticket->subject ) : '';
        $priority = ! empty( $ticket->priority ) ? $ticket_service->get_priority_label( $ticket->priority ) : '';
        $created_at = ! empty( $ticket->created_at ) ? sanitize_text_field( (string) $ticket->created_at ) : current_time( 'mysql' );
        $admin_url = admin_url( 'admin.php?page=qibbs-tickets&ticket_id=' . $ticket_id );

        $lines = array(
            '工单号'   => '#' . $ticket_id,
            '标题'     => $subject,
            '分类'     => $category_name,
            '优先级'   => $priority,
            '提交用户' => $author_name,
            '提交时间' => $created_at,
            '后台查看' => $admin_url,
        );

        $content = $this->build_plain_text_message( $lines );
        if ( '' !== $content ) {
            $message .= "\n\n" . $content;
        }

        return $message;
    }

    /**
     * 标准化并获取目标推送通道 ID
     *
     * @return array
     */
    private function get_push_channels() {
        $raw_channels = qibbs_get_option( 'ticket_push_channels', array() );
        $raw_channels = is_array( $raw_channels ) ? $raw_channels : array();

        $channels = array();
        foreach ( $raw_channels as $channel_id ) {
            $clean_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $channel_id );
            if ( '' !== $clean_id ) {
                $channels[] = $clean_id;
            }
        }

        return array_values( array_unique( $channels ) );
    }

    /**
     * 构建纯文本格式的推送正文
     *
     * @param array $lines Content lines.
     * @return string
     */
    private function build_plain_text_message( $lines ) {
        if ( empty( $lines ) || ! is_array( $lines ) ) {
            return '';
        }

        $formatted = array();
        foreach ( $lines as $label => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'strval', $value ) );
            }

            $clean_value = trim( wp_strip_all_tags( (string) $value ) );
            if ( '' === $clean_value ) {
                continue;
            }

            if ( is_string( $label ) && '' !== $label && ! is_numeric( $label ) ) {
                $formatted[] = wp_strip_all_tags( $label ) . ': ' . $clean_value;
            } else {
                $formatted[] = $clean_value;
            }
        }

        return implode( "\n", $formatted );
    }
}
