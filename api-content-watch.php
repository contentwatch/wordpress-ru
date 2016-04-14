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
	protected $true_page = 'api-content-watch.php';
	protected $settingsGroup = 'content_watch_plugin_settings';

	public function init()
	{
		add_action('admin_head', [$this, 'content_watch_plugin_admincss']);
		add_action('admin_head', 'content_watch_plugin_adminjs');
		add_action('admin_menu', [$this, 'plugin_options']);
		add_action('admin_init', [$this, 'register_settings']);

		if (get_option($this->settingsGroup)["Content-watch_status"] == "on") {
			add_filter('save_post', [$this, 'sarlab_check_callback4']);
			add_filter('publush_post', [$this, 'sarlab_check_callback4']);
		}

		// создаем новую колонку
		add_filter('manage_edit-post_columns', 'add_boom_column', 4);
		add_filter('manage_edit-page_columns', 'add_boom_column', 4);


		// заполняем колонку данными
		add_filter('manage_posts_custom_column', 'fill_boom_column', 5, 2);
		add_filter('manage_pages_custom_column', 'fill_boom_column', 5, 2);

		/* Обработчики AJAX */
		add_action('wp_ajax_sarlab_check_balance', 'sarlab_check_balance');

		add_action('wp_ajax_sarlab_check', 'sarlab_check_callback');
		add_action('wp_ajax_sarlab_check2', 'sarlab_check_callback2');

		add_action('add_meta_boxes', 'boom_add_custom_box');

		/* HTML код блока */
		add_action('wp_ajax_boom_meta_box_ajax_callback', 'boom_meta_box_ajax_callback');

		add_action( 'boom_event', 'sarlab_check_callback3', 10, 3 );
	}

	/**
	 * @param string $text
	 * @return array
	 */
	protected function queryAPI($text)
	{
		$post_data = [
			'key' => get_option($this->settingsGroup)["Content-watch_api_key"],
			'text' => $text,
		];

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_POST, TRUE);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($curl, CURLOPT_URL, 'https://content-watch.ru/public/api/');
		$return = json_decode(trim(curl_exec($curl)), TRUE);
		curl_close($curl);
		return $return;
	}

	public function plugin_options() {
		add_options_page(
			'Настройка Api_Content-watch',
			'Настройка Content-watch',
			'manage_options',
			$this->true_page,
			[$this, 'plugin_options_page']
		);
	}

	/**
	 * Plugin options page
	 */
	public function plugin_options_page()
	{
		echo <<<HTML
		<div class="wrap">
			<h2>Api Content-watch</h2>
			<p>Плагин позволяет интегрировать автоматическую проверку уникальности
			вашего контента сервисом <a href="https://content-watch.ru/" target="_blank">
			https://content-watch.ru</a></p>
			<form method="post" enctype="multipart/form-data" action="options.php">
HTML;
		echo settings_fields($this->settingsGroup);
		echo do_settings_sections($this->true_page);

		echo <<<HTML
				<p class="submit">
					<input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
				</p>
			</form>
		<button class="button" id="button_sarlab_balance"
			data-id="<?php echo get_option($this->settingsGroup)["Content-watch_api_key"]; ?>">
			Проверить баланс
		</button>
HTML;

		$post_data = array(
			'action' => 'GET_BALANCE', // указываем, что нам необходимо получить баланс аккаунта
			'key' => get_option($this->settingsGroup)["Content-watch_api_key"] // ваш ключ доступа
		);

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_POST, TRUE);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($curl, CURLOPT_URL, 'http://www.content-watch.ru/public/api/');
		$return = json_decode(trim(curl_exec($curl)), TRUE);
		curl_close($curl);

		if (!empty($return['error'])) {
			$balance = 'Возникла ошибка: ' . $return['error'];
		} else {
			$balance = $return["balance"];
		}

		echo <<<HTML
			<input id="sarlab_balance" value="Баланс: <?php echo $balance; ?> руб."
				disabled="disabled"><br/>
			<a class="button" href="http://content-watch.ru/pay/#api" target="_blank">
				Пополнить баланс</a>
		</div>
