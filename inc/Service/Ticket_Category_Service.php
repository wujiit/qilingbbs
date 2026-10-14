<?php
/**
 * 工单分类管理服务类
 *
 * 负责工单分类的新增、编辑、排序及层级维护
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Ticket_Category_Service {

    /**
     * 获取所有工单分类列表
     *
     * @param bool $active_only Only return active categories.
     * @return array
     */
    public function get_categories( $active_only = true ) {
        global $wpdb;
        $table = Qibbs_Database::ticket_categories();

        $where = $active_only ? 'WHERE is_active = 1' : '';

        return $wpdb->get_results(
            "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, id ASC"
        );
    }

    /**
     * 根据 ID 查询单个工单分类
     *
     * @param int $id Category ID.
     * @return object|null
     */
    public function get( $id ) {
        global $wpdb;
        $table = Qibbs_Database::ticket_categories();

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ) );
    }

    /**
     * 根据别名 slug 查询工单分类
     *
     * @param string $slug Category slug.
     * @return object|null
     */
    public function get_by_slug( $slug ) {
        global $wpdb;
        $table = Qibbs_Database::ticket_categories();

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE slug = %s",
            $slug
        ) );
    }

    /**
     * 创建新工单分类
     *
     * @param string $name        Category name.
     * @param string $slug        Category slug.
     * @param string $description Category description.
     * @param int    $sort_order  Sort order.
     * @return int|false Category ID or false on failure.
     */
    public function create( $name, $slug = '', $description = '', $sort_order = 0, $sla_first_response_hours = 4, $sla_resolution_hours = 48 ) {
        global $wpdb;
        $table = Qibbs_Database::ticket_categories();

        $name = sanitize_text_field( $name );
        if ( empty( $name ) ) {
            return false;
        }

        $slug = empty( $slug ) ? sanitize_title( $name ) : sanitize_title( $slug );

        // 确保分类别名全局唯一
        $slug = $this->get_unique_slug( $slug );

        $sla_first_response_hours = max( 1, min( 720, absint( $sla_first_response_hours ) ) );
        $sla_resolution_hours = max( 1, min( 2160, absint( $sla_resolution_hours ) ) );

        $result = $wpdb->insert(
            $table,
            array(
                'name'        => $name,
                'slug'        => $slug,
                'description' => sanitize_textarea_field( $description ),
                'sort_order'  => absint( $sort_order ),
                'is_active'   => 1,
                'sla_first_response_hours' => $sla_first_response_hours,
                'sla_resolution_hours' => $sla_resolution_hours,
                'created_at'  => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * 更新指定工单分类信息
     *
     * @param int   $id   Category ID.
     * @param array $data Data to update.
     * @return bool
     */
    public function update( $id, $data ) {
        global $wpdb;
        $table = Qibbs_Database::ticket_categories();

        $allowed_fields = array( 'name', 'slug', 'description', 'sort_order', 'is_active', 'sla_first_response_hours', 'sla_resolution_hours' );
        $update_data = array();
        $format = array();

        foreach ( $data as $key => $value ) {
            if ( in_array( $key, $allowed_fields, true ) ) {
                if ( $key === 'name' ) {
                    $update_data[ $key ] = sanitize_text_field( $value );
                    $format[] = '%s';
                } elseif ( $key === 'slug' ) {
                    $update_data[ $key ] = sanitize_title( $value );
                    $format[] = '%s';
                } elseif ( $key === 'description' ) {
                    $update_data[ $key ] = sanitize_textarea_field( $value );
                    $format[] = '%s';
                } elseif ( in_array( $key, array( 'sla_first_response_hours', 'sla_resolution_hours' ), true ) ) {
                    $max = ( 'sla_first_response_hours' === $key ) ? 720 : 2160;
                    $update_data[ $key ] = max( 1, min( $max, absint( $value ) ) );
                    $format[] = '%d';
                } else {
                    $update_data[ $key ] = absint( $value );
                    $format[] = '%d';
                }
            }
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            array( 'id' => $id ),
            $format,
            array( '%d' )
        );

        return $result !== false;
    }

    /**
     * 删除指定工单分类
     *
     * @param int $id Category ID.
     * @return bool|WP_Error
     */
    public function delete( $id ) {
        global $wpdb;
        $table = Qibbs_Database::ticket_categories();
        $tickets_table = Qibbs_Database::tickets();

        // 检查分类下是否有关联工单
        $ticket_count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tickets_table} WHERE category_id = %d",
            $id
        ) );

        if ( $ticket_count > 0 ) {
            return new WP_Error( 'has_tickets', __( '该分类下还有工单，无法删除', 'qibbs-community' ) );
        }

        $result = $wpdb->delete(
            $table,
            array( 'id' => $id ),
            array( '%d' )
        );

        return $result !== false;
    }

    /**
     * 更新工单分类排序权重
     *
     * @param array $orders Array of category_id => sort_order.
     * @return bool
     */
    public function update_order( $orders ) {
        global $wpdb;
        $table = Qibbs_Database::ticket_categories();

        foreach ( $orders as $id => $order ) {
            $wpdb->update(
                $table,
                array( 'sort_order' => absint( $order ) ),
                array( 'id' => absint( $id ) ),
                array( '%d' ),
                array( '%d' )
            );
        }

        return true;
    }

    /**
     * 生成唯一的分类别名 slug
     *
     * @param string $slug Base slug.
     * @param int    $exclude_id ID to exclude from check.
     * @return string
     */
    private function get_unique_slug( $slug, $exclude_id = 0 ) {
        global $wpdb;
        $table = Qibbs_Database::ticket_categories();

        $original_slug = $slug;
        $counter = 1;

        while ( true ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE slug = %s AND id != %d",
                $slug, $exclude_id
            ) );

            if ( ! $existing ) {
                break;
            }

            $slug = $original_slug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * 初始化默认工单分类
     *
     * @return void
     */
    public function create_defaults() {
        $existing = $this->get_categories( false );

        if ( ! empty( $existing ) ) {
            return;
        }

        $defaults = array(
            array( 'name' => __( '产品咨询', 'qibbs-community' ), 'slug' => 'product', 'sort_order' => 1 ),
            array( 'name' => __( '售后服务', 'qibbs-community' ), 'slug' => 'support', 'sort_order' => 2 ),
            array( 'name' => __( '账号问题', 'qibbs-community' ), 'slug' => 'account', 'sort_order' => 3 ),
            array( 'name' => __( '其他问题', 'qibbs-community' ), 'slug' => 'other', 'sort_order' => 4 ),
        );

        foreach ( $defaults as $category ) {
            $this->create( $category['name'], $category['slug'], '', $category['sort_order'] );
        }
    }
}
