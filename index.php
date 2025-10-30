<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 配置
$config = [
    'font_path' => __DIR__ . '/fonts/',
    'output_path' => __DIR__ . '/output/',
    'config_path' => __DIR__ . '/configs/',
    'default_font' => 'msyh.ttf'
];

// 创建必要的目录
foreach ([$config['font_path'], $config['output_path'], $config['config_path']] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

// 获取授权的 wxid
function isAuthorizedWxid($wxid) {
    $authorizedWxids = file(__DIR__ . '/wxid.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return in_array($wxid, $authorizedWxids);
}

// 获取请求参数
$action = $_GET['ac'] ?? 'all';
$wxid = $_GET['wxid'] ?? '';  // 获取wxid作为配置ID
$start = intval($_GET['start'] ?? 0);
$limit = intval($_GET['limit'] ?? 40);
$keyword = $_GET['keyword'] ?? '';

// 检查 wxid 是否授权
if (!isAuthorizedWxid($wxid)) {
    // 如果 wxid 未授权，替换请求的文字信息
    $keyword = '我是狗，偷接口，偷来接口当小丑';
}

// 处理请求
switch ($action) {
    case 'search':
    case 'all':
        $items = [];
        $text = urldecode($keyword);  // 使用替换后的文字
        
        // 获取用户配置
        if (!empty($wxid)) {
            $userConfig = getConfig($wxid);
            if ($userConfig['code'] === 1) {
                $styles = $userConfig['data']['styles'] ?? [];
            }
        }
        
        // 如果没有配置或获取失败，使用默认样式
        if (empty($styles)) {
            $styles = [[
                'font_family' => $config['default_font'],
                'font_size' => 32,
                'font_bold' => false,
                'text_align' => 'center',
                'random_color' => false,
                'effect' => 'none',
                'font_color' => '#000000',
                'bg_color' => '#FFFFFF',
                'thumbnail_mode' => false,
                'force_size' => false
            ]];
        }
        
        // 生成图片
        foreach ($styles as $style) {
            $params = [
                'font_size' => $style['font_size'] ?? 32,
                'font_color' => $style['font_color'] ?? '#000000',
                'bg_color' => $style['bg_color'] ?? '#FFFFFF',
                'bg_image_size' => $style['bg_image_size'] ?? 'cover',
                'font_file' => $config['font_path'] . ($style['font_family'] ?? $config['default_font']),
                'font_weight' => $style['font_weight'] ?? 0,
                'pos_x' => $style['pos_x'] ?? 50,
                'pos_y' => $style['pos_y'] ?? 50,
                'effect' => $style['effect'] ?? 'none',
                'random_color' => $style['random_color'] ?? false,
                'auto_size' => $style['auto_size'] ?? false
            ];
            
            // 如果启用了随机颜色，生成新的随机颜色
            if ($params['random_color']) {
                $params['font_color'] = sprintf('#%06X', mt_rand(0, 0xFFFFFF)); // 生成随机颜色
            } else {
                // 确保使用固定颜色
                $params['font_color'] = $style['font_color'] ?? '#000000';
            }
            
            $imageInfo = generateImage($text, $params);
            if ($imageInfo) {
                $items[] = [
                    'title' => time() . rand(1000, 9999),
                    'url' => $imageInfo['url']
                ];
            }
        }
        
        // 分页处理
        $totalSize = count($items);
        $items = array_slice($items, $start, $limit);
        
        echo json_encode([
            'items' => $items,
            'pageNum' => floor($start / $limit) + 1,
            'pageSize' => $limit,
            'totalPages' => ceil($totalSize / $limit),
            'totalSize' => $totalSize
        ], JSON_UNESCAPED_UNICODE);
        break;
        
    case 'config':
        // 处理配置保存和获取
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            $result = saveConfig($data);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } else {
            $id = $_GET['id'] ?? '';
            $result = getConfig($id);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'items':
        // 处理分页查询
        $keyword = $_GET['keyword'] ?? '';
        $pageNum = intval($_GET['pageNum'] ?? 1);
        $pageSize = intval($_GET['pageSize'] ?? 30);
        
        $items = [];
        if (!empty($keyword)) {
            $params = [
                'font_size' => 32,
                'font_color' => '#000000',
                'bg_color' => '#FFFFFF',
                'font_file' => $config['font_path'] . $config['default_font'],
                'padding' => 20
            ];
            
            $imageInfo = generateImage($keyword, $params);
            if ($imageInfo) {
                $items[] = [
                    'title' => $keyword,
                    'url' => $imageInfo['url']
                ];
            }
        }
        
        echo json_encode([
            'totalSize' => count($items),
            'totalPages' => 1,
            'pageSize' => $pageSize,
            'items' => $items
        ], JSON_UNESCAPED_UNICODE);
        break;
        
    case 'fonts':
        // 获取字体列表
        $fonts = getFontList();
        echo json_encode([
            'code' => 1,
            'msg' => '获取成功',
            'data' => $fonts
        ], JSON_UNESCAPED_UNICODE);
        break;
        
    case 'authorize':
        // 授权 wxid
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            $wxid = trim($data['wxid'] ?? '');
            
            // 验证 wxid
            if (empty($wxid)) {
                echo json_encode(['code' => 0, 'msg' => 'wxid不能为空'], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            if (strlen($wxid) > 30) {
                echo json_encode(['code' => 0, 'msg' => 'wxid不能超过30个字符'], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            // 读取现有的 wxid 列表
            $wxidFile = __DIR__ . '/wxid.txt';
            $existingWxids = [];
            
            if (file_exists($wxidFile)) {
                $existingWxids = file($wxidFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            }
            
            // 检查是否已存在
            if (in_array($wxid, $existingWxids)) {
                echo json_encode(['code' => 0, 'msg' => '该wxid已经授权过了'], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            // 添加新的 wxid
            if (file_put_contents($wxidFile, $wxid . PHP_EOL, FILE_APPEND | LOCK_EX)) {
                echo json_encode(['code' => 1, 'msg' => '授权成功'], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['code' => 0, 'msg' => '授权失败，请检查文件权限'], JSON_UNESCAPED_UNICODE);
            }
        } else {
            echo json_encode(['code' => 0, 'msg' => '请使用POST方法'], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'upload':
        // 上传字体文件
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // 检查是否有文件上传
            if (!isset($_FILES['font']) || $_FILES['font']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['code' => 0, 'msg' => '文件上传失败'], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            $file = $_FILES['font'];
            $fileName = $file['name'];
            $fileSize = $file['size'];
            $fileTmpPath = $file['tmp_name'];
            
            // 验证文件扩展名
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($fileExtension !== 'ttf' && $fileExtension !== 'ttc') {
                echo json_encode(['code' => 0, 'msg' => '只支持TTF/TTC格式的字体文件'], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            // 验证文件大小 (50MB = 50 * 1024 * 1024 bytes)
            if ($fileSize > 50 * 1024 * 1024) {
                echo json_encode(['code' => 0, 'msg' => '文件大小不能超过50MB'], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            // 确保字体目录存在
            $fontPath = $config['font_path'];
            if (!is_dir($fontPath)) {
                mkdir($fontPath, 0777, true);
            }
            
            // 保存文件
            $destPath = $fontPath . $fileName;
            
            // 检查文件是否已存在
            if (file_exists($destPath)) {
                echo json_encode(['code' => 0, 'msg' => '文件已存在，请重命名后再上传'], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            // 移动文件到目标目录
            if (move_uploaded_file($fileTmpPath, $destPath)) {
                // 设置文件权限
                chmod($destPath, 0644);
                echo json_encode([
                    'code' => 1,
                    'msg' => '上传成功',
                    'data' => [
                        'filename' => $fileName,
                        'size' => $fileSize
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['code' => 0, 'msg' => '保存文件失败'], JSON_UNESCAPED_UNICODE);
            }
        } else {
            echo json_encode(['code' => 0, 'msg' => '请使用POST方法'], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'subset':
        // 获取字体子集
        $text = $_GET['text'] ?? '预览文字';  // 要显示的文字
        $font = $_GET['font'] ?? '';  // 字体文件名
        $fontSize = intval($_GET['size'] ?? 32);
        $fontWeight = intval($_GET['weight'] ?? 0);  // 字体粗细 0-10
        $posX = intval($_GET['posX'] ?? 50);  // 水平位置百分比 0-100
        $posY = intval($_GET['posY'] ?? 50);  // 垂直位置百分比 0-100
        $fontColor = $_GET['color'] ?? '#000000';
        $bgColor = $_GET['bgcolor'] ?? '#FFFFFF';
        $bgImageSize = $_GET['bgsize'] ?? 'cover';  // 背景图缩放方式
        $effect = $_GET['effect'] ?? 'none';
        $isRandom = filter_var($_GET['random'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $autoSize = filter_var($_GET['autoSize'] ?? false, FILTER_VALIDATE_BOOLEAN);  // 自动字号
        
        // 如果开启自动字号，根据画布和文字长度自动计算最佳字体大小
        if ($autoSize) {
            $textLength = mb_strlen($text, 'UTF-8');
            // 目标画布大小 - 微信表情最大支持1024
            $targetWidth = 1024;
            $targetHeight = 1024;
            $padding = 50;
            
            // 二分查找最佳字体大小
            $minSize = 50;
            $maxSize = 900;  // 增加最大字号到900
            $bestSize = $fontSize;
            
            while ($maxSize - $minSize > 1) {
                $testSize = intval(($minSize + $maxSize) / 2);
                $box = imagettfbbox($testSize, 0, $config['font_path'] . $font, $text);
                $testWidth = abs($box[4] - $box[0]);
                $testHeight = abs($box[5] - $box[1]);
                
                // 检查是否适合画布（留出padding）
                if ($testWidth + ($padding * 2) <= $targetWidth && $testHeight + ($padding * 2) <= $targetHeight) {
                    $bestSize = $testSize;
                    $minSize = $testSize;  // 尝试更大的
                } else {
                    $maxSize = $testSize;  // 太大了，缩小
                }
            }
            
            $fontSize = $bestSize;
        }
        
        if (empty($font)) {
            echo json_encode(['code' => 0, 'msg' => '字体不能为空']);
            break;
        }
        
        $fontPath = $config['font_path'] . $font;
        if (!file_exists($fontPath)) {
            echo json_encode(['code' => 0, 'msg' => '字体文件不存在']);
            break;
        }
        
        try {
            // 计算文字尺寸
            $padding = 30;  // 增加内边距让位置更明显
            $box = imagettfbbox($fontSize, 0, $fontPath, $text);
            $text_width = abs($box[4] - $box[0]);
            $text_height = abs($box[5] - $box[1]);
            
            // 创建画布 - 如果开启自动字号则使用更大的画布
            if ($autoSize) {
                $width = max($text_width + (50 * 2), 1024);
                $height = max($text_height + (50 * 2), 1024);
            } else {
                $width = max($text_width + ($padding * 2), 400);
                $height = max($text_height + ($padding * 2), 200);
            }
            
            // 创建图片
            $image = imagecreatetruecolor($width, $height);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            
            // 设置背景
            if ($bgColor === 'image') {
                // 使用图片作为背景
                $bg_image_path = __DIR__ . '/img/background.png';
                if (file_exists($bg_image_path)) {
                    // 自动检测图片类型
                    $imageInfo = @getimagesize($bg_image_path);
                    $bg_image = false;
                    
                    if ($imageInfo !== false) {
                        switch ($imageInfo[2]) {
                            case IMAGETYPE_PNG:
                                $bg_image = @imagecreatefrompng($bg_image_path);
                                break;
                            case IMAGETYPE_JPEG:
                                $bg_image = @imagecreatefromjpeg($bg_image_path);
                                break;
                            case IMAGETYPE_GIF:
                                $bg_image = @imagecreatefromgif($bg_image_path);
                                break;
                        }
                    }
                    
                    if ($bg_image !== false) {
                        $bg_width = imagesx($bg_image);
                        $bg_height = imagesy($bg_image);
                        
                        switch ($bgImageSize) {
                            case 'cover':
                                // 填充：保持比例，覆盖整个区域
                                $ratio_w = $width / $bg_width;
                                $ratio_h = $height / $bg_height;
                                $ratio = max($ratio_w, $ratio_h);
                                $new_w = $bg_width * $ratio;
                                $new_h = $bg_height * $ratio;
                                $x_offset = ($width - $new_w) / 2;
                                $y_offset = ($height - $new_h) / 2;
                                imagecopyresampled($image, $bg_image, $x_offset, $y_offset, 0, 0, $new_w, $new_h, $bg_width, $bg_height);
                                break;
                                
                            case 'contain':
                                // 适应：保持比例，完整显示
                                $ratio_w = $width / $bg_width;
                                $ratio_h = $height / $bg_height;
                                $ratio = min($ratio_w, $ratio_h);
                                $new_w = $bg_width * $ratio;
                                $new_h = $bg_height * $ratio;
                                $x_offset = ($width - $new_w) / 2;
                                $y_offset = ($height - $new_h) / 2;
                                // 先填充白色背景
                                $white = imagecolorallocate($image, 255, 255, 255);
                                imagefill($image, 0, 0, $white);
                                imagecopyresampled($image, $bg_image, $x_offset, $y_offset, 0, 0, $new_w, $new_h, $bg_width, $bg_height);
                                break;
                                
                            case 'stretch':
                                // 拉伸：填满整个区域
                                imagecopyresampled($image, $bg_image, 0, 0, 0, 0, $width, $height, $bg_width, $bg_height);
                                break;
                                
                            case 'tile':
                                // 平铺：重复图片
                                for ($x = 0; $x < $width; $x += $bg_width) {
                                    for ($y = 0; $y < $height; $y += $bg_height) {
                                        imagecopy($image, $bg_image, $x, $y, 0, 0, $bg_width, $bg_height);
                                    }
                                }
                                break;
                                
                            default:
                                // 默认拉伸
                                imagecopyresampled($image, $bg_image, 0, 0, 0, 0, $width, $height, $bg_width, $bg_height);
                        }
                        
                        imagedestroy($bg_image);
                    } else {
                        // 如果图片无法加载，使用白色背景
                        $bg = imagecolorallocate($image, 255, 255, 255);
                        imagefill($image, 0, 0, $bg);
                    }
                } else {
                    // 背景图片不存在，使用白色背景
                    $bg = imagecolorallocate($image, 255, 255, 255);
                    imagefill($image, 0, 0, $bg);
                }
            } elseif ($bgColor === 'transparent') {
                $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
                imagefill($image, 0, 0, $transparent);
            } else {
                $bg_color = hex2rgb($bgColor);
                $bg = imagecolorallocate($image, $bg_color[0], $bg_color[1], $bg_color[2]);
                imagefill($image, 0, 0, $bg);
            }
            
            imagealphablending($image, true);
            
            // 设置字体颜色
            if ($isRandom) {
                $r = rand(0, 255);
                $g = rand(0, 255);
                $b = rand(0, 255);
            } else {
                list($r, $g, $b) = hex2rgb($fontColor);
            }
            $textColor = imagecolorallocate($image, $r, $g, $b);
            
            // 使用百分比计算位置
            $currentPadding = $autoSize ? 50 : $padding;
            
            // 水平位置：0% = 最左, 50% = 居中, 100% = 最右
            if ($posX == 50) {
                // 精确居中 - 使用bounding box的偏移量
                $x = ($width - $text_width) / 2 - $box[0];
            } else {
                $available_width = $width - $text_width - ($currentPadding * 2);
                $x = $currentPadding + ($available_width * $posX / 100) - $box[0];
            }
            
            // 垂直位置：0% = 最上, 50% = 居中, 100% = 最下
            if ($posY == 50) {
                // 精确居中（考虑文字基线和偏移）
                $y = ($height - $box[5] + $box[1]) / 2 - $box[1];
            } else {
                $available_height = $height - $text_height - ($currentPadding * 2);
                $y = $currentPadding + $text_height + ($available_height * $posY / 100);
            }
            
            // 应用特效
            switch ($effect) {
                case 'shadow':
                    // 阴影效果
                    $shadowColor = imagecolorallocatealpha($image, 0, 0, 0, 80);
                    imagettftext($image, $fontSize, 0, $x + 2, $y + 2, $shadowColor, $fontPath, $text);
                    break;
                    
                case 'outline':
                    // 描边效果
                    $outlineColor = imagecolorallocate($image, 0, 0, 0);
                    for($i = -1; $i <= 1; $i++) {
                        for($j = -1; $j <= 1; $j++) {
                            if($i != 0 || $j != 0) {
                                imagettftext($image, $fontSize, 0, $x + $i, $y + $j, $outlineColor, $fontPath, $text);
                            }
                        }
                    }
                    break;
                    
                case 'glow':
                    // 发光效果
                    $glowColor = imagecolorallocatealpha($image, 255, 255, 255, 80);
                    for($i = -2; $i <= 2; $i++) {
                        for($j = -2; $j <= 2; $j++) {
                            imagettftext($image, $fontSize, 0, $x + $i, $y + $j, $glowColor, $fontPath, $text);
                        }
                    }
                    break;
            }
            
            // 写入主文字 - 根据粗细值多次绘制
            if ($fontWeight > 0) {
                // 粗细值越大，绘制的偏移次数越多
                $offset = $fontWeight / 10;  // 最大偏移1像素
                for ($i = -$fontWeight; $i <= $fontWeight; $i++) {
                    $ox = $i * $offset / $fontWeight;
                    for ($j = -$fontWeight; $j <= $fontWeight; $j++) {
                        $oy = $j * $offset / $fontWeight;
                        if ($i == 0 && $j == 0) continue;
                        imagettftext($image, $fontSize, 0, $x + $ox, $y + $oy, $textColor, $fontPath, $text);
                    }
                }
            }
            imagettftext($image, $fontSize, 0, $x, $y, $textColor, $fontPath, $text);
        
            // 保存为 PNG
            $outputPath = $config['output_path'] . md5($text . $font . json_encode($_GET)) . '.png';
            imagepng($image, $outputPath);
            imagedestroy($image);
            
            echo json_encode([
                'code' => 1,
                'msg' => '成功',
                'data' => [
                    'url' => 'output/' . basename($outputPath)
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'code' => 0,
                'msg' => '生成失败: ' . $e->getMessage()
            ]);
        }
        break;
        
    default:
        echo json_encode([
            'code' => 0,
            'msg' => '无效的请求'
        ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取样式列表
 */
function getStyles($configId = '') {
    global $config;
    
    // 如果有配置ID，尝试读取配置
    if (!empty($configId)) {
        $configFile = $config['config_path'] . $configId . '.json';
        if (file_exists($configFile)) {
            $userConfig = json_decode(file_get_contents($configFile), true);
            if ($userConfig && isset($userConfig['styles'])) {
                return $userConfig['styles'];
            }
        }
    }
    
    // 默认样式
    return [
        [
            'title' => '默认黑',
            'font_size' => 32,
            'font_color' => '#000000',
            'bg_color' => '#FFFFFF'
        ],
        [
            'title' => '反转白',
            'font_size' => 32,
            'font_color' => '#FFFFFF',
            'bg_color' => '#000000'
        ],
        // ... 其他默认样式 ...
    ];
}

/**
 * 保存配置
 */
function saveConfig($data) {
    global $config;
    
    if (empty($data['id'])) {
        return ['code' => 0, 'msg' => '配置ID不能为空'];
    }
    
    $configFile = $config['config_path'] . $data['id'] . '.json';
    if (file_put_contents($configFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
        return ['code' => 1, 'msg' => '保存成功'];
    }
    
    return ['code' => 0, 'msg' => '保存失败'];
}

/**
 * 获取配置
 */
function getConfig($id) {
    global $config;
    
    try {
        // 尝试读取配置文件
        $configFile = $config['config_path'] . $id . '.json';
        if (file_exists($configFile)) {
            $content = file_get_contents($configFile);
            $data = json_decode($content, true);
            return [
                'code' => 1,
                'msg' => '获取成功',
                'data' => $data
            ];
        }
        return [
            'code' => 0,
            'msg' => '配置不存在'
        ];
    } catch (Exception $e) {
        return [
            'code' => 0,
            'msg' => '读取配置失败'
        ];
    }
}

/**
 * 生成图片
 */
function generateImage($text, $params) {
    try {
        // 如果开启自动字号，根据画布和文字长度自动计算最佳字体大小
        if (isset($params['auto_size']) && $params['auto_size']) {
            $textLength = mb_strlen($text, 'UTF-8');
            // 目标画布大小 - 微信表情最大支持1024
            $targetWidth = 1024;
            $targetHeight = 1024;
            $padding = 50;
            
            // 二分查找最佳字体大小
            $minSize = 50;
            $maxSize = 900;  // 增加最大字号到900
            $bestSize = $params['font_size'];
            
            while ($maxSize - $minSize > 1) {
                $testSize = intval(($minSize + $maxSize) / 2);
                $box = imagettfbbox($testSize, 0, $params['font_file'], $text);
                $testWidth = abs($box[4] - $box[0]);
                $testHeight = abs($box[5] - $box[1]);
                
                // 检查是否适合画布（留出padding）
                if ($testWidth + ($padding * 2) <= $targetWidth && $testHeight + ($padding * 2) <= $targetHeight) {
                    $bestSize = $testSize;
                    $minSize = $testSize;  // 尝试更大的
                } else {
                    $maxSize = $testSize;  // 太大了，缩小
                }
            }
            
            $params['font_size'] = $bestSize;
        }
        
        // 计算文字尺寸
        $box = imagettfbbox($params['font_size'], 0, $params['font_file'], $text);
        $text_width = abs($box[4] - $box[0]);
        $text_height = abs($box[5] - $box[1]);
        
        // 计算图片尺寸 - 使用和预览一样的尺寸
        if (isset($params['auto_size']) && $params['auto_size']) {
            // 自动字号时使用大画布
            $padding = 50;
            $width = max($text_width + ($padding * 2), 1024);  // 最小1024
            $height = max($text_height + ($padding * 2), 1024);
        } else {
            // 普通模式使用小画布
            $padding = 30;
            $width = max($text_width + ($padding * 2), 400);  // 最小宽度 400
            $height = max($text_height + ($padding * 2), 200);  // 最小高度 200
        }
        
        // 创建图片
        $image = imagecreatetruecolor($width, $height);
        
        // 启用透明度支持
        imagealphablending($image, true);
        imagesavealpha($image, true);
        
        // 设置背景
        if ($params['bg_color'] === 'image') {
            // 使用图片作为背景
            $bg_image_path = __DIR__ . '/img/background.png';
            if (file_exists($bg_image_path)) {
                // 自动检测图片类型
                $imageInfo = @getimagesize($bg_image_path);
                $bg_image = false;
                
                if ($imageInfo !== false) {
                    switch ($imageInfo[2]) {
                        case IMAGETYPE_PNG:
                            $bg_image = @imagecreatefrompng($bg_image_path);
                            break;
                        case IMAGETYPE_JPEG:
                            $bg_image = @imagecreatefromjpeg($bg_image_path);
                            break;
                        case IMAGETYPE_GIF:
                            $bg_image = @imagecreatefromgif($bg_image_path);
                            break;
                    }
                }
                
                if ($bg_image !== false) {
                    $bg_width = imagesx($bg_image);
                    $bg_height = imagesy($bg_image);
                    $bgImageSize = $params['bg_image_size'] ?? 'cover';
                    
                    imagealphablending($image, false);
                    
                    switch ($bgImageSize) {
                        case 'cover':
                            $ratio_w = $width / $bg_width;
                            $ratio_h = $height / $bg_height;
                            $ratio = max($ratio_w, $ratio_h);
                            $new_w = $bg_width * $ratio;
                            $new_h = $bg_height * $ratio;
                            $x_offset = ($width - $new_w) / 2;
                            $y_offset = ($height - $new_h) / 2;
                            imagecopyresampled($image, $bg_image, $x_offset, $y_offset, 0, 0, $new_w, $new_h, $bg_width, $bg_height);
                            break;
                            
                        case 'contain':
                            $ratio_w = $width / $bg_width;
                            $ratio_h = $height / $bg_height;
                            $ratio = min($ratio_w, $ratio_h);
                            $new_w = $bg_width * $ratio;
                            $new_h = $bg_height * $ratio;
                            $x_offset = ($width - $new_w) / 2;
                            $y_offset = ($height - $new_h) / 2;
                            $white = imagecolorallocate($image, 255, 255, 255);
                            imagefill($image, 0, 0, $white);
                            imagecopyresampled($image, $bg_image, $x_offset, $y_offset, 0, 0, $new_w, $new_h, $bg_width, $bg_height);
                            break;
                            
                        case 'stretch':
                            imagecopyresampled($image, $bg_image, 0, 0, 0, 0, $width, $height, $bg_width, $bg_height);
                            break;
                            
                        case 'tile':
                            for ($x_pos = 0; $x_pos < $width; $x_pos += $bg_width) {
                                for ($y_pos = 0; $y_pos < $height; $y_pos += $bg_height) {
                                    imagecopy($image, $bg_image, $x_pos, $y_pos, 0, 0, $bg_width, $bg_height);
                                }
                            }
                            break;
                            
                        default:
                            imagecopyresampled($image, $bg_image, 0, 0, 0, 0, $width, $height, $bg_width, $bg_height);
                    }
                    
                    imagealphablending($image, true);
                    imagedestroy($bg_image);
                }
            } else {
                // 如果图片不存在，使用白色背景
                $bg = imagecolorallocate($image, 255, 255, 255);
                imagefill($image, 0, 0, $bg);
            }
        } elseif ($params['bg_color'] === 'transparent') {
            // 完全透明的背景
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagefill($image, 0, 0, $transparent);
        } else {
            // 普通背景色
            $bg_color = hex2rgb($params['bg_color']);
            $bg = imagecolorallocate($image, $bg_color[0], $bg_color[1], $bg_color[2]);
            imagefill($image, 0, 0, $bg);
        }
        
        // 设置字体颜色
        if ($params['random_color']) {
            $r = rand(0, 255);
            $g = rand(0, 255);
            $b = rand(0, 255);
            $color = imagecolorallocate($image, $r, $g, $b);
        } else {
            // 使用固定颜色
            $font_color = hex2rgb($params['font_color']);
            $color = imagecolorallocate($image, $font_color[0], $font_color[1], $font_color[2]);
        }
        
        // 使用百分比计算位置
        $pos_x = isset($params['pos_x']) ? $params['pos_x'] : 50;
        $pos_y = isset($params['pos_y']) ? $params['pos_y'] : 50;
        
        // 水平位置：0% = 最左, 50% = 居中, 100% = 最右
        if ($pos_x == 50) {
            // 精确居中 - 使用bounding box的偏移量
            $x = ($width - $text_width) / 2 - $box[0];
        } else {
            $available_width = $width - $text_width - ($padding * 2);
            $x = $padding + ($available_width * $pos_x / 100) - $box[0];
        }
        
        // 垂直位置：0% = 最上, 50% = 居中, 100% = 最下
        if ($pos_y == 50) {
            // 精确居中（考虑文字基线和偏移）
            $y = ($height - $box[5] + $box[1]) / 2 - $box[1];
        } else {
            $available_height = $height - $text_height - ($padding * 2);
            $y = $padding + $text_height + ($available_height * $pos_y / 100);
        }
        
        // 应用特效
        switch ($params['effect'] ?? 'none') {
            case 'shadow':
                // 添加文字阴影
                $shadow_color = imagecolorallocatealpha($image, 0, 0, 0, 80);
                imagettftext($image, $params['font_size'], 0, $x + 2, $y + 2, $shadow_color, $params['font_file'], $text);
                break;
                
            case 'outline':
                // 文字描边
                $outline_color = imagecolorallocate($image, 0, 0, 0);
                for ($i = -1; $i <= 1; $i++) {
                    for ($j = -1; $j <= 1; $j++) {
                        if ($i != 0 || $j != 0) {
                            imagettftext($image, $params['font_size'], 0, $x + $i, $y + $j, $outline_color, $params['font_file'], $text);
                        }
                    }
                }
                break;
                
            case 'glow':
                // 发光效果
                $glow_color = imagecolorallocatealpha($image, 255, 255, 255, 80);
                for ($i = -2; $i <= 2; $i++) {
                    for ($j = -2; $j <= 2; $j++) {
                        imagettftext($image, $params['font_size'], 0, $x + $i, $y + $j, $glow_color, $params['font_file'], $text);
                    }
                }
                break;
        }
        
        // 写入主文字 - 根据粗细值多次绘制
        $font_weight = isset($params['font_weight']) ? $params['font_weight'] : 0;
        if ($font_weight > 0) {
            // 粗细值越大，绘制的偏移次数越多
            $offset = $font_weight / 10;  // 最大偏移1像素
            for ($i = -$font_weight; $i <= $font_weight; $i++) {
                $ox = $i * $offset / $font_weight;
                for ($j = -$font_weight; $j <= $font_weight; $j++) {
                    $oy = $j * $offset / $font_weight;
                    if ($i == 0 && $j == 0) continue;
                    imagettftext($image, $params['font_size'], 0, $x + $ox, $y + $oy, $color, $params['font_file'], $text);
                }
            }
        }
        imagettftext($image, $params['font_size'], 0, $x, $y, $color, $params['font_file'], $text);
        
        // 保存为 PNG
        $filename = md5($text . json_encode($params)) . '.png';
        $filepath = $GLOBALS['config']['output_path'] . $filename;
        imagepng($image, $filepath);
        
        imagedestroy($image);
        
        return [
            'url' => 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/output/' . $filename
        ];
        
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 创建渐变背景
 */
function createGradientBackground($image, $width, $height, $gradient) {
    $temp = imagecreatetruecolor($width, $height);
    
    $start_color = hex2rgb($gradient['start']);
    $end_color = hex2rgb($gradient['end']);
    
    for($i = 0; $i < $height; $i++) {
        $ratio = $i / $height;
        $r = $start_color[0] * (1 - $ratio) + $end_color[0] * $ratio;
        $g = $start_color[1] * (1 - $ratio) + $end_color[1] * $ratio;
        $b = $start_color[2] * (1 - $ratio) + $end_color[2] * $ratio;
        
        $color = imagecolorallocate($temp, $r, $g, $b);
        imageline($temp, 0, $i, $width, $i, $color);
    }
    
    return $temp;
}

/**
 * 颜色代码转RGB
 */
function hex2rgb($hex) {
    $hex = str_replace('#', '', $hex);
    if (strlen($hex) == 3) {
        $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
        $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
        $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    return [$r, $g, $b];
}

/**
 * 获取字体列表
 */
function getFontList() {
    global $config;
    $fonts = [];
    
    try {
        // 输出完整的字体目录路径和当前工作目录
        $currentDir = getcwd();
        $fontPath = realpath($config['font_path']);
        error_log("Current directory: " . $currentDir);
        error_log("Font directory path: " . $fontPath);
        error_log("Font directory config: " . $config['font_path']);
        
        // 确保字体目录存在
        if (!is_dir($config['font_path'])) {
            error_log("Font directory does not exist, creating...");
            mkdir($config['font_path'], 0777, true);
        }
        
        // 检查目录权限
        error_log("Directory exists: " . (is_dir($config['font_path']) ? 'yes' : 'no'));
        error_log("Directory readable: " . (is_readable($config['font_path']) ? 'yes' : 'no'));
        
        // 列出目录中的所有文件
        $files = scandir($config['font_path']);
        if ($files === false) {
            error_log("Failed to scan directory");
            throw new Exception("Failed to scan directory");
        }
        
        error_log("Files in directory: " . implode(", ", $files));
        
        // 过滤字体文件
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $fullPath = $config['font_path'] . $file;
            error_log("Processing file: " . $file);
            error_log("Full path: " . $fullPath);
            error_log("File exists: " . (file_exists($fullPath) ? 'yes' : 'no'));
            error_log("File readable: " . (is_readable($fullPath) ? 'yes' : 'no'));
            
            if (preg_match('/\.(ttf|ttc|otf)$/i', $file)) {
                $fonts[] = [
                    'file' => $file,
                    'name' => pathinfo($file, PATHINFO_FILENAME)
                ];
                error_log("Added font: " . $file);
            }
        }
        
        error_log("Total fonts found: " . count($fonts));
        error_log("Font list: " . json_encode($fonts));
        
        return $fonts;
        
    } catch (Exception $e) {
        error_log("Error in getFontList: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        return [];
    }
}












