<?php
/**
 * Plugin Name: IP Status Lamp
 * Plugin URI: https://www.mipaudit.com/
 * Description: Віджет-лампочка для моніторингу доступності IP через PING
 * Version: 2.0.0
 * Author: MIP Audit
 * Text Domain: ip-status-lamp
 */

if (!defined('ABSPATH')) {
    exit;
}

class IP_Status_Lamp {
    
    private static $instance = null;
    private $option_name = 'ip_status_lamp_settings';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('rest_api_init', [$this, 'register_rest_route']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_shortcode('ip_status_lamp', [$this, 'render_shortcode']);
        
        // Заборона кешування для сторінок з shortcode
        add_action('template_redirect', [$this, 'disable_page_cache']);
    }
    
    /**
     * Заборона кешування сторінок з віджетом
     */
    public function disable_page_cache() {
        global $post;
        
        if (!is_singular() || empty($post)) {
            return;
        }
        
        // Перевірити чи сторінка містить наш shortcode
        if (has_shortcode($post->post_content, 'ip_status_lamp')) {
            // Константи для плагінів кешування
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            if (!defined('DONOTCACHEDB')) {
                define('DONOTCACHEDB', true);
            }
            if (!defined('DONOTMINIFY')) {
                define('DONOTMINIFY', true);
            }
            if (!defined('DONOTCDN')) {
                define('DONOTCDN', true);
            }
            
            // Агресивні HTTP заголовки
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
            header('Pragma: no-cache');
            header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
            header('Vary: *');
            
            // Cloudflare специфічні
            header('CDN-Cache-Control: no-store');
            header('Cloudflare-CDN-Cache-Control: no-store');
            
            // Додаємо meta теги через wp_head
            add_action('wp_head', [$this, 'add_no_cache_meta'], 1);
        }
    }
    
    /**
     * Meta теги для заборони кешування
     */
    public function add_no_cache_meta() {
        echo '<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">' . "\n";
        echo '<meta http-equiv="Pragma" content="no-cache">' . "\n";
        echo '<meta http-equiv="Expires" content="0">' . "\n";
    }
    
    /**
     * Отримати налаштування плагіна
     */
    public function get_settings() {
        $defaults = [
            'target_ip' => '',
            'ping_interval' => 5,
            'ping_count' => 3,
            'timeout' => 2,
            'label_online' => 'Онлайн',
            'label_offline' => 'Офлайн',
            'show_label' => true,
            'position' => 'left',
        ];
        
        $settings = get_option($this->option_name, []);
        return wp_parse_args($settings, $defaults);
    }
    
    /**
     * ========================================
     * АДМІН-ПАНЕЛЬ
     * ========================================
     */
    
    public function add_admin_menu() {
        add_options_page(
            'IP Status Lamp',
            'IP Status Lamp',
            'manage_options',
            'ip-status-lamp',
            [$this, 'render_admin_page']
        );
    }
    
    public function register_settings() {
        register_setting(
            'ip_status_lamp_group',
            $this->option_name,
            [$this, 'sanitize_settings']
        );
        
        // Секція основних налаштувань
        add_settings_section(
            'ip_status_lamp_main',
            'Основні налаштування',
            null,
            'ip-status-lamp'
        );
        
        add_settings_field(
            'target_ip',
            'IP-адреса для моніторингу',
            [$this, 'render_field_ip'],
            'ip-status-lamp',
            'ip_status_lamp_main'
        );
        
        add_settings_field(
            'ping_interval',
            'Інтервал перевірки (хвилин)',
            [$this, 'render_field_interval'],
            'ip-status-lamp',
            'ip_status_lamp_main'
        );
        
        add_settings_field(
            'ping_count',
            'Кількість PING для перевірки',
            [$this, 'render_field_ping_count'],
            'ip-status-lamp',
            'ip_status_lamp_main'
        );
        
        add_settings_field(
            'timeout',
            'Таймаут відповіді (секунд)',
            [$this, 'render_field_timeout'],
            'ip-status-lamp',
            'ip_status_lamp_main'
        );
        
        // Секція відображення
        add_settings_section(
            'ip_status_lamp_display',
            'Налаштування відображення',
            null,
            'ip-status-lamp'
        );
        
        add_settings_field(
            'show_label',
            'Показувати текстовий статус',
            [$this, 'render_field_show_label'],
            'ip-status-lamp',
            'ip_status_lamp_display'
        );
        
        add_settings_field(
            'label_online',
            'Текст "Онлайн"',
            [$this, 'render_field_label_online'],
            'ip-status-lamp',
            'ip_status_lamp_display'
        );
        
        add_settings_field(
            'label_offline',
            'Текст "Офлайн"',
            [$this, 'render_field_label_offline'],
            'ip-status-lamp',
            'ip_status_lamp_display'
        );
        
        add_settings_field(
            'position',
            'Позиція віджету',
            [$this, 'render_field_position'],
            'ip-status-lamp',
            'ip_status_lamp_display'
        );
    }
    
