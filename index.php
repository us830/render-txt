<?php
/**
 * 草叶集 · 文本网盘 + 文件床（事件委托修复版）
 * 纯 PHP 单文件版，无需数据库，数据存储于 /data 目录
 * 后台管理位于根路径，密码：Asd123456!
 */

// 关闭错误显示（生产环境可开启调试）
error_reporting(0);
ini_set('display_errors', 0);
session_start();

// 常量定义
define('DATA_DIR', __DIR__ . '/data');
define('FILES_DIR', DATA_DIR . '/files');
define('PASSWORD', 'Asd123456!');
define('SESSION_DAYS', 7);
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50MB

// 确保数据目录存在且可写
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}
if (!is_dir(FILES_DIR)) {
    mkdir(FILES_DIR, 0755, true);
}

/**
 * 读取 JSON 文件
 */
function readJson($file) {
    $path = DATA_DIR . '/' . $file;
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    return json_decode($content, true) ?: [];
}

/**
 * 写入 JSON 文件
 */
function writeJson($file, $data) {
    $path = DATA_DIR . '/' . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * 生成随机 6 位字母数字
 */
function generateRandomSlug() {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    return substr(str_shuffle($chars), 0, 6);
}

/**
 * 获取唯一 slug（同时检查文本和文件）
 */
function getUniqueSlug($customSlug = null) {
    $textItems = readJson('items.json');
    $fileItems = readJson('files.json');
    $allSlugs = array_merge(
        array_column($textItems, 'slug'),
        array_column($fileItems, 'slug')
    );
    
    if ($customSlug && trim($customSlug) !== '') {
        $slug = trim($customSlug);
        if (in_array($slug, $allSlugs)) {
            throw new Exception('自定义链接后缀已存在');
        }
        return $slug;
    }
    do {
        $slug = generateRandomSlug();
    } while (in_array($slug, $allSlugs));
    return $slug;
}

/**
 * 获取内容前10个字
 */
function getPreviewText($content) {
    $text = strip_tags($content);
    $preview = mb_substr($text, 0, 10, 'UTF-8');
    if (mb_strlen($text, 'UTF-8') > 10) $preview .= '...';
    return $preview;
}

/**
 * 检查登录状态（Session + Cookie 自动登录）
 */
function checkAuth() {
    if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        return true;
    }
    if (isset($_COOKIE['admin_token'])) {
        $sessions = readJson('sessions.json');
        $token = $_COOKIE['admin_token'];
        if (isset($sessions[$token]) && $sessions[$token] > time()) {
            $_SESSION['admin_logged_in'] = true;
            return true;
        }
    }
    return false;
}

/**
 * 设置登录状态（记住密码）
 */
function setAuth() {
    $token = bin2hex(random_bytes(32));
    $expires = time() + SESSION_DAYS * 86400;
    $sessions = readJson('sessions.json');
    $sessions[$token] = $expires;
    writeJson('sessions.json', $sessions);
    setcookie('admin_token', $token, $expires, '/', '', false, true);
    $_SESSION['admin_logged_in'] = true;
}

/**
 * 清除登录
 */
function clearAuth() {
    if (isset($_COOKIE['admin_token'])) {
        $sessions = readJson('sessions.json');
        unset($sessions[$_COOKIE['admin_token']]);
        writeJson('sessions.json', $sessions);
        setcookie('admin_token', '', time() - 3600, '/');
    }
    unset($_SESSION['admin_logged_in']);
    session_destroy();
}

// ===================== 路由与请求处理 =====================
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// ---------- 1. 纯文本分享 /raw/xxxxxx ----------
if (preg_match('#^/raw/([a-zA-Z0-9]+)$#', $path, $matches)) {
    $slug = $matches[1];
    $items = readJson('items.json');
    $target = null;
    foreach ($items as $item) {
        if ($item['slug'] === $slug) { $target = $item; break; }
    }
    if (!$target) { http_response_code(404); die('分享不存在或已失效'); }
    header('Content-Type: text/plain; charset=utf-8');
    echo $target['content'];
    exit;
}

