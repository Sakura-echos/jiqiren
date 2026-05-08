<?php
/**
 * 发卡系统多语言包
 */

$lang_pack = [
    'zh_CN' => [
        // 主菜单
        'welcome' => '🏠 <b>主菜单</b>',
        'welcome_desc' => "欢迎使用自动发卡系统！",
        'current_balance' => "💰 当前余额：<b>\$%s</b>",
        'select_function' => "请选择您需要的功能：",
        
        // 按钮
        'btn_categories' => '📂 分类列表',
        'btn_products' => '📋 商品列表',
        'btn_my_orders' => '📧 我的订单',
        'btn_profile' => '👤 个人中心',
        'btn_recharge' => '💰 余额充值',
        'btn_contact' => '📱 联系客服',
        'btn_language' => '🌐 Language',
        'btn_back_menu' => '🏠 返回主菜单',
        'btn_back' => '🔙 返回',
        
        // 语言选择
        'language_title' => '🌐 <b>语言选择 / Language Selection</b>',
        'language_desc' => "请选择您的语言：\nPlease select your language:",
        'language_set' => "✅ 语言已设置为：<b>简体中文</b>",
        'language_set_success' => "✅ 已设置为 简体中文",
        
        // 分类和商品
        'category_list' => '📂 <b>商品分类</b>',
        'select_category' => '请选择商品分类：',
        'product_list' => '📋 <b>商品列表</b>',
        'select_product' => '请选择要购买的商品：',
        'no_categories' => '❌ 暂无商品分类',
        'no_products' => '❌ 该分类下暂无商品',
        'in_stock' => '库存：%d',
        'out_of_stock' => '已售罄',
        
        // 商品详情
        'product_detail' => '📦 <b>%s</b>',
        'price' => '💰 <b>价格：</b>\$%s',
        'stock' => '📦 <b>库存：</b>%d 件',
        'description' => '📝 <b>商品说明：</b>',
        'select_quantity' => '请选择购买数量：',
        'input_quantity' => '⌨️ 输入数量',
        
        // 订单
        'order_created' => '✅ <b>订单创建成功</b>',
        'order_no' => '📝 订单号：<code>%s</code>',
        'product_name' => '📦 商品：%s',
        'quantity' => '🔢 数量：%d',
        'total_amount' => '💰 总金额：\$%s',
        'select_payment' => '请选择支付方式：',
        'pay_with_balance' => '💳 使用余额支付',
        'my_orders' => '📧 <b>我的订单</b>',
        'no_orders' => '暂无订单记录',
        'order_status' => '状态：%s',
        'view_order' => '查看订单 #%s',
        
        // 充值
        'recharge_title' => '💰 <b>余额充值</b>',
        'select_recharge_method' => '请选择充值方式：',
        'recharge_detail' => '💰 <b>%s 充值</b>',
        'min_recharge' => '💳 <b>最小充值：</b>\$%s',
        'wallet_address' => '📮 <b>收款地址：</b>',
        'network' => '🌐 <b>网络：</b>%s',
        'recharge_instructions' => '📝 <b>充值说明：</b>',
        'recharge_note' => '⚠️ <b>注意事项：</b>',
        
        // 个人中心
        'user_profile' => '👤 <b>个人中心</b>',
        'user_id' => 'ID：%s',
        'total_orders' => '📦 总订单：%d',
        'total_spent' => '💸 累计消费：\$%s',
        
        // 客服
        'contact_service' => '📱 <b>联系客服</b>',
        'contact_info' => "如需帮助，请联系客服：",
        'no_contact' => '客服信息暂未设置',
        
        // 支付
        'payment_success' => '✅ 支付成功！',
        'payment_failed' => '❌ 支付失败',
        'insufficient_balance' => '余额不足，当前余额：\$%s',
        'order_completed' => '订单已完成！',
        'cards_received' => '您购买的卡密：',
        
        // 状态
        'status_pending' => '待支付',
        'status_paid' => '已支付',
        'status_completed' => '已完成',
        'status_cancelled' => '已取消',
        'status_refunded' => '已退款',
        
        // 错误消息
        'error_occurred' => '操作失败，请重试',
        'product_not_found' => '商品不存在或已下架',
        'insufficient_stock' => '库存不足',
        'order_not_found' => '订单不存在',
        'unknown_action' => '未知操作',
    ],
    
    'zh_TW' => [
        // 主菜單
        'welcome' => '🏠 <b>主菜單</b>',
        'welcome_desc' => "歡迎使用自動發卡系統！",
        'current_balance' => "💰 當前餘額：<b>\$%s</b>",
        'select_function' => "請選擇您需要的功能：",
        
        // 按鈕
        'btn_categories' => '📂 分類列表',
        'btn_products' => '📋 商品列表',
        'btn_my_orders' => '📧 我的訂單',
        'btn_profile' => '👤 個人中心',
        'btn_recharge' => '💰 餘額充值',
        'btn_contact' => '📱 聯繫客服',
        'btn_language' => '🌐 Language',
        'btn_back_menu' => '🏠 返回主菜單',
        'btn_back' => '🔙 返回',
        
        // 語言選擇
        'language_title' => '🌐 <b>語言選擇 / Language Selection</b>',
        'language_desc' => "請選擇您的語言：\nPlease select your language:",
        'language_set' => "✅ 語言已設置為：<b>繁體中文</b>",
        'language_set_success' => "✅ 已設置為 繁體中文",
        
        // 其他翻译...
        'category_list' => '📂 <b>商品分類</b>',
        'select_category' => '請選擇商品分類：',
        'product_list' => '📋 <b>商品列表</b>',
        'no_products' => '❌ 該分類下暫無商品',
        'in_stock' => '庫存：%d',
        'error_occurred' => '操作失敗，請重試',
    ],
    
    'en_US' => [
        // Main menu
        'welcome' => '🏠 <b>Main Menu</b>',
        'welcome_desc' => "Welcome to Auto Card System!",
        'current_balance' => "💰 Current Balance: <b>\$%s</b>",
        'select_function' => "Please select a function:",
        
        // Buttons
        'btn_categories' => '📂 Categories',
        'btn_products' => '📋 Products',
        'btn_my_orders' => '📧 My Orders',
        'btn_profile' => '👤 Profile',
        'btn_recharge' => '💰 Recharge',
        'btn_contact' => '📱 Contact',
        'btn_language' => '🌐 Language',
        'btn_back_menu' => '🏠 Back to Menu',
        'btn_back' => '🔙 Back',
        
        // Language selection
        'language_title' => '🌐 <b>Language Selection</b>',
        'language_desc' => "Please select your language:",
        'language_set' => "✅ Language set to: <b>English</b>",
        'language_set_success' => "✅ Set to English",
        
        // Others...
        'category_list' => '📂 <b>Categories</b>',
        'select_category' => 'Please select a category:',
        'product_list' => '📋 <b>Products</b>',
        'no_products' => '❌ No products in this category',
        'in_stock' => 'Stock: %d',
        'error_occurred' => 'Operation failed, please try again',
    ],
    
    'ru_RU' => [
        // Главное меню
        'welcome' => '🏠 <b>Главное меню</b>',
        'welcome_desc' => "Добро пожаловать в систему автоматических карт!",
        'current_balance' => "💰 Текущий баланс: <b>\$%s</b>",
        'select_function' => "Пожалуйста, выберите функцию:",
        
        // Кнопки
        'btn_categories' => '📂 Категории',
        'btn_products' => '📋 Товары',
        'btn_my_orders' => '📧 Мои заказы',
        'btn_profile' => '👤 Профиль',
        'btn_recharge' => '💰 Пополнить',
        'btn_contact' => '📱 Контакты',
        'btn_language' => '🌐 Language',
        'btn_back_menu' => '🏠 Главное меню',
        'btn_back' => '🔙 Назад',
        
        // Выбор языка
        'language_title' => '🌐 <b>Выбор языка / Language Selection</b>',
        'language_desc' => "Пожалуйста, выберите язык:",
        'language_set' => "✅ Язык установлен: <b>Русский</b>",
        'language_set_success' => "✅ Установлен русский",
        
        // Другое...
        'category_list' => '📂 <b>Категории</b>',
        'select_category' => 'Пожалуйста, выберите категорию:',
        'product_list' => '📋 <b>Товары</b>',
        'no_products' => '❌ Нет товаров в этой категории',
        'in_stock' => 'В наличии: %d',
        'error_occurred' => 'Операция не удалась, попробуйте снова',
    ]
];

