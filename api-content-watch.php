<?php
/*
Plugin Name: Content-Watch.ru API
Author: Content Watch
Version: 1.0
Description: Плагин для проверки уникальности контента
Author URI: https://content-watch.ru/api/
*/

$plugin = new ContentWatchPlugin();
$plugin->init();

/**
 * Class ContentWatchPlugin
 */
class ContentWatchPlugin
{
    protected $pageName = 'api-content-watch.php';
    protected $settingsGroup = 'content_watch_plugin_settings';

    public function init()
    {
        add_action('admin_head', array($this, 'content_watch_plugin_admincss'));
        add_action('admin_head', array($this, 'content_watch_plugin_adminjs'));
        add_action('admin_menu', array($this, 'plugin_options'));
        add_action('admin_init', array($this, 'register_settings'));

        if ($this->getOption('Content-watch_status') == "on") {
            add_filter('save_post', array($this, 'onPostSave'));
            add_filter('publush_post', array($this, 'onPostSave'));
        }

        // создаем новую колонку
        add_filter('manage_edit-post_columns', array($this, 'add_column'), 4);
        add_filter('manage_edit-page_columns', array($this, 'add_column'), 4);


        // заполняем колонку данными
        add_filter('manage_posts_custom_column', array($this, 'fill_column'), 5, 2);
        add_filter('manage_pages_custom_column', array($this, 'fill_column'), 5, 2);

        /* Обработчики AJAX */
        add_action('wp_ajax_cw_check_balance', array($this, 'check_balance'));

        add_action('wp_ajax_check_post_by_id', array($this, 'ajaxCheckTriggerId'));
        add_action('wp_ajax_check_post_new_text', array($this, 'ajaxCheckTriggerText'));

        add_action('add_meta_boxes', array($this, 'addMetaBox'));

        /* HTML код блока */
        add_action('wp_ajax_boom_meta_box_get_check_results', array($this, 'metaBoxGetCheckResults'));

        add_action('cw_scheduled_check', array($this, 'checkPost'), 10, 3);
    }

    /**
     * @param string $name
     * @return null
     */
    protected function getOption($name)
    {
        $options = get_option($this->settingsGroup);
        $fullOptionName = $name;

        if (empty($options) || !is_array($options)) {
            return null;
        }

        return $options[$fullOptionName];
    }

    public function plugin_options() {
        add_options_page(
            'Настройка Api_Content-watch',
            'Настройка Content-watch',
            'manage_options',
            $this->pageName,
            array($this, 'plugin_options_page')
        );
    }

    /**
     * Plugin options page
     */
    public function plugin_options_page()
    {
        echo <<<HTML
        <div class="wrap">
            <h2>API проверки на уникальность Content-watch</h2>
            <p>Плагин позволяет автоматически проверять текст
                ваших постов на уникальность при добавлении и правке.</p>
            <p>Для проверки используется API сервиса
                <a href="https://content-watch.ru/api/" target="_blank">https://content-watch.ru/api/</a></p>
HTML;

        $stepsHtml = <<<HTML
            <p>1. Зарегистрируйтесь на сайте
                <a href="https://content-watch.ru/login/register/" target="_blank">content-watch.ru</a></p>
            <p>2. Подключите API на странице
                <a href="https://content-watch.ru/api/" target="_blank">content-watch.ru/api/</a></p>
            <p>3. Введите ваш уникальный API-ключ ниже в настройках</p>
            <p>4. При добавлении и правке постов их текст будет автоматически проверяться на уникальность!</p>

            <form method="post" enctype="multipart/form-data" action="options.php">
HTML;

        $key = $this->getOption('Content-watch_api_key');
        if ($this->getOption('Content-watch_api_key')) {
            echo <<<HTML
            <h2>Ваш аккаунт</h2>
            <p><strong>Денег на счету: {$this->getBalanceFromAPI()}</strong></p>
            <p><button class="button" id="button_sarlab_balance" data-id="{$key}">Проверить баланс</button></p>
            <p><a class="button" href="http://content-watch.ru/pay/#api" target="_blank">Пополнить баланс</a></p>
        </div>
HTML;
        } else {
            echo '<h2>Начало работы</h2>' . $stepsHtml;
        }

        echo settings_fields($this->settingsGroup);
        echo do_settings_sections($this->pageName);
        echo <<<HTML
                <p class="submit">
                    <input type="submit" class="button-primary" value="Сохранить настройки" />
                </p>
            </form>
HTML;

        if ($this->getOption('Content-watch_api_key')) {
            echo '<h2>Схема работы</h2>' . $stepsHtml;
        }
    }