// ---------- 2. 网页浏览 /web/xxxxxx ----------
if (preg_match('#^/web/([a-zA-Z0-9]+)$#', $path, $matches)) {
    $slug = $matches[1];
    $items = readJson('items.json');
    $target = null;
    foreach ($items as $item) {
        if ($item['slug'] === $slug) { $target = $item; break; }
    }
    if (!$target) { http_response_code(404); die('分享不存在或已失效'); }
    $title = htmlspecialchars($target['title']);
    $content = nl2br(htmlspecialchars($target['content']));
    $category = htmlspecialchars($target['category']);
    echo <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>$title - 草叶集</title>
<style>body{background:#f4fce8;font-family:system-ui;padding:2rem;max-width:900px;margin:0 auto;}.paper{background:white;border-radius:2rem;padding:2rem;box-shadow:0 8px 20px rgba(0,0,0,0.05);}h1{color:#3f7822;}</style>
</head>
<body><div class="paper"><h1>📄 $title</h1><div style="white-space:pre-wrap; line-height:1.6;">$content</div><hr/><small>分类: $category · 草叶集</small></div></body>
</html>
HTML;
    exit;
}

// ---------- 3. 文件分享 /file/xxxxxx ----------
if (preg_match('#^/file/([a-zA-Z0-9]+)$#', $path, $matches)) {
    $slug = $matches[1];
    $files = readJson('files.json');
    $target = null;
    foreach ($files as $file) {
        if ($file['slug'] === $slug) { $target = $file; break; }
    }
    if (!$target) { http_response_code(404); die('文件不存在或已失效'); }
    
    $filePath = FILES_DIR . '/' . $target['savedName'];
    if (!file_exists($filePath)) {
        http_response_code(404);
        die('文件实体丢失');
    }
    
    // 根据 MIME 类型决定是否内联显示图片
    $isImage = strpos($target['mime'], 'image/') === 0;
    if ($isImage) {
        header('Content-Type: ' . $target['mime']);
        header('Content-Disposition: inline; filename="' . rawurlencode($target['originalName']) . '"');
    } else {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($target['originalName']) . '"');
    }
    header('Content-Length: ' . $target['size']);
    readfile($filePath);
    exit;
}

// ---------- 4. API 路由 (/api/...) ----------
if (strpos($path, '/api/') === 0) {
    if ($path !== '/api/login' && !checkAuth()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // --- 文本管理 ---
    if ($path === '/api/items' && $method === 'GET') {
        echo json_encode(readJson('items.json'));
        exit;
    }
    if ($path === '/api/items' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $category = trim($input['category'] ?? '');
        $customSlug = trim($input['customSlug'] ?? '');
        if (!$title || !$content || !$category) {
            http_response_code(400);
            echo json_encode(['error' => '缺少必填字段']);
            exit;
        }
        try {
            $slug = getUniqueSlug($customSlug);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
        $id = uniqid();
        $preview = getPreviewText($content);
        $newItem = [
            'id' => $id,
            'title' => $title,
            'content' => $content,
            'category' => $category,
            'slug' => $slug,
            'createdAt' => time(),
            'updatedAt' => time(),
            'preview' => $preview
        ];
        $items = readJson('items.json');
        array_unshift($items, $newItem);
        writeJson('items.json', $items);
        $categories = readJson('categories.json');
        if (!in_array($category, $categories)) {
            $categories[] = $category;
            writeJson('categories.json', $categories);
        }
        echo json_encode(['success' => true, 'slug' => $slug]);
        exit;
    }
    if (preg_match('#^/api/items/(.+)$#', $path, $matches) && $method === 'PUT') {
        $id = $matches[1];
        $input = json_decode(file_get_contents('php://input'), true);
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $category = trim($input['category'] ?? '');
        $items = readJson('items.json');
        $found = false;
        foreach ($items as &$item) {
            if ($item['id'] === $id) {
                $item['title'] = $title;
                $item['content'] = $content;
                $item['category'] = $category;
                $item['updatedAt'] = time();
                $item['preview'] = getPreviewText($content);
                $found = true;
                break;
            }
        }
        if (!$found) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
        writeJson('items.json', $items);
        $categories = readJson('categories.json');
        if (!in_array($category, $categories)) {
            $categories[] = $category;
            writeJson('categories.json', $categories);
        }
        echo json_encode(['success' => true]);
        exit;
    }
    if (preg_match('#^/api/items/(.+)$#', $path, $matches) && $method === 'DELETE') {
        $id = $matches[1];
        $items = readJson('items.json');
        $newItems = [];
        foreach ($items as $item) {
            if ($item['id'] !== $id) $newItems[] = $item;
        }
        writeJson('items.json', $newItems);
        echo json_encode(['success' => true]);
        exit;
    }

    // --- 文件管理 API ---
    if ($path === '/api/files' && $method === 'GET') {
        echo json_encode(readJson('files.json'));
        exit;
    }
    if ($path === '/api/upload' && $method === 'POST') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => '文件上传失败']);
            exit;
        }
        $file = $_FILES['file'];
        if ($file['size'] > MAX_FILE_SIZE) {
            http_response_code(413);
            echo json_encode(['error' => '文件过大，最大允许 ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB']);
            exit;
        }
        $customSlug = trim($_POST['customSlug'] ?? '');
        try {
            $slug = getUniqueSlug($customSlug);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $savedName = $slug . ($ext ? '.' . $ext : '');
        $destPath = FILES_DIR . '/' . $savedName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            http_response_code(500);
            echo json_encode(['error' => '保存文件失败']);
            exit;
        }
        $fileItem = [
            'id' => uniqid(),
            'slug' => $slug,
            'originalName' => $file['name'],
            'savedName' => $savedName,
            'size' => $file['size'],
            'mime' => $file['type'],
            'createdAt' => time()
        ];
        $files = readJson('files.json');
        array_unshift($files, $fileItem);
        writeJson('files.json', $files);
        echo json_encode(['success' => true, 'slug' => $slug, 'originalName' => $file['name']]);
        exit;
    }
    if (preg_match('#^/api/files/(.+)$#', $path, $matches) && $method === 'DELETE') {
        $slug = $matches[1];
        $files = readJson('files.json');
        $newFiles = [];
        $deletedFile = null;
        foreach ($files as $file) {
            if ($file['slug'] === $slug) {
                $deletedFile = $file;
                continue;
            }
            $newFiles[] = $file;
        }
        if ($deletedFile) {
            $filePath = FILES_DIR . '/' . $deletedFile['savedName'];
            if (file_exists($filePath)) unlink($filePath);
            writeJson('files.json', $newFiles);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => '文件不存在']);
        }
        exit;
    }

    // 分类管理
    if ($path === '/api/categories' && $method === 'GET') {
        echo json_encode(readJson('categories.json'));
        exit;
    }
    if ($path === '/api/categories' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        if (!$name) { http_response_code(400); echo json_encode(['error' => 'Name required']); exit; }
        $categories = readJson('categories.json');
        if (!in_array($name, $categories)) {
            $categories[] = $name;
            writeJson('categories.json', $categories);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // 登录/登出
    if ($path === '/api/login' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $password = trim($input['password'] ?? '');
        if ($password === PASSWORD) {
            setAuth();
            echo json_encode(['success' => true]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => '密码错误']);
        }
        exit;
    }
    if ($path === '/api/logout' && $method === 'POST') {
        clearAuth();
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'API not found']);
    exit;
}

