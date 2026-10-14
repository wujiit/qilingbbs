<?php
/**
 * 社区 UID 与号段规则计算引擎
 * 负责号段范围判定、数字过滤（如排除含4）、靓号特征模式算法分析与官方保留拦截
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 号段规则与特征识别服务
 */
class Qibbs_UID_Rule_Service {

    /**
     * 单例实例
     *
     * @var Qibbs_UID_Rule_Service|null
     */
    private static $instance = null;

    /**
     * 运行期解析缓存
     *
     * @var array
     */
    private $parsed_rules = array();

    /**
     * 获取单例实例
     *
     * @return Qibbs_UID_Rule_Service
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取 UID 全局配置
     *
     * @param bool $refresh Whether to force refresh from database.
     * @return array
     */
    public function get_settings( $refresh = false ) {
        if ( function_exists( 'qibbs_get_options_store' ) ) {
            $options = qibbs_get_options_store( null, $refresh );
        } elseif ( function_exists( 'qibbs_get_options' ) ) {
            $options = qibbs_get_options();
        } else {
            $options = get_option( 'qibbs_options', array() );
        }
        $options = is_array( $options ) ? $options : array();

        $defaults = array(
            'uid_free_start'       => 10000,
            'uid_reserved_ranges'  => '1-100',
            'uid_reserved_singles' => '10086,110,119,120,999',
            'uid_vanity_ranges'    => '',
        );

        return array(
            'free_start'       => isset( $options['uid_free_start'] ) ? max( 1, absint( $options['uid_free_start'] ) ) : $defaults['uid_free_start'],
            'reserved_ranges'  => isset( $options['uid_reserved_ranges'] ) ? (string) $options['uid_reserved_ranges'] : $defaults['uid_reserved_ranges'],
            'reserved_singles' => isset( $options['uid_reserved_singles'] ) ? (string) $options['uid_reserved_singles'] : $defaults['uid_reserved_singles'],
            'vanity_ranges'    => isset( $options['uid_vanity_ranges'] ) ? (string) $options['uid_vanity_ranges'] : $defaults['uid_vanity_ranges'],
        );
    }

    /**
     * 判定指定号码是否属于系统官方保留号（不公开分配）
     * 仅按管理员指定的保留号段区间与指定保留单号判定
     *
     * @param int $cuid Number to check.
     * @return bool
     */
    public function is_reserved( $cuid ) {
        $cuid = absint( $cuid );
        if ( $cuid <= 0 ) {
            return true;
        }

        $settings = $this->get_settings();

        // 1. 检查官方保留指定独立单号
        $singles = $this->parse_number_list( $settings['reserved_singles'] );
        if ( in_array( $cuid, $singles, true ) ) {
            return true;
        }

        // 2. 检查官方保留号段区间（如 1-100）
        $ranges = $this->parse_range_list( $settings['reserved_ranges'] );
        foreach ( $ranges as $range ) {
            if ( $cuid >= $range['start'] && $cuid <= $range['end'] ) {
                return true;
            }
        }

        return (bool) apply_filters( 'qibbs_uid_is_reserved', false, $cuid );
    }

    /**
     * 判定指定号码是否属于商业销售靓号
     * 仅限显式配置的商业销售号段，或已录入官方池的号码
     *
     * @param int $cuid Number to check.
     * @return bool
     */
    public function is_vanity( $cuid ) {
        $cuid = absint( $cuid );
        if ( $cuid <= 0 ) {
            return false;
        }

        // 1. 如果算法命中靓号特征模式 (Grade 1-4，如1-4位短号、连号、豹子等)，直接判定为靓号
        if ( $this->detect_pattern_grade( $cuid ) > 0 ) {
            return true;
        }

        $settings = $this->get_settings();

        // 2. 检查是否在管理员显式配置的商业靓号号段内
        $vanity_ranges = $this->parse_vanity_ranges( $settings['vanity_ranges'] );
        foreach ( $vanity_ranges as $vr ) {
            if ( $cuid >= $vr['start'] && $cuid <= $vr['end'] ) {
                return true;
            }
        }

        // 3. 检查是否已被录入官方靓号池
        global $wpdb;
        $vanity_table = Qibbs_Database::vanity_uids();
        $in_pool      = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT cuid FROM {$vanity_table} WHERE cuid = %d LIMIT 1", $cuid )
        );
        if ( $in_pool > 0 ) {
            return true;
        }

