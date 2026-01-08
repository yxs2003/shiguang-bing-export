<?php
/*
Plugin Name: Shiguang Bing URL Export
Plugin URI: https://www.shiguang.ink/
Description: 企业级 Bing 站长助手 v6.1。修复 IndexNow 验证问题，增加卸载清理，优化安全性，恢复Sitemap详细索引。
Version: 6.1
Author: Shiguang
License: GPLv2
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SGBing_Pro {

    private $api_endpoint = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlbatch';
    private $quota_endpoint = 'https://ssl.bing.com/webmaster/api.svc/json/GetUrlSubmissionQuota';
    private $indexnow_endpoint = 'https://api.indexnow.org/indexnow';
    private $log_table;
    private $db_version = '1.6'; // 版本号升级

    public function __construct() {
        global $wpdb;
        $this->log_table = $wpdb->prefix . 'sgbing_logs';

        // 激活与卸载
        register_activation_hook( __FILE__, array( $this, 'install_db' ) );
        register_uninstall_hook( __FILE__, array( 'SGBing_Pro', 'on_uninstall' ) );
        
        // 初始化检查
        add_action( 'plugins_loaded', array( $this, 'check_db_update' ) );
        
        // 后台菜单与资源
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'load_assets' ) );
        
        // 自动提交钩子
        add_action( 'transition_post_status', array( $this, 'auto_submit_post' ), 10, 3 );

        // Sitemap 相关
        add_action( 'init', array( $this, 'sitemap_init' ) );
        add_action( 'template_redirect', array( $this, 'sitemap_render' ) );
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
        add_filter( 'redirect_canonical', array( $this, 'stop_canonical_redirect' ), 10, 2 );

        // 禁用 WP 自带 Sitemap
        if ( get_option( 'sgbing_sm_enable', 1 ) ) {
            add_filter( 'wp_sitemaps_enabled', '__return_false' );
        }

        // AJAX 处理器
        add_action( 'wp_ajax_sgbing_handle_key', array( $this, 'ajax_handle_key' ) );
        add_action( 'wp_ajax_sgbing_manual_submit', array( $this, 'ajax_manual_submit' ) );
        add_action( 'wp_ajax_sgbing_get_data', array( $this, 'ajax_get_data' ) );
        add_action( 'wp_ajax_sgbing_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_sgbing_gen_indexnow', array( $this, 'ajax_gen_indexnow_key' ) );
    }

    /**
     * 静态卸载方法：清理数据库表和残留文件
     */
    public static function on_uninstall() {
        global $wpdb;
        // 1. 删除数据表
        $table_name = $wpdb->prefix . 'sgbing_logs';
        $wpdb->query( "DROP TABLE IF EXISTS $table_name" );

        // 2. 删除设置项
        delete_option( 'sgbing_db_version' );
        delete_option( 'sgbing_api_key' );
        delete_option( 'sgbing_sm_enable' );

        // 3. 删除 IndexNow 文件
        $key = get_option( 'sgbing_indexnow_key' );
        if ( $key ) {
            $file = ABSPATH . $key . '.txt';
            if ( file_exists( $file ) ) {
                @unlink( $file );
            }
            delete_option( 'sgbing_indexnow_key' );
        }
    }

    // --- 1. 基础设置与文件处理 ---

    public function check_db_update() {
        if ( get_option( 'sgbing_db_version' ) != $this->db_version ) {
            $this->install_db();
        }
        // 双重保险：每次加载插件检查文件是否存在，不存在则补全
        $key = get_option( 'sgbing_indexnow_key' );
        if ( $key && ! file_exists( ABSPATH . $key . '.txt' ) ) {
            $this->update_indexnow_file( $key );
        }
    }

    public function install_db() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $this->log_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            time datetime DEFAULT CURRENT_TIMESTAMP,
            url text NOT NULL,
            status varchar(50) NOT NULL,
            msg text NOT NULL,
            method varchar(20) DEFAULT 'API' NOT NULL, 
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
        update_option( 'sgbing_db_version', $this->db_version );
        
        $this->sitemap_init();
        
        // 初始化 IndexNow Key 并写入物理文件
        $key = get_option('sgbing_indexnow_key');
        if ( ! $key ) {
            $key = wp_generate_password( 32, false );
            update_option( 'sgbing_indexnow_key', $key );
        }
        $this->update_indexnow_file( $key );

        flush_rewrite_rules();
    }

    /**
     * 写入 IndexNow 验证文件到根目录
     */
    private function update_indexnow_file( $key ) {
        $file_path = ABSPATH . $key . '.txt';
        // 尝试写入
        $result = @file_put_contents( $file_path, $key );
        return $result !== false;
    }

    public function stop_canonical_redirect( $redirect_url, $requested_url ) {
        if ( get_query_var( 'sg_sitemap' ) ) {
            return false;
        }
        return $redirect_url;
    }

    public function load_assets( $hook ) {
        if ( strpos( $hook, 'sgbing-export' ) === false ) return;
        wp_enqueue_style( 'sgbing-css', plugin_dir_url( __FILE__ ) . 'admin.css', array(), '6.1' );
        wp_enqueue_script( 'sgbing-js', plugin_dir_url( __FILE__ ) . 'admin.js', array( 'jquery' ), '6.1', true );
        wp_localize_script( 'sgbing-js', 'sgbingVars', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'sgbing_nonce' )
        ));
    }

    public function add_menu() {
        add_menu_page( 'Bing Pro', 'Bing Pro', 'manage_options', 'sgbing-export', array( $this, 'render_ui' ), 'dashicons-chart-line', 99 );
    }

    // --- 2. AJAX IndexNow 生成 ---

    public function ajax_gen_indexnow_key() {
        check_ajax_referer( 'sgbing_nonce', 'nonce' );
        if(!current_user_can('manage_options')) wp_send_json_error('无权限');

        // 1. 删除旧文件
        $old_key = get_option('sgbing_indexnow_key');
        if ( $old_key && file_exists( ABSPATH . $old_key . '.txt' ) ) {
            @unlink( ABSPATH . $old_key . '.txt' );
        }

        // 2. 生成并保存新 Key
        $new_key = wp_generate_password(32, false);
        update_option('sgbing_indexnow_key', $new_key);
        
        // 3. 写入新文件
        if ( $this->update_indexnow_file( $new_key ) ) {
            wp_send_json_success( $new_key );
        } else {
            wp_send_json_error( 'Key 已生成，但根目录写入失败（权限不足）。请手动创建文件：' . $new_key . '.txt' );
        }
    }

    // --- 3. UI 渲染 ---

    public function render_ui() {
        $api_key = get_option( 'sgbing_api_key' );
        $has_key = ! empty( $api_key );
        $indexnow_key = get_option( 'sgbing_indexnow_key' );
        $post_ids = get_posts( array('numberposts' => -1, 'post_status' => 'publish', 'fields' => 'ids') );
        $chunks = array_chunk( $post_ids, 50 );
        $sm_enable = get_option( 'sgbing_sm_enable', 1 );

        // 定义所有 Sitemap URL
        $sm_main  = home_url( '/sitemap.xml' );
        $sm_post  = home_url( '/sitemap-posts.xml' );
        $sm_page  = home_url( '/sitemap-pages.xml' );
        $sm_cat   = home_url( '/sitemap-cats.xml' );
        $sm_tag   = home_url( '/sitemap-tags.xml' );
        
        ?>
        <div class="sg-app">
            <?php if ( ! $has_key ) : ?>
                <div class="sg-login-wall">
                    <div class="sg-login-card">
                        <div class="sg-login-header">
                            <span class="dashicons dashicons-shield-alt"></span>
                            <h2>Bing Webmaster Pro</h2>
                            <p>请输入您的 Bing API Key 以开始同步</p>
                        </div>
                        <div class="sg-login-body">
                            <input type="password" id="api-key-input" placeholder="例如: 8d7f8d7..." class="sg-input lg">
                            <button id="btn-save-key" class="sg-btn primary full lg">验证并连接</button>
                            <div class="sg-login-footer">
                                <a href="https://learn.microsoft.com/en-us/bingwebmaster/getting-access" target="_blank">
                                    <span class="dashicons dashicons-external"></span> 如何获取 API Key?
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else : ?>
                <div class="sg-mobile-nav-toggle" onclick="toggleNav()">
                    <span class="dashicons dashicons-menu-alt"></span> 菜单
                </div>
                <div class="sg-sidebar" id="sg-sidebar">
                    <div class="sg-brand">Bing Pro <span class="ver">v6.1</span></div>
                    <nav class="sg-nav">
                        <a href="#dashboard" class="nav-item active" data-tab="dashboard"><span class="dashicons dashicons-dashboard"></span> 概览 & 日志</a>
                        <a href="#bulk" class="nav-item" data-tab="bulk"><span class="dashicons dashicons-upload"></span> 链接提交</a>
                        <a href="#sitemap" class="nav-item" data-tab="sitemap"><span class="dashicons dashicons-networking"></span> 网站地图</a>
                        <a href="#settings" class="nav-item" data-tab="settings"><span class="dashicons dashicons-admin-settings"></span> 设置 & 密钥</a>
                    </nav>
                </div>
                <div class="sg-main">
                    <div class="sg-content">
                        <div id="tab-dashboard" class="sg-pane active">
                            <div class="sg-stats-bar">
                                <div class="stat-card blue">
                                    <span class="label">24小时提交</span>
                                    <h3 id="stat-24h-total">-</h3>
                                </div>
                                <div class="stat-card green">
                                    <span class="label">成功提交</span>
                                    <h3 id="stat-24h-success">-</h3>
                                </div>
                                <div class="stat-card red">
                                    <span class="label">提交失败</span>
                                    <h3 id="stat-24h-failed">-</h3>
                                </div>
                                <div class="stat-card purple chart-card">
                                    <div class="chart-wrapper">
                                        <div class="chart-canvas-box" id="quota-chart-box">
                                            <svg viewBox="0 0 36 36" class="circular-chart">
                                                <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                                <path class="circle" id="quota-circle-path" stroke-dasharray="0, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                                <text x="18" y="20.35" class="percentage" id="quota-text">--</text>
                                            </svg>
                                        </div>
                                        <div class="chart-info">
                                            <span class="label">API 今日剩余</span>
                                            <div class="sub-label">每日上限: <span id="quota-limit-text">100</span></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="sg-card">
                                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                                    <h4 style="margin:0;">提交日志</h4>
                                    <div style="display:flex; gap:10px;">
                                        <button class="sg-btn sm outline" onclick="loadData(currentPage)">刷新</button>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="sg-table full-width">
                                        <thead>
                                            <tr>
                                                <th style="width:15%">时间</th>
                                                <th style="width:10%">方式</th>
                                                <th style="width:35%">URL</th>
                                                <th style="width:10%">状态</th>
                                                <th style="width:20%">消息</th>
                                            </tr>
                                        </thead>
                                        <tbody id="log-tbody"></tbody>
                                    </table>
                                </div>
                                <div id="sg-pagination" class="sg-pagination"></div>
                            </div>
                        </div>

                        <div id="tab-bulk" class="sg-pane">
                            <div class="sg-alert warning" id="quota-warning" style="display:none">
                                <span class="dashicons dashicons-warning"></span> 
                                <strong>配额告急</strong>：今日 API 配额不足。建议切换下方“提交通道”为 <b>IndexNow</b> 继续提交。
                            </div>
                            <div class="sg-grid-2">
                                <div class="sg-card">
                                    <h4 style="margin-top:0;">手动提交</h4>
                                    <div class="sg-form-group">
                                        <label>提交通道</label>
                                        <div class="radio-group">
                                            <label><input type="radio" name="submit_channel" value="api" checked> Bing API (消耗配额)</label>
                                            <label><input type="radio" name="submit_channel" value="indexnow"> IndexNow (无配额限制)</label>
                                        </div>
                                        <p style="font-size:12px; color:#666; margin-top:5px;">提示：API 模式失败时，系统会自动尝试 IndexNow。</p>
                                    </div>
                                    <textarea id="manual-urls" class="sg-textarea" placeholder="https://... (一行一个)"></textarea>
                                    <button onclick="submitManual()" class="sg-btn primary full" style="margin-top:10px;">立即提交</button>
                                </div>
                                <div class="sg-card">
                                    <h4 style="margin-top:0;">全站分批 (50/组)</h4>
                                    <div class="chunk-list-modern">
                                        <?php if(empty($chunks)): ?>
                                            <div style="padding:20px; text-align:center; color:#999;">暂无已发布文章</div>
                                        <?php else: ?>
                                            <?php foreach($chunks as $i => $ids): 
                                                $urls = array_map('get_permalink', $ids);
                                                $val = implode("\n", $urls);
                                                $count = count($ids);
                                                $group_id = 'group-' . $i;
                                            ?>
                                            <div class="sg-batch-wrapper">
                                                <div class="sg-batch-item">
                                                    <div class="batch-left">
                                                        <div class="batch-icon"><span class="dashicons dashicons-images-alt2"></span></div>
                                                        <div class="batch-info">
                                                            <strong>第 <?php echo $i+1; ?> 组</strong>
                                                            <span><?php echo $count; ?> 篇文章</span>
                                                        </div>
                                                    </div>
                                                    <div class="batch-actions">
                                                        <button class="sg-btn sm outline btn-copy" data-target="<?php echo $group_id; ?>">复制</button>
                                                        <button class="sg-btn sm primary btn-submit-batch">提交</button>
                                                    </div>
                                                </div>
                                                <div id="<?php echo $group_id; ?>" class="batch-content" style="display:none;">
                                                    <textarea class="sg-textarea sm batch-textarea" readonly><?php echo esc_textarea($val); ?></textarea>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="tab-sitemap" class="sg-pane">
                            <div class="sg-card">
                                <h4 style="margin-top:0;">Sitemap 索引管理</h4>
                                <div class="sg-form-group" style="margin-bottom:25px; display:flex; align-items:center;">
                                    <label class="sg-switch">
                                        <input type="checkbox" id="sm_enable" <?php checked($sm_enable, 1); ?>>
                                        <span class="slider round"></span>
                                    </label>
                                    <span style="margin-left: 15px; font-weight:600; color:#333;">启用插件接管 Sitemap</span>
                                </div>
                                <div id="sm-options" style="<?php echo $sm_enable ? '' : 'opacity:0.5; pointer-events:none;'; ?>">
                                    <p style="font-size:13px; color:#666; margin-bottom:15px;">以下是自动生成的站点地图。主入口为 <b>索引文件</b>，其他为子地图。提交给搜索引擎时，建议只提交主入口。</p>
                                    <div class="sg-sitemap-grid">
                                        <div class="sitemap-item main">
                                            <div class="icon"><span class="dashicons dashicons-networking"></span></div>
                                            <div class="info">
                                                <div class="title">Sitemap 主索引 (入口)</div>
                                                <div class="url"><?php echo esc_url($sm_main); ?></div>
                                            </div>
                                            <button class="sg-btn sm outline btn-copy-link" data-url="<?php echo esc_attr($sm_main); ?>">复制</button>
                                        </div>
                                        <div class="sitemap-item">
                                            <div class="icon"><span class="dashicons dashicons-admin-post"></span></div>
                                            <div class="info">
                                                <div class="title">文章子地图</div>
                                                <div class="url"><?php echo esc_url($sm_post); ?></div>
                                            </div>
                                            <button class="sg-btn sm outline btn-copy-link" data-url="<?php echo esc_attr($sm_post); ?>">复制</button>
                                        </div>
                                        <div class="sitemap-item">
                                            <div class="icon"><span class="dashicons dashicons-admin-page"></span></div>
                                            <div class="info">
                                                <div class="title">页面子地图</div>
                                                <div class="url"><?php echo esc_url($sm_page); ?></div>
                                            </div>
                                            <button class="sg-btn sm outline btn-copy-link" data-url="<?php echo esc_attr($sm_page); ?>">复制</button>
                                        </div>
                                        <div class="sitemap-item">
                                            <div class="icon"><span class="dashicons dashicons-category"></span></div>
                                            <div class="info">
                                                <div class="title">分类子地图</div>
                                                <div class="url"><?php echo esc_url($sm_cat); ?></div>
                                            </div>
                                            <button class="sg-btn sm outline btn-copy-link" data-url="<?php echo esc_attr($sm_cat); ?>">复制</button>
                                        </div>
                                        <div class="sitemap-item">
                                            <div class="icon"><span class="dashicons dashicons-tag"></span></div>
                                            <div class="info">
                                                <div class="title">标签子地图</div>
                                                <div class="url"><?php echo esc_url($sm_tag); ?></div>
                                            </div>
                                            <button class="sg-btn sm outline btn-copy-link" data-url="<?php echo esc_attr($sm_tag); ?>">复制</button>
                                        </div>
                                    </div>
                                </div>
                                <button onclick="saveSettings()" class="sg-btn primary" style="margin-top:25px;">保存开关状态</button>
                            </div>
                        </div>

                        <div id="tab-settings" class="sg-pane">
                            <div class="sg-card">
                                <h4 style="margin-top:0;">IndexNow 配置</h4>
                                <div class="sg-form-group">
                                    <label>IndexNow Key</label>
                                    <div style="display:flex; gap:10px;">
                                        <input type="text" value="<?php echo esc_attr($indexnow_key); ?>" disabled class="sg-input" style="flex:1;">
                                        <button onclick="genIndexNow()" class="sg-btn sm outline">重新生成 (更新文件)</button>
                                    </div>
                                </div>
                                <div class="sg-info-box">
                                    验证文件: <a href="<?php echo home_url('/'.$indexnow_key.'.txt'); ?>" target="_blank"><?php echo home_url('/'.$indexnow_key.'.txt'); ?></a>
                                    <br><span style="font-size:12px; color:#666;">(点击链接应直接显示 Key，如果报 404 请检查网站目录写权限)</span>
                                </div>
                            </div>
                            <div class="sg-card">
                                <h4 style="margin-top:0;">API 密钥管理</h4>
                                <input type="password" value="<?php echo esc_attr($api_key); ?>" disabled class="sg-input input-blur" style="background:#eee;">
                                <button onclick="resetKey()" class="sg-btn danger sm" style="margin-top:10px;">删除密钥</button>
                            </div>
                            <div class="sg-card">
                                <h4 style="margin-top:0;">关于插件</h4>
                                <p style="font-size:13px; color:#666; line-height:1.6;">
                                    <strong>Bing Webmaster Pro</strong> v6.1<br>
                                    开发者: Shiguang
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // --- 4. AJAX 逻辑 ---
    
    public function ajax_get_data() {
        check_ajax_referer( 'sgbing_nonce', 'nonce' );
        
        if ( ! headers_sent() ) {
            header( 'Cache-Control: no-cache, no-store, must-revalidate' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );
        }

        global $wpdb;
        
        $per_page = 10;
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        if($page < 1) $page = 1;
        $offset = ($page - 1) * $per_page;

        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $this->log_table");
        $total_pages = ceil($total_items / $per_page);

        $logs = $wpdb->get_results( $wpdb->prepare("SELECT * FROM $this->log_table ORDER BY time DESC LIMIT %d OFFSET %d", $per_page, $offset) );
        
        if($logs) {
            foreach($logs as $log) {
                $log->msg = $this->translate_error($log->msg);
            }
        } else {
            $logs = array();
        }

        $stats = $wpdb->get_row( "SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Success' THEN 1 ELSE 0 END) as success, SUM(CASE WHEN status != 'Success' THEN 1 ELSE 0 END) as failed FROM $this->log_table WHERE time > NOW() - INTERVAL 24 HOUR" );

        $quota = array( 'daily' => 0, 'limit' => 100, 'monthly' => '-', 'text' => '检查中...' );
        $api_key = get_option( 'sgbing_api_key' );
        if ( $api_key ) {
            $q_res = $this->fetch_quota($api_key);
            if($q_res['success']) {
                $quota['daily'] = $q_res['daily'];
                $quota['limit'] = $q_res['limit'];
                $quota['text'] = $quota['daily'] . ' / ' . $quota['limit'];
            } else {
                $quota['daily'] = -1; 
                $quota['text'] = 'API Err';
            }
        }
        
        wp_send_json_success( array( 
            'logs' => $logs, 
            'stats' => $stats, 
            'quota' => $quota,
            'pagination' => array(
                'current_page' => $page,
                'total_pages' => $total_pages,
                'total_items' => $total_items
            )
        ) );
    }

    private function fetch_quota($key) {
        $req_url = $this->quota_endpoint . '?siteUrl=' . urlencode(home_url()) . '&apikey=' . $key;
        $res = wp_remote_get( $req_url, array('sslverify' => false, 'timeout' => 30));
        
        if ( is_wp_error( $res ) ) return array('success'=>false);
        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        
        if ( isset( $body['d'] ) ) {
            $daily = $body['d']['DailyQuota'];
            $limit = ($daily > 100) ? 10000 : 100;
            return array('success'=>true, 'daily'=>$daily, 'limit'=>$limit);
        }
        return array('success'=>false);
    }

    public function ajax_manual_submit() {
        check_ajax_referer( 'sgbing_nonce', 'nonce' );
        // 安全过滤：清理 textarea 输入的内容
        $raw_urls = preg_split( '/\r\n|[\r\n]/', $_POST['urls'] );
        $urls = array();
        foreach ( $raw_urls as $u ) {
            $u = trim($u);
            if ( ! empty($u) ) {
                $urls[] = esc_url_raw($u);
            }
        }
        
        if(empty($urls)) wp_send_json_error('URL 为空');

        $channel = isset($_POST['channel']) ? $_POST['channel'] : 'api';
        
        $success = false;
        $msg = '';
        $final_method = '';

        if($channel === 'indexnow') {
            $res = $this->submit_to_indexnow($urls);
            $success = $res['success'];
            $msg = $res['msg'] ? $res['msg'] : ($success ? 'OK' : 'Unknown Error'); 
            $final_method = 'IndexNow';
        } else {
            $key = get_option('sgbing_api_key');
            if(!$key) {
                $res = $this->submit_to_indexnow($urls);
                $success = $res['success'];
                $msg = "No API Key -> IndexNow: " . $res['msg'];
                $final_method = 'API->IndexNow';
            } else {
                $res = $this->call_bing_api($this->api_endpoint, array('siteUrl'=>home_url(), 'urlList'=>array_values($urls)), $key);
                
                if($res['success']) {
                    $success = true;
                    $msg = 'OK';
                    $final_method = 'API';
                } else {
                    $code = $res['code'];
                    if($code == 400 || $code == 402 || $code == 429 || $code == 0) {
                        $in_res = $this->submit_to_indexnow($urls);
                        if($in_res['success']) {
                            $success = true;
                            $msg = "API Err {$code} -> IndexNow OK";
                            $final_method = 'API->IndexNow';
                        } else {
                            $success = false;
                            $msg = "API {$code} & IndexNow Fail: " . $in_res['msg'];
                            $final_method = 'API&IndexNow Fail';
                        }
                    } else {
                        $success = false;
                        $msg = $res['msg'];
                        $final_method = 'API';
                    }
                }
            }
        }

        foreach($urls as $u) {
            $this->log_transaction($u, $success?'Success':'Failed', $msg, $final_method);
        }

        $success ? wp_send_json_success($msg) : wp_send_json_error($msg);
    }

    public function auto_submit_post( $new_status, $old_status, $post ) {
        if ( $new_status !== 'publish' ) return;
        
        $lock_key = 'sg_bing_lock_' . $post->ID;
        if ( get_transient( $lock_key ) ) return;
        set_transient( $lock_key, 1, 30 ); // 增加锁时间防止并发

        $url = get_permalink( $post->ID );
        $api_key = get_option( 'sgbing_api_key' );
        
        $method = 'API';
        $final_status = 'Failed';
        $final_msg = 'Unknown Error';

        if ( $api_key ) {
            $res = $this->call_bing_api( $this->api_endpoint, array('siteUrl' => home_url(), 'urlList' => array( $url )), $api_key );
            
            if ($res['success']) {
                $final_status = 'Success';
                $final_msg = 'Auto Publish (API)';
            } else {
                $err_code = $res['code']; 
                // 402=QuotaExceeded, 429=TooManyRequests, 400=BadReq, 0=ConnectionFail
                if ($err_code == 402 || $err_code == 429 || $err_code == 400 || $err_code == 0) {
                     $in_res = $this->submit_to_indexnow(array($url));
                     $method = 'IndexNow';
                     if ($in_res['success']) {
                         $final_status = 'Success';
                         $final_msg = "API Err {$err_code} -> IndexNow OK";
                     } else {
                         $final_status = 'Failed';
                         $final_msg = "API {$err_code} & IndexNow Fail: " . $in_res['msg'];
                     }
                } else {
                    $final_msg = 'API Fail: ' . $res['msg'];
                }
            }
        } else {
            $res = $this->submit_to_indexnow(array($url));
            $method = 'IndexNow';
            $final_status = $res['success'] ? 'Success' : 'Failed';
            $final_msg = $res['success'] ? 'Auto Publish (IndexNow)' : $res['msg'];
        }

        $this->log_transaction( $url, $final_status, $final_msg, $method );
    }

    private function submit_to_indexnow($urls) {
        $key = get_option('sgbing_indexnow_key');
        if(!$key) return array('success'=>false, 'msg'=>'无 IndexNow Key');
        
        // 验证文件地址
        $key_location = home_url('/'.$key.'.txt');
        
        $data = array(
            'host' => parse_url(home_url(), PHP_URL_HOST),
            'key' => $key,
            'keyLocation' => $key_location,
            'urlList' => array_values($urls)
        );
        
        $res = wp_remote_post($this->indexnow_endpoint, array(
            'body' => wp_json_encode($data), 
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'), 
            'sslverify' => false, 
            'timeout' => 45, 
            'user-agent' => 'WordPress/ShiguangPlugin'
        ));
        
        if(is_wp_error($res)) return array('success'=>false, 'msg'=>$res->get_error_message());
        $code = wp_remote_retrieve_response_code($res);
        if($code === 200 || $code === 202) return array('success'=>true, 'msg'=>'OK');
        return array('success'=>false, 'msg'=>'IndexNow Err '.$code);
    }

    private function translate_error($msg) {
        if(strpos($msg, '400') !== false) return 'Err 400: 参数/Quota问题';
        if(strpos($msg, '401') !== false) return 'Err 401: Key 无效';
        if(strpos($msg, '402') !== false) return 'Err 402: Quota 用完';
        if(strpos($msg, '429') !== false) return 'Err 429: 请求太快';
        if(strpos($msg, '28') !== false) return '连接超时';
        return $msg; 
    }

    private function call_bing_api( $url, $data, $key ) {
        $req_url = $url . '?apikey=' . $key;
        $response = wp_remote_post( $req_url, array('body' => wp_json_encode( $data ), 'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ), 'sslverify' => false, 'timeout' => 45));
        
        if ( is_wp_error( $response ) ) return array( 'success' => false, 'msg' => $response->get_error_message(), 'code' => 0 );
        
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 ) return array( 'success' => true, 'msg' => 'OK', 'code' => 200 );
        return array( 'success' => false, 'msg' => 'Err ' . $code, 'code' => $code );
    }

    private function log_transaction( $url, $status, $msg, $method='API' ) {
        global $wpdb;
        $wpdb->insert( 
            $this->log_table, 
            array(
                'time' => current_time( 'mysql' ), 
                'url' => substr( $url, 0, 500 ), 
                'status' => $status, 
                'msg' => substr( $msg, 0, 500 ), 
                'method' => $method
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );
    }

    public function ajax_save_settings() {
        check_ajax_referer('sgbing_nonce', 'nonce');
        if(!current_user_can('manage_options')) wp_send_json_error();
        update_option('sgbing_sm_enable', $_POST['enable']==='true'?1:0);
        // 保存后刷新 rewrite 规则以应用 Sitemap 拦截
        flush_rewrite_rules(); 
        wp_send_json_success();
    }
    
    public function ajax_handle_key() {
        check_ajax_referer( 'sgbing_nonce', 'nonce' );
        if ( $_POST['type'] === 'save' ) {
            $key = sanitize_text_field( $_POST['key'] );
            $check = $this->fetch_quota($key);
            if($check['success']) {
                update_option( 'sgbing_api_key', $key ); 
                wp_send_json_success();
            } else {
                wp_send_json_error('API Key 无效或无法连接 Bing 服务器');
            }
        } else { 
            delete_option( 'sgbing_api_key' ); 
            wp_send_json_success();
        }
    }

    // --- 5. Sitemap 逻辑 ---

    public function sitemap_init() {
        add_rewrite_rule( 'sitemap\.xml$', 'index.php?sg_sitemap=index', 'top' );
        add_rewrite_rule( 'sitemap-posts\.xml$', 'index.php?sg_sitemap=posts', 'top' );
        add_rewrite_rule( 'sitemap-pages\.xml$', 'index.php?sg_sitemap=pages', 'top' );
        add_rewrite_rule( 'sitemap-cats\.xml$', 'index.php?sg_sitemap=cats', 'top' );
        add_rewrite_rule( 'sitemap-tags\.xml$', 'index.php?sg_sitemap=tags', 'top' );
    }
    
    public function register_query_vars( $vars ) { $vars[] = 'sg_sitemap'; return $vars; }
    
    public function sitemap_render() {
        $type = get_query_var( 'sg_sitemap' ); 
        if ( ! $type ) return;
        
        // 确保不被缓存插件缓存 XML
        if ( ! headers_sent() ) {
            status_header( 200 );
            header( 'Content-Type: application/xml; charset=utf-8' );
            header( 'X-Robots-Tag: noindex, follow' );
        }
        
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<?xml-stylesheet type="text/xsl" href="'.includes_url('css/dist/block-library/sitemap.xsl').'"?>'; // 尝试使用 WP 默认样式（如果有）
        
        if ($type === 'index') {
            echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            echo '<sitemap><loc>'.esc_url(home_url('/sitemap-posts.xml')).'</loc></sitemap>';
            echo '<sitemap><loc>'.esc_url(home_url('/sitemap-pages.xml')).'</loc></sitemap>';
            echo '<sitemap><loc>'.esc_url(home_url('/sitemap-cats.xml')).'</loc></sitemap>';
            echo '<sitemap><loc>'.esc_url(home_url('/sitemap-tags.xml')).'</loc></sitemap>';
            echo '</sitemapindex>';
            exit;
        }
        
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        
        // 优化查询：只获取 ID 和 修改时间，减少内存消耗
        if($type==='posts'){
            $posts = get_posts(array(
                'numberposts' => 2000, 
                'post_status' => 'publish',
                'orderby'     => 'modified',
                'order'       => 'DESC'
            ));
            foreach($posts as $p) {
                echo '<url>';
                echo '<loc>'.esc_url(get_permalink($p->ID)).'</loc>';
                echo '<lastmod>'.get_the_modified_date('Y-m-d', $p->ID).'</lastmod>';
                echo '</url>';
            }
        }
        elseif($type==='pages'){
            $pages = get_pages(array(
                'number' => 500, 
                'post_status' => 'publish'
            ));
            foreach($pages as $p) {
                echo '<url>';
                echo '<loc>'.esc_url(get_permalink($p->ID)).'</loc>';
                echo '<lastmod>'.get_the_modified_date('Y-m-d', $p->ID).'</lastmod>';
                echo '</url>';
            }
        }
        elseif($type==='cats'){
            $terms = get_terms(array('taxonomy' => 'category', 'hide_empty' => true));
            if(!empty($terms) && !is_wp_error($terms)){
                foreach($terms as $t) echo '<url><loc>'.esc_url(get_category_link($t->term_id)).'</loc></url>';
            }
        }
        elseif($type==='tags'){
            $tags = get_terms(array('taxonomy' => 'post_tag', 'hide_empty' => true, 'number' => 1000));
            if(!empty($tags) && !is_wp_error($tags)) {
                foreach($tags as $t) echo '<url><loc>'.esc_url(get_tag_link($t->term_id)).'</loc></url>';
            }
        }
        echo '</urlset>'; 
        exit;
    }
}

new SGBing_Pro();