// ---------- 5. 根路径：后台管理界面（包含登录页） ----------
if ($path === '/' || $path === '') {
    if (!checkAuth()) {
        echo <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>草叶后台 - 登录</title>
<style>body{background:#eaf4e2;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;}.login-box{background:white;border-radius:2rem;padding:2rem;width:90%;max-width:360px;box-shadow:0 20px 30px rgba(0,0,0,0.05);}input,button{width:100%;margin-top:1rem;padding:0.7rem;border-radius:2rem;border:1px solid #c2dcb0;}button{background:#6f9e3f;color:white;border:none;cursor:pointer;}</style>
</head>
<body>
<div class="login-box"><h2>🔐 管理员登录</h2><input type="password" id="pwd" placeholder="请输入密码"><button id="loginBtn">登录</button><p id="err" style="color:red;margin-top:1rem;"></p></div>
<script>
document.getElementById('loginBtn').onclick=async()=>{
    const pwd=document.getElementById('pwd').value;
    const res=await fetch('/api/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({password:pwd})});
    if(res.ok){ window.location.href='/'; }
    else{ document.getElementById('err').innerText='密码错误'; }
};
</script>
</body>
</html>
HTML;
        exit;
    }

    // 已登录：显示完整后台管理界面（折叠卡片 + 文件床，事件委托修复展开）
    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>草叶后台 · 文本网盘 + 文件床</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{background:#f0f7ea;font-family:system-ui,sans-serif;padding:1rem;padding-bottom:3rem;color:#1f3b0e;}
        .container{max-width:1280px;margin:0 auto;}
        .btn{background:#eef3e8;border:1px solid #c2dcb0;padding:0.5rem 1rem;border-radius:2rem;font-weight:500;cursor:pointer;color:#2a5512;transition:0.2s;}
        .btn-primary{background:#6f9e3f;border-color:#4f742a;color:white;}
        .btn-primary:hover{background:#5c852f;}
        .btn-outline{background:transparent;border:1px solid #8bb56a;color:#3a6b1f;}
        .card{background:white;border-radius:1.5rem;box-shadow:0 2px 4px rgba(0,0,0,0.05);margin-bottom:1.5rem;border:1px solid #dbeacf;overflow:hidden;}
        .card-header{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.5rem;background:#fdfdf5;cursor:pointer;user-select:none;}
        .card-header h3{margin:0;font-size:1.2rem;color:#3a6b1f;}
        .toggle-icon{font-size:1.2rem;color:#6f9e3f;transition:transform 0.2s;}
        .card-content{padding:0 1.5rem 1.2rem 1.5rem;border-top:1px solid #e2efd6;}
        .card-content.collapsed{display:none;}
        .form-group{margin-bottom:1.2rem;}
        label{font-weight:600;display:block;margin-bottom:0.4rem;color:#2b4b12;}
        input,textarea,select{width:100%;padding:0.7rem 1rem;border-radius:1.2rem;border:1px solid #cfe2c0;background:#fefef7;font-family:inherit;}
        input:focus,textarea:focus,select:focus{outline:none;border-color:#6f9e3f;box-shadow:0 0 0 2px rgba(111,158,63,0.2);}
        .flex-row{display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between;}
        .category-chips{display:flex;flex-wrap:wrap;gap:0.6rem;margin:0.8rem 0 1.2rem;}
        .chip{background:#eef3e8;border-radius:2rem;padding:0.3rem 1rem;font-size:0.85rem;cursor:pointer;border:1px solid transparent;}
        .chip.active{background:#6f9e3f;color:white;}
        .item-list{display:flex;flex-direction:column;gap:0.8rem;}
        .accordion-item{background:white;border-radius:1.2rem;overflow:hidden;border:1px solid #e2efd6;}
        .accordion-header{padding:1rem 1.2rem;background:#fdfdf5;cursor:pointer;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;font-weight:600;}
        .item-title{font-size:1.05rem;color:#2f5a14;}
        .item-preview{font-size:0.8rem;color:#6f8460;background:#f6fbf1;padding:0.2rem 0.6rem;border-radius:2rem;}
        .action-buttons{display:flex;gap:0.8rem;margin-top:0.6rem;flex-wrap:wrap;}
        .icon-btn{background:none;border:none;font-size:1.4rem;cursor:pointer;color:#557c34;padding:0 0.2rem;}
        .accordion-body{padding:0 1.2rem 1rem 1.2rem;border-top:1px solid #e2efd6;background:#fff;display:none;}
        .accordion-body.open{display:block;}
        .modal{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;}
        .modal-content{background:white;max-width:550px;width:90%;border-radius:2rem;padding:1.5rem;max-height:85vh;overflow-y:auto;}
        .hidden{display:none;}
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
        @media (max-width:640px){.grid-2{grid-template-columns:1fr;}}
        .toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#2b5219;color:white;padding:0.5rem 1.2rem;border-radius:2rem;z-index:1100;}
        .qr-center{display:flex;justify-content:center;margin:10px 0;}
        .qr-item-container{margin-bottom:20px; border-bottom:1px solid #e0e0e0; padding-bottom:15px;}
        .qr-item-container:last-child{border-bottom:none;}
        .qr-label{font-weight:bold; margin-bottom:5px; color:#3a6b1f;}
        .file-size{font-size:0.7rem; color:#6f8460;}
        progress{width:100%; height:8px; border-radius:4px; overflow:hidden;}
        progress::-webkit-progress-bar{background:#e2efd6;}
        progress::-webkit-progress-value{background:#6f9e3f;}
    </style>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs2@0.0.2/qrcode.min.js"></script>
</head>
<body>
<div class="container">
    <div class="flex-row" style="margin-bottom:0.8rem;"><h2 style="color:#497e24;">📄 草叶后台 + 文件床</h2><button id="logoutBtn" class="btn logout-btn">🚪 退出</button></div>

    <!-- 分类筛选卡片（仅文本） -->
    <div class="card">
        <div class="card-header" style="cursor:default; background:#f0f7ea;"><strong>📂 分类筛选</strong><button id="showNewCatModalBtn" class="btn btn-outline" style="margin-left:auto;">+ 新建分类</button></div>
        <div class="card-content" style="padding-top:0;"><div id="categoryChips" class="category-chips"></div></div>
    </div>

    <!-- 创建新文本分享卡片 -->
    <div class="card">
        <div class="card-header" id="createHeader">
            <h3>✨ 创建新文本分享</h3>
            <span class="toggle-icon" id="createToggle">▼</span>
        </div>
        <div class="card-content" id="createContent">
            <div class="grid-2">
                <div class="form-group"><label>标题 *</label><input id="newTitle" placeholder="标题"></div>
                <div class="form-group"><label>分类 *</label><select id="newCategorySelect"></select></div>
            </div>
            <div class="form-group"><label>内容 *</label><textarea id="newContent" rows="12" placeholder="填写文本内容..."></textarea></div>
            <div class="form-group"><label>自定义链接后缀 (选填)</label><input id="newSlug" placeholder="例如 mynote2024"></div>
            <button id="createBtn" class="btn btn-primary">📎 创建分享链接</button>
        </div>
    </div>

    <!-- 文件床卡片（上传+列表） -->
    <div class="card">
        <div class="card-header" id="fileHeader">
            <h3>📁 文件床</h3>
            <span class="toggle-icon" id="fileToggle">▼</span>
        </div>
        <div class="card-content" id="fileContent">
            <div class="form-group"><label>📤 上传文件（最大50MB）</label><input type="file" id="uploadFile"></div>
            <div class="form-group"><label>自定义链接后缀 (选填)</label><input id="fileCustomSlug" placeholder="例如 mypic2024"></div>
            <progress id="uploadProgress" value="0" max="100" style="display:none;"></progress>
            <button id="uploadBtn" class="btn btn-primary">⬆️ 上传</button>
            <hr style="margin:1rem 0;">
            <h4>📋 已上传文件</h4>
            <div id="fileList" class="item-list" style="margin-top:1rem;">加载中...</div>
        </div>
    </div>

    <!-- 所有文本内容卡片 -->
    <div class="card">
        <div class="card-header" id="listHeader">
            <h3>📋 所有文本内容</h3>
            <span class="toggle-icon" id="listToggle">▼</span>
        </div>
        <div class="card-content" id="listContent">
            <div id="itemsList" class="item-list" style="margin-top:1rem;">加载中...</div>
        </div>
    </div>
</div>

<!-- 文本编辑模态框 -->
<div id="editModal" class="modal hidden"><div class="modal-content"><h3>✏️ 编辑文本</h3><input type="hidden" id="editId"><div class="form-group"><label>标题</label><input id="editTitle"></div><div class="form-group"><label>分类</label><select id="editCategory"></select></div><div class="form-group"><label>内容</label><textarea id="editContent" rows="5"></textarea></div><div class="flex-row"><button id="saveEditBtn" class="btn btn-primary">保存修改</button><button id="closeModalBtn" class="btn">取消</button></div></div></div>

<!-- 二维码模态框 -->
<div id="qrModal" class="modal hidden"><div class="modal-content" style="text-align:center;"><h4>📱 分享二维码</h4><div id="qrWebContainer" class="qr-item-container"><div class="qr-label">🌐 链接</div><div id="qrcodeWeb" class="qr-center"></div><p id="webLinkText" style="word-break:break-all; font-size:0.8rem; margin-top:5px;"></p></div><button id="closeQrBtn" class="btn">关闭</button></div></div>

<!-- 复制链接选择模态框（仅文本需要选择网页/原始） -->
<div id="copySelectModal" class="modal hidden"><div class="modal-content" style="text-align:center;"><h4>📋 选择链接类型</h4><div style="display:flex; gap:1rem; justify-content:center; margin-top:1rem;"><button id="copyWebBtn" class="btn btn-primary">🌐 网页链接</button><button id="copyRawBtn" class="btn btn-outline">📄 原始文本链接</button></div><button id="closeCopyModalBtn" class="btn" style="margin-top:1rem;">取消</button></div></div>

<script>
    let allItems = [], categories = [], currentFilter = "all";
    let allFiles = [];
    let pendingCopyWebUrl = "", pendingCopyRawUrl = "";

    // 折叠功能（大卡片折叠，非列表内折叠）
    function initCollapse() {
        const createHeader = document.getElementById('createHeader'), createContent = document.getElementById('createContent'), createToggle = document.getElementById('createToggle');
        const listHeader = document.getElementById('listHeader'), listContent = document.getElementById('listContent'), listToggle = document.getElementById('listToggle');
        const fileHeader = document.getElementById('fileHeader'), fileContent = document.getElementById('fileContent'), fileToggle = document.getElementById('fileToggle');
        let createCollapsed = false, listCollapsed = false, fileCollapsed = false;
        createHeader.addEventListener('click', () => { createCollapsed = !createCollapsed; createContent.classList.toggle('collapsed'); createToggle.textContent = createCollapsed ? '▶' : '▼'; });
        listHeader.addEventListener('click', () => { listCollapsed = !listCollapsed; listContent.classList.toggle('collapsed'); listToggle.textContent = listCollapsed ? '▶' : '▼'; });
        fileHeader.addEventListener('click', () => { fileCollapsed = !fileCollapsed; fileContent.classList.toggle('collapsed'); fileToggle.textContent = fileCollapsed ? '▶' : '▼'; });
    }

    async function apiFetch(path, options={}) {
        const resp = await fetch(path, {...options, headers:{'Content-Type':'application/json', ...options.headers}});
        if(resp.status===401){ alert("认证失效，请重新登录"); window.location.href="/?logout=1"; return null; }
        return resp;
    }
    async function loadData() {
        try{
            const [itemsRes, catsRes, filesRes] = await Promise.all([apiFetch("/api/items"), apiFetch("/api/categories"), apiFetch("/api/files")]);
            if(itemsRes && itemsRes.ok) allItems = await itemsRes.json();
            if(catsRes && catsRes.ok) categories = await catsRes.json();
            if(filesRes && filesRes.ok) allFiles = await filesRes.json();
            renderCategoryChips(); renderItemsList(); renderFileList(); updateCategorySelects();
        }catch(e){ console.error(e); }
    }
    function updateCategorySelects() {
        ['newCategorySelect','editCategory'].forEach(id=>{
            const sel = document.getElementById(id);
            if(sel){ sel.innerHTML = categories.map(c=>'<option value="'+c+'">'+c+'</option>').join(''); if(id==='newCategorySelect' && categories.length) sel.value = categories[0]; }
        });
    }
    function renderCategoryChips() {
        const container = document.getElementById('categoryChips');
        let html = '<div class="chip '+(currentFilter==='all'?'active':'')+'" data-cat="all">📌 全部</div>';
        categories.forEach(cat=>{ html += '<div class="chip '+(currentFilter===cat?'active':'')+'" data-cat="'+cat+'">🌿 '+cat+'</div>'; });
        container.innerHTML = html;
        document.querySelectorAll('.chip').forEach(chip=>{ chip.addEventListener('click',()=>{ currentFilter = chip.dataset.cat; renderCategoryChips(); renderItemsList(); }); });
    }
    function escapeHtml(str){ if(!str) return ''; return str.replace(/[&<>]/g, function(m){ if(m==='&') return '&amp;'; if(m==='<') return '&lt;'; if(m==='>') return '&gt;'; return m;}); }
    function formatSize(bytes) { if(bytes<1024) return bytes+'B'; if(bytes<1048576) return (bytes/1024).toFixed(1)+'KB'; return (bytes/1048576).toFixed(1)+'MB'; }

    // 渲染文本列表（仅HTML，不再单独绑定折叠事件，使用全局委托）
    function renderItemsList() {
        const container = document.getElementById('itemsList');
        let filtered = currentFilter==='all' ? allItems : allItems.filter(i=>i.category===currentFilter);
        if(filtered.length===0){ container.innerHTML='<div style="padding:1rem;text-align:center;">暂无文本，创建一条吧~</div>'; return; }
        let html = '';
        filtered.forEach(item=>{
            const webUrl = window.location.origin + '/web/' + item.slug;
            const rawUrl = window.location.origin + '/raw/' + item.slug;
            const previewText = item.preview || "无内容";
            html += '<div class="accordion-item" data-id="'+item.id+'" data-type="text">'+
                '<div class="accordion-header">'+
                    '<span class="item-title">📄 '+escapeHtml(item.title)+'</span>'+
                    '<span class="item-preview">'+escapeHtml(previewText)+'</span>'+
                '</div>'+
                '<div class="accordion-body">'+
                    '<div class="action-buttons">'+
                        '<button class="icon-btn edit-item" data-id="'+item.id+'" title="编辑">✏️</button>'+
                        '<button class="icon-btn open-web" data-url="'+webUrl+'" title="网页浏览">🔗</button>'+
                        '<button class="icon-btn open-raw" data-url="'+rawUrl+'" title="原始文本">📄</button>'+
                        '<button class="icon-btn copy-link" data-web="'+webUrl+'" data-raw="'+rawUrl+'" title="复制链接">📋</button>'+
                        '<button class="icon-btn qr-item" data-web="'+webUrl+'" data-raw="'+rawUrl+'" title="二维码">📱</button>'+
                        '<button class="icon-btn delete-item" data-id="'+item.id+'" data-type="text" title="删除">🗑️</button>'+
                    '</div>'+
                    '<div style="font-size:0.75rem; margin-top:0.5rem;">🔗 /web/'+item.slug+'</div>'+
                '</div>'+
            '</div>';
        });
        container.innerHTML = html;
    }

    // 渲染文件列表
    function renderFileList() {
        const container = document.getElementById('fileList');
        if(allFiles.length===0){ container.innerHTML='<div style="padding:1rem;text-align:center;">暂无文件，上传一个吧~</div>'; return; }
        let html = '';
        allFiles.forEach(file=>{
            const fileUrl = window.location.origin + '/file/' + file.slug;
            html += '<div class="accordion-item" data-id="'+file.id+'" data-type="file">'+
                '<div class="accordion-header">'+
                    '<span class="item-title">📎 '+escapeHtml(file.originalName)+'</span>'+
                    '<span class="file-size">'+formatSize(file.size)+'</span>'+
                '</div>'+
                '<div class="accordion-body">'+
                    '<div class="action-buttons">'+
                        '<button class="icon-btn open-file" data-url="'+fileUrl+'" title="访问文件">🔗</button>'+
                        '<button class="icon-btn copy-file-link" data-url="'+fileUrl+'" title="复制链接">📋</button>'+
                        '<button class="icon-btn qr-file" data-url="'+fileUrl+'" title="二维码">📱</button>'+
                        '<button class="icon-btn delete-file" data-slug="'+file.slug+'" title="删除">🗑️</button>'+
                    '</div>'+
                    '<div style="font-size:0.75rem; margin-top:0.5rem;">🔗 /file/'+file.slug+'</div>'+
                '</div>'+
            '</div>';
        });
        container.innerHTML = html;
    }

    // 全局事件委托（处理所有动态元素的点击）
    function bindGlobalDelegation() {
        document.body.addEventListener('click', (e) => {
            // 折叠/展开：点击 .accordion-header
            const header = e.target.closest('.accordion-header');
            if (header && !e.target.closest('.icon-btn') && !e.target.closest('.action-buttons')) {
                const body = header.parentElement.querySelector('.accordion-body');
                if (body) {
                    e.preventDefault();
                    body.classList.toggle('open');
                }
                return;
            }
            // 编辑文本
            if (e.target.closest('.edit-item')) {
                const btn = e.target.closest('.edit-item');
                const id = btn.dataset.id;
                openEditModal(id);
                return;
            }
            // 打开网页链接
            if (e.target.closest('.open-web')) {
                const btn = e.target.closest('.open-web');
                window.open(btn.dataset.url, '_blank');
                return;
            }
            // 打开原始文本
            if (e.target.closest('.open-raw')) {
                const btn = e.target.closest('.open-raw');
                window.open(btn.dataset.url, '_blank');
                return;
            }
            // 复制文本链接（显示选择框）
            if (e.target.closest('.copy-link')) {
                const btn = e.target.closest('.copy-link');
                showCopyChoice(btn.dataset.web, btn.dataset.raw);
                return;
            }
            // 文本二维码
            if (e.target.closest('.qr-item')) {
                const btn = e.target.closest('.qr-item');
                showDoubleQR(btn.dataset.web, btn.dataset.raw);
                return;
            }
            // 删除文本
            if (e.target.closest('.delete-item') && e.target.closest('.delete-item').dataset.type === 'text') {
                const btn = e.target.closest('.delete-item');
                if(confirm('确定删除此文本？')) deleteTextItem(btn.dataset.id);
                return;
            }
            // 打开文件链接
            if (e.target.closest('.open-file')) {
                const btn = e.target.closest('.open-file');
                window.open(btn.dataset.url, '_blank');
                return;
            }
            // 复制文件链接
            if (e.target.closest('.copy-file-link')) {
                const btn = e.target.closest('.copy-file-link');
                copyToClipboard(btn.dataset.url, "文件链接");
                return;
            }
            // 文件二维码
            if (e.target.closest('.qr-file')) {
                const btn = e.target.closest('.qr-file');
                showSingleQR(btn.dataset.url);
                return;
            }
            // 删除文件
            if (e.target.closest('.delete-file')) {
                const btn = e.target.closest('.delete-file');
                if(confirm('确定删除此文件？')) deleteFile(btn.dataset.slug);
                return;
            }
        });
    }

    function showCopyChoice(webUrl, rawUrl) { pendingCopyWebUrl = webUrl; pendingCopyRawUrl = rawUrl; document.getElementById('copySelectModal').classList.remove('hidden'); }
    async function copyToClipboard(text, typeMsg) { try { await navigator.clipboard.writeText(text); showToast("✅ "+typeMsg+" 已复制"); } catch(err) { showToast("❌ 复制失败"); } }
    function handleCopyWeb() { copyToClipboard(pendingCopyWebUrl, "网页链接"); closeCopyModal(); }
    function handleCopyRaw() { copyToClipboard(pendingCopyRawUrl, "原始文本链接"); closeCopyModal(); }
    function closeCopyModal() { document.getElementById('copySelectModal').classList.add('hidden'); pendingCopyWebUrl=""; pendingCopyRawUrl=""; }
    function showDoubleQR(webUrl, rawUrl) {
        document.getElementById('qrcodeWeb').innerHTML = ""; 
        new QRCode(document.getElementById('qrcodeWeb'), { text: webUrl, width: 180, height: 180, colorDark: '#2a5512' });
        document.getElementById('webLinkText').innerHTML = "🌐 网页链接<br>"+webUrl;
        document.getElementById('qrModal').classList.remove('hidden');
    }
    function showSingleQR(url) {
        document.getElementById('qrcodeWeb').innerHTML = ""; 
        new QRCode(document.getElementById('qrcodeWeb'), { text: url, width: 180, height: 180, colorDark: '#2a5512' });
        document.getElementById('webLinkText').innerHTML = "🔗 访问链接<br>"+url;
        document.getElementById('qrModal').classList.remove('hidden');
    }
    async function openEditModal(id){ const item=allItems.find(i=>i.id===id); if(!item) return; document.getElementById('editId').value=item.id; document.getElementById('editTitle').value=item.title; document.getElementById('editContent').value=item.content; const catSelect=document.getElementById('editCategory'); catSelect.innerHTML=categories.map(c=>'<option value="'+c+'" '+(c===item.category?'selected':'')+'>'+c+'</option>').join(''); document.getElementById('editModal').classList.remove('hidden'); }
    async function saveEdit(){ const id=document.getElementById('editId').value, title=document.getElementById('editTitle').value, content=document.getElementById('editContent').value, category=document.getElementById('editCategory').value; if(!title.trim()||!content.trim()){ alert("标题和内容不能为空"); return; } const resp=await apiFetch('/api/items/'+id,{method:'PUT',body:JSON.stringify({title,content,category})}); if(resp&&resp.ok){ closeModal(); loadData(); showToast("更新成功"); }else showToast("更新失败"); }
    async function deleteTextItem(id){ const resp=await apiFetch('/api/items/'+id,{method:'DELETE'}); if(resp&&resp.ok){ loadData(); showToast("已删除文本"); } }
    async function deleteFile(slug){ const resp=await apiFetch('/api/files/'+slug,{method:'DELETE'}); if(resp&&resp.ok){ loadData(); showToast("已删除文件"); } }
    async function createItem(){ const title=document.getElementById('newTitle').value, content=document.getElementById('newContent').value, category=document.getElementById('newCategorySelect').value, customSlug=document.getElementById('newSlug').value; if(!title.trim()||!content.trim()){ alert("标题和内容不能为空"); return; } const resp=await apiFetch("/api/items",{method:'POST',body:JSON.stringify({title,content,category,customSlug})}); if(resp&&resp.ok){ const newItem=await resp.json(); document.getElementById('newTitle').value=''; document.getElementById('newContent').value=''; document.getElementById('newSlug').value=''; loadData(); showToast('创建成功！链接: /web/'+newItem.slug); }else showToast("创建失败，slug可能重复"); }
    async function uploadFile() { const fileInput=document.getElementById('uploadFile'); const file=fileInput.files[0]; if(!file){ alert("请选择文件"); return; } const customSlug=document.getElementById('fileCustomSlug').value; const formData=new FormData(); formData.append('file',file); formData.append('customSlug',customSlug); const progress=document.getElementById('uploadProgress'); progress.style.display='block'; const xhr=new XMLHttpRequest(); xhr.open('POST','/api/upload',true); xhr.upload.onprogress=function(e){ if(e.lengthComputable){ const percent=e.loaded/e.total*100; progress.value=percent; } }; xhr.onload=function(){ if(xhr.status===200){ const res=JSON.parse(xhr.responseText); if(res.success){ showToast('上传成功！链接: /file/'+res.slug); fileInput.value=''; document.getElementById('fileCustomSlug').value=''; loadData(); }else{ showToast('上传失败: '+res.error); } }else{ showToast('上传失败'); } progress.style.display='none'; }; xhr.send(formData); }
    function closeModal(){ document.getElementById('editModal').classList.add('hidden'); document.getElementById('qrModal').classList.add('hidden'); }
    function showToast(msg){ let t=document.createElement('div'); t.className='toast'; t.innerText=msg; document.body.appendChild(t); setTimeout(()=>t.remove(),2000); }
    async function newCategory(){ let catName=prompt("请输入分类名称"); if(catName&&catName.trim()){ const resp=await apiFetch("/api/categories",{method:'POST',body:JSON.stringify({name:catName.trim()})}); if(resp&&resp.ok){ loadData(); showToast("分类添加成功"); }else showToast("分类已存在或无效"); } }
    async function logout(){ await apiFetch("/api/logout",{method:'POST'}); window.location.href="/"; }

    document.addEventListener('DOMContentLoaded',()=>{
        initCollapse();
        bindGlobalDelegation();
        loadData();
        document.getElementById('createBtn').addEventListener('click',createItem);
        document.getElementById('logoutBtn').addEventListener('click',logout);
        document.getElementById('saveEditBtn').addEventListener('click',saveEdit);
        document.getElementById('closeModalBtn').addEventListener('click',closeModal);
        document.getElementById('closeQrBtn').addEventListener('click',closeModal);
        document.getElementById('showNewCatModalBtn').addEventListener('click',newCategory);
        document.getElementById('uploadBtn').addEventListener('click',uploadFile);
        document.getElementById('copyWebBtn').addEventListener('click',handleCopyWeb);
        document.getElementById('copyRawBtn').addEventListener('click',handleCopyRaw);
        document.getElementById('closeCopyModalBtn').addEventListener('click',closeCopyModal);
    });
</script>
</body>
</html>
HTML;
    exit;
}

http_response_code(404);
echo 'Not Found';