    public function sanitize_settings($input) {
        $sanitized = [];
        
        // IP-адреса: валідація IP або домену
        $target = sanitize_text_field($input['target_ip'] ?? '');
        $sanitized['target_ip'] = $this->validate_target($target);
        
        // Інтервал: 1-60 хвилин
        $sanitized['ping_interval'] = absint($input['ping_interval'] ?? 5);
        $sanitized['ping_interval'] = max(1, min(60, $sanitized['ping_interval']));
        
        // Кількість пінгів: 1-10
        $sanitized['ping_count'] = absint($input['ping_count'] ?? 3);
        $sanitized['ping_count'] = max(1, min(10, $sanitized['ping_count']));
        
        // Таймаут: 1-10 секунд
        $sanitized['timeout'] = absint($input['timeout'] ?? 2);
        $sanitized['timeout'] = max(1, min(10, $sanitized['timeout']));
        
        // Текстові поля
        $sanitized['label_online'] = sanitize_text_field($input['label_online'] ?? 'Онлайн');
        $sanitized['label_offline'] = sanitize_text_field($input['label_offline'] ?? 'Офлайн');
        $sanitized['show_label'] = !empty($input['show_label']);
        
        // Позиція
        $valid_positions = ['left', 'center', 'right'];
        $sanitized['position'] = in_array($input['position'] ?? 'left', $valid_positions) 
            ? $input['position'] 
            : 'left';
        
        return $sanitized;
    }
    
    /**
     * Валідація IP-адреси або доменного імені
     */
    private function validate_target($target) {
        if (empty($target)) {
            return '';
        }
        
        // Видалити пробіли
        $target = trim($target);
        
        // Перевірка на небезпечні символи (command injection)
        if (preg_match('/[;&|`$(){}\\[\\]<>\'\"\\\\]/', $target)) {
            add_settings_error(
                'ip_status_lamp_settings',
                'invalid_ip',
                'IP-адреса містить недопустимі символи',
                'error'
            );
            return '';
        }
        
        // Валідний IPv4
        if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $target;
        }
        