HTML;
	}

	/*
     * Регистрируем настройки
     */
	function register_settings()
	{
		register_setting(
			$this->settingsGroup,
			$this->settingsGroup,
			'true_validate_settings'
		);

		// Добавляем секцию
		add_settings_section(
			'sarlab_section_1',
			'Настройки плагина Content-watch',
			'',
			$this->true_page
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
			'API key',
			[$this, 'display_input_field'],
			$this->true_page,
			'sarlab_section_1',
			$true_field_params
		);

		$true_field_params = array(
			'type' => 'radio',
			'id'   => 'Content-watch_status',
			'vals' => array('off' => 'Не проверять на уникальность', 'on' => 'Проверять на уникальность')
		);
		add_settings_field(
			'Content-watch_status',
			'Состояние проверок',
			[$this, 'display_input_field'],
			$this->true_page,
			'sarlab_section_1',
			$true_field_params
		);
	}

	/*
     * Функция отображения полей ввода
     * Здесь задаётся HTML и PHP, выводящий поля
     */
	function display_input_field($args) {
		extract( $args );

		$option_name = $this->settingsGroup;

		$o = get_option($option_name);

		/**
		 * @var string $type
		 * @var string $desc
		 * @var int $id
		 * @var array $vals
		 */
		switch ( $type ) {
			case 'text':
				$o[$id] = esc_attr( stripslashes($o[$id]) );
				echo "<input class='regular-text' type='text' id='$id' required='required' name='" . $option_name . "[$id]' value='$o[$id]' />";
				echo ($desc != '') ? "<br /><span class='description'>$desc</span>" : "";
				break;
			case 'textarea':
				$o[$id] = esc_attr( stripslashes($o[$id]) );
				echo "<textarea class='code large-text' cols='50' rows='10' type='text' id='$id' name='" . $option_name . "[$id]'>$o[$id]</textarea>";
				echo ($desc != '') ? "<br /><span class='description'>$desc</span>" : "";
				break;
			case 'checkbox':
				$checked = ($o[$id] == 'on') ? " checked='checked'" :  '';
				echo "<label><input type='checkbox' id='$id' name='" . $option_name . "[$id]' $checked /> ";
				echo ($desc != '') ? $desc : "";
				echo "</label>";
				break;
			case 'select':
				echo "<select id='$id' name='" . $option_name . "[$id]'>";
				foreach($vals as $v=>$l){
					$selected = ($o[$id] == $v) ? "selected='selected'" : '';
					echo "<option value='$v' $selected>$l</option>";
				}
				echo ($desc != '') ? $desc : "";
				echo "</select>";
				break;
			case 'radio':
				echo "<fieldset>";
				foreach($vals as $v=>$l){
					$checked = ($o[$id] == $v||!$o[$id]) ? "checked='checked'" : '';
					echo "<label><input type='radio' name='" . $option_name . "[$id]' value='$v' $checked />$l</label><br />";
				}
				echo "</fieldset>";
				break;
		}
	}

	/*
     * Функция проверки правильности вводимых полей
     */
	function true_validate_settings($input)
	{
		foreach($input as $k => $v) {
			$valid_input[$k] = trim($v);
		}
		return $valid_input;
	}

	function add_boom_column( $columns )
	{
		$columns['sarlab_column'] = 'Content-watch';
		return $columns;
	}

	function fill_boom_column($column_name, $post_id)
	{
		if ($column_name != 'sarlab_column') {
			return;
		}

		echo '<span class="sarlab_column_value">';
		$date_mod = get_post($post_id, "OBJECT");
		$date_mod_array = explode(' ', $date_mod->post_modified);
		$date_mod_array_date = explode('-', $date_mod_array[0]);
		$date_mod_array_time = explode(':', $date_mod_array[1]);
		$date_mod = mktime(
			$date_mod_array_time[0],
			$date_mod_array_time[1],
			$date_mod_array_time[2],
			$date_mod_array_date[1],
			$date_mod_array_date[2],
			$date_mod_array_date[0]
		);

		if ($date_mod <= get_post_meta($post_id, 'content-watch-date', 1)) {
			echo get_post_meta($post_id, 'content-watch', 1) . "<br/>Последняя проверка: " . date("d.m.Y", get_post_meta($post_id, 'content-watch-date', 1));
		} else if (get_post_meta($post_id, 'content-watch-check', 1) == "check") {
			echo "Идет проверка";
		} else {
			echo "Еще не проверен";
		}

		echo '</span><br/><span class="sarlab_check" data-id="' . $post_id . '">Проверить</span>';
	}

	function sarlab_check_balance()
	{
		$post_data = array(
			'action' => 'GET_BALANCE',
			'key' => get_option($this->settingsGroup)["Content-watch_api_key"],
		);

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_POST, TRUE);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($curl, CURLOPT_URL, 'http://www.content-watch.ru/public/api/');
		$return = json_decode(trim(curl_exec($curl)), TRUE);
		curl_close($curl);

		if (!empty($return['error'])) {
			$balance = 'Возникла ошибка: ' . $return['error'];
		} else {
			$balance = $return["balance"];
		}
		$text_done = "Баланс: ".$balance." руб.";
		echo $text_done;
		wp_die(); // выход нужен для того, чтобы в ответе не было ничего лишнего,
		// только то что возвращает функция
	}

	function sarlab_check_callback()
	{
		$posts2 = get_post($_POST['post_id']);

		$text = $posts2->post_content;

		$post_data = array(
			'key' => get_option($this->settingsGroup)["Content-watch_api_key"],
			'text' => $text,
			'test' => 0,
		);

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_POST, TRUE);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($curl, CURLOPT_URL, 'http://www.content-watch.ru/public/api/');
		$return = json_decode(trim(curl_exec($curl)), TRUE);
		curl_close($curl);

		if (!isset($return['error'])) {
			echo 'Ошибка запроса';
		} else if (!empty($return['error'])) {
			$text_done = '<span title="' . $return['error'].'">Ошибка проверки</span>';
		} else {
			$text_done = "Уникальность: " . $return["percent"]."%";
			update_post_meta( $_POST['post_id'], "content-watch-json", json_encode($return["matches"]));
		}

		$timestamp = time() + get_option('gmt_offset') * 3600;
		update_post_meta( $_POST['post_id'], "content-watch-date", $timestamp);
		update_post_meta( $_POST['post_id'], "content-watch", $text_done);
		echo $text_done;
		wp_die(); // выход нужен для того, чтобы в ответе не было ничего лишнего, только то что возвращает функция
	}

	function sarlab_check_callback2()
	{
		$text = $_POST['text'];
		$post_data = array(
			'key' => get_option($this->settingsGroup)["Content-watch_api_key"],
			'text' => $text,
			'test' => 0,
		);

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_POST, TRUE);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($curl, CURLOPT_URL, 'http://www.content-watch.ru/public/api/');
		$return = json_decode(trim(curl_exec($curl)), TRUE);
		curl_close($curl);

		if (!isset($return['error'])) {
			echo 'Ошибка запроса';
		} else if (!empty($return['error'])) {
			$text_done = 'Ошибка проверки: '.$return['error'];
		} else {
			$text_done = "Уникальность: " . $return["percent"] . "%";
			update_post_meta($_POST['post_id'], "content-watch-json", json_encode($return["matches"]));
		}

		$timestamp = time() + get_option('gmt_offset') * 3600;
		update_post_meta($_POST['post_id'], "content-watch-date", $timestamp);
		update_post_meta($_POST['post_id'], "content-watch", $text_done);

		$arr_json = $return["matches"];
		if (isset($arr_json[0]["url"])) {
			$text_done .= "<table><tr><th>Сайт</th><th>Процент</th></tr>";
			foreach ($arr_json as $val) {
				$text_done .= "<tr>
						<td><a href='" . $val["url"] . "' target='_blank'>" . $val["url"] . "</a></td>
						<td>" . $val["percent"] . "</td>
					</tr>";
			}
			$text_done .= "</table>";
		}
		echo $text_done;
		wp_die(); // выход нужен для того, чтобы в ответе не было ничего лишнего, только то что возвращает функция
	}

	/* Блок для сингла */
	function boom_add_custom_box()
	{
		$screens = array('post','page');
		foreach ( $screens as $screen ) {
			add_meta_box('boom_sectionid_box', 'Content-watch', 'boom_meta_box_callback', $screen, 'normal', 'high');
		}
	}

	function boom_meta_box_callback()
	{
		global $post;
		// Используем nonce для верификации
		wp_nonce_field( plugin_basename(__FILE__), 'boom_noncename' );

		if (get_post_meta( $post->ID, "content-watch-check", 1) == "check") {
			echo '<div class="sarlab_for_check">Текст отправлен на проверку уникальности<br/><span class="button sarlab_check_cron" data-id="'.$post->ID.'">Получить результат без перезагрузки страницы</span></div><div id="sarlab_result"></div>';
		} else {
			// Поля формы для введения данных
			echo '<span class="button sarlab_check" data-id="'.$post->ID.'">Проверить текст</span>
				<div class="sarlab_column_value">'.get_post_meta($post->ID, 'content-watch', 1);
			$arr_json2 = get_post_meta($post->ID, 'content-watch-json', 1);
			$arr_json = json_decode($arr_json2,true);
			if (isset($arr_json[0]["url"])) {
				echo "<table><tr><th>Сайт</th><th>Процент</th></tr>";
				foreach ($arr_json as $val) {
					echo "<tr>
							<td><a href='" . $val["url"] . "' target='_blank'>" . $val["url"] . "</a></td>
							<td>" . $val["percent"] . "</td>
						</tr>";
				}
				echo "</table>";
			}
			echo '</div>';
		}
	}

	function boom_meta_box_ajax_callback()
	{
		$post_id = $_POST['post_id'];
		if (get_post_meta( $post_id, "content-watch-check", 1)=="check") {
			echo 'error';
		} else {
			// Поля формы для введения данных
			echo '<span class="button sarlab_check" data-id="'.$post_id.'">Проверить текст</span>
				<div class="sarlab_column_value">'
				. get_post_meta($post_id, 'content-watch', 1);

			$arr_json2 = get_post_meta($post_id, 'content-watch-json', 1);
			$arr_json = json_decode($arr_json2, true);
			if (isset($arr_json[0]["url"])) {
				echo "<table><tr><th>Сайт</th><th>Процент</th></tr>";
				foreach($arr_json as $val) {
					echo "<tr>
							<td>
								<a href='" . $val["url"] . "' target='_blank'>" . $val["url"] . "</a>
							</td>
							<td>".$val["percent"]."</td>
						</tr>";
				}
				echo "</table>";
			}
			echo '</div>';
		}

		wp_die();
	}

	function sarlab_check_callback3($post_ID)
	{
		$posts2 = get_post($post_ID);

		$text = $posts2->post_content;

		$post_data = array(
			'key' => get_option($this->settingsGroup)["Content-watch_api_key"],
			'text' => $text,
			'test' => 0,
		);

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_POST, TRUE);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($curl, CURLOPT_URL, 'http://www.content-watch.ru/public/api/');
		$return = json_decode(trim(curl_exec($curl)), TRUE);
		curl_close($curl);

		if (!isset($return['error'])) {
			echo 'Ошибка запроса';

		} else if (!empty($return['error'])) {
			$text_done = 'Ошибка проверки: '.$return['error'];
		} else {
			$text_done = "Уникальность: ".$return["percent"]."%";
			update_post_meta(
				$post_ID,
				"content-watch-json",
				json_encode($return["matches"])
			);
		}

		$timestamp = time() + get_option('gmt_offset') * 3600;
		update_post_meta($post_ID, "content-watch-date", $timestamp);
		update_post_meta($post_ID, "content-watch", $text_done);
		update_post_meta($post_ID, "content-watch-check", "nocheck");
		return true;
	}

	function sarlab_check_callback4($post_ID)
	{
		update_post_meta($post_ID, "content-watch-check", "check");
		wp_schedule_single_event(time() + 1, 'boom_event', array($post_ID));
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
					action: "sarlab_check",
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
					action: "sarlab_check2",
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
					action: "sarlab_check_balance",
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
					action: "boom_meta_box_ajax_callback",
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
}