        return (bool) apply_filters( 'qibbs_uid_is_vanity', false, $cuid );
    }

    /**
     * 检测号码的靓号品相等级 (0: 普通非靓号, 1: 普靓, 2: 银牌, 3: 极品, 4: 至尊天花板)
     *
     * @param int|string $cuid Number to check.
     * @return int Grade (0-4).
     */
    public function detect_pattern_grade( $cuid ) {
        $str = (string) absint( $cuid );
        $len = strlen( $str );
        if ( $len <= 0 ) {
            return 0;
        }

        $settings   = $this->get_settings();
        $free_start = (int) $settings['free_start'];

        // 仅当低于普通号起始值时，极短号才作为天然靓号
        if ( $cuid < $free_start && $len <= 3 ) {
            if ( $len <= 2 ) {
                return 4;
            }
            if ( 3 === $len ) {
                return 3;
            }
        }

        // 1. 全相同数字（炸弹/天尊号，如 666, 8888, 99999, 888888）-> 4级
        if ( preg_match( '/^(\d)\1+$/', $str ) ) {
            return 4;
        }

        // 2. 尾数4连非零相同（如 128888, 599999）-> 4级
        if ( preg_match( '/([1-9])\1{3,}$/', $str ) ) {
            return 4;
        }

        // 3. 连续顺子 (如 1234, 5678, 9876，至少4位递增或递减) -> 3级
        if ( $len >= 4 && $this->is_sequential( $str, 4 ) ) {
            return 3;
        }

        // 4. 尾数3连非零相同 (如 12666, 35888) -> 3级
        if ( preg_match( '/([1-9])\1{2}$/', $str ) ) {
            return 3;
        }

        // 5. 对子重复模式: AABB / ABAB (如 6688, 1212) -> 2级
        if ( preg_match( '/^(\d)\1(\d)\2$/', $str ) || ( $len >= 4 && preg_match( '/^(\d{2})\1+$/', $str ) ) ) {
            return 2;
        }

        // 6. 自然短号：仅当小于普通号起始值时才按自然短号估值，避免把用户设定的普通号（如 101/1000 开始）误判为靓号
        if ( $cuid < $free_start ) {
            if ( 4 === $len ) {
                return 2;
            }
            if ( 5 === $len ) {
                return 1;
            }
        }

        return (int) apply_filters( 'qibbs_uid_pattern_grade', 0, $cuid, $len );
    }

    public function get_default_grade_definitions() {
        return array(
            1 => array( 'name' => __( '普靓', 'qibbs-community' ), 'points' => 300, 'cash' => 3.00 ),
            2 => array( 'name' => __( '银牌靓号', 'qibbs-community' ), 'points' => 800, 'cash' => 8.00 ),
            3 => array( 'name' => __( '极品靓号', 'qibbs-community' ), 'points' => 2000, 'cash' => 20.00 ),
            4 => array( 'name' => __( '至尊天花板', 'qibbs-community' ), 'points' => 5000, 'cash' => 50.00 ),
        );
    }

    public function get_grade_definitions() {
        $options  = function_exists( 'qibbs_get_options' ) ? qibbs_get_options() : get_option( 'qibbs_options', array() );
        $custom   = $options['uid_grade_definitions'] ?? null;
        $defaults = $this->get_default_grade_definitions();
        if ( ! is_array( $custom ) || empty( $custom ) ) {
            return $defaults;
        }

        $merged = array();
        foreach ( $defaults as $g => $def ) {
            $merged[ $g ] = array(
                'name'   => ! empty( $custom[ $g ]['name'] ) ? sanitize_text_field( $custom[ $g ]['name'] ) : $def['name'],
                'points' => isset( $custom[ $g ]['points'] ) ? max( 0, absint( $custom[ $g ]['points'] ) ) : $def['points'],
                'cash'   => isset( $custom[ $g ]['cash'] ) ? max( 0.0, (float) $custom[ $g ]['cash'] ) : $def['cash'],
            );
        }
        return $merged;
    }

    public function get_grade_label( $grade ) {
        $grade = absint( $grade );
        $defs  = $this->get_grade_definitions();
        return isset( $defs[ $grade ]['name'] ) ? $defs[ $grade ]['name'] : sprintf( __( '%d级靓号', 'qibbs-community' ), $grade );
    }

    public function get_grade_labels_map() {
        $defs = $this->get_grade_definitions();
        $map  = array();
        foreach ( $defs as $g => $info ) {
            $map[ $g ] = $info['name'];
        }
        return $map;
    }

    /**
     * 获取指定靓号的推荐参考定价
     *
     * @param int $cuid Number to check.
     * @return int Recommended price in points.
     */
    public function get_reference_price( $cuid ) {
        $cuid = absint( $cuid );
        $settings = $this->get_settings();
        $vanity_ranges = $this->parse_vanity_ranges( $settings['vanity_ranges'] );

        // 匹配号段配置中的基准价格
        foreach ( $vanity_ranges as $vr ) {
            if ( $cuid >= $vr['start'] && $cuid <= $vr['end'] && $vr['price'] > 0 ) {
                return (int) apply_filters( 'qibbs_uid_base_price', $vr['price'], $cuid );
            }
        }

        // 按品相等级计算基准定价（从自定义品相配置中读取）
        $grade = $this->detect_pattern_grade( $cuid );
        $defs  = $this->get_grade_definitions();
        $price = isset( $defs[ $grade ]['points'] ) ? $defs[ $grade ]['points'] : 300;
        return (int) apply_filters( 'qibbs_uid_base_price', $price, $cuid, $grade );
    }

    /**
     * 获取指定靓号的推荐参考定价（现金/元）
     *
     * @param int $cuid Number to check.
     * @return float Recommended cash price.
     */
    public function get_reference_cash_price( $cuid ) {
        $cuid  = absint( $cuid );
        $grade = $this->detect_pattern_grade( $cuid );
        $defs  = $this->get_grade_definitions();
        $cash  = isset( $defs[ $grade ]['cash'] ) ? $defs[ $grade ]['cash'] : 3.00;
        return (float) apply_filters( 'qibbs_uid_base_cash_price', $cash, $cuid, $grade );
    }

    /**
     * 辅助算法：检查字符串是否包含指定长度的连续递增或递减顺子
     *
     * @param string $str Numeric string.
     * @param int    $min_len Minimum sequential length.
     * @return bool
     */
    private function is_sequential( $str, $min_len = 4 ) {
        $len = strlen( $str );
        if ( $len < $min_len ) {
            return false;
        }

        $inc_count = 1;
        $dec_count = 1;

        for ( $i = 1; $i < $len; $i++ ) {
            $prev = (int) $str[ $i - 1 ];
            $curr = (int) $str[ $i ];

            if ( $curr === ( $prev + 1 ) % 10 ) {
                $inc_count++;
                if ( $inc_count >= $min_len ) {
                    return true;
                }
            } else {
                $inc_count = 1;
            }

            if ( $curr === ( $prev - 1 + 10 ) % 10 ) {
                $dec_count++;
                if ( $dec_count >= $min_len ) {
                    return true;
                }
            } else {
                $dec_count = 1;
            }
        }

        return false;
    }

    /**
     * 解析逗号/换行分隔的离散数字列表
     *
     * @param string $raw Raw input.
     * @return array
     */
    public function parse_number_list( $raw ) {
        $parts = preg_split( '/[\s,]+/', (string) $raw );
        $list = array();
        foreach ( (array) $parts as $p ) {
            $val = absint( trim( $p ) );
            if ( $val > 0 ) {
                $list[] = $val;
            }
        }
        return array_values( array_unique( $list ) );
    }

    /**
     * 解析区间规则列表（如 1-100, 1000-1999）
     *
     * @param string $raw Raw input.
     * @return array Array of array{start:int, end:int}.
     */
    public function parse_range_list( $raw ) {
        $lines = preg_split( '/[\r\n,]+/', (string) $raw );
        $ranges = array();
        foreach ( (array) $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            if ( preg_match( '/^(\d+)\s*-\s*(\d+)$/', $line, $matches ) ) {
                $start = absint( $matches[1] );
                $end   = absint( $matches[2] );
                if ( $start > 0 && $end >= $start ) {
                    $ranges[] = array(
                        'start' => $start,
                        'end'   => $end,
                    );
                }
            }
        }
        return $ranges;
    }

    /**
     * 解析带价格的靓号号段列表（如 101-999:1000）
     *
     * @param string $raw Raw input.
     * @return array Array of array{start:int, end:int, price:int}.
     */
    public function parse_vanity_ranges( $raw ) {
        $lines = preg_split( '/[\r\n]+/', (string) $raw );
        $ranges = array();
        foreach ( (array) $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            if ( preg_match( '/^(\d+)\s*-\s*(\d+)(?:\s*:\s*(\d+))?$/', $line, $matches ) ) {
                $start = absint( $matches[1] );
                $end   = absint( $matches[2] );
                $price = isset( $matches[3] ) ? absint( $matches[3] ) : 0;
                if ( $start > 0 && $end >= $start ) {
                    $ranges[] = array(
                        'start' => $start,
                        'end'   => $end,
                        'price' => $price,
                    );
                }
            }
        }
        return $ranges;
    }
}