        // Валідний IPv6
        if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $target;
        }
        
        // Валідне доменне ім'я (тільки букви, цифри, дефіс, крапка)
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]{0,253}[a-zA-Z0-9]$/', $target)) {
            // Додаткова перевірка - має бути хоча б одна крапка для домену
            if (strpos($target, '.') !== false) {
                return $target;
            }
        }
        
        add_settings_error(
            'ip_status_lamp_settings',
            'invalid_ip',
            'Введіть коректну IP-адресу (напр. 192.168.1.1) або доменне ім\'я (напр. example.com)',
            'error'
        );
        
        return '';
    }
    
    /**
     * Перевірка безпеки перед виконанням ping
     */
    private function is_safe_target($target) {
        // Заборонені символи для command injection
        if (preg_match('/[;&|`$(){}\\[\\]<>\'\"\\\\]/', $target)) {
            return false;
        }
        
        // Перевірка на валідний IPv4
        if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }
        
        // Перевірка на валідний IPv6
        if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }
        
        // Перевірка на валідний домен
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]{0,253}[a-zA-Z0-9]$/', $target) 
            && strpos($target, '.') !== false) {
            return true;
        }
        
        return false;
    }
    
    // Рендеринг полів форми
    public function render_field_ip() {
        $settings = $this->get_settings();
        echo '<input type="text" name="' . $this->option_name . '[target_ip]" 
              value="' . esc_attr($settings['target_ip']) . '" 
              placeholder="192.168.1.1 або example.com"
              class="regular-text">';
        echo '<p class="description">IP-адреса або доменне ім\'я для перевірки</p>';
    }
    
    public function render_field_interval() {
        $settings = $this->get_settings();
        echo '<input type="number" name="' . $this->option_name . '[ping_interval]" 
              value="' . esc_attr($settings['ping_interval']) . '" 
              min="1" max="60" style="width: 80px;"> хвилин';
        echo '<p class="description">Інтервал автоматичного оновлення статусу на сторінці</p>';
    }
    
    public function render_field_ping_count() {
        $settings = $this->get_settings();
        echo '<input type="number" name="' . $this->option_name . '[ping_count]" 
              value="' . esc_attr($settings['ping_count']) . '" 
              min="1" max="10" style="width: 80px;">';
        echo '<p class="description">Статус визначається за більшістю успішних відповідей</p>';
    }
    
    public function render_field_timeout() {
        $settings = $this->get_settings();
        echo '<input type="number" name="' . $this->option_name . '[timeout]" 
              value="' . esc_attr($settings['timeout']) . '" 
              min="1" max="10" style="width: 80px;"> секунд';
    }
    
    public function render_field_show_label() {
        $settings = $this->get_settings();
        echo '<label><input type="checkbox" name="' . $this->option_name . '[show_label]" 
              value="1" ' . checked($settings['show_label'], true, false) . '> 
              Показувати текст біля лампочки</label>';
    }
    
    public function render_field_label_online() {
        $settings = $this->get_settings();
        echo '<input type="text" name="' . $this->option_name . '[label_online]" 
              value="' . esc_attr($settings['label_online']) . '" class="regular-text">';
    }
    
    public function render_field_label_offline() {
        $settings = $this->get_settings();
        echo '<input type="text" name="' . $this->option_name . '[label_offline]" 
              value="' . esc_attr($settings['label_offline']) . '" class="regular-text">';
    }
    
    public function render_field_position() {
        $settings = $this->get_settings();
        $positions = [
            'left' => 'Зліва',
            'center' => 'По центру',
            'right' => 'Справа',
        ];
        
        foreach ($positions as $value => $label) {
            echo '<label style="margin-right: 20px;">';
            echo '<input type="radio" name="' . $this->option_name . '[position]" 
                  value="' . esc_attr($value) . '" ' . checked($settings['position'], $value, false) . '> ';
            echo esc_html($label);
            echo '</label>';
        }
    }
    
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('ip_status_lamp_group');
                do_settings_sections('ip-status-lamp');
                submit_button('Зберегти налаштування');
                ?>
            </form>
            
            <hr>
            
            <h2>Використання</h2>
            <p>Вставте shortcode на будь-яку сторінку або запис:</p>
            <code>[ip_status_lamp]</code>
            
            <h3>Додаткові параметри shortcode:</h3>
            <ul>
                <li><code>[ip_status_lamp size="large"]</code> — великий розмір (small/medium/large)</li>
                <li><code>[ip_status_lamp label="Сервер офісу"]</code> — власний заголовок</li>
                <li><code>[ip_status_lamp position="center"]</code> — позиція (left/center/right)</li>
            </ul>
            
            <hr>
            
            <h2>Тестування</h2>
            <?php $this->render_test_section(); ?>
        </div>
        <?php
    }
    
    private function render_test_section() {
        $settings = $this->get_settings();
        
        if (empty($settings['target_ip'])) {
            echo '<p style="color: #d63638;">⚠️ Спочатку вкажіть IP-адресу в налаштуваннях</p>';
            return;
        }
        
        // Виконати реальний ping
        $result = $this->perform_ping();
        $status_text = $result['online'] ? '🟢 Онлайн' : '🔴 Офлайн';
        
        echo '<table class="widefat" style="max-width: 500px;">';
        echo '<tr><th>IP-адреса</th><td>' . esc_html($settings['target_ip']) . '</td></tr>';
        echo '<tr><th>Статус</th><td>' . $status_text . '</td></tr>';
        echo '<tr><th>Успішних пінгів</th><td>' . esc_html($result['successful']) . ' з ' . esc_html($result['total']) . '</td></tr>';
        echo '<tr><th>Час перевірки</th><td>' . esc_html($result['checked_at']) . '</td></tr>';
        echo '</table>';
        
        echo '<p><a href="' . esc_url(admin_url('options-general.php?page=ip-status-lamp')) . '" class="button">🔄 Оновити</a></p>';
        echo '<p class="description">⚡ Кешування вимкнено — кожен запит виконує реальний ping</p>';
    }
    
    /**
     * ========================================
     * PING ЛОГІКА
     * ========================================
     */
    
    public function perform_ping() {
        $settings = $this->get_settings();
        $target = $settings['target_ip'];
        $count = $settings['ping_count'];
        $timeout = $settings['timeout'];
        
        // Якщо IP не вказано
        if (empty($target)) {
            return [
                'online' => false,
                'successful' => 0,
                'total' => 0,
                'checked_at' => current_time('mysql'),
                'error' => 'IP не налаштовано'
            ];
        }
        
        // Додаткова перевірка безпеки перед виконанням команди
        if (!$this->is_safe_target($target)) {
            return [
                'online' => false,
                'successful' => 0,
                'total' => 0,
                'checked_at' => current_time('mysql'),
                'error' => 'Некоректний IP або домен'
            ];
        }
        
        // Виконати ping
        $successful = 0;
        
        // Визначити ОС сервера
        $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        for ($i = 0; $i < $count; $i++) {
            if ($is_windows) {
                // Windows: ping -n 1 -w timeout_ms
                $cmd = sprintf('ping -n 1 -w %d %s', 
                    $timeout * 1000, 
                    escapeshellarg($target)
                );
            } else {
                // Linux/Unix: ping -c 1 -W timeout_sec
                $cmd = sprintf('ping -c 1 -W %d %s 2>&1', 
                    $timeout, 
                    escapeshellarg($target)
                );
            }
            
            exec($cmd, $output, $return_code);
            
            if ($return_code === 0) {
                $successful++;
            }
            
            // Невелика затримка між пінгами
            if ($i < $count - 1) {
                usleep(200000); // 0.2 секунди
            }
        }
        
        // Статус онлайн, якщо більше половини пінгів успішні
        $is_online = $successful > ($count / 2);
        
        return [
            'online' => $is_online,
            'successful' => $successful,
            'total' => $count,
            'checked_at' => current_time('mysql'),
        ];
    }
    
    /**
     * ========================================
     * REST API
     * ========================================
     */
    
    public function register_rest_route() {
        register_rest_route('ip-status-lamp/v1', '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_status'],
            'permission_callback' => '__return_true',
        ]);
    }
    
    public function rest_get_status() {
        // Агресивна заборона кешування REST API
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        header('CDN-Cache-Control: no-store');
        header('Cloudflare-CDN-Cache-Control: no-store');
        header('Vary: *');
        
        $result = $this->perform_ping();
        $settings = $this->get_settings();
        
        return [
            'online' => $result['online'],
            'label' => $result['online'] ? $settings['label_online'] : $settings['label_offline'],
            'checked_at' => $result['checked_at'],
            'timestamp' => time(), // Для дебагу
        ];
    }
    
    /**
     * ========================================
     * FRONTEND
     * ========================================
     */
    
    public function enqueue_frontend_assets() {
        // CSS
        wp_register_style(
            'ip-status-lamp',
            false,
            [],
            time() // Унікальна версія кожен раз
        );
        wp_enqueue_style('ip-status-lamp');
        wp_add_inline_style('ip-status-lamp', $this->get_inline_css());
        
        // JS з унікальною версією
        wp_register_script(
            'ip-status-lamp',
            false,
            [],
            time(), // Унікальна версія кожен раз
            true
        );
    }
    
    private function get_inline_css() {
        return '
        .ip-status-lamp-wrapper {
            display: flex;
            width: 100%;
            margin: 0;
            padding: 0;
        }
        
        .ip-status-lamp-wrapper.position-left {
            justify-content: flex-start;
        }
        
        .ip-status-lamp-wrapper.position-center {
            justify-content: center;
        }
        
        .ip-status-lamp-wrapper.position-right {
            justify-content: flex-end;
        }
        
        .ip-status-lamp-container {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0;
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        .ip-status-lamp-svg {
            transition: all 0.3s ease;
        }
        
        .ip-status-lamp-svg.size-small {
            width: 24px;
            height: 24px;
        }
        
        .ip-status-lamp-svg.size-medium {
            width: 40px;
            height: 40px;
        }
        
        .ip-status-lamp-svg.size-large {
            width: 64px;
            height: 64px;
        }
        
        .ip-status-lamp-svg.online .lamp-bulb {
            fill: #22c55e;
        }
        
        .ip-status-lamp-svg.offline .lamp-bulb {
            fill: #ef4444;
        }
        
        .ip-status-lamp-svg.loading .lamp-bulb {
            fill: #f59e0b;
            animation: lamp-pulse 1s ease-in-out infinite;
        }
        
        @keyframes lamp-pulse {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }
        
        .ip-status-lamp-label.loading {
            color: #888;
        }
        
        .ip-status-lamp-label {
            font-size: 14px;
            font-weight: 500;
            color: #333;
        }
        
        .ip-status-lamp-label.online {
            color: #228B22;
        }
        
        .ip-status-lamp-label.offline {
            color: #DC143C;
        }
        
        .ip-status-lamp-title {
            font-size: 12px;
            color: #666;
            margin-right: 5px;
        }
        ';
    }
    
    private function get_inline_js($settings = null) {
        return "
        (function() {
            var wrappers = document.querySelectorAll('.ip-status-lamp-wrapper');
            if (!wrappers.length) return;
            
            wrappers.forEach(function(wrapper) {
                var svg = wrapper.querySelector('.ip-status-lamp-svg');
                var label = wrapper.querySelector('.ip-status-lamp-label');
                var apiUrl = wrapper.getAttribute('data-api-url');
                var labelOnline = wrapper.getAttribute('data-label-online');
                var labelOffline = wrapper.getAttribute('data-label-offline');
                var interval = parseInt(wrapper.getAttribute('data-interval')) || 300000;
                
                function updateStatus() {
                    var url = apiUrl + (apiUrl.indexOf('?') > -1 ? '&' : '?') + '_=' + Date.now();
                    
                    fetch(url, {
                        cache: 'no-store',
                        headers: {
                            'Cache-Control': 'no-cache, no-store, must-revalidate',
                            'Pragma': 'no-cache'
                        }
                    })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        svg.classList.remove('online', 'offline', 'loading');
                        if (label) {
                            label.classList.remove('online', 'offline', 'loading');
                        }
                        
                        if (data.online) {
                            svg.classList.add('online');
                            if (label) {
                                label.textContent = labelOnline;
                                label.classList.add('online');
                            }
                        } else {
                            svg.classList.add('offline');
                            if (label) {
                                label.textContent = labelOffline;
                                label.classList.add('offline');
                            }
                        }
                    })
                    .catch(function() {
                        svg.classList.remove('online', 'offline');
                        svg.classList.add('loading');
                        if (label) {
                            label.textContent = '...';
                        }
                    });
                }
                
                updateStatus();
                setInterval(updateStatus, interval);
            });
        })();
        ";
    }
    
    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'size' => 'medium',
            'label' => '',
            'position' => '',
        ], $atts);
        
        $settings = $this->get_settings();
        
        // Позиція: з shortcode або з налаштувань
        $position = !empty($atts['position']) ? $atts['position'] : $settings['position'];
        
        // Підключити JS
        wp_enqueue_script('ip-status-lamp');
        wp_add_inline_script('ip-status-lamp', $this->get_inline_js($settings));
        
        // Унікальний ID для кількох віджетів на сторінці
        $widget_id = 'ip-lamp-' . wp_rand(1000, 9999);
        
        ob_start();
        ?>
        <!-- IP Status Lamp v2.0 - <?php echo date('Y-m-d H:i:s'); ?> -->
        <div class="ip-status-lamp-wrapper position-<?php echo esc_attr($position); ?>" id="<?php echo esc_attr($widget_id); ?>" 
             data-api-url="<?php echo esc_url(rest_url('ip-status-lamp/v1/status')); ?>"
             data-label-online="<?php echo esc_attr($settings['label_online']); ?>"
             data-label-offline="<?php echo esc_attr($settings['label_offline']); ?>"
             data-interval="<?php echo esc_attr($settings['ping_interval'] * 60 * 1000); ?>">
            <div class="ip-status-lamp-container">
                <?php if (!empty($atts['label'])): ?>
                    <span class="ip-status-lamp-title"><?php echo esc_html($atts['label']); ?>:</span>
                <?php endif; ?>
                
                <!-- Початковий стан - завантаження -->
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="ip-status-lamp-svg size-<?php echo esc_attr($atts['size']); ?> loading">
                    <path class="lamp-bulb" d="M32 6C23 6 16 13 16 22c0 5.5 2.7 10.3 7 13.4V40h18v-4.6c4.3-3.1 7-7.9 7-13.4 0-9-7-16-16-16z"/>
                    <rect x="23" y="42" width="18" height="4" rx="1" fill="#888"/>
                    <rect x="23" y="48" width="18" height="4" rx="1" fill="#777"/>
                    <rect x="26" y="54" width="12" height="4" rx="2" fill="#666"/>
                </svg>
                
                <?php if ($settings['show_label']): ?>
                    <span class="ip-status-lamp-label loading">...</span>
                <?php endif; ?>
            </div>
            <noscript>
                <style>.ip-status-lamp-wrapper { display: none !important; }</style>
            </noscript>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Ініціалізація плагіна
IP_Status_Lamp::get_instance();