    /*
     * Регистрируем настройки
     */
    public function register_settings()
    {
        register_setting(
            $this->settingsGroup,
            $this->settingsGroup,
            array($this, 'true_validate_settings')
        );

        // Добавляем секцию
        add_settings_section(
            'cw_section',
            'Настройки',
            '',
            $this->pageName
        );

        $desc = '* обязательно подключите API на странице
            <a href="https://content-watch.ru/api/" target="_blank">
                https://content-watch.ru/api/
            </a> и введите здесь ваш уникальный ключ';
        $true_field_params = array(
            'type'      => 'text',
            'id'        => 'Content-watch_api_key',
            'required'  => ' required="required"',
            'desc'      => $desc,
            'label_for' => 'Content-watch_api_key'
        );

        add_settings_field(
            'my_text_field',
            'Ваш API ключ',
            array($this, 'display_input_field'),
            $this->pageName,
            'cw_section',
            $true_field_params
        );

        $true_field_params = array(
            'type' => 'radio',
            'id'   => 'Content-watch_status',
            'vals' => array('off' => 'Не проверять автоматически', 'on' => 'Проверять посты при добавлении и правке')
        );
        add_settings_field(
            'Content-watch_status',
            'Статус плагина',
            array($this, 'display_input_field'),
            $this->pageName,
            'cw_section',
            $true_field_params
        );
    }

    /*
     * Функция проверки правильности вводимых полей
     */
    public function true_validate_settings($input)
    {
        foreach($input as $k => $v) {
            $valid_input[$k] = trim($v);
        }
        return $valid_input;
    }

    /**
     * @param $columns
     * @return mixed
     */
    public function add_column($columns)
    {
        $columns['sarlab_column'] = 'Content-watch';
        return $columns;
    }

    /**
     * @param $column_name
     * @param $post_id
     */
    public function fill_column($column_name, $post_id)
    {
        if ($column_name != 'sarlab_column') {
            return;
        }

        echo '<span class="sarlab_column_value">';
        $post = get_post($post_id, "OBJECT");
        $timestamp = strtotime($post->post_modified);

        if ($timestamp <= get_post_meta($post_id, 'content-watch-date', 1)) {
            echo get_post_meta($post_id, 'content-watch', 1)
                . "<br/>Последняя проверка: "
                . date("d.m.Y", get_post_meta($post_id, 'content-watch-date', 1));
        } else if (get_post_meta($post_id, 'content-watch-check', 1) == "check") {
            echo "Идет проверка";
        } else {
            echo "Еще не проверен";
        }

        echo '</span><br/><span class="sarlab_check" data-id="' . $post_id . '">Проверить</span>';
    }

    public function check_balance()
    {
        echo "Денег на счету: " . $this->getBalanceFromAPI();
        wp_die();
    }

    public function addMetaBox()
    {
        $screens = array('post','page');
        foreach ($screens as $screen) {
            add_meta_box('boom_sectionid_box', 'Content-watch', array($this, 'metaBoxGetPrintCheckResults'), $screen, 'normal', 'high');
        }
    }

    public function metaBoxGetPrintCheckResults()
    {
        global $post;
        // Используем nonce для верификации
        wp_nonce_field(plugin_basename(__FILE__), 'boom_noncename');

        if (get_post_meta($post->ID, "content-watch-check", 1) == "check") {
            echo '<div class="sarlab_for_check">Текст отправлен на проверку уникальности<br/><span class="button sarlab_check_cron" data-id="'.$post->ID.'">Получить результат без перезагрузки страницы</span></div><div id="sarlab_result"></div>';
        } else {
            // Поля формы для введения данных
            echo '<span class="button sarlab_check" data-id="'.$post->ID.'">Проверить текст</span>
                <div class="sarlab_column_value">'.get_post_meta($post->ID, 'content-watch', 1);
            echo $this->getPostMatchesHTML($post->ID);
            echo '</div>';
        }
    }