/**
 * 获取翻译文本
 */
function getLang($key, $lang_code = 'zh_CN', ...$args) {
    global $lang_pack;
    static $db_translations = [];
    static $db_loaded = [];
    
    // 如果该语言未从数据库加载过，尝试加载
    if (!isset($db_loaded[$lang_code])) {
        try {
            require_once __DIR__ . '/../config.php';
            $db = getDB();
            $stmt = $db->prepare("SELECT trans_key, trans_value FROM language_translations WHERE language_code = ?");
            $stmt->execute([$lang_code]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 将翻译存入缓存
            $db_translations[$lang_code] = [];
            foreach ($rows as $row) {
                $db_translations[$lang_code][$row['trans_key']] = $row['trans_value'];
            }
            
            $db_loaded[$lang_code] = true;
        } catch (Exception $e) {
            error_log("Load translations error: " . $e->getMessage());
            $db_loaded[$lang_code] = false;
        }
    }
    
    // 优先使用数据库翻译
    if (isset($db_translations[$lang_code][$key])) {
        $text = $db_translations[$lang_code][$key];
    }
    // 回退到硬编码的语言包
    elseif (isset($lang_pack[$lang_code][$key])) {
        $text = $lang_pack[$lang_code][$key];
    }
    // 尝试使用默认语言的数据库翻译
    elseif (isset($db_translations['zh_CN'][$key])) {
        $text = $db_translations['zh_CN'][$key];
    }
    // 尝试使用默认语言的硬编码翻译
    elseif (isset($lang_pack['zh_CN'][$key])) {
        $text = $lang_pack['zh_CN'][$key];
    }
    // 最后返回键本身
    else {
        $text = $key;
    }
    
    // 如果提供了参数，使用 sprintf 格式化（或简单的字符串替换）
    if (!empty($args)) {
        // 支持 {lang} 等特殊占位符
        if (strpos($text, '{lang}') !== false) {
            $text = str_replace('{lang}', $args[0] ?? '', $text);
        } elseif (strpos($text, '%') !== false) {
            return sprintf($text, ...$args);
        }
    }
    
    return $text;
}

/**
 * 获取用户语言
 */
function getUserLanguage($db, $user_id) {
    try {
        $stmt = $db->prepare("SELECT language FROM card_users WHERE telegram_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if ($user && $user['language']) {
            $lang = $user['language'];
            
            // 兼容旧格式：如果是'zh'，转换为'zh_CN'
            if ($lang == 'zh') {
                return 'zh_CN';
            }
            
            return $lang;
        }
    } catch (Exception $e) {
        error_log("Get user language error: " . $e->getMessage());
    }
    
    return 'zh_CN'; // 默认简体中文
}