    public function metaBoxGetCheckResults()
    {
        $post_id = $_POST['post_id'];
        if (get_post_meta($post_id, "content-watch-check", 1) == "check") {
            echo 'error';
        } else {
            // Поля формы для введения данных
            echo '<span class="button sarlab_check" data-id="'.$post_id.'">Проверить текст</span>
                <div class="sarlab_column_value">'
                . get_post_meta($post_id, 'content-watch', 1);


            echo $this->getPostMatchesHTML($post_id);
            echo '</div>';
        }

        wp_die();
    }

    /**
     * @param int $postId
     * @return string
     */
    protected function getPostMatchesHTML($postId)
    {
        $matches = json_decode(get_post_meta($postId, 'content-watch-json', 1), true);
        return $this->formatMatchesHTML($matches);
    }

    /**
     * @param array $matches
     * @return string
     */
    protected function formatMatchesHTML($matches)
    {
        $return = '';

        if (isset($matches[0]["url"])) {
            $return .= "<table><tr><th>Сайт</th><th>Процент</th></tr>";

            foreach($matches as $match) {
                $return .= "<tr>
                            <td><a href='" . $match["url"] . "' target='_blank'>" . $match["url"] . "</a></td>
                            <td>" . $match["percent"] . "</td>
                        </tr>";
            }

            $return .= "</table>";
        }

        return $return;
    }

    public function ajaxCheckTriggerId()
    {
        $postId = intval($_POST['post_id']);
        $this->checkPost($_POST['post_id']);
        echo get_post_meta($postId, 'content-watch', 1);
        wp_die();
    }

    public function ajaxCheckTriggerText()
    {
        $postId = intval($_POST['post_id']);
        $this->checkPost($postId, $_POST['text']);
        echo get_post_meta($postId, 'content-watch', 1)
            . $this->getPostMatchesHTML($postId);
        wp_die();
    }

    /**
     * @param $postId
     * @param null $text
     * @return bool
     */
    public function checkPost($postId, $text = null)
    {
        $postId = intval($postId);

        if ($text === null) {
            $post = get_post($postId);
            $text = $post->post_content;
        }
        $return = $this->queryAPI(array('text' => $text));

        if (!isset($return['error'])) {
            $text_done = 'Ошибка запроса на проверку уникальности';
            update_post_meta($postId, "content-watch-json", json_encode(array()));
        } else if (!empty($return['error'])) {
            $text_done = 'Ошибка проверки: ' . $return['error'];
            update_post_meta($postId, "content-watch-json", json_encode(array()));
        } else {
            $text_done = "Уникальность: " . $return["percent"] . "%";
            update_post_meta($postId, "content-watch-json", json_encode($return["matches"]));
        }

        $timestamp = time() + get_option('gmt_offset') * 3600;
        update_post_meta($postId, "content-watch-date", $timestamp);
        update_post_meta($postId, "content-watch", $text_done);
        update_post_meta($postId, "content-watch-check", "nocheck");
        return true;
    }

    /**
     * @param $post_ID
     */
    public function onPostSave($post_ID)
    {
        update_post_meta($post_ID, "content-watch-check", "check");
        wp_schedule_single_event(time() + 1, 'cw_scheduled_check', array($post_ID));
    }

    /**
     * @return string
     */
    protected function getBalanceFromAPI()
    {
        $params = array(
            'action' => 'GET_BALANCE',
        );
        $response = $this->queryAPI($params);

        if (!empty($response['error'])) {
            return 'ошибка (' . $response['error'] . ')';
        }

        return $response["balance"] . ' руб.';
    }

    /**
     * @param array $params
     * @return array
     */
    protected function queryAPI(array $params)
    {
        $params += array(
            'key' => $this->getOption('Content-watch_api_key'),
        );

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
        curl_setopt($curl, CURLOPT_URL, 'https://content-watch.ru/public/api/');
        $response = json_decode(trim(curl_exec($curl)), true);
        curl_close($curl);

        return $response;
    }

    public function content_watch_plugin_admincss()
    {
        echo <<<HTML
            <style type='text/css'>
                .sarlab_column {
                    line-height: 2.2em;
                    padding-right: 15px;
                }
                .sarlab_check {
                    color: blue;
                    font-weight: bold;
                    cursor: pointer;
                }
            </style>
HTML;
    }

    public function content_watch_plugin_adminjs() {
        echo <<<HTML
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            jQuery(".sarlab_column .sarlab_check").bind("click",function(){
                var post_id = jQuery(this).data("id");
                var data = {
                    action: "check_post_by_id",
                    post_id: post_id
                };
                jQuery("#post-"+post_id+" .sarlab_column_value").html("Идет проверка");
                jQuery.post( ajaxurl, data, function(response) {
                    jQuery("#post-"+post_id+" .sarlab_column_value").html(response);
                    console.log("Ответ сервера: " + response);
                });
            });
            jQuery("#boom_sectionid_box .sarlab_check").bind("click",function(){
                var post_id = jQuery(this).data("id");
                var text = jQuery("textarea#content").val();
                var data = {
                    action: "check_post_new_text",
                    text: text,
                    post_id: post_id
                };
                jQuery("#boom_sectionid_box .sarlab_column_value").html("Идет проверка");
                jQuery.post( ajaxurl, data, function(response) {
                    jQuery("#boom_sectionid_box .sarlab_column_value").html(response);
                    console.log("Ответ сервера: " + response);
                });
            });
            jQuery("#button_sarlab_balance").bind("click",function(){
                var key = jQuery(this).data("id");
                var data = {
                    action: "cw_check_balance",
                    key: key
                };
                jQuery("#sarlab_balance").val("Идет запрос");
                jQuery.post( ajaxurl, data, function(response) {
                    jQuery("#sarlab_balance").val(response);
                    console.log("Ответ сервера: " + response);
                });
            });
            jQuery(".sarlab_check_cron").bind("click",function(){
                var post_id = jQuery(this).data("id");
                var data = {
                    action: "boom_meta_box_get_check_results",
                    post_id: post_id
                };
                jQuery("#sarlab_result").html("Идет запрос");
                jQuery.post( ajaxurl, data, function(response) {
                    if(response=="error") {
                        jQuery("#sarlab_result").html("Результат проверки не готов");
                    } else {
                        jQuery(".sarlab_for_check").remove();
                        jQuery("#sarlab_result").html(response);
                        }
                    console.log("Ответ сервера: " + response);
                });
            });
        });
        </script>
HTML;
    }

    /*
     * Функция отображения полей ввода
     * Здесь задаётся HTML и PHP, выводящий поля
     */
    public function display_input_field($args) {
        extract( $args );

        /**
         * @var string $type
         * @var string $desc
         * @var int $id
         * @var array $vals
         */
        $option = $this->getOption($id);
        switch ( $type ) {
            case 'text':
                echo sprintf(
                    "<input class='regular-text' type='text' id='%s' required='required' name='%s[%s]' value='%s' />", $id, $this->settingsGroup, $id, esc_attr(stripslashes($option))
                );
                echo $desc ? "<br /><span class='description'>$desc</span>" : "";
                break;
            case 'textarea':
                echo sprintf(
                    "<textarea class='code large-text' cols='50' rows='10' type='text' id='%s' name='%s[%s]'>%s</textarea>", $id, $this->settingsGroup, $id, esc_attr(stripslashes($option))
                );
                echo $desc ? "<br /><span class='description'>$desc</span>" : "";
                break;
            case 'checkbox':
                $checked = ($option == 'on') ? " checked='checked'" :  '';
                echo sprintf(
                    "<label><input type='checkbox' id='%s' name='%s[%s]' %s /> ", $id, $this->settingsGroup, $id, $checked
                );
                echo $desc ? $desc : "";
                echo "</label>";
                break;
            case 'select':
                echo sprintf(
                    "<select id='%s' name='%s[%s]'>", $id, $this->settingsGroup, $id
                );
                foreach($vals as $v => $l){
                    $selected = ($option == $v) ? "selected='selected'" : '';
                    echo "<option value='$v' $selected>$l</option>";
                }
                echo $desc ? $desc : "";
                echo "</select>";
                break;
            case 'radio':
                echo "<fieldset>";
                foreach($vals as $v=>$l){
                    $checked = ($option == $v || !$option) ? "checked='checked'" : '';
                    echo sprintf(
                        "<label><input type='radio' name='%s[%s]' value='%s' %s />%s</label><br />", $this->settingsGroup, $id, $v, $checked, $l
                    );
                }
                echo "</fieldset>";
                break;
        }
    }
}
